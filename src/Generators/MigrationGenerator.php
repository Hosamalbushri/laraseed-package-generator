<?php

namespace Laraseed\PackageGenerator\Generators;

use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Support\PackageResolver;
use Laraseed\PackageGenerator\Support\ResolvedPackage;

class MigrationGenerator
{
    protected string $basePath;

    public function __construct(
        protected PackageResolver $resolver = new PackageResolver,
        protected StubRenderer $renderer = new StubRenderer,
        protected FilesystemWriter $writer = new FilesystemWriter,
        ?string $basePath = null
    ) {
        $this->basePath = $basePath ?? base_path();
        $this->resolver = new PackageResolver(basePath: $this->basePath);
        $this->writer = new FilesystemWriter(basePath: $this->basePath);
    }

    /**
     * Generate a database migration file for the specified package.
     *
     * @return array{
     *     package: ResolvedPackage,
     *     migration: string,
     *     filename: string,
     *     dry_run: bool,
     *     force: bool,
     *     files: array<int, array{path: string, full_path: string, action: string, bytes: int}>
     * }
     */
    public function generate(
        string $packageInput,
        string $migrationName,
        bool $dryRun = false,
        bool $force = false,
        ?string $timestamp = null
    ): array {
        $resolved = $this->resolver->resolve($packageInput);
        $this->validateMigrationName($migrationName);

        $ts = $timestamp ?? date('Y_m_d_His');
        $filename = "{$ts}_{$migrationName}.php";

        if (str_starts_with($migrationName, 'create_') && str_ends_with($migrationName, '_table')) {
            $tableName = substr($migrationName, 7, -6);
            $content = $this->renderer->render('migration_create.php.stub', $resolved->identity);
            $content = str_replace('{{ TABLE_NAME }}', $tableName, $content);
        } else {
            $content = $this->renderer->render('migration_general.php.stub', $resolved->identity);
        }

        $relativePath = "src/Database/Migrations/{$filename}";
        $files = [$relativePath => $content];

        $plan = new GenerationPlan($resolved->identity->relativePackagePath, $this->basePath, $files);
        $plan->preflight($force);

        $results = $this->writer->execute($plan, $dryRun, $force);

        return [
            'package' => $resolved,
            'migration' => $migrationName,
            'filename' => $filename,
            'dry_run' => $dryRun,
            'force' => $force,
            'files' => $results,
        ];
    }

    protected function validateMigrationName(string $name): void
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw PackageGenerationException::invalidInput('Migration name cannot be empty.');
        }

        if (str_contains($trimmed, '..') || str_contains($trimmed, '/') || str_contains($trimmed, '\\') || str_contains($trimmed, "\0")) {
            throw PackageGenerationException::invalidInput('Migration name contains path traversal or invalid path characters.');
        }

        if (preg_match('/^[a-z0-9_]+$/', $trimmed) !== 1) {
            throw PackageGenerationException::invalidInput("Migration name [{$trimmed}] must be lowercase snake_case (e.g. create_posts_table).");
        }
    }
}
