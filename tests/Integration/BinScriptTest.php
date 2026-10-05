<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Tests\Integration;

use Contenir\Diagnostics\Tests\Trait\TemporaryDirectoryTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_put_contents;
use function is_resource;
use function mkdir;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function var_export;

use const PHP_BINARY;

/**
 * Runs bin/diagnostics as a separate process in a temporary application root.
 */
#[Group('integration')]
#[Group('slow')]
final class BinScriptTest extends TestCase
{
    use TemporaryDirectoryTrait;

    #[Test]
    public function failsOutsideAnApplicationRoot(): void
    {
        [$exitCode, , $stderr] = $this->runScript();

        static::assertSame(1, $exitCode);
        static::assertStringContainsString('Could not find container.php', $stderr);
    }

    #[Test]
    public function failsWhenTheContainerCannotProvideTheCommand(): void
    {
        $this->createApplication('return new Laminas\ServiceManager\ServiceManager();');

        [$exitCode, , $stderr] = $this->runScript();

        static::assertSame(1, $exitCode);
        static::assertStringContainsString('Error: Could not load DiagnosticsCommand', $stderr);
    }

    #[Test]
    public function runsTheCommandFromTheApplicationContainer(): void
    {
        $this->createApplication(<<<'PHP'
            return new Laminas\ServiceManager\ServiceManager([
                'factories' => [
                    Contenir\Diagnostics\Command\DiagnosticsCommand::class =>
                        Contenir\Diagnostics\Command\DiagnosticsCommandFactory::class,
                ],
                'services'  => ['config' => []],
            ]);
            PHP);

        [$exitCode, $stdout] = $this->runScript('--fix');

        static::assertSame(1, $exitCode);
        static::assertStringContainsString('Auto-fix mode enabled', $stdout);
    }

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    private function createApplication(string $container): void
    {
        mkdir($this->path('config'));
        mkdir($this->path('vendor'));
        file_put_contents($this->path('config/container.php'), data: "<?php\n\n{$container}\n");
        file_put_contents(
            $this->path('vendor/autoload.php'),
            data: '<?php return require '
                . var_export(dirname(__DIR__, levels: 2) . '/vendor/autoload.php', return: true)
                . ";\n",
        );
    }

    /**
     * @return array{int, string, string}
     */
    private function runScript(string ...$arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, levels: 2) . '/bin/diagnostics', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tmpDir,
        );
        static::assertTrue(is_resource($process));

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
