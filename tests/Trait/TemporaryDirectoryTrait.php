<?php

declare(strict_types=1);

namespace Contenir\Diagnostics\Tests\Trait;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_reverse;
use function chmod;
use function clearstatcache;
use function fileperms;
use function function_exists;
use function is_dir;
use function mkdir;
use function posix_geteuid;
use function rmdir;
use function sys_get_temp_dir;
use function umask;
use function uniqid;
use function unlink;

/**
 * A private scratch directory per test, removed afterwards even when a test
 * left read-only directories or files, or symlinks out of it, behind. Symlinks
 * are removed, never followed.
 *
 * The umask is fixed at 022 for the test, so the permissions of the
 * directories it creates are predictable, and restored afterwards.
 */
trait TemporaryDirectoryTrait
{
    private string $tmpDir;

    private int $previousUmask;

    protected function setUpTemporaryDirectory(): void
    {
        $this->previousUmask = umask(0o022);
        $this->tmpDir        = sys_get_temp_dir() . '/contenir-diagnostics-' . uniqid(more_entropy: true);
        mkdir($this->tmpDir, permissions: 0o777, recursive: true);
    }

    protected function tearDownTemporaryDirectory(): void
    {
        umask($this->previousUmask);

        if (! is_dir($this->tmpDir)) {
            return;
        }

        chmod($this->tmpDir, permissions: 0o755);
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tmpDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var list<SplFileInfo> $found */
        $found = [];
        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            if (! $item->isLink()) {
                chmod($item->getPathname(), permissions: $item->isDir() ? 0o755 : 0o644);
            }

            $found[] = $item;
        }

        foreach (array_reverse($found) as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->tmpDir);
    }

    private function path(string $name): string
    {
        return "{$this->tmpDir}/{$name}";
    }

    /**
     * The permission bits of a file or directory in the scratch directory.
     */
    private function permissionsOf(string $name): int
    {
        clearstatcache();

        return fileperms($this->path($name)) & 0o777;
    }

    private function skipWhenRunningAsRoot(): void
    {
        if (function_exists('posix_geteuid') && 0 === posix_geteuid()) {
            self::markTestSkipped('Running as root bypasses filesystem permission checks.');
        }
    }
}
