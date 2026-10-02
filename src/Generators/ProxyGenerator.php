<?php

namespace Laraseed\PackageGenerator\Generators;

use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Support\PackageResolver;
use Laraseed\PackageGenerator\Support\ResolvedPackage;

class ProxyGenerator
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
     * Generate a Concord ModelProxy class for the specified package.
     *
     * @return array{
     *     package: ResolvedPackage,
     *     proxy: string,
     *     dry_run: bool,
     *     force: bool,
     *     files: array<int, array{path: string, full_path: string, action: string, bytes: int}>
     * }
     */
    public function generate(
        string $packageInput,
        string $proxyName,
        bool $dryRun = false,
        bool $force = false
    ): array {
        $resolved = $this->resolver->resolve($packageInput);
        $normalizedClassName = $this->normalizeProxyName($proxyName);
        $this->validateProxyName($normalizedClassName);

        $stub = $this->renderer->render('proxy.php.stub', $resolved->identity);
        $content = str_replace(
            [
                '{{ NAMESPACE }}',
                '{{ CLASS_NAME }}',
                $resolved->identity->namespace,
            ],
            [
                $resolved->namespace,
                $normalizedClassName,
                $resolved->namespace,
            ],
            $stub
        );

        $relativePath = "src/Models/{$normalizedClassName}.php";
        $files = [$relativePath => $content];

        $plan = new GenerationPlan($resolved->identity->relativePackagePath, $this->basePath, $files);
        $plan->preflight($force);

        $results = $this->writer->execute($plan, $dryRun, $force);

        return [
            'package' => $resolved,
            'proxy'   => $normalizedClassName,
            'dry_run' => $dryRun,
            'force'   => $force,
            'files'   => $results,
        ];
    }

    /**
     * Normalize the proxy class name to ensure it has the 'Proxy' suffix.
     */
    public function normalizeProxyName(string $name): string
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            return '';
        }

        if (str_ends_with($trimmed, 'Proxy')) {
            return $trimmed;
        }

        return $trimmed . 'Proxy';
    }

    /**
     * Validate the proxy class name for PHP identifier validity and path traversal.
     */
    protected function validateProxyName(string $name): void
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw PackageGenerationException::invalidInput('Proxy class name cannot be empty.');
        }

        if (str_contains($trimmed, '..') || str_contains($trimmed, '/') || str_contains($trimmed, '\\') || str_contains($trimmed, "\0")) {
            throw PackageGenerationException::invalidInput('Proxy class name contains path traversal or invalid path characters.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $trimmed) !== 1) {
            throw PackageGenerationException::invalidInput("Proxy class name [{$trimmed}] must be a valid PHP class identifier.");
        }
    }
}
