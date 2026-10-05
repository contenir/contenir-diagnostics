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
