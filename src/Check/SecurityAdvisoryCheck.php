<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Check;

use InvalidArgumentException;
use Laminas\Diagnostics\Check\Callback;
use Laminas\Diagnostics\Check\CheckInterface;
use Laminas\Diagnostics\Check\SecurityAdvisory;
use Laminas\Diagnostics\Result\Skip;

/**
 * Builds the security advisories check for the working directory's
 * composer.lock, or a check that skips with the reason when
 * enlightn/security-checker is not installed or there is no composer.lock.
 *
 * @internal
 */
final class SecurityAdvisoryCheck
{
    public static function create(): CheckInterface
    {
        try {
            return new SecurityAdvisory();
        } catch (InvalidArgumentException $e) {
            $reason = $e->getMessage();

            return new Callback(static fn(): Skip => new Skip($reason));
        }
    }
}
