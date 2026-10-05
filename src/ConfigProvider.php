<?php

declare(strict_types=1);

namespace Contenir\Diagnostics;

use Contenir\Diagnostics\Command\DiagnosticsCommand;
use Contenir\Diagnostics\Command\DiagnosticsCommandFactory;

/**
 * Configuration provider for the Contenir Diagnostics module: the command
 * service and its laminas-cli registration.
 *
 * @api
 */
final class ConfigProvider
{
    /**
     * @return array{commands: array<string, class-string>}
     */
    public function getCliConfig(): array
    {
        return [
            'commands' => [
                'diagnostics' => DiagnosticsCommand::class,
            ],
        ];
    }

    /**
     * @return array{factories: array<class-string, class-string>}
     */
    public function getDependencies(): array
    {
        return [
            'factories' => [
                DiagnosticsCommand::class => DiagnosticsCommandFactory::class,
            ],
        ];
    }

    /**
     * @return array{
     *     dependencies: array{factories: array<class-string, class-string>},
     *     laminas-cli: array{commands: array<string, class-string>}
     * }
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
            'laminas-cli'  => $this->getCliConfig(),
        ];
    }
}
