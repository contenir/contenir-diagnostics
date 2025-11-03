<?php

declare(strict_types=1);

namespace Contenir\Diagnostics;

use Contenir\Diagnostics\Command\DiagnosticsCommand;
use Contenir\Diagnostics\Command\DiagnosticsCommandFactory;

/**
 * Configuration provider for Contenir Diagnostics module
 */
class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
        ];
    }

    public function getDependencies(): array
    {
        return [
            'factories' => [
                // Commands
                DiagnosticsCommand::class => DiagnosticsCommandFactory::class,
            ],
        ];
    }
}