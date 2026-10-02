<?php

namespace Laraseed\PackageGenerator\Generators;

use Laraseed\PackageGenerator\Support\PackageResolver;
use Laraseed\PackageGenerator\Support\ResolvedPackage;

class ModuleProviderGenerator
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
    }

    /**
     * Generate or regenerate the Concord ModuleServiceProvider for the specified package.
     *
     * @return array{
     *     package: ResolvedPackage,
     *     provider: string,
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

        $content = $this->renderer->render('module_provider.php.stub', $resolved->identity);
        $content = str_replace(
            ['{{ NAMESPACE }}', '{{ MODULE_CLASS }}'],
            [$resolved->namespace, 'ModuleServiceProvider'],
            $content
        );

        $relativePath = 'src/Providers/ModuleServiceProvider.php';
        $files = [$relativePath => $content];

        $plan = new GenerationPlan($resolved->identity->relativePackagePath, $this->basePath, $files);
        $plan->preflight($force);

        $results = $this->writer->execute($plan, $dryRun, $force);

        return [
            'package' => $resolved,
            'provider' => 'ModuleServiceProvider',
            'dry_run' => $dryRun,
            'force' => $force,
            'files' => $results,
        ];
    }
}
