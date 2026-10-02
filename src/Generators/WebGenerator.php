<?php

namespace Laraseed\PackageGenerator\Generators;

use Illuminate\Support\Str;
use JsonException;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Support\PackageLock;
use Laraseed\PackageGenerator\Support\PackageResolver;
use Laraseed\PackageGenerator\Support\ResolvedPackage;
use Laraseed\PackageGenerator\Templates\WebTemplateCatalog;

class WebGenerator
{
    protected string $basePath;

    public function __construct(
        protected ?PackageResolver $resolver = null,
        protected ?StubRenderer $renderer = null,
        protected ?FilesystemWriter $writer = null,
        protected ?PackageLock $lock = null,
        ?string $basePath = null
    ) {
        $this->basePath = $basePath ?? base_path();
        $this->resolver = $resolver ?? new PackageResolver(basePath: $this->basePath);
        $this->renderer = $renderer ?? new StubRenderer;
        $this->writer = $writer ?? new FilesystemWriter(basePath: $this->basePath);
        $this->lock = $lock ?? new PackageLock(basePath: $this->basePath);
    }

    /**
     * Generate optional Web capability skeleton for the specified package.
     *
     * @return array{
     *     package: ResolvedPackage,
     *     template: string,
     *     dry_run: bool,
     *     force: bool,
     *     files: array<int, array{path: string, full_path: string, action: string, bytes: int}>
     * }
     */
    public function generate(
        string $packageInput,
        string $template = WebTemplateCatalog::DEFAULT_TEMPLATE,
        bool $dryRun = false,
        bool $force = false
    ): array {
        $templateData = WebTemplateCatalog::get($template);
        $resolved = $this->resolver->resolve($packageInput);
        $identity = $resolved->identity;

        return $this->lock->withLock($identity->relativePackagePath, function () use ($templateData, $template, $resolved, $identity, $dryRun, $force) {
            // 1. Validate and prepare package composer.json mutation
            $composerPath = $resolved->packagePath . '/composer.json';
            if (! file_exists($composerPath) || ! is_readable($composerPath)) {
                throw PackageGenerationException::invalidInput('Package manifest [composer.json] is missing or not readable.');
            }

            $rawJson = (string) file_get_contents($composerPath);
            try {
                $composerData = json_decode($rawJson, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw PackageGenerationException::invalidInput('Package manifest [composer.json] contains invalid JSON: ' . $e->getMessage());
            }

            if (! is_array($composerData)) {
                throw PackageGenerationException::invalidInput('Package manifest [composer.json] must contain a JSON object.');
            }

            if (! isset($composerData['extra']['laraseed']) || ! is_array($composerData['extra']['laraseed'])) {
                throw PackageGenerationException::invalidInput('Package manifest [composer.json] is missing extra.laraseed metadata.');
            }

            $existingCapabilities = $composerData['extra']['laraseed']['capabilities'] ?? null;
            if ($existingCapabilities !== null && ! is_array($existingCapabilities)) {
                throw PackageGenerationException::invalidInput('Package manifest [composer.json] has an invalid capabilities definition.');
            }

            if (isset($existingCapabilities['web']) && ! $force) {
                throw PackageGenerationException::collisionDetected([
                    "{$identity->relativePackagePath}/composer.json (capabilities.web)",
                ]);
            }

            $packageKey = strtolower($identity->vendor) . '_' . $identity->packageSnake;
            $packageSlug = $identity->vendorKebab . '-' . $identity->packageKebab;
            $packageTitle = Str::headline($identity->package);
            $upperPackageKey = strtoupper($packageKey);

            $webProviderClass = $resolved->namespace . '\\Web\\Providers\\WebServiceProvider';
            if (! isset($composerData['extra']['laraseed']['capabilities']) || ! is_array($composerData['extra']['laraseed']['capabilities'])) {
                $composerData['extra']['laraseed']['capabilities'] = [];
            }
            $composerData['extra']['laraseed']['capabilities']['web'] = [
                'provider' => $webProviderClass,
                'enabled'  => true,
            ];

            $newComposerJson = json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

            // 2. Prepare Web capability files from template

            $replacements = [
                '{{ PACKAGE_KEY }}'       => $packageKey,
                '{{ PACKAGE_SLUG }}'      => $packageSlug,
                '{{ PACKAGE_TITLE }}'     => $packageTitle,
                '{{ UPPER_PACKAGE_KEY }}' => $upperPackageKey,
                '{{ TEMPLATE_ID }}'       => $templateData['id'],
            ];

            $renderFile = function (string $stub) use ($identity, $replacements): string {
                $content = $this->renderer->render($stub, $identity);

                return str_replace(array_keys($replacements), array_values($replacements), $content);
            };

            $files = [];
            foreach ($templateData['files'] as $destPath => $stubPath) {
                $files[$destPath] = $renderFile($stubPath);
            }

            // 3. Preflight Web files collision
            $plan = new GenerationPlan($identity->relativePackagePath, $this->basePath, $files);
            $plan->preflight($force);

            // 4. Execute atomic write or dry-run simulation
            if ($dryRun) {
                $results = $this->writer->simulate($plan, $force);
                $results[] = [
                    'path' => "{$identity->relativePackagePath}/composer.json",
                    'full_path' => $composerPath,
                    'action' => 'UPDATE',
                    'bytes' => strlen($newComposerJson),
                ];

                return [
                    'package' => $resolved,
                    'template' => $template,
                    'dry_run' => true,
                    'force' => $force,
                    'files' => $results,
                ];
            }

            $transaction = $this->writer->transaction($this->basePath);
            $results = $transaction->run(function (FilesystemTransaction $tx) use ($plan, $composerPath, $newComposerJson, $identity, $force) {
                $results = $tx->executePlan($plan, $force);

                $composerResult = $tx->writeFile(
                    $composerPath,
                    $newComposerJson,
                    "{$identity->relativePackagePath}/composer.json",
                    true
                );
                $composerResult['action'] = 'UPDATE';
                $results[] = $composerResult;

                return $results;
            });

            return [
                'package' => $resolved,
                'template' => $template,
                'dry_run' => false,
                'force' => $force,
                'files' => $results,
            ];
        });
    }
}
