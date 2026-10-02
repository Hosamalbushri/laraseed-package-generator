<?php

namespace Laraseed\PackageGenerator\Generators;

use Illuminate\Filesystem\Filesystem;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Support\PathGuard;
use Throwable;

class FilesystemTransaction
{
    protected string $basePath;

    /**
     * Paths of files newly created during this transaction.
     *
     * @var list<string>
     */
    protected array $createdFiles = [];

    /**
     * Map of overwritten files and their original content.
     *
     * @var array<string, string>
     */
    protected array $overwrittenBackups = [];

    /**
     * Paths of directories created during this transaction (ordered shallowest to deepest).
     *
     * @var list<string>
     */
    protected array $createdDirectories = [];

    protected bool $committed = false;

    protected bool $rolledBack = false;

    public function __construct(
        protected Filesystem $filesystem = new Filesystem,
        ?string $basePath = null
    ) {
        $this->basePath = $basePath ?? base_path();
    }

    /**
     * Execute a generation plan within this transaction.
     *
     * @return array<int, array{path: string, full_path: string, action: string, bytes: int}>
     *
     * @throws PackageGenerationException
     */
    public function executePlan(GenerationPlan $plan, bool $force = false): array
    {
        $targetDir = $plan->targetDirectory();
        PathGuard::assertWithinAuthorizedPackages($targetDir, $plan->basePath, 'Target package');

        $results = [];

        foreach ($plan->files as $relativePath => $content) {
            $fullPath = "{$targetDir}/{$relativePath}";
            $displayPath = "{$plan->relativePackagePath}/{$relativePath}";

            $results[] = $this->writeFile($fullPath, $content, $displayPath, $force);
        }

        return $results;
    }

    /**
     * Write a single file to disk within this transaction.
     *
     * @return array{path: string, full_path: string, action: string, bytes: int}
     *
     * @throws PackageGenerationException
     */
    public function writeFile(
        string $fullPath,
        string $content,
        ?string $displayPath = null,
        bool $force = true
    ): array {
        PathGuard::assertWithinAuthorizedPackages($fullPath, $this->basePath, 'File destination');

        $exists = $this->filesystem->exists($fullPath);

        if ($exists) {
            if (! $force) {
                throw PackageGenerationException::collisionDetected([$displayPath ?? $fullPath]);
            }

            if ($this->filesystem->isDirectory($fullPath)) {
                throw PackageGenerationException::invalidInput("Target path [{$fullPath}] is an existing directory, cannot overwrite with file.");
            }

            // Backup original content if not already backed up
            if (! array_key_exists($fullPath, $this->overwrittenBackups) && ! in_array($fullPath, $this->createdFiles, true)) {
                $this->overwrittenBackups[$fullPath] = (string) $this->filesystem->get($fullPath);
            }

            $action = 'OVERWRITE';
        } else {
            $action = 'CREATE';
            if (! in_array($fullPath, $this->createdFiles, true)) {
                $this->createdFiles[] = $fullPath;
            }
        }

        $directory = dirname($fullPath);
        $this->ensureDirectoryExists($directory);

        $written = $this->filesystem->put($fullPath, $content);
        if ($written === false) {
            throw new \RuntimeException("Failed to write file to [{$fullPath}].");
        }

        return [
            'path' => $displayPath ?? $fullPath,
            'full_path' => $fullPath,
            'action' => $action,
            'bytes' => strlen($content),
        ];
    }

    /**
     * Ensure a directory hierarchy exists, recording any newly created directories.
     */
    public function ensureDirectoryExists(string $directory): void
    {
        PathGuard::assertWithinAuthorizedPackages($directory, $this->basePath, 'Directory');

        if ($this->filesystem->isDirectory($directory)) {
            return;
        }

        if ($this->filesystem->exists($directory) && ! $this->filesystem->isDirectory($directory)) {
            throw PackageGenerationException::invalidInput("Cannot create directory [{$directory}] because a file already exists at that path.");
        }

        $toCreate = [];
        $curr = $directory;

        while (! $this->filesystem->isDirectory($curr)) {
            $toCreate[] = $curr;
            $parent = dirname($curr);
            if ($parent === $curr) {
                break;
            }
            $curr = $parent;
        }

        $toCreate = array_reverse($toCreate);

        foreach ($toCreate as $dir) {
            if (! $this->filesystem->isDirectory($dir)) {
                $created = $this->filesystem->makeDirectory($dir, 0755, true);
                if (! $created && ! $this->filesystem->isDirectory($dir)) {
                    throw new \RuntimeException("Failed to create directory [{$dir}].");
                }
                if (! in_array($dir, $this->createdDirectories, true)) {
                    $this->createdDirectories[] = $dir;
                }
            }
        }
    }

    /**
     * Execute a callback within an isolated filesystem transaction.
     *
     * Automatically rolls back on any error, cleanly propagating exceptions.
     *
     * @template T
     *
     * @param  callable(self): T  $callback
     * @return T
     *
     * @throws PackageGenerationException
     */
    public function run(callable $callback): mixed
    {
        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (Throwable $e) {
            $rollbackError = null;

            try {
                $this->rollback();
            } catch (Throwable $rbe) {
                $rollbackError = $rbe;
            }

            if ($rollbackError !== null) {
                throw PackageGenerationException::invalidInput(
                    "Generation failed: {$e->getMessage()} | Rollback failure: {$rollbackError->getMessage()}"
                );
            }

            if ($e instanceof PackageGenerationException) {
                throw $e;
            }

            throw PackageGenerationException::invalidInput(
                'Generation failed with transactional rollback: ' . $e->getMessage()
            );
        }
    }

    /**
     * Roll back all created files, restore overwritten files, and remove empty created directories.
     */
    public function rollback(): void
    {
        if ($this->rolledBack) {
            return;
        }

        $this->rolledBack = true;
        $rollbackErrors = [];

        // 1. Delete all newly created files
        foreach ($this->createdFiles as $filePath) {
            if ($this->filesystem->exists($filePath) || is_link($filePath)) {
                try {
                    if (is_link($filePath)) {
                        @unlink($filePath);
                    } else {
                        $deleted = @$this->filesystem->delete($filePath);
                        if (! $deleted && ($this->filesystem->exists($filePath) || is_link($filePath))) {
                            $rollbackErrors[] = "Failed to delete created file [{$filePath}]";
                        }
                    }
                } catch (Throwable $e) {
                    $rollbackErrors[] = "Error deleting created file [{$filePath}]: {$e->getMessage()}";
                }
            }
        }

        // 2. Restore overwritten files from backup
        foreach ($this->overwrittenBackups as $filePath => $originalContent) {
            try {
                $restored = @$this->filesystem->put($filePath, $originalContent);
                if ($restored === false) {
                    $rollbackErrors[] = "Failed to restore overwritten file [{$filePath}]";
                }
            } catch (Throwable $e) {
                $rollbackErrors[] = "Error restoring overwritten file [{$filePath}]: {$e->getMessage()}";
            }
        }

        // 3. Remove only newly created directories (deepest first)
        $dirs = array_reverse($this->createdDirectories);
        foreach ($dirs as $dir) {
            if ($this->filesystem->isDirectory($dir) && ! is_link($dir)) {
                $items = @scandir($dir);
                if ($items !== false) {
                    $remaining = array_diff($items, ['.', '..']);
                    if (empty($remaining)) {
                        @rmdir($dir);
                    }
                }
            } elseif (is_link($dir)) {
                @unlink($dir);
            }
        }

        if ($rollbackErrors !== []) {
            throw new \RuntimeException('Rollback completed with errors: ' . implode('; ', $rollbackErrors));
        }
    }

    /**
     * Commit the transaction, clearing rollback records.
     */
    public function commit(): void
    {
        $this->committed = true;
        $this->createdFiles = [];
        $this->overwrittenBackups = [];
        $this->createdDirectories = [];
    }

    public function getCreatedFiles(): array
    {
        return $this->createdFiles;
    }

    public function getOverwrittenBackups(): array
    {
        return $this->overwrittenBackups;
    }

    public function getCreatedDirectories(): array
    {
        return $this->createdDirectories;
    }

    public function isCommitted(): bool
    {
        return $this->committed;
    }

    public function isRolledBack(): bool
    {
        return $this->rolledBack;
    }
}
