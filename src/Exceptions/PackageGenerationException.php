<?php

namespace Laraseed\PackageGenerator\Exceptions;

use RuntimeException;

class PackageGenerationException extends RuntimeException
{
    /**
     * @param  list<string>  $files
     */
    public static function collisionDetected(array $files): self
    {
        $fileList = implode(', ', $files);

        return new self("Package generation aborted: Existing files detected [{$fileList}]. Use --force to overwrite recipe files.");
    }

    public static function invalidInput(string $reason): self
    {
        return new self("Invalid package specification: {$reason}");
    }

    public static function reservedName(string $name): self
    {
        return new self("Cannot create package: [{$name}] is a reserved Foundation package or namespace name.");
    }

    public static function lockTimeout(string $package, int $seconds): self
    {
        return new self("Package generation aborted: Timeout acquiring exclusive lock for package [{$package}] after {$seconds} seconds. Another generation process may be in progress.");
    }
}
