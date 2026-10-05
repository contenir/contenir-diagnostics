<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Command;

use Contenir\Diagnostics\Check\CheckProviderInterface;
use Contenir\Diagnostics\Check\SecurityAdvisoryCheck;
use Laminas\Diagnostics\Check;
use Laminas\Diagnostics\Result;
use Laminas\Diagnostics\Result\Collection as ResultCollection;
use Laminas\Diagnostics\Runner\Runner;
use Override;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_filter;
use function array_values;
use function chmod;
use function count;
use function dirname;
use function file_exists;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_scalar;
use function is_string;
use function is_writable;
use function mkdir;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;

/**
 * System diagnostics: PHP version and extensions, writable directories,
 * configuration files, database connectivity, security advisories and disk
 * space. With --fix it first creates missing directories, makes read-only
 * ones writable and writes a default config/autoload/local.php.
 *
 * Paths are relative to the working directory: run it from the application
 * root.
 *
 * Usage:
 *   vendor/bin/laminas diagnostics           # check only
 *   vendor/bin/laminas diagnostics --fix     # check and auto-fix issues
 *
 * Sites add checks through CheckProviderInterface services listed under
 * `contenir_diagnostics.check_providers`, and can replace the required
 * extensions with `contenir_diagnostics.required_extensions`.
 *
 * @api
 *
 * @mago-expect lint:too-many-methods One command runs every check; splitting it into check providers is a suggested follow-up.
 * @mago-expect lint:kan-defect One command runs every check; splitting it into check providers is a suggested follow-up.
 * @mago-expect lint:cyclomatic-complexity One command runs every check; splitting it into check providers is a suggested follow-up.
 */
final class DiagnosticsCommand extends Command
{
    /** Directories that must exist and be writable, relative to the working directory. */
    public const array WRITABLE_DIRECTORIES = ['data', 'data/cache', 'data/cache/laminas', 'data/logs'];

    /** Configuration files that must exist, relative to the working directory. */
    public const array REQUIRED_CONFIG_FILES = ['config/autoload/local.php', 'config/autoload/cache.global.php'];

    /** PHP extensions that must be loaded. */
    public const array REQUIRED_EXTENSIONS = [
        'pdo',
        'pdo_mysql',
        'pdo_sqlite',
        'gd',
        'mbstring',
        'json',
        'intl',
        'fileinfo',
        'zip',
    ];

    /** Minimum free disk space, in bytes. */
    public const int MINIMUM_FREE_DISK_SPACE = 100 * 1024 * 1024;

    private const string LOCAL_CONFIG_FILE = 'config/autoload/local.php';

    private const string DEFAULT_LOCAL_CONFIG = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Laminas\ConfigAggregator\ConfigAggregator;

        return [
            ConfigAggregator::ENABLE_CACHE => false,
        ];

        PHP;

    private bool $autoFix = false;

    private SymfonyStyle $io;

    /** @var list<bool> Whether each auto-fix attempt succeeded. */
    private array $fixAttempts = [];

    /**
     * @param array<array-key, mixed> $config The application config; reads the db.cms and db.site adapters
     *     and contenir_diagnostics.required_extensions.
     * @param iterable<CheckProviderInterface> $checkProviders Site-specific checks, run after the built-in ones.
     */
    public function __construct(
        private readonly array $config,
        private readonly iterable $checkProviders = [],
    ) {
        parent::__construct();

        $this->io = new SymfonyStyle(new ArrayInput([]), new NullOutput());
    }

    /**
     * Run a filesystem call, reporting failure through its return value
     * rather than the warning it raises.
     *
     * @param callable(): bool $operation
     */
    private static function silently(callable $operation): bool
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @mago-expect analysis:mixed-assignment Config values are untyped; the type is checked here.
     */
    private static function stringValue(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    #[Override]
    protected function configure(): void
    {
        $this->setName('diagnostics')
            ->setAliases(['diagnostics:run'])
            ->setDescription('Run system diagnostics to verify application health')
            ->addOption(
                'fix',
                'f',
                InputOption::VALUE_NONE,
                'Attempt to automatically fix common issues (missing directories, permissions)',
            );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io          = new SymfonyStyle($input, $output);
        $this->autoFix     = true === $input->getOption('fix');
        $this->fixAttempts = [];

        $this->io->title('Contenir System Diagnostics');

        if ($this->autoFix) {
            $this->io->note('Auto-fix mode enabled: will attempt to repair common issues');
        }

        $this->io->section('Phase 1: Initial Checks' . ($this->autoFix ? ' and Auto-Fix' : ''));
        $this->performChecksWithFixes();

        if ($this->autoFix && [] !== $this->fixAttempts) {
            $this->io->newLine();
            $this->io->section('Phase 2: Verification');
        }

        $runner = new Runner();
        $this->addPhpEnvironmentChecks($runner);
        $this->addDirectoryChecks($runner);
        $this->addConfigurationChecks($runner);
        $this->addDatabaseChecks($runner);
        $this->addSecurityChecks($runner);
        $this->addDiskSpaceChecks($runner);
        $this->addProvidedChecks($runner);

        $results = $runner->run();
        $this->displayResults($runner, $results);

        return $results->getFailureCount() > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The adapter config under db.<name>, or db.adapters.<name>.
     *
     * @return array<array-key, mixed>|null
     *
     * @mago-expect analysis:mixed-assignment Config values are untyped; each level is checked here.
     */
    private function adapterConfig(string $name): ?array
    {
        $db       = $this->config['db'] ?? null;
        $db       = is_array($db) ? $db : [];
        $adapters = is_array($db['adapters'] ?? null) ? $db['adapters'] : [];
        $adapter  = $db[$name] ?? $adapters[$name] ?? null;

        return is_array($adapter) ? $adapter : null;
    }

    /**
     * Add the configuration file checks.
     */
    private function addConfigurationChecks(Runner $runner): void
    {
        $this->announce('Configuration Files');

        foreach (self::REQUIRED_CONFIG_FILES as $file) {
            $runner->addCheck(
                new Check\Callback(static fn(): Result\ResultInterface => file_exists($file)
                    ? new Result\Success("Found: {$file}")
                    : new Result\Failure("Missing: {$file}")),
                "Config: {$file}",
            );
        }
    }

    /**
     * Add the database checks: the CMS SQLite file exists, and the site
     * database accepts a connection. Each runs only when its adapter is
     * configured, under db.cms / db.site (or db.adapters.cms / db.adapters.site).
     */
    private function addDatabaseChecks(Runner $runner): void
    {
        $this->announce('Database Connectivity');

        $cms = $this->adapterConfig('cms');
        if (null !== $cms) {
            $cmsDbPath = self::stringValue($cms, 'database', 'data/database.sqlite');
            $runner->addCheck(
                new Check\Callback(static fn(): Result\ResultInterface => file_exists($cmsDbPath)
                    ? new Result\Success("CMS Database found: {$cmsDbPath}")
                    : new Result\Failure("CMS Database missing: {$cmsDbPath}")),
                'CMS Database (SQLite)',
            );
        }

        $site = $this->adapterConfig('site');
        if (null !== $site) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s',
                self::stringValue($site, 'hostname', 'localhost'),
                self::stringValue($site, 'database', ''),
            );
            $port = self::stringValue($site, 'port', '');
            $runner->addCheck(
                new Check\PDOCheck(
                    '' === $port ? $dsn : "{$dsn};port={$port}",
                    self::stringValue($site, 'username', ''),
                    self::stringValue($site, 'password', ''),
                ),
                'Site Database (MySQL)',
            );
        }
    }

    /**
     * Add the writable directory checks.
     */
    private function addDirectoryChecks(Runner $runner): void
    {
        $this->announce('Directories & Permissions');

        foreach (self::WRITABLE_DIRECTORIES as $dir) {
            $runner->addCheck(new Check\DirWritable($dir), "Writable: {$dir}");
        }
    }

    /**
     * Add the free disk space check for the working directory's filesystem.
     */
    private function addDiskSpaceChecks(Runner $runner): void
    {
        $this->announce('Disk Space');

        $runner->addCheck(
            new Check\DiskFree(self::MINIMUM_FREE_DISK_SPACE, '.'),
            'Disk Space (min 100MB free)',
        );
    }

    /**
     * Add the PHP version and extension checks.
     */
    private function addPhpEnvironmentChecks(Runner $runner): void
    {
        $this->announce('PHP Environment');

        $runner->addCheck(new Check\PhpVersion('8.3', operator: '>='), 'PHP Version >= 8.3');

        foreach ($this->requiredExtensions() as $ext) {
            $runner->addCheck(new Check\ExtensionLoaded($ext), "PHP Extension: {$ext}");
        }
    }

    /**
     * Add the checks from the registered check providers.
     */
    private function addProvidedChecks(Runner $runner): void
    {
        foreach ($this->checkProviders as $provider) {
            foreach ($provider->getChecks() as $alias => $check) {
                $runner->addCheck($check, $alias);
            }
        }
    }

    /**
     * Add the security advisories check for the working directory's
     * composer.lock. It is skipped, with the reason, when
     * enlightn/security-checker is not installed or there is no composer.lock.
     */
    private function addSecurityChecks(Runner $runner): void
    {
        $this->announce('Security');

        $runner->addCheck(SecurityAdvisoryCheck::create(), 'Security Advisories');
    }

    /**
     * Print a progress line for a check group when not in fix mode (fix mode
     * already printed one during the first pass).
     */
    private function announce(string $group): void
    {
        if (! $this->autoFix) {
            $this->io->writeln("→ Checking {$group}...");
        }
    }

    private function attemptFixConfigFile(string $file): void
    {
        $this->io->writeln('    <fg=cyan>→ Attempting to create config file...</>');

        $dir = dirname($file);
        $this->recordFix(
            static fn(): bool => (
                (
                    is_dir($dir)
                    || mkdir($dir, permissions: 0o755, recursive: true)
                )
                && false !== file_put_contents($file, self::DEFAULT_LOCAL_CONFIG)
            ),
            "    <info>✓ Successfully created {$file}</info>",
            "    <error>✗ Failed to create {$file}</error>",
        );
    }

    private function checkAndFixDirectory(string $dir): void
    {
        if (is_dir($dir) && is_writable($dir)) {
            $this->io->writeln("  <info>✓</info> {$dir} - exists and writable");

            return;
        }

        if (! is_dir($dir)) {
            $this->io->writeln("  <error>✗</error> {$dir} - <comment>does not exist</comment>");
            if ($this->autoFix) {
                $this->io->writeln('    <fg=cyan>→ Attempting to create directory...</>');
                $this->recordFix(
                    static fn(): bool => mkdir($dir, permissions: 0o755, recursive: true),
                    "    <info>✓ Successfully created {$dir}</info>",
                    "    <error>✗ Failed to create {$dir}</error>",
                );
            }

            return;
        }

        $this->io->writeln("  <error>✗</error> {$dir} - <comment>not writable</comment>");
        if ($this->autoFix) {
            $this->io->writeln('    <fg=cyan>→ Attempting to chmod directory...</>');
            $this->recordFix(
                static fn(): bool => chmod($dir, permissions: 0o755),
                "    <info>✓ Successfully fixed permissions for {$dir}</info>",
                "    <error>✗ Failed to fix permissions for {$dir}</error>",
            );
        }
    }

    private function checkConfigFile(string $file): void
    {
        if (file_exists($file)) {
            $this->io->writeln("  <info>✓</info> {$file} - found");

            return;
        }

        $this->io->writeln("  <error>✗</error> {$file} - <comment>missing</comment>");

        if (! $this->autoFix) {
            return;
        }

        if (self::LOCAL_CONFIG_FILE === $file) {
            $this->attemptFixConfigFile($file);

            return;
        }

        $this->io->writeln("    <fg=yellow>⚠ Cannot auto-create {$file} (requires manual setup)</>");
    }

    private function displayFixSummary(): void
    {
        if (! $this->autoFix || [] === $this->fixAttempts) {
            return;
        }

        $this->io->newLine();
        $fixSuccesses = count(array_filter($this->fixAttempts));
        $fixFailures  = count($this->fixAttempts) - $fixSuccesses;

        if ($fixSuccesses > 0) {
            $this->io->writeln("<info>Auto-fix: {$fixSuccesses} issue(s) repaired successfully</info>");
        }

        if ($fixFailures > 0) {
            $this->io->writeln("<error>Auto-fix: {$fixFailures} issue(s) could not be repaired</error>");
        }
    }

    /**
     * @mago-expect analysis:mixed-assignment The runner's checks and results are untyped collections.
     * @mago-expect analysis:mixed-array-index The runner's checks and results are untyped collections.
     */
    private function displayResults(Runner $runner, ResultCollection $results): void
    {
        $this->io->newLine();

        foreach ($runner->getChecks() as $alias => $check) {
            $result = $results[$check];
            $line   = match (true) {
                $result instanceof Result\SuccessInterface => "<info>  ✓ {$alias}</info>",
                $result instanceof Result\WarningInterface
                    => "<comment>  ⚠ {$alias}: {$result->getMessage()}</comment>",
                $result instanceof Result\FailureInterface => "<error>  ✗ {$alias}: {$result->getMessage()}</error>",
                $result instanceof Result\SkipInterface => "<fg=gray>  ⊘ {$alias}: {$result->getMessage()}</>",
                default => null,
            };
            if (null !== $line) {
                $this->io->writeln($line);
            }
        }

        $this->io->newLine();
        $this->io->writeln('───────────────────────────────────────────────────────────────────────────');
        $this->io->writeln(sprintf(
            'Summary: <info>%d passed</info> | <comment>%d warnings</comment> | <error>%d failures</error>',
            $results->getSuccessCount(),
            $results->getWarningCount(),
            $results->getFailureCount(),
        ));

        $this->displayFixSummary();
        $this->io->newLine();

        if ($results->getFailureCount() > 0) {
            $this->io->error(
                $this->autoFix
                    ? 'System still has critical issues. Some problems require manual intervention.'
                    : 'System has critical issues. Run with --fix to attempt automatic repairs.',
            );

            return;
        }

        if ($results->getWarningCount() > 0) {
            $this->io->warning('System has warnings. Application may run with reduced functionality.');

            return;
        }

        $this->io->success('All checks passed! System is ready.');
    }

    /**
     * The first pass: report (and with --fix, repair) directories and config files.
     */
    private function performChecksWithFixes(): void
    {
        $this->io->writeln('→ Checking Directories & Permissions...');
        foreach (self::WRITABLE_DIRECTORIES as $dir) {
            $this->checkAndFixDirectory($dir);
        }

        $this->io->writeln('→ Checking Configuration Files...');
        foreach (self::REQUIRED_CONFIG_FILES as $file) {
            $this->checkConfigFile($file);
        }
    }

    /**
     * @param callable(): bool $fix
     */
    private function recordFix(callable $fix, string $successMessage, string $failureMessage): void
    {
        $success = self::silently($fix);
        $this->io->writeln($success ? $successMessage : $failureMessage);
        $this->fixAttempts[] = $success;
    }

    /**
     * The extensions to require: contenir_diagnostics.required_extensions
     * when configured as a list of names, otherwise REQUIRED_EXTENSIONS.
     *
     * @return list<string>
     *
     * @mago-expect analysis:mixed-assignment Config values are untyped; each level is checked here.
     */
    private function requiredExtensions(): array
    {
        $options    = $this->config['contenir_diagnostics'] ?? null;
        $extensions = is_array($options) ? $options['required_extensions'] ?? null : null;

        if (! is_array($extensions)) {
            return self::REQUIRED_EXTENSIONS;
        }

        return array_values(array_filter($extensions, is_string(...)));
    }
}
