<?php

namespace Laraseed\PackageGenerator\Generators;

use Illuminate\Support\Str;
use JsonException;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Support\PackageLock;
use Laraseed\PackageGenerator\Support\PackageResolver;
use Laraseed\PackageGenerator\Support\ResolvedPackage;

class AdminGenerator
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
     * Generate optional Admin integration layer skeleton for the specified package.
     *
     * @return array{
     *     package: ResolvedPackage,
     *     dry_run: bool,
     *     force: bool,
     *     files: array<int, array{path: string, full_path: string, action: string, bytes: int}>
     * }
     */
    public function generate(
        string $packageInput,
        bool $dryRun = false,
        bool $force = false
    ): array {
        $resolved = $this->resolver->resolve($packageInput);
        $identity = $resolved->identity;

        return $this->lock->withLock($identity->relativePackagePath, function () use ($resolved, $identity, $dryRun, $force) {
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

            if (isset($existingCapabilities['admin']) && ! $force) {
                throw PackageGenerationException::collisionDetected([
                    "{$identity->relativePackagePath}/composer.json (capabilities.admin)",
                ]);
            }

            $adminProviderClass = $resolved->namespace . '\\Admin\\Providers\\AdminServiceProvider';
            if (! isset($composerData['extra']['laraseed']['capabilities']) || ! is_array($composerData['extra']['laraseed']['capabilities'])) {
                $composerData['extra']['laraseed']['capabilities'] = [];
            }
            $composerData['extra']['laraseed']['capabilities']['admin'] = [
                'provider' => $adminProviderClass,
                'enabled' => true,
            ];

            $newComposerJson = json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

            // 2. Prepare Admin integration files
            $packageKey = strtolower($identity->vendor) . '_' . $identity->packageSnake;
            $packageSlug = $identity->vendorKebab . '-' . $identity->packageKebab;
            $packageTitle = Str::headline($identity->package);

            $replacements = [
                '{{ PACKAGE_KEY }}'   => $packageKey,
                '{{ PACKAGE_SLUG }}'  => $packageSlug,
                '{{ PACKAGE_TITLE }}' => $packageTitle,
            ];

            $renderFile = function (string $stub) use ($identity, $replacements): string {
                $content = $this->renderer->render($stub, $identity);

                return str_replace(array_keys($replacements), array_values($replacements), $content);
            };

            $files = [
                'src/Admin/Providers/AdminServiceProvider.php' => $renderFile('admin_provider.php.stub'),
                'src/Admin/Config/menu.php'                    => $renderFile('admin_menu.php.stub'),
                'src/Admin/Config/acl.php'                     => $renderFile('admin_acl.php.stub'),
                'src/Admin/Http/Controllers/AdminController.php' => $renderFile('admin_controller.php.stub'),
                'src/Admin/Routes/web.php'                     => $renderFile('admin_routes_web.php.stub'),
                'src/Admin/Resources/lang/en/app.php'          => $renderFile('admin_lang_en.php.stub'),
                'src/Admin/Resources/lang/ar/app.php'          => $renderFile('admin_lang_ar.php.stub'),
                'src/Admin/Resources/views/index.blade.php'   => $renderFile('admin_view_index.blade.php.stub'),
            ];

            // 3. Preflight Admin files collision
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
                'dry_run' => false,
                'force' => $force,
                'files' => $results,
            ];
        });
    }
}
