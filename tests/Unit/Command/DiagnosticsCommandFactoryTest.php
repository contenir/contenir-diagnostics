<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Tests\Unit\Command;

use Contenir\Diagnostics\Command\DiagnosticsCommand;
use Contenir\Diagnostics\Command\DiagnosticsCommandFactory;
use Contenir\Diagnostics\Tests\TestAsset\Container\InMemoryContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class DiagnosticsCommandFactoryTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function containers(): array
    {
        return [
            'config service'         => [['config' => ['db' => []]]],
            'no config service'      => [[]],
            'config is not an array' => [['config' => 'nope']],
        ];
    }

    /**
     * @param array<string, mixed> $services
     */
    #[Test]
    #[DataProvider('containers')]
    public function buildsTheCommand(array $services): void
    {
        static::assertInstanceOf(
            DiagnosticsCommand::class,
            (new DiagnosticsCommandFactory())(new InMemoryContainer($services)),
        );
    }
}
