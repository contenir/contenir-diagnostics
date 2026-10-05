<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Tests\Integration\Check;

use Contenir\Diagnostics\Check\SecurityAdvisoryCheck;
use Contenir\Diagnostics\Tests\Trait\TemporaryDirectoryTrait;
use Laminas\Diagnostics\Check\SecurityAdvisory;
use Laminas\Diagnostics\Result\Skip;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chdir;
use function file_put_contents;
use function getcwd;

/**
 * Builds the check in a temporary working directory. The check is never run,
 * so the advisories service is not contacted.
 */
#[Group('integration')]
final class SecurityAdvisoryCheckTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $originalCwd;

    #[Test]
    public function checksTheComposerLockOfTheWorkingDirectory(): void
    {
        file_put_contents('composer.lock', data: '{"packages": []}');

        static::assertInstanceOf(SecurityAdvisory::class, SecurityAdvisoryCheck::create());
    }

    #[Test]
    public function skipsWithTheReasonWithoutAComposerLock(): void
    {
        $result = SecurityAdvisoryCheck::create()->check();

        static::assertInstanceOf(Skip::class, $result);
        static::assertStringContainsString('there is no "composer.lock" file', (string) $result->getMessage());
    }

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->originalCwd = (string) getcwd();
        chdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->tearDownTemporaryDirectory();
    }
}
