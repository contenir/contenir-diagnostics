<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Tests\TestAsset\Container;

use Override;
use Psr\Container\ContainerInterface;

use function array_key_exists;

/**
 * Minimal PSR-11 container over a fixed service array.
 */
final readonly class InMemoryContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $services
     */
    public function __construct(
        private array $services = [],
    ) {}

    #[Override]
    public function get(string $id): mixed
    {
        if (! array_key_exists($id, $this->services)) {
            throw new ServiceNotFoundException($id);
        }

        return $this->services[$id];
    }

    #[Override]
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}
