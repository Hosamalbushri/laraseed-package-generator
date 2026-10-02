<?php

namespace Laraseed\PackageGenerator\Support;

use Fiber;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Throwable;

class PackageLock
{
    /**
     * Default lock timeout in seconds.
     */
    public const DEFAULT_TIMEOUT = 5;

    /**
     * Directory where lock files are stored.
     */
    protected string $locksDirectory;

    /**
     * Application base path used for canonical path resolution.
     */
    protected string $basePath;

    /**
     * In-process lock ownership registry.
     *
     * Maps lock file path => array{handle: mixed, depth: int, owner: string}
     *
     * @var array<string, array{handle: mixed, depth: int, owner: string}>
     */
    protected static array $heldLocks = [];

    public function __construct(?string $locksDirectory = null, ?string $basePath = null)
    {
        $this->basePath = $basePath ?? (function_exists('base_path') ? base_path() : getcwd());

        if ($locksDirectory !== null) {
            $this->locksDirectory = $locksDirectory;
        } else {
            $storageFrameworkLocks = null;
            if (function_exists('storage_path')) {
                try {
                    $storageFramework = storage_path('framework');
                    if (is_dir($storageFramework)) {
                        $storageFrameworkLocks = storage_path('framework/locks');
                    }
                } catch (Throwable) {
                    // Application container not fully initialized or storagePath not available
                }
            }

            $this->locksDirectory = $storageFrameworkLocks ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laraseed_locks');
        }
    }

    /**
     * Acquire an exclusive cross-process lock on a package, execute the callback, and reliably release the lock.
     *
     * Supports safe reentrant acquisition within the same execution context.
     *
     * @template T
     *
     * @param  string  $packageIdentifier Unique package key, relative path, or absolute path
     * @param  callable(): T  $callback
     * @param  int  $timeoutSeconds Maximum seconds to wait for lock acquisition
     * @return T
     *
     * @throws PackageGenerationException
     */
    public function withLock(string $packageIdentifier, callable $callback, int $timeoutSeconds = self::DEFAULT_TIMEOUT): mixed
    {
        $canonicalPath = $this->canonicalizePackage($packageIdentifier);
        $lockFilePath = $this->getLockFilePath($canonicalPath);
        $contextId = static::getCurrentContextId();

        // 1. Safe reentrancy: check if current execution context already holds this lock
        if (isset(static::$heldLocks[$lockFilePath])) {
            $lockInfo = static::$heldLocks[$lockFilePath];
            if ($lockInfo['owner'] === $contextId) {
                static::$heldLocks[$lockFilePath]['depth']++;
                try {
                    return $callback();
                } finally {
                    static::$heldLocks[$lockFilePath]['depth']--;
                    if (static::$heldLocks[$lockFilePath]['depth'] <= 0) {
                        $fp = static::$heldLocks[$lockFilePath]['handle'];
                        unset(static::$heldLocks[$lockFilePath]);
                        @flock($fp, LOCK_UN);
                        @fclose($fp);
                    }
                }
            }
        }

        // 2. Outermost lock acquisition
        $dir = dirname($lockFilePath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = @fopen($lockFilePath, 'c+');
        if (! $fp) {
            throw PackageGenerationException::invalidInput("Unable to create or open lock file at [{$lockFilePath}].");
        }

        $acquired = false;
        $startTime = microtime(true);
        $timeoutMicro = $timeoutSeconds * 1000000;

        while (! $acquired) {
            $heldByOtherInProcess = isset(static::$heldLocks[$lockFilePath]) && static::$heldLocks[$lockFilePath]['owner'] !== $contextId;

            if (! $heldByOtherInProcess && @flock($fp, LOCK_EX | LOCK_NB)) {
                $acquired = true;
                break;
            }

            $elapsedMicro = (microtime(true) - $startTime) * 1000000;
            if ($elapsedMicro >= $timeoutMicro) {
                break;
            }

            usleep(25000); // 25ms
        }

        if (! $acquired) {
            @fclose($fp);
            throw PackageGenerationException::lockTimeout($packageIdentifier, $timeoutSeconds);
        }

        static::$heldLocks[$lockFilePath] = [
            'handle' => $fp,
            'depth' => 1,
            'owner' => $contextId,
        ];

        try {
            return $callback();
        } finally {
            if (isset(static::$heldLocks[$lockFilePath])) {
                static::$heldLocks[$lockFilePath]['depth']--;
                if (static::$heldLocks[$lockFilePath]['depth'] <= 0) {
                    $handle = static::$heldLocks[$lockFilePath]['handle'] ?? $fp;
                    unset(static::$heldLocks[$lockFilePath]);
                    @flock($handle, LOCK_UN);
                    @fclose($handle);
                }
            } else {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
        }
    }

    /**
     * Canonicalize a package identifier into an absolute path strictly within authorized packages.
     *
     * @throws PackageGenerationException
     */
    public function canonicalizePackage(string $packageIdentifier): string
    {
        $trimmed = trim($packageIdentifier);
        if ($trimmed === '') {
            throw PackageGenerationException::invalidInput('Package identifier cannot be empty for locking.');
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);

        if (PathGuard::isAbsolutePath($normalized)) {
            return PathGuard::assertWithinAuthorizedPackages($normalized, $this->basePath, 'Package');
        }

        $relativeTrimmed = trim($normalized, DIRECTORY_SEPARATOR);

        if (str_starts_with(strtolower($relativeTrimmed), 'packages' . DIRECTORY_SEPARATOR) || strtolower($relativeTrimmed) === 'packages') {
            $candidate = $relativeTrimmed;
        } else {
            $candidate = 'packages' . DIRECTORY_SEPARATOR . $relativeTrimmed;
        }

        return PathGuard::assertWithinAuthorizedPackages($candidate, $this->basePath, 'Package');
    }

    /**
     * Compute a deterministic, filesystem-safe lock file path for the package.
     *
     * @throws PackageGenerationException
     */
    public function getLockFilePath(string $packageIdentifier): string
    {
        $canonicalPath = $this->canonicalizePackage($packageIdentifier);
        $hash = md5(strtolower($canonicalPath));

        return rtrim($this->locksDirectory, '/\\') . DIRECTORY_SEPARATOR . "laraseed_pkg_{$hash}.lock";
    }

    /**
     * Get the current execution context identifier.
     */
    protected static function getCurrentContextId(): string
    {
        $fiberId = 'main';
        if (class_exists(Fiber::class)) {
            $currentFiber = Fiber::getCurrent();
            if ($currentFiber !== null) {
                $fiberId = 'fiber_' . spl_object_id($currentFiber);
            }
        }

        return getmypid() . ':' . $fiberId;
    }

    /**
     * Reset the in-process held locks registry (primarily for test cleanup).
     */
    public static function resetHeldLocks(): void
    {
        foreach (static::$heldLocks as $lockInfo) {
            if (isset($lockInfo['handle']) && is_resource($lockInfo['handle'])) {
                @flock($lockInfo['handle'], LOCK_UN);
                @fclose($lockInfo['handle']);
            }
        }
        static::$heldLocks = [];
    }

    /**
     * Get the locks directory.
     */
    public function getLocksDirectory(): string
    {
        return $this->locksDirectory;
    }

    /**
     * Get the base path.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }
}
