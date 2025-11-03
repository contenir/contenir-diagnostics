<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Command;

use Psr\Container\ContainerInterface;

class DiagnosticsCommandFactory
{
    public function __invoke(ContainerInterface $container): DiagnosticsCommand
    {
        $config = $container->get('config');

        return new DiagnosticsCommand($config);
    }
}
