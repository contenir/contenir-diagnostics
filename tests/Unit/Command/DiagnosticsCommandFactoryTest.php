<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Tests\Unit\Command;

use Contenir\Diagnostics\Check\CheckProviderInterface;
use Contenir\Diagnostics\Command\DiagnosticsCommand;
use Contenir\Diagnostics\Command\DiagnosticsCommandFactory;
use Contenir\Diagnostics\Tests\TestAsset\Container\InMemoryContainer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

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
            'providers not a list'   => [['config' => ['contenir_diagnostics' => ['check_providers' => 'x']]]],
            'options not an array'   => [['config' => ['contenir_diagnostics' => true]]],
        ];
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidProviders(): array
    {
        return [
            'service of the wrong type' => ['SiteChecks', 'Check provider "SiteChecks" must implement '],
            'non-string entry'          => [42, 'Check provider "int" must implement '],
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

    #[Test]
    #[DataProvider('invalidProviders')]
    public function rejectsAnInvalidCheckProvider(mixed $name, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message . CheckProviderInterface::class);

        (new DiagnosticsCommandFactory())(new InMemoryContainer([
            'config'     => ['contenir_diagnostics' => ['check_providers' => [$name]]],
            'SiteChecks' => new stdClass(),
        ]));
    }
}
