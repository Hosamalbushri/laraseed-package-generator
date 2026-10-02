<?php

namespace Laraseed\PackageGenerator\Generators;

use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Support\PackageResolver;
use Laraseed\PackageGenerator\Support\ResolvedPackage;

class RouteGenerator
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
     * Generate a route file within the specified package.
     *
     * @return array{
     *     package: ResolvedPackage,
     *     route: string,
     *     type: string,
     *     dry_run: bool,
     *     force: bool,
     *     files: array<int, array{path: string, full_path: string, action: string, bytes: int}>
     * }
     */
    public function generate(
        string $packageInput,
        string $routeName,
        string $type = 'web',
        bool $dryRun = false,
        bool $force = false
    ): array {
        $resolved = $this->resolver->resolve($packageInput);

        $normalizedType = strtolower(trim($type));
        if ($normalizedType !== 'web' && $normalizedType !== 'api') {
            throw PackageGenerationException::invalidInput("Route type must be either 'web' or 'api', [{$type}] given.");
        }

        $cleanRouteName = str_ends_with($routeName, '.php') ? substr($routeName, 0, -4) : $routeName;
        $this->validateRouteName($cleanRouteName);

        $stubFile = $normalizedType === 'api' ? 'route_api.php.stub' : 'route_web.php.stub';
        $content = $this->renderer->render($stubFile, $resolved->identity);
        $content = str_replace('{{ ROUTE_NAME }}', $cleanRouteName, $content);

        $relativePath = "src/Routes/{$cleanRouteName}.php";
        $files = [$relativePath => $content];

        $plan = new GenerationPlan($resolved->identity->relativePackagePath, $this->basePath, $files);
        $plan->preflight($force);

        $results = $this->writer->execute($plan, $dryRun, $force);

        return [
            'package' => $resolved,
            'route' => $cleanRouteName,
            'type' => $normalizedType,
            'dry_run' => $dryRun,
            'force' => $force,
            'files' => $results,
        ];
    }

    protected function validateRouteName(string $name): void
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw PackageGenerationException::invalidInput('Route name cannot be empty.');
        }

        if (str_contains($trimmed, '..') || str_contains($trimmed, '/') || str_contains($trimmed, '\\') || str_contains($trimmed, "\0")) {
            throw PackageGenerationException::invalidInput('Route name contains path traversal or invalid path characters.');
        }

        if (preg_match('/^[a-z0-9_.-]+$/', $trimmed) !== 1) {
            throw PackageGenerationException::invalidInput("Route name [{$trimmed}] must be a valid lowercase identifier (e.g. admin, web, v1).");
        }
    }
}
