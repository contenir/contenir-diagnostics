<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Tests\Integration\Command;

use Contenir\Diagnostics\Command\DiagnosticsCommand;
use Contenir\Diagnostics\Command\DiagnosticsCommandFactory;
use Contenir\Diagnostics\Tests\TestAsset\Check\FixedCheckProvider;
use Contenir\Diagnostics\Tests\TestAsset\Container\InMemoryContainer;
use Contenir\Diagnostics\Tests\TestAsset\Network\ClosingTcpServer;
use Contenir\Diagnostics\Tests\TestAsset\Result\UnclassifiedResult;
use Contenir\Diagnostics\Tests\Trait\TemporaryDirectoryTrait;
use Laminas\Diagnostics\Check\Callback;
use Laminas\Diagnostics\Check\CheckInterface;
use Laminas\Diagnostics\Result\Skip;
use Laminas\Diagnostics\Result\Success;
use Laminas\Diagnostics\Result\Warning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function array_filter;
use function array_values;
use function chdir;
use function chmod;
use function error_clear_last;
use function error_get_last;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function is_dir;
use function is_writable;
use function mkdir;
use function reset;
use function rmdir;
use function str_starts_with;
use function symlink;

/**
 * Runs the command in a temporary application root.
 */
#[Group('integration')]
final class DiagnosticsCommandTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private const string RULE = '───────────────────────────────────────────────────────────────────────────';

    private string $originalCwd;

    /**
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function configsWithoutDatabases(): array
    {
        return [
            'no db key'          => [[]],
            'db is not an array' => [['db' => 'sqlite']],
            'adapters not array' => [['db' => ['adapters' => 'x']]],
            'adapter not array'  => [['db' => ['cms' => 'cms.db', 'site' => true]]],
        ];
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string}>
     */
    public static function databaseConfigs(): array
    {
        return [
            'cms under db'               => [
                ['db' => ['cms' => ['database' => 'cms.db']]],
                'CMS Database (SQLite): CMS Database missing: cms.db',
            ],
            'cms under db.adapters'      => [
                ['db' => ['adapters' => ['cms' => ['database' => 'cms.db']]]],
                'CMS Database missing: cms.db',
            ],
            'cms without a database key' => [['db' => ['cms' => []]], 'CMS Database missing: data/database.sqlite'],
            'db before db.adapters'      => [
                ['db' => ['cms' => ['database' => 'cms.db'], 'adapters' => ['cms' => ['database' => 'other.db']]]],
                'CMS Database missing: cms.db',
            ],
            'site database'              => [
                [
                    'db' => ['site' => [
                        'hostname' => '127.0.0.1',
                        'port'     => 1,
                        'database' => 'none',
                        'username' => 'nobody',
                    ]],
                ],
                'Site Database (MySQL)',
            ],
            'site on the default port'   => [
                ['db' => ['site' => ['hostname' => '127.0.0.1', 'username' => 'nobody']]],
                'Site Database (MySQL)',
            ],
            'site under db.adapters'     => [
                ['db' => ['adapters' => ['site' => ['hostname' => '127.0.0.1', 'port' => '1']]]],
                'Site Database (MySQL)',
            ],
        ];
    }

    /**
     * @return array<string, array{mixed, list<string>, list<string>}>
     */
    public static function extensionConfigs(): array
    {
        return [
            'configured list'          => [
                ['json',                42],
                ['PHP Extension: json'],
                ['PHP Extension: gd',   'PHP Extension: 42'],
            ],
            'not a list uses defaults' => ['json', ['PHP Extension: gd', 'PHP Extension: zip'], []],
        ];
    }

    private static function check(mixed $result): CheckInterface
    {
        return new Callback(static fn(): mixed => $result);
    }

    /**
     * A command that requires only the json extension, so results do not
     * depend on the extensions of the machine running the tests.
     *
     * @param array<array-key, mixed> $config
     */
    private static function commandFor(array $config): DiagnosticsCommand
    {
        return new DiagnosticsCommand([...$config, 'contenir_diagnostics' => ['required_extensions' => ['json']]]);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    #[Test]
    #[DataProvider('databaseConfigs')]
    public function checksTheConfiguredDatabases(array $config, string $expected): void
    {
        $this->prepareApplication();

        $tester = $this->execute(self::commandFor($config));

        static::assertStringContainsString($expected, $tester->getDisplay());
        static::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function connectsToTheSiteDatabaseOnTheConfiguredPort(): void
    {
        $this->prepareApplication();
        $server = new ClosingTcpServer();

        try {
            $display = $this->execute(self::commandFor([
                'db' => ['site' => ['hostname' => '127.0.0.1', 'port' => $server->port, 'username' => 'nobody']],
            ]))->getDisplay();
        } finally {
            $server->stop();
        }

        static::assertMatchesRegularExpression(
            '/✗ Site Database \(MySQL\): Could not talk to database server, e: (2006|2013)\b/',
            $display,
        );
    }

    #[Test]
    public function createsMissingDirectoriesAndTheLocalConfigWithFix(): void
    {
        $tester = $this->executeWithFix($this->command());

        static::assertDirectoryExists('data/cache/laminas');
        static::assertStringContainsString(
            'ConfigAggregator::ENABLE_CACHE => false',
            (string) file_get_contents('config/autoload/local.php'),
        );
        static::assertStringContainsString(
            "1 failures\n\nAuto-fix: 5 issue(s) repaired successfully\n\n [ERROR]",
            $tester->getDisplay(),
        );
        static::assertStringContainsString(
            'Cannot auto-create config/autoload/cache.global.php',
            $tester->getDisplay(),
        );
        static::assertStringContainsString('System still has critical issues', $tester->getDisplay());
    }

    #[Test]
    public function createsMissingDirectoriesReadableByEveryone(): void
    {
        $this->executeWithFix($this->command());

        static::assertSame(
            ['data/logs' => 0o755, 'config/autoload' => 0o755],
            [
                'data/logs'       => $this->permissionsOf('data/logs'),
                'config/autoload' => $this->permissionsOf('config/autoload'),
            ],
        );
    }

    #[Test]
    public function findsAnExistingCmsDatabase(): void
    {
        $this->prepareApplication();
        file_put_contents('cms.db', data: 'sqlite');

        $display = $this->execute(self::commandFor([
            'db' => ['cms' => ['database' => 'cms.db']],
        ]))->getDisplay();

        static::assertStringContainsString('✓ CMS Database (SQLite)', $display);
    }

    #[Test]
    public function headsACheckRunWithoutTheAutoFixNote(): void
    {
        $this->prepareApplication();

        $display = $this->execute($this->command())->getDisplay();

        static::assertStringStartsWith(
            "\nContenir System Diagnostics\n===========================\n\nPhase 1: Initial Checks\n-----------------------\n\n",
            $display,
        );
    }

    #[Test]
    public function headsAFixRunWithTheAutoFixNote(): void
    {
        $this->prepareApplication();

        $display = $this->executeWithFix($this->command())->getDisplay();

        static::assertMatchesRegularExpression(
            '/^\nContenir System Diagnostics\n=+\n\n ! \[NOTE\] Auto-fix mode enabled: will attempt to repair common issues *\n\n'
                . 'Phase 1: Initial Checks and Auto-Fix\n-{36}\n\n/',
            $display,
        );
    }

    #[Test]
    public function leavesMissingConfigFilesAloneWithoutFix(): void
    {
        $this->execute($this->command());

        static::assertFileDoesNotExist('config/autoload/local.php');
    }

    #[Test]
    public function listsEachCheckGroupAsItIsAdded(): void
    {
        $this->prepareApplication();

        $display = $this->execute($this->command())->getDisplay();

        static::assertStringContainsString(
            <<<'TEXT'
                → Checking Directories & Permissions...
                  ✓ data - exists and writable
                  ✓ data/cache - exists and writable
                  ✓ data/cache/laminas - exists and writable
                  ✓ data/logs - exists and writable
                → Checking Configuration Files...
                  ✓ config/autoload/local.php - found
                  ✓ config/autoload/cache.global.php - found
                → Checking PHP Environment...
                → Checking Directories & Permissions...
                → Checking Configuration Files...
                → Checking Database Connectivity...
                → Checking Security...
                → Checking Disk Space...

                  ✓ PHP Version >= 8.3

                TEXT,
            $display,
        );
    }

    #[Test]
    public function listsOnlyTheFirstPassGroupsWithFix(): void
    {
        $this->prepareApplication();

        $display = $this->executeWithFix($this->command())->getDisplay();

        static::assertSame(
            ['→ Checking Directories & Permissions...', '→ Checking Configuration Files...'],
            array_values(array_filter(
                explode("\n", $display),
                static fn(string $line): bool => str_starts_with($line, '→ Checking'),
            )),
        );
    }

    #[Test]
    public function listsSkippedChecksAndIgnoresUnclassifiedResults(): void
    {
        $this->prepareApplication();

        $display = $this->execute($this->command([
            'Optional' => self::check(new Skip('not configured')),
            'Odd'      => self::check(new UnclassifiedResult('odd')),
        ]))->getDisplay();

        static::assertStringContainsString('⊘ Optional: not configured', $display);
        static::assertStringNotContainsString('Odd', $display);
    }

    #[Test]
    public function makesAReadOnlyDirectoryWritableWithFix(): void
    {
        $this->skipWhenRunningAsRoot();
        $this->prepareApplication();
        chmod('data/logs', permissions: 0o555);

        $tester = $this->executeWithFix($this->command());

        static::assertStringContainsString(
            "  ✗ data/logs - not writable\n    → Attempting to chmod directory...\n"
                . "    ✓ Successfully fixed permissions for data/logs\n",
            $tester->getDisplay(),
        );
        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertSame(0o755, $this->permissionsOf('data/logs'));
    }

    #[Test]
    public function passesForAPreparedApplication(): void
    {
        $this->prepareApplication();

        $tester = $this->execute($this->command());

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertStringContainsString(
            "\n\n"
                . self::RULE
                . "\nSummary: 9 passed | 0 warnings | 0 failures\n\n [OK] All checks passed! System is ready.",
            $tester->getDisplay(),
        );
    }

    #[Test]
    public function passesWithFixWhenNothingNeedsFixing(): void
    {
        $this->prepareApplication();

        $display = $this->executeWithFix($this->command())->getDisplay();

        static::assertStringNotContainsString('Phase 2: Verification', $display);
        static::assertStringContainsString(
            "\nSummary: 9 passed | 0 warnings | 0 failures\n\n [OK] All checks passed! System is ready.",
            $display,
        );
    }

    #[Test]
    public function reportsADirectoryItCannotMakeWritable(): void
    {
        $this->skipWhenRunningAsRoot();
        $this->prepareApplication();
        $rootOwned = array_filter(
            ['/usr', '/bin'],
            static fn(string $dir): bool => is_dir($dir) && ! is_writable($dir),
        );
        if ([] === $rootOwned) {
            static::markTestSkipped('No read-only root-owned directory available.');
        }

        rmdir('data/logs');
        symlink((string) reset($rootOwned), link: 'data/logs');

        $display = $this->executeWithFix($this->command())->getDisplay();

        static::assertStringContainsString('Failed to fix permissions for data/logs', $display);
    }

    #[Test]
    public function reportsAReadOnlyDirectory(): void
    {
        $this->skipWhenRunningAsRoot();
        $this->prepareApplication();
        chmod('data/logs', permissions: 0o555);

        $tester = $this->execute($this->command());

        static::assertStringContainsString('data/logs - not writable', $tester->getDisplay());
        static::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function reportsEachFixAsItIsAttempted(): void
    {
        $display = $this->executeWithFix($this->command())->getDisplay();

        static::assertStringContainsString(
            <<<'TEXT'
                → Checking Directories & Permissions...
                  ✗ data - does not exist
                    → Attempting to create directory...
                    ✓ Successfully created data
                  ✗ data/cache - does not exist
                    → Attempting to create directory...
                    ✓ Successfully created data/cache
                  ✗ data/cache/laminas - does not exist
                    → Attempting to create directory...
                    ✓ Successfully created data/cache/laminas
                  ✗ data/logs - does not exist
                    → Attempting to create directory...
                    ✓ Successfully created data/logs
                → Checking Configuration Files...
                  ✗ config/autoload/local.php - missing
                    → Attempting to create config file...
                    ✓ Successfully created config/autoload/local.php
                  ✗ config/autoload/cache.global.php - missing
                    ⚠ Cannot auto-create config/autoload/cache.global.php (requires manual setup)

                Phase 2: Verification

                TEXT,
            $display,
        );
    }

    #[Test]
    public function reportsFailedFixesWithoutRaisingTheirWarnings(): void
    {
        $this->skipWhenRunningAsRoot();
        chmod($this->tmpDir, permissions: 0o555);
        error_clear_last();

        $this->executeWithFix($this->command());

        static::assertNull(error_get_last());
    }

    #[Test]
    public function reportsFixesThatFail(): void
    {
        $this->skipWhenRunningAsRoot();
        chmod($this->tmpDir, permissions: 0o555);

        $display = $this->executeWithFix($this->command())->getDisplay();

        static::assertStringContainsString('Failed to create data/logs', $display);
        static::assertStringContainsString('Failed to create config/autoload/local.php', $display);
        static::assertStringContainsString('Auto-fix: 5 issue(s) could not be repaired', $display);
        static::assertStringNotContainsString('repaired successfully', $display);
    }

    #[Test]
    public function reportsMissingDirectoriesAndConfigFiles(): void
    {
        $tester = $this->execute(new DiagnosticsCommand([]));

        static::assertSame(Command::FAILURE, $tester->getStatusCode());
        static::assertStringContainsString('data/logs - does not exist', $tester->getDisplay());
        static::assertStringContainsString('config/autoload/local.php - missing', $tester->getDisplay());
        static::assertStringContainsString('Run with --fix', $tester->getDisplay());
        static::assertStringNotContainsString('Phase 2: Verification', $tester->getDisplay());
        static::assertStringNotContainsString('All checks passed', $tester->getDisplay());
    }

    /**
     * @param list<string> $present
     * @param list<string> $absent
     */
    #[Test]
    #[DataProvider('extensionConfigs')]
    public function requiresTheConfiguredExtensions(mixed $extensions, array $present, array $absent): void
    {
        $display = $this->execute(new DiagnosticsCommand([
            'contenir_diagnostics' => ['required_extensions' => $extensions],
        ]))->getDisplay();

        foreach ($present as $label) {
            static::assertStringContainsString($label, $display);
        }

        foreach ($absent as $label) {
            static::assertStringNotContainsString($label, $display);
        }
    }

    #[Test]
    public function runsEveryCheckGroup(): void
    {
        $display = $this->execute(new DiagnosticsCommand([]))->getDisplay();

        foreach ([
            'PHP Version >= 8.3',
            'PHP Extension: json',
            'Writable: data',
            'Config: config/autoload/local.php',
            'Security Advisories',
            'Disk Space (min 100MB free)',
        ] as $alias) {
            static::assertStringContainsString($alias, $display);
        }
    }

    #[Test]
    public function runsTheChecksOfEveryProvider(): void
    {
        $this->prepareApplication();
        $command = new DiagnosticsCommand(['contenir_diagnostics' => ['required_extensions' => ['json']]], [
            new FixedCheckProvider(['Search index' => self::check(new Success('fresh'))]),
            new FixedCheckProvider(['Mail queue' => self::check(new Warning('slow'))]),
        ]);

        $display = $this->execute($command)->getDisplay();

        static::assertStringContainsString('✓ Search index', $display);
        static::assertStringContainsString('⚠ Mail queue: slow', $display);
    }

    #[Test]
    public function runsTheProvidersTheFactoryResolvesFromConfig(): void
    {
        $this->prepareApplication();
        $command = (new DiagnosticsCommandFactory())(new InMemoryContainer([
            'config'     => [
                'contenir_diagnostics' => ['required_extensions' => ['json'], 'check_providers' => ['SiteChecks']],
            ],
            'SiteChecks' => new FixedCheckProvider(['Search index' => self::check(new Success('fresh'))]),
        ]));

        static::assertStringContainsString('✓ Search index', $this->execute($command)->getDisplay());
    }

    /**
     * @param array<array-key, mixed> $config
     */
    #[Test]
    #[DataProvider('configsWithoutDatabases')]
    public function skipsDatabaseChecksWithoutAdapterConfig(array $config): void
    {
        $this->prepareApplication();

        $display = $this->execute(self::commandFor($config))->getDisplay();

        static::assertStringNotContainsString('(SQLite)', $display);
        static::assertStringNotContainsString('(MySQL)', $display);
    }

    #[Test]
    public function skipsTheSecurityCheckWithoutAComposerLock(): void
    {
        $this->prepareApplication();

        $display = $this->execute($this->command())->getDisplay();

        static::assertStringContainsString('⊘ Security Advisories: You have not provided lock file path', $display);
    }

    #[Test]
    public function warnsWhenAnyCheckWarns(): void
    {
        $this->prepareApplication();

        $tester = $this->execute($this->command(['Cache' => self::check(new Warning('cold'))]));

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertStringContainsString('⚠ Cache: cold', $tester->getDisplay());
        static::assertStringContainsString('System has warnings', $tester->getDisplay());
        static::assertStringNotContainsString('All checks passed', $tester->getDisplay());
    }

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->originalCwd = (string) getcwd();
        chdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->tearDownTemporaryDirectory();
    }

    /**
     * Like commandFor([]), with extra checks from a provider.
     *
     * @param array<string, CheckInterface> $checks
     */
    private function command(array $checks = []): DiagnosticsCommand
    {
        return new DiagnosticsCommand(
            ['contenir_diagnostics' => ['required_extensions' => ['json']]],
            [new FixedCheckProvider($checks)],
        );
    }

    private function execute(Command $command): CommandTester
    {
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    private function executeWithFix(Command $command): CommandTester
    {
        $tester = new CommandTester($command);
        $tester->execute(['--fix' => true]);

        return $tester;
    }

    private function prepareApplication(): void
    {
        foreach (DiagnosticsCommand::WRITABLE_DIRECTORIES as $directory) {
            mkdir($directory, recursive: true);
        }

        mkdir('config/autoload', recursive: true);
        foreach (DiagnosticsCommand::REQUIRED_CONFIG_FILES as $file) {
            file_put_contents($file, data: '<?php return [];');
        }
    }
}
