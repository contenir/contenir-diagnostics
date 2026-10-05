<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Command;

use Contenir\Diagnostics\Check\CheckProviderInterface;
use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

use function get_debug_type;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Builds the DiagnosticsCommand with the application config and the check
 * providers listed under `contenir_diagnostics.check_providers`.
 *
 * @api
 */
final class DiagnosticsCommandFactory
{
    /**
     * @param array<array-key, mixed> $config
     *
     * @return list<CheckProviderInterface>
     *
     * @throws ContainerExceptionInterface
     * @throws InvalidArgumentException When a check provider service is not a CheckProviderInterface.
     *
     * @mago-expect analysis:mixed-assignment Config values and services are untyped; their types are checked here.
     */
    private static function checkProviders(ContainerInterface $container, array $config): array
    {
        $options = $config['contenir_diagnostics'] ?? null;
        $names   = is_array($options) ? $options['check_providers'] ?? null : null;

        $providers = [];
        foreach (is_array($names) ? $names : [] as $name) {
            $provider = is_string($name) ? $container->get($name) : null;
            if (! $provider instanceof CheckProviderInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Check provider "%s" must implement %s',
                    is_string($name) ? $name : get_debug_type($name),
                    CheckProviderInterface::class,
                ));
            }

            $providers[] = $provider;
        }

        return $providers;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws InvalidArgumentException When a check provider service is not a CheckProviderInterface.
     *
     * @mago-expect analysis:mixed-assignment The config service is untyped; its type is checked here.
     */
    public function __invoke(ContainerInterface $container): DiagnosticsCommand
    {
        $config = $container->has('config') ? $container->get('config') : [];
        $config = is_array($config) ? $config : [];

        return new DiagnosticsCommand($config, self::checkProviders($container, $config));
    }
}
