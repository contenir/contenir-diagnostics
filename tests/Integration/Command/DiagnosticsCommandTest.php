<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Tests\Integration\Command;

use Contenir\Diagnostics\Command\DiagnosticsCommand;
use Contenir\Diagnostics\Tests\TestAsset\Command\FixedChecksCommand;
use Contenir\Diagnostics\Tests\TestAsset\Result\UnclassifiedResult;
use Contenir\Diagnostics\Tests\Trait\TemporaryDirectoryTrait;
use Laminas\Diagnostics\Check\Callback;
use Laminas\Diagnostics\Check\CheckInterface;
use Laminas\Diagnostics\Check\SecurityAdvisory;
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
use function chdir;
use function chmod;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function is_dir;
use function is_writable;
use function mkdir;
use function reset;
use function rmdir;
use function symlink;

/**
 * Runs the command in a temporary application root.
 */
#[Group('integration')]
final class DiagnosticsCommandTest extends TestCase
{
    use TemporaryDirectoryTrait;

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

    private static function check(mixed $result): CheckInterface
    {
        return new Callback(static fn(): mixed => $result);
    }

    #[Test]
    public function checksSecurityAdvisoriesWhenThereIsAComposerLock(): void
    {
        file_put_contents('composer.lock', data: '{"packages": []}');

        $checks = $this->command()->securityChecks();

        static::assertInstanceOf(SecurityAdvisory::class, $checks['Security Advisories'] ?? null);
    }

    /**
     * @param array<array-key, mixed> $config
     */
    #[Test]
    #[DataProvider('databaseConfigs')]
    public function checksTheConfiguredDatabases(array $config, string $expected): void
    {
        $this->prepareApplication();

        $tester = $this->execute(new FixedChecksCommand($config, []));

        static::assertStringContainsString($expected, $tester->getDisplay());
        static::assertSame(Command::FAILURE, $tester->getStatusCode());
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
        static::assertStringContainsString('Auto-fix: 5 issue(s) repaired successfully', $tester->getDisplay());
        static::assertStringContainsString(
            'Cannot auto-create config/autoload/cache.global.php',
            $tester->getDisplay(),
        );
        static::assertStringContainsString('System still has critical issues', $tester->getDisplay());
    }

    #[Test]
    public function findsAnExistingCmsDatabase(): void
    {
        $this->prepareApplication();
        file_put_contents('cms.db', data: 'sqlite');

        $display = $this->execute(new FixedChecksCommand([
            'db' => ['cms' => ['database' => 'cms.db']],
        ], []))->getDisplay();

        static::assertStringContainsString('✓ CMS Database (SQLite)', $display);
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

        static::assertStringContainsString('Successfully fixed permissions for data/logs', $tester->getDisplay());
        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    #[Test]
    public function passesForAPreparedApplication(): void
    {
        $this->prepareApplication();

        $tester = $this->execute($this->command());

        static::assertSame(Command::SUCCESS, $tester->getStatusCode());
        static::assertStringContainsString('All checks passed! System is ready.', $tester->getDisplay());
    }

    #[Test]
    public function passesWithFixWhenNothingNeedsFixing(): void
    {
        $this->prepareApplication();

        $display = $this->executeWithFix($this->command())->getDisplay();

        static::assertStringNotContainsString('Phase 2: Verification', $display);
        static::assertStringContainsString('All checks passed! System is ready.', $display);
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
    public function reportsFixesThatFail(): void
    {
        $this->skipWhenRunningAsRoot();
        chmod($this->tmpDir, permissions: 0o555);

        $display = $this->executeWithFix($this->command())->getDisplay();

        static::assertStringContainsString('Failed to create data/logs', $display);
        static::assertStringContainsString('Failed to create config/autoload/local.php', $display);
        static::assertStringContainsString('Auto-fix: 5 issue(s) could not be repaired', $display);
    }

    #[Test]
    public function reportsMissingDirectoriesAndConfigFiles(): void
    {
        $tester = $this->execute(new DiagnosticsCommand([]));

        static::assertSame(Command::FAILURE, $tester->getStatusCode());
        static::assertStringContainsString('data/logs - does not exist', $tester->getDisplay());
        static::assertStringContainsString('config/autoload/local.php - missing', $tester->getDisplay());
        static::assertStringContainsString('Run with --fix', $tester->getDisplay());
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

    /**
     * @param array<array-key, mixed> $config
     */
    #[Test]
    #[DataProvider('configsWithoutDatabases')]
    public function skipsDatabaseChecksWithoutAdapterConfig(array $config): void
    {
        $this->prepareApplication();

        $display = $this->execute(new FixedChecksCommand($config, []))->getDisplay();

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
     * @param array<string, CheckInterface> $checks
     */
    private function command(array $checks = []): FixedChecksCommand
    {
        return new FixedChecksCommand([], [] === $checks ? ['PHP' => self::check(new Success())] : $checks);
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
