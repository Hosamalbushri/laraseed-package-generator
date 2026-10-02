<?php

namespace Laraseed\PackageGenerator\Generators;

use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Support\PackageResolver;
use Laraseed\PackageGenerator\Support\ResolvedPackage;

class ContractGenerator
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
     * Generate a Contract interface for the specified package.
     *
     * @return array{
     *     package: ResolvedPackage,
     *     contract: string,
     *     dry_run: bool,
     *     force: bool,
     *     files: array<int, array{path: string, full_path: string, action: string, bytes: int}>
     * }
     */
    public function generate(string $packageInput, string $contractName, bool $dryRun = false, bool $force = false): array
    {
        $resolved = $this->resolver->resolve($packageInput);
        $this->validateContractName($contractName);

        $stub = "<?php\n\nnamespace {{ NAMESPACE }}\\Contracts;\n\ninterface {{ CONTRACT_NAME }}\n{\n}\n";

        $content = str_replace(
            ['{{ NAMESPACE }}', '{{ CONTRACT_NAME }}'],
            [$resolved->namespace, $contractName],
            $stub
        );

        $relativePath = "src/Contracts/{$contractName}.php";
        $files = [$relativePath => $content];

        $plan = new GenerationPlan($resolved->identity->relativePackagePath, $this->basePath, $files);
        $plan->preflight($force);

        $results = $this->writer->execute($plan, $dryRun, $force);

        return [
            'package' => $resolved,
            'contract' => $contractName,
            'dry_run' => $dryRun,
            'force' => $force,
            'files' => $results,
        ];
    }

    protected function validateContractName(string $name): void
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw PackageGenerationException::invalidInput('Contract interface name cannot be empty.');
        }

        if (str_contains($trimmed, '..') || str_contains($trimmed, '/') || str_contains($trimmed, '\\') || str_contains($trimmed, "\0")) {
            throw PackageGenerationException::invalidInput('Contract interface name contains path traversal or invalid path characters.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $trimmed) !== 1) {
            throw PackageGenerationException::invalidInput("Contract interface name [{$trimmed}] must be a valid PHP interface identifier.");
        }
    }
}
