<?php

namespace Laraseed\PackageGenerator\Generators;

use Illuminate\Filesystem\Filesystem;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Support\PackageResolver;
use Laraseed\PackageGenerator\Support\ResolvedPackage;

class RepositoryGenerator
{
    protected string $basePath;

    public function __construct(
        protected PackageResolver $resolver = new PackageResolver,
        protected StubRenderer $renderer = new StubRenderer,
        protected FilesystemWriter $writer = new FilesystemWriter,
        protected Filesystem $filesystem = new Filesystem,
        ?string $basePath = null
    ) {
        $this->basePath = $basePath ?? base_path();
        $this->resolver = new PackageResolver(basePath: $this->basePath);
        $this->writer = new FilesystemWriter(basePath: $this->basePath);
    }

    /**
     * Generate a Repository class for the specified package.
     *
     * @return array{
     *     package: ResolvedPackage,
     *     repository: string,
     *     model: ?string,
     *     dry_run: bool,
     *     force: bool,
     *     files: array<int, array{path: string, full_path: string, action: string, bytes: int}>
     * }
     */
    public function generate(
        string $packageInput,
        string $repositoryName,
        ?string $modelName = null,
        bool $dryRun = false,
        bool $force = false
    ): array {
        $resolved = $this->resolver->resolve($packageInput);
        $this->validateRepositoryName($repositoryName);

        $modelFqn = '';

        if ($modelName !== null && trim($modelName) !== '') {
            $trimmedModel = trim($modelName);
            $this->validateModelName($trimmedModel);

            $modelPath = "{$resolved->packagePath}/src/Models/{$trimmedModel}.php";

            if (! $this->filesystem->exists($modelPath)) {
                throw PackageGenerationException::invalidInput("Model [{$trimmedModel}] not found in package [{$packageInput}] at [src/Models/{$trimmedModel}.php]. Generate the model first using laraseed:make-model.");
            }

            $modelFqn = "{$resolved->namespace}\\Models\\{$trimmedModel}";
        }

        $content = $this->renderer->render('repository.php.stub', $resolved->identity);
        $content = str_replace(
            ['{{ NAMESPACE }}', '{{ REPOSITORY_NAME }}', '{{ MODEL_FQN }}'],
            [$resolved->namespace, $repositoryName, $modelFqn],
            $content
        );

        $relativePath = "src/Repositories/{$repositoryName}.php";
        $files = [$relativePath => $content];

        $plan = new GenerationPlan($resolved->identity->relativePackagePath, $this->basePath, $files);
        $plan->preflight($force);

        $results = $this->writer->execute($plan, $dryRun, $force);

        return [
            'package' => $resolved,
            'repository' => $repositoryName,
            'model' => $modelName,
            'dry_run' => $dryRun,
            'force' => $force,
            'files' => $results,
        ];
    }

    protected function validateRepositoryName(string $name): void
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw PackageGenerationException::invalidInput('Repository class name cannot be empty.');
        }

        if (str_contains($trimmed, '..') || str_contains($trimmed, '/') || str_contains($trimmed, '\\') || str_contains($trimmed, "\0")) {
            throw PackageGenerationException::invalidInput('Repository class name contains path traversal or invalid path characters.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $trimmed) !== 1) {
            throw PackageGenerationException::invalidInput("Repository class name [{$trimmed}] must be a valid PHP class identifier.");
        }
    }

    protected function validateModelName(string $name): void
    {
        if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw PackageGenerationException::invalidInput('Model class name contains path traversal or invalid path characters.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw PackageGenerationException::invalidInput("Model class name [{$name}] must be a valid PHP class identifier.");
        }
    }
}
