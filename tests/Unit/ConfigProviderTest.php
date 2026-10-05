<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Tests\Unit;

use Contenir\Diagnostics\Command\DiagnosticsCommand;
use Contenir\Diagnostics\Command\DiagnosticsCommandFactory;
use Contenir\Diagnostics\ConfigProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ConfigProviderTest extends TestCase
{
    #[Test]
    public function providesTheDependencies(): void
    {
        static::assertSame(
            ['factories' => [DiagnosticsCommand::class => DiagnosticsCommandFactory::class]],
            (new ConfigProvider())->getDependencies(),
        );
    }

    #[Test]
    public function providesTheLaminasCliCommands(): void
    {
        static::assertSame(
            ['commands' => ['diagnostics' => DiagnosticsCommand::class]],
            (new ConfigProvider())->getCliConfig(),
        );
    }

    #[Test]
    public function registersTheCommandServiceAndItsLaminasCliName(): void
    {
        static::assertSame(
            [
                'dependencies' => ['factories' => [DiagnosticsCommand::class => DiagnosticsCommandFactory::class]],
                'laminas-cli'  => ['commands' => ['diagnostics' => DiagnosticsCommand::class]],
            ],
            (new ConfigProvider())(),
        );
    }
}
