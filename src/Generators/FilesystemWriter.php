<?php

namespace Laraseed\PackageGenerator\Generators;

use Illuminate\Filesystem\Filesystem;
use Laraseed\PackageGenerator\Support\PackageLock;
use Laraseed\PackageGenerator\Support\PathGuard;

class FilesystemWriter
{
    protected string $basePath;

    public function __construct(
        protected Filesystem $filesystem = new Filesystem,
        protected ?PackageLock $lock = null,
        ?string $basePath = null
    ) {
        $this->basePath = $basePath ?? base_path();
        $this->lock = $lock ?? new PackageLock(basePath: $this->basePath);
    }

    /**
     * Write planned files to disk transactionally or simulate dry-run execution.
     *
     * @return array<int, array{path: string, full_path: string, action: string, bytes: int}>
     */
    public function execute(GenerationPlan $plan, bool $dryRun = false, bool $force = false): array
    {
        return $this->lock->withLock($plan->relativePackagePath, function () use ($plan, $dryRun, $force) {
            if ($dryRun) {
                return $this->simulate($plan, $force);
            }

            $transaction = $this->transaction($plan->basePath);

            return $transaction->run(function (FilesystemTransaction $tx) use ($plan, $force) {
                return $tx->executePlan($plan, $force);
            });
        });
    }

    /**
     * Simulate generation without modifying the filesystem.
     *
     * @return array<int, array{path: string, full_path: string, action: string, bytes: int}>
     */
    public function simulate(GenerationPlan $plan, bool $force = false): array
    {
        $targetDir = $plan->targetDirectory();
        PathGuard::assertWithinAuthorizedPackages($targetDir, $plan->basePath, 'Target package');

        $results = [];

        foreach ($plan->files as $relativePath => $content) {
            $fullPath = "{$targetDir}/{$relativePath}";
            PathGuard::assertWithinAuthorizedPackages($fullPath, $plan->basePath, 'File destination');

            $exists = $this->filesystem->exists($fullPath);
            $action = $exists ? 'OVERWRITE' : 'CREATE';

            $results[] = [
                'path' => "{$plan->relativePackagePath}/{$relativePath}",
                'full_path' => $fullPath,
                'action' => $action,
                'bytes' => strlen($content),
            ];
        }

        return $results;
    }

    /**
     * Create a new isolated filesystem transaction.
     */
    public function transaction(?string $basePath = null): FilesystemTransaction
    {
        return new FilesystemTransaction($this->filesystem, $basePath ?? $this->basePath);
    }
}
