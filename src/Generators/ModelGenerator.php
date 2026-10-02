<?php

namespace Laraseed\PackageGenerator\Generators;

use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Support\PackageResolver;
use Laraseed\PackageGenerator\Support\ResolvedPackage;

class ModelGenerator
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
     * Generate an Eloquent model (and optional companion Contract and Concord Proxy) for the specified package.
     *
     * @return array{
     *     package: ResolvedPackage,
     *     model: string,
     *     has_contract: bool,
     *     has_proxy: bool,
     *     dry_run: bool,
     *     force: bool,
     *     files: array<int, array{path: string, full_path: string, action: string, bytes: int}>
     * }
     */
    public function generate(
        string $packageInput,
        string $modelName,
        bool $withProxy = false,
        bool $withContract = false,
        bool $dryRun = false,
        bool $force = false
    ): array {
        $resolved = $this->resolver->resolve($packageInput);
        $this->validateModelName($modelName);

        // Proxy mode implies contract generation automatically
        $hasContract = $withContract || $withProxy;
        $hasProxy = $withProxy;

        $files = [];

        // 1. Model file
        $modelStub = $this->renderer->render('model.php.stub', $resolved->identity);
        $contractImport = $hasContract
            ? "use {$resolved->namespace}\\Contracts\\{$modelName} as {$modelName}Contract;\n\n"
            : '';
        $implementsClause = $hasContract
            ? " implements {$modelName}Contract"
            : '';

        $modelContent = str_replace(
            [
                '{{ NAMESPACE }}',
                '{{ CLASS_NAME }}',
                '{{ CONTRACT_IMPORT }}',
                '{{ IMPLEMENTS_CONTRACT }}',
                $resolved->identity->namespace,
            ],
            [
                $resolved->namespace,
                $modelName,
                $contractImport,
                $implementsClause,
                $resolved->namespace,
            ],
            $modelStub
        );
        $files["src/Models/{$modelName}.php"] = $modelContent;

        // 2. Contract file
        if ($hasContract) {
            $contractStub = $this->renderer->render('contract.php.stub', $resolved->identity);
            $contractContent = str_replace(
                [
                    '{{ NAMESPACE }}',
                    '{{ CONTRACT_NAME }}',
                    $resolved->identity->namespace,
                ],
                [
                    $resolved->namespace,
                    $modelName,
                    $resolved->namespace,
                ],
                $contractStub
            );
            $files["src/Contracts/{$modelName}.php"] = $contractContent;
        }

        // 3. Proxy file
        if ($hasProxy) {
            $proxyStub = $this->renderer->render('proxy.php.stub', $resolved->identity);
            $proxyClassName = "{$modelName}Proxy";
            $proxyContent = str_replace(
                [
                    '{{ NAMESPACE }}',
                    '{{ CLASS_NAME }}',
                    $resolved->identity->namespace,
                ],
                [
                    $resolved->namespace,
                    $proxyClassName,
                    $resolved->namespace,
                ],
                $proxyStub
            );
            $files["src/Models/{$proxyClassName}.php"] = $proxyContent;
        }

        // Bundle all artifacts into a single atomic generation plan
        $plan = new GenerationPlan($resolved->identity->relativePackagePath, $this->basePath, $files);
        $plan->preflight($force);

        $results = $this->writer->execute($plan, $dryRun, $force);

        return [
            'package'      => $resolved,
            'model'        => $modelName,
            'has_contract' => $hasContract,
            'has_proxy'    => $hasProxy,
            'dry_run'      => $dryRun,
            'force'        => $force,
            'files'        => $results,
        ];
    }

    protected function validateModelName(string $name): void
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw PackageGenerationException::invalidInput('Model class name cannot be empty.');
        }

        if (str_contains($trimmed, '..') || str_contains($trimmed, '/') || str_contains($trimmed, '\\') || str_contains($trimmed, "\0")) {
            throw PackageGenerationException::invalidInput('Model class name contains path traversal or invalid path characters.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $trimmed) !== 1) {
            throw PackageGenerationException::invalidInput("Model class name [{$trimmed}] must be a valid PHP class identifier.");
        }
    }
}
