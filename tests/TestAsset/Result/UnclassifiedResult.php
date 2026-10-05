<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Tests\TestAsset\Result;

use Laminas\Diagnostics\Result\AbstractResult;

/**
 * A result that is neither success, warning, failure nor skip.
 */
final class UnclassifiedResult extends AbstractResult {}
