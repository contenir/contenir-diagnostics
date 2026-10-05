<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Tests\TestAsset\Check;

use Contenir\Diagnostics\Check\CheckProviderInterface;
use Laminas\Diagnostics\Check\CheckInterface;
use Override;

/**
 * Provides a fixed set of checks.
 */
final readonly class FixedCheckProvider implements CheckProviderInterface
{
    /**
     * @param array<string, CheckInterface> $checks Checks keyed by label.
     */
    public function __construct(
        private array $checks = [],
    ) {}

    #[Override]
    public function getChecks(): iterable
    {
        return $this->checks;
    }
}
