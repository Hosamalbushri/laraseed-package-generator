<?php

namespace Laraseed\PackageGenerator\Generators;

use Laraseed\PackageGenerator\Support\PackageIdentity;

class PackageGenerator
{
    protected string $basePath;

    public function __construct(
        protected StubRenderer $renderer = new StubRenderer,
        protected FilesystemWriter $writer = new FilesystemWriter,
        ?string $basePath = null
    ) {
        $this->basePath = $basePath ?? base_path();
        $this->writer = new FilesystemWriter(basePath: $this->basePath);
    }

    /**
     * Generate an optional Laraseed package.
     *
     * @return array{
     *     identity: PackageIdentity,
     *     plain: bool,
     *     dry_run: bool,
     *     force: bool,
     *     files: array<int, array{path: string, full_path: string, action: string, bytes: int}>
     * }
     */
    public function generate(
        string $input,
        bool $plain = false,
        bool $dryRun = false,
        bool $force = false
    ): array {
        $identity = PackageIdentity::fromInput($input);

        if ($plain) {
            $files = [
                'composer.json' => $this->renderer->render('plain_composer.json.stub', $identity),
                "src/Providers/{$identity->providerClass}.php" => $this->renderer->render('plain_provider.php.stub', $identity),
                "src/Config/{$identity->packageSnake}.php" => $this->renderer->render('config.php.stub', $identity),
                'tests/TestCase.php' => $this->renderer->render('test_case.php.stub', $identity),
                'tests/Feature/PackageTest.php' => $this->renderer->render('package_test.php.stub', $identity),
            ];
        } else {
            $files = [
                'composer.json' => $this->renderer->render('composer.json.stub', $identity),
                "src/Providers/{$identity->providerClass}.php" => $this->renderer->render('provider.php.stub', $identity),
                "src/Providers/{$identity->moduleClass}.php" => $this->renderer->render('module_provider.php.stub', $identity),
                "src/Config/{$identity->packageSnake}.php" => $this->renderer->render('config.php.stub', $identity),
                'src/Resources/lang/en/app.php' => $this->renderer->render('lang_en.php.stub', $identity),
                'src/Resources/lang/ar/app.php' => $this->renderer->render('lang_ar.php.stub', $identity),
                'src/Routes/web.php' => $this->renderer->render('routes_web.php.stub', $identity),
                'src/Routes/api.php' => $this->renderer->render('routes_api.php.stub', $identity),
                'tests/TestCase.php' => $this->renderer->render('test_case.php.stub', $identity),
                'tests/Feature/PackageTest.php' => $this->renderer->render('package_test.php.stub', $identity),
            ];
        }

        $plan = new GenerationPlan($identity->relativePackagePath, $this->basePath, $files);
        $plan->preflight($force);

        $results = $this->writer->execute($plan, $dryRun, $force);

        return [
            'identity' => $identity,
            'plain'    => $plain,
            'dry_run'  => $dryRun,
            'force'    => $force,
            'files'    => $results,
        ];
    }
}
