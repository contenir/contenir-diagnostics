<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Check;

use Laminas\Diagnostics\Check\CheckInterface;

/**
 * Adds site-specific checks to the diagnostics command. Register provider
 * services under `contenir_diagnostics.check_providers`; their checks run
 * after the built-in ones.
 *
 * @api
 */
interface CheckProviderInterface
{
    /**
     * The checks to run, keyed by the label shown in the output.
     *
     * @return iterable<string, CheckInterface>
     */
    public function getChecks(): iterable;
}
