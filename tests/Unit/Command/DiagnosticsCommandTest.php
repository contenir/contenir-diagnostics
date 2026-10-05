<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Tests\Unit\Command;

use Contenir\Diagnostics\Command\DiagnosticsCommand;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The command definition; running it is covered by the integration suite.
 */
#[Group('unit')]
final class DiagnosticsCommandTest extends TestCase
{
    #[Test]
    public function isNamedDiagnosticsWithARunAlias(): void
    {
        $command = new DiagnosticsCommand([]);

        static::assertSame(['diagnostics', ['diagnostics:run']], [$command->getName(), $command->getAliases()]);
    }

    #[Test]
    public function takesAFixFlagWithAShortcut(): void
    {
        $option = (new DiagnosticsCommand([]))->getDefinition()
            ->getOption('fix');

        static::assertSame(['f', false], [$option->getShortcut(), $option->acceptValue()]);
    }
}
