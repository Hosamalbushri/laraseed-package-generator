<?php

namespace Laraseed\PackageGenerator\Generators;

use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Support\PathGuard;

class GenerationPlan
{
    /**
     * @param  array<string, string>  $files  Relative file path => rendered content
     */
    public function __construct(
        public readonly string $relativePackagePath,
        public readonly string $basePath,
        public readonly array $files,
    ) {}

    /**
     * Get target package root directory path.
     */
    public function targetDirectory(): string
    {
        return rtrim($this->basePath, '/') . '/' . ltrim($this->relativePackagePath, '/');
    }

    /**
     * Perform preflight collision and path containment checks for all planned files.
     *
     * @throws PackageGenerationException
     */
    public function preflight(bool $force): void
    {
        $targetDir = $this->targetDirectory();
        PathGuard::assertWithinAuthorizedPackages($targetDir, $this->basePath, 'Target package');

        $collisions = [];

        foreach ($this->files as $relativePath => $content) {
            $fullPath = "{$targetDir}/{$relativePath}";
            PathGuard::assertWithinAuthorizedPackages($fullPath, $this->basePath, 'File destination');

            if (file_exists($fullPath)) {
                if (! $force) {
                    $collisions[] = "{$this->relativePackagePath}/{$relativePath}";
                } elseif (is_dir($fullPath)) {
                    throw PackageGenerationException::invalidInput("Target path [{$relativePath}] is an existing directory, cannot overwrite with file.");
                }
            }
        }

        if ($collisions !== []) {
            throw PackageGenerationException::collisionDetected($collisions);
        }
    }
}
