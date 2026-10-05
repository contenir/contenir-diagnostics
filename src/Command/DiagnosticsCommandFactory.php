<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Command;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

use function is_array;

/**
 * Builds the DiagnosticsCommand with the application config.
 *
 * @api
 */
final class DiagnosticsCommandFactory
{
    /**
     * @throws ContainerExceptionInterface
     *
     * @mago-expect analysis:mixed-assignment The config service is untyped; its type is checked here.
     */
    public function __invoke(ContainerInterface $container): DiagnosticsCommand
    {
        $config = $container->has('config') ? $container->get('config') : [];

        return new DiagnosticsCommand(is_array($config) ? $config : []);
    }
}
