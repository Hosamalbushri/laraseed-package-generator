<?php

namespace Laraseed\PackageGenerator\Generators;

use Illuminate\Support\Str;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Support\PackageResolver;
use Laraseed\PackageGenerator\Support\ResolvedPackage;

class CommandGenerator
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
     * Generate a Console Command class for the specified package.
     *
     * @return array{
     *     package: ResolvedPackage,
     *     command: string,
     *     signature: string,
     *     dry_run: bool,
     *     force: bool,
     *     files: array<int, array{path: string, full_path: string, action: string, bytes: int}>
     * }
     */
    public function generate(
        string $packageInput,
        string $commandName,
        ?string $signature = null,
        bool $dryRun = false,
        bool $force = false
    ): array {
        $resolved = $this->resolver->resolve($packageInput);
        $this->validateCommandName($commandName);

        if ($signature !== null && trim($signature) !== '') {
            $trimmedSig = trim($signature);
            $this->validateSignature($trimmedSig);
            $finalSignature = $trimmedSig;
        } else {
            $cmdKebab = Str::kebab($commandName);
            $finalSignature = "{$resolved->identity->packageKebab}:{$cmdKebab}";
        }

        $content = $this->renderer->render('console_command.php.stub', $resolved->identity);
        $content = str_replace(
            ['{{ NAMESPACE }}', '{{ COMMAND_NAME }}', '{{ SIGNATURE }}'],
            [$resolved->namespace, $commandName, $finalSignature],
            $content
        );

        $relativePath = "src/Console/Commands/{$commandName}.php";
        $files = [$relativePath => $content];

        $plan = new GenerationPlan($resolved->identity->relativePackagePath, $this->basePath, $files);
        $plan->preflight($force);

        $results = $this->writer->execute($plan, $dryRun, $force);

        return [
            'package' => $resolved,
            'command' => $commandName,
            'signature' => $finalSignature,
            'dry_run' => $dryRun,
            'force' => $force,
            'files' => $results,
        ];
    }

    protected function validateCommandName(string $name): void
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            throw PackageGenerationException::invalidInput('Command class name cannot be empty.');
        }

        if (str_contains($trimmed, '..') || str_contains($trimmed, '/') || str_contains($trimmed, '\\') || str_contains($trimmed, "\0")) {
            throw PackageGenerationException::invalidInput('Command class name contains path traversal or invalid path characters.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $trimmed) !== 1) {
            throw PackageGenerationException::invalidInput("Command class name [{$trimmed}] must be a valid PHP class identifier.");
        }
    }

    protected function validateSignature(string $signature): void
    {
        if (str_starts_with(strtolower($signature), 'laraseed:')) {
            throw PackageGenerationException::invalidInput("The 'laraseed:' command prefix is reserved for Laraseed foundation seed tools.");
        }

        if (preg_match('/^[a-z0-9_:-]+$/i', $signature) !== 1) {
            throw PackageGenerationException::invalidInput("Command signature [{$signature}] contains invalid characters.");
        }
    }
}
