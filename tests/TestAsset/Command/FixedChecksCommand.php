<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Tests\TestAsset\Command;

use Contenir\Diagnostics\Command\DiagnosticsCommand;
use Laminas\Diagnostics\Check\CheckInterface;
use Laminas\Diagnostics\Runner\Runner;
use Override;

/**
 * A DiagnosticsCommand whose PHP environment checks are replaced by fixed
 * ones, so results do not depend on the extensions of the machine running
 * the tests. Also exposes the security check it would add.
 */
final class FixedChecksCommand extends DiagnosticsCommand
{
    /**
     * @param array<array-key, mixed> $config
     * @param array<string, CheckInterface> $environmentChecks Checks keyed by alias.
     */
    public function __construct(
        array $config,
        private readonly array $environmentChecks,
    ) {
        parent::__construct($config);
    }

    /**
     * The checks addSecurityChecks() adds, keyed by alias.
     *
     * @return array<array-key, mixed>
     */
    public function securityChecks(): array
    {
        $runner = new Runner();
        $this->addSecurityChecks($runner);

        return $runner->getChecks()->getArrayCopy();
    }

    #[Override]
    protected function addPhpEnvironmentChecks(Runner $runner): void
    {
        foreach ($this->environmentChecks as $alias => $check) {
            $runner->addCheck($check, $alias);
        }
    }
}
