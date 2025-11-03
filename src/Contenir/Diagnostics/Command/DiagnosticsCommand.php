<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Command;

use Laminas\Diagnostics\Check;
use Laminas\Diagnostics\Result;
use Laminas\Diagnostics\Result\Collection as ResultCollection;
use Laminas\Diagnostics\Runner\Runner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function array_filter;
use function chmod;
use function count;
use function dirname;
use function file_exists;
use function file_put_contents;
use function getcwd;
use function is_dir;
use function is_writable;
use function mkdir;
use function sprintf;

/**
 * System Diagnostics Command
 *
 * Runs comprehensive system diagnostics to verify application health:
 * - PHP version and required extensions
 * - Directory permissions (data, cache, logs)
 * - Configuration files
 * - Database connectivity (CMS SQLite + Site MySQL)
 * - Security advisories
 * - Disk space
 *
 * Usage:
 *   vendor/bin/laminas diagnostics           # Check only
 *   vendor/bin/laminas diagnostics --fix     # Check and auto-fix issues
 */
class DiagnosticsCommand extends Command
{
    private bool $autoFix = false;
    private SymfonyStyle $io;
    private array $fixAttempts = [];

    public function __construct(
        private readonly array $config
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('diagnostics')
            ->setAliases(['diagnostics:run'])
            ->setDescription('Run system diagnostics to verify application health')
            ->addOption(
                'fix',
                'f',
                InputOption::VALUE_NONE,
                'Attempt to automatically fix common issues (missing directories, permissions)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io      = new SymfonyStyle($input, $output);
        $this->autoFix = (bool) $input->getOption('fix');

        $this->io->title('Contenir System Diagnostics');

        if ($this->autoFix) {
            $this->io->note('Auto-fix mode enabled: will attempt to repair common issues');
        }

        // First pass: Check and attempt fixes if enabled
        $this->io->section('Phase 1: Initial Checks' . ($this->autoFix ? ' and Auto-Fix' : ''));
        $this->performChecksWithFixes();

        // Second pass: Verify fixes worked
        if ($this->autoFix && ! empty($this->fixAttempts)) {
            $this->io->newLine();
            $this->io->section('Phase 2: Verification');
        }

        // Initialize diagnostic runner for final check
        $runner = new Runner();

        // Add all diagnostic checks
        $this->addPhpEnvironmentChecks($runner);
        $this->addDirectoryChecks($runner);
        $this->addConfigurationChecks($runner);
        $this->addDatabaseChecks($runner);
        $this->addSecurityChecks($runner);
        $this->addDiskSpaceChecks($runner);

        // Run all checks
        $results = $runner->run();

        // Display results
        $this->displayResults($runner, $results);

        // Return appropriate exit code
        return $this->hasFailures($results) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Perform checks with auto-fix attempts
     */
    private function performChecksWithFixes(): void
    {
        $this->io->writeln('→ Checking Directories & Permissions...');

        $writableDirectories = [
            'data',
            'data/cache',
            'data/cache/laminas',
            'data/logs',
        ];

        foreach ($writableDirectories as $dir) {
            $this->checkAndFixDirectory($dir);
        }

        $this->io->writeln('→ Checking Configuration Files...');

        $requiredConfigFiles = [
            'config/autoload/local.php',
            'config/autoload/cache.global.php',
        ];

        foreach ($requiredConfigFiles as $file) {
            $this->checkConfigFile($file);
        }
    }

    /**
     * Check directory and attempt to fix if needed
     */
    private function checkAndFixDirectory(string $dir): void
    {
        $exists   = is_dir($dir);
        $writable = $exists && is_writable($dir);

        if ($exists && $writable) {
            $this->io->writeln("  <info>✓</info> {$dir} - exists and writable");
            return;
        }

        // Directory has issues
        if (! $exists) {
            $this->io->writeln("  <error>✗</error> {$dir} - <comment>does not exist</comment>");

            if ($this->autoFix) {
                $this->attemptFixDirectory($dir, 'create');
            }
        } elseif (! $writable) {
            $this->io->writeln("  <error>✗</error> {$dir} - <comment>not writable</comment>");

            if ($this->autoFix) {
                $this->attemptFixDirectory($dir, 'chmod');
            }
        }
    }

    /**
     * Attempt to fix directory issue
     */
    private function attemptFixDirectory(string $dir, string $action): void
    {
        $this->io->writeln("    <fg=cyan>→ Attempting to {$action} directory...</>");

        try {
            if ($action === 'create') {
                // Create directory with proper permissions
                $result = mkdir($dir, 0755, true);

                if ($result) {
                    $this->io->writeln("    <info>✓ Successfully created {$dir}</info>");
                    $this->fixAttempts[] = ['dir' => $dir, 'action' => 'create', 'success' => true];
                } else {
                    $this->io->writeln("    <error>✗ Failed to create {$dir}</error>");
                    $this->fixAttempts[] = ['dir' => $dir, 'action' => 'create', 'success' => false];
                }
            } elseif ($action === 'chmod') {
                // Fix permissions
                $result = chmod($dir, 0755);

                if ($result) {
                    $this->io->writeln("    <info>✓ Successfully fixed permissions for {$dir}</info>");
                    $this->fixAttempts[] = ['dir' => $dir, 'action' => 'chmod', 'success' => true];
                } else {
                    $this->io->writeln("    <error>✗ Failed to fix permissions for {$dir}</error>");
                    $this->fixAttempts[] = ['dir' => $dir, 'action' => 'chmod', 'success' => false];
                }
            }
        } catch (Throwable $e) {
            $this->io->writeln("    <error>✗ Error: {$e->getMessage()}</error>");
            $this->fixAttempts[] = ['dir' => $dir, 'action' => $action, 'success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check configuration file
     */
    private function checkConfigFile(string $file): void
    {
        if (file_exists($file)) {
            $this->io->writeln("  <info>✓</info> {$file} - found");
        } else {
            $this->io->writeln("  <error>✗</error> {$file} - <comment>missing</comment>");

            if ($this->autoFix) {
                // Special handling for local.php - we can auto-create it
                if ($file === 'config/autoload/local.php') {
                    $this->attemptFixConfigFile($file);
                } else {
                    $this->io->writeln("    <fg=yellow>⚠ Cannot auto-create {$file} (requires manual setup)</>");
                }
            }
        }
    }

    /**
     * Attempt to create missing config file
     */
    private function attemptFixConfigFile(string $file): void
    {
        $this->io->writeln("    <fg=cyan>→ Attempting to create config file...</>");

        try {
            // Default content for local.php
            $defaultContent = <<<'PHP'
<?php

declare(strict_types=1);

use Laminas\ConfigAggregator\ConfigAggregator;

return [
    ConfigAggregator::ENABLE_CACHE => false,
];

PHP;

            // Ensure directory exists
            $dir = dirname($file);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Write the file
            $result = file_put_contents($file, $defaultContent);

            if ($result !== false) {
                $this->io->writeln("    <info>✓ Successfully created {$file}</info>");
                $this->fixAttempts[] = ['file' => $file, 'action' => 'create', 'success' => true];
            } else {
                $this->io->writeln("    <error>✗ Failed to create {$file}</error>");
                $this->fixAttempts[] = ['file' => $file, 'action' => 'create', 'success' => false];
            }
        } catch (Throwable $e) {
            $this->io->writeln("    <error>✗ Error: {$e->getMessage()}</error>");
            $this->fixAttempts[] = [
                'file'    => $file,
                'action'  => 'create',
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Add PHP environment checks
     */
    private function addPhpEnvironmentChecks(Runner $runner): void
    {
        if (! $this->autoFix) {
            $this->io->writeln('→ Checking PHP Environment...');
        }

        $runner->addCheck(new Check\PhpVersion('8.3', '>='), 'PHP Version >= 8.3');

        $requiredExtensions = [
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

        foreach ($requiredExtensions as $ext) {
            $runner->addCheck(new Check\ExtensionLoaded($ext), "PHP Extension: {$ext}");
        }
    }

    /**
     * Add directory and permission checks
     */
    private function addDirectoryChecks(Runner $runner): void
    {
        if (! $this->autoFix) {
            $this->io->writeln('→ Checking Directories & Permissions...');
        }

        $writableDirectories = [
            'data',
            'data/cache',
            'data/cache/laminas',
            'data/logs',
        ];

        foreach ($writableDirectories as $dir) {
            $runner->addCheck(new Check\DirWritable($dir), "Writable: {$dir}");
        }
    }

    /**
     * Add configuration file checks
     */
    private function addConfigurationChecks(Runner $runner): void
    {
        if (! $this->autoFix) {
            $this->io->writeln('→ Checking Configuration Files...');
        }

        $requiredConfigFiles = [
            'config/autoload/local.php',
            'config/autoload/cache.global.php',
        ];

        foreach ($requiredConfigFiles as $file) {
            // Use a simple check to verify file exists
            $runner->addCheck(
                new Check\Callback(function () use ($file) {
                    if (! file_exists($file)) {
                        return new Result\Failure("Missing: {$file}");
                    }
                    return new Result\Success("Found: {$file}");
                }),
                "Config: {$file}"
            );
        }
    }

    /**
     * Add database connectivity checks
     */
    private function addDatabaseChecks(Runner $runner): void
    {
        if (! $this->autoFix) {
            $this->io->writeln('→ Checking Database Connectivity...');
        }

        try {
            // Check CMS Database (SQLite)
            if (isset($this->config['db']['adapters']['cms'])) {
                $cmsDbPath = $this->config['db']['adapters']['cms']['database'] ?? 'data/database.sqlite';

                $runner->addCheck(
                    new Check\Callback(function () use ($cmsDbPath) {
                        if (! file_exists($cmsDbPath)) {
                            return new Result\Failure("CMS Database missing: {$cmsDbPath}");
                        }
                        return new Result\Success("CMS Database found: {$cmsDbPath}");
                    }),
                    'CMS Database (SQLite)'
                );
            }

            // Check Site Database (MySQL)
            if (isset($this->config['db']['adapters']['site'])) {
                $siteDb = $this->config['db']['adapters']['site'];
                $dsn    = sprintf(
                    'mysql:host=%s;dbname=%s',
                    $siteDb['hostname'] ?? 'localhost',
                    $siteDb['database'] ?? ''
                );

                $runner->addCheck(
                    new Check\PDOCheck(
                        $dsn,
                        $siteDb['username'] ?? '',
                        $siteDb['password'] ?? ''
                    ),
                    'Site Database (MySQL)'
                );
            }
        } catch (Throwable $e) {
            $this->io->warning("Database config error: {$e->getMessage()}");
        }
    }

    /**
     * Add security checks
     */
    private function addSecurityChecks(Runner $runner): void
    {
        if (! $this->autoFix) {
            $this->io->writeln('→ Checking Security...');
        }

        $runner->addCheck(new Check\SecurityAdvisory('composer.lock'), 'Security Advisories');
    }

    /**
     * Add disk space checks
     */
    private function addDiskSpaceChecks(Runner $runner): void
    {
        if (! $this->autoFix) {
            $this->io->writeln('→ Checking Disk Space...');
        }

        $runner->addCheck(
            new Check\DiskFree(1024 * 1024 * 100, getcwd()), // 100MB minimum
            'Disk Space (min 100MB free)'
        );
    }

    /**
     * Display diagnostic results
     */
    private function displayResults(Runner $runner, ResultCollection $results): void
    {
        $this->io->newLine();

        $success = 0;
        $warning = 0;
        $failure = 0;
        $skip    = 0;

        $checks = $runner->getChecks();

        foreach ($checks as $alias => $check) {
            $result = $results[$check];

            if ($result instanceof Result\Success) {
                $this->io->writeln("<info>  ✓ {$alias}</info>");
                $success++;
            } elseif ($result instanceof Result\Warning) {
                $this->io->writeln("<comment>  ⚠ {$alias}: {$result->getMessage()}</comment>");
                $warning++;
            } elseif ($result instanceof Result\Failure) {
                $this->io->writeln("<error>  ✗ {$alias}: {$result->getMessage()}</error>");
                $failure++;
            } elseif ($result instanceof Result\Skip) {
                $this->io->writeln("<fg=gray>  ⊘ {$alias}: {$result->getMessage()}</>");
                $skip++;
            }
        }

        // Summary
        $this->io->newLine();
        $this->io->writeln('───────────────────────────────────────────────────────────────────────────');
        $this->io->writeln(sprintf(
            'Summary: <info>%d passed</info> | <comment>%d warnings</comment> | <error>%d failures</error>',
            $success,
            $warning,
            $failure
        ));

        // Show fix summary if applicable
        if ($this->autoFix && ! empty($this->fixAttempts)) {
            $this->io->newLine();
            $fixSuccesses = count(array_filter($this->fixAttempts, fn($f) => $f['success']));
            $fixFailures  = count($this->fixAttempts) - $fixSuccesses;

            if ($fixSuccesses > 0) {
                $this->io->writeln(
                    "<info>Auto-fix: {$fixSuccesses} issue(s) repaired successfully</info>"
                );
            }
            if ($fixFailures > 0) {
                $this->io->writeln(
                    "<error>Auto-fix: {$fixFailures} issue(s) could not be repaired</error>"
                );
            }
        }

        $this->io->newLine();

        if ($failure > 0) {
            if ($this->autoFix) {
                $this->io->error('System still has critical issues. Some problems require manual intervention.');
            } else {
                $this->io->error('System has critical issues. Run with --fix to attempt automatic repairs.');
            }
        } elseif ($warning > 0) {
            $this->io->warning('System has warnings. Application may run with reduced functionality.');
        } else {
            $this->io->success('All checks passed! System is ready.');
        }
    }

    /**
     * Check if there are any failures in the results
     */
    private function hasFailures(ResultCollection $results): bool
    {
        foreach ($results as $result) {
            if ($result instanceof Result\Failure) {
                return true;
            }
        }

        return false;
    }
}
