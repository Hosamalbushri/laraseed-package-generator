<?php

namespace Laraseed\PackageGenerator\Support;

use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;

class PathGuard
{
    /**
     * Get the canonical path to the authorized packages directory.
     *
     * @throws PackageGenerationException
     */
    public static function getAuthorizedPackagesRoot(string $basePath): string
    {
        $packagesDir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'packages';

        if (file_exists($packagesDir) || is_link($packagesDir)) {
            $canonical = realpath($packagesDir);
            if ($canonical === false) {
                throw PackageGenerationException::invalidInput("Authorized packages directory [{$packagesDir}] cannot be resolved.");
            }

            return $canonical;
        }

        $canonicalBase = realpath($basePath);
        if ($canonicalBase === false) {
            $canonicalBase = rtrim($basePath, '/\\');
        }

        return rtrim($canonicalBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'packages';
    }

    /**
     * Canonicalize a destination path even if some or all components do not exist yet.
     *
     * Inspects existing parent directories and resolves any symbolic links along the path.
     *
     * @throws PackageGenerationException
     */
    public static function canonicalizeDestination(string $path, ?string $basePath = null): string
    {
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        // If path is relative, prefix with basePath or base_path()
        if (! static::isAbsolutePath($normalizedPath)) {
            $root = $basePath ?? base_path();
            $normalizedPath = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . ltrim($normalizedPath, DIRECTORY_SEPARATOR);
        }

        // Split into existing ancestor and non-existing trailing segments
        $probe = $normalizedPath;
        $tail = [];

        while (! file_exists($probe) && ! is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                // Reached root of filesystem
                break;
            }
            $tail[] = basename($probe);
            $probe = $parent;
        }

        if (is_link($probe)) {
            $realProbe = realpath($probe);
            if ($realProbe === false) {
                throw PackageGenerationException::invalidInput("Path [{$path}] contains a broken or dangling symbolic link at [{$probe}].");
            }
        } elseif (file_exists($probe)) {
            $realProbe = realpath($probe);
            if ($realProbe === false) {
                throw PackageGenerationException::invalidInput("Path [{$path}] cannot be canonicalized at [{$probe}].");
            }
        } else {
            $realProbe = $probe;
        }

        $canonical = $realProbe;

        while (! empty($tail)) {
            $segment = array_pop($tail);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                $canonical = dirname($canonical);
                continue;
            }
            $canonical = rtrim($canonical, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $segment;
        }

        return $canonical;
    }

    /**
     * Check if a path resolves strictly within the authorized packages directory.
     */
    public static function isWithinAuthorizedPackages(string $path, string $basePath): bool
    {
        try {
            static::assertWithinAuthorizedPackages($path, $basePath);

            return true;
        } catch (PackageGenerationException) {
            return false;
        }
    }

    /**
     * Assert that a destination path resolves strictly within the authorized packages directory.
     *
     * @throws PackageGenerationException
     */
    public static function assertWithinAuthorizedPackages(
        string $path,
        string $basePath,
        ?string $label = null
    ): string {
        $canonicalRoot = static::getAuthorizedPackagesRoot($basePath);
        $canonicalPath = static::canonicalizeDestination($path, $basePath);

        $boundaryPrefix = rtrim($canonicalRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (! str_starts_with($canonicalPath, $boundaryPrefix)) {
            $desc = $label ? "{$label} path" : 'Path';
            throw PackageGenerationException::invalidInput(
                "{$desc} [{$path}] resolves to [{$canonicalPath}] outside the authorized packages directory [{$canonicalRoot}]."
            );
        }

        return $canonicalPath;
    }

    /**
     * Determine whether the given path is an absolute path.
     */
    public static function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        // Windows drive letters (e.g. C:\ or D:/)
        return (bool) preg_match('/^[a-zA-Z]:[\\\\\/]/', $path);
    }
}
