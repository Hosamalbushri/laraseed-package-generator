<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Laraseed\PackageGenerator\Support\PackageIdentity;
use Webkul\Core\Packages\OptionalPackageComposition;
use Webkul\Core\Packages\OptionalPackageManifestLoader;
use Laraseed\PackageGenerator\Tests\TestCase;

class WebPackageGeneratorTest extends TestCase
{
    protected Filesystem $filesystem;

    protected array $createdDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem;
        $this->createdDirectories = [];

        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'AcmeTest\\')) {
                $relative = substr($class, strlen('AcmeTest\\'));
                $parts = explode('\\', $relative, 2);
                $package = $parts[0];
                $subPath = isset($parts[1]) ? str_replace('\\', '/', $parts[1]) : '';
                $path = base_path("packages/AcmeTest/{$package}/src/{$subPath}.php");
                if (file_exists($path)) {
                    require_once $path;
                }
            }
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDirectories as $dir) {
            if ($this->filesystem->isDirectory($dir)) {
                $this->filesystem->deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    protected function trackDirectory(string $relativeOrAbsolutePath): string
    {
        $abs = str_starts_with($relativeOrAbsolutePath, '/')
            ? $relativeOrAbsolutePath
            : base_path($relativeOrAbsolutePath);

        if (! in_array($abs, $this->createdDirectories, true)) {
            $this->createdDirectories[] = $abs;
        }

        return $abs;
    }

    public function test_make_web_generates_package_owned_web_capability_skeleton(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebTestPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebTestPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-web AcmeTest/WebTestPkg')->assertExitCode(0);

        $webFiles = [
            "{$pkgDir}/package.json",
            "{$pkgDir}/vite.config.js",
            "{$pkgDir}/tailwind.config.js",
            "{$pkgDir}/postcss.config.js",
            "{$pkgDir}/src/Web/Providers/WebServiceProvider.php",
            "{$pkgDir}/src/Web/Config/web.php",
            "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php",
            "{$pkgDir}/src/Web/Http/Controllers/HomeController.php",
            "{$pkgDir}/src/Web/Http/Controllers/PageController.php",
            "{$pkgDir}/src/Web/Http/Controllers/AccountController.php",
            "{$pkgDir}/src/Web/Routes/web.php",
            "{$pkgDir}/src/Web/Resources/lang/en/app.php",
            "{$pkgDir}/src/Web/Resources/lang/ar/app.php",
            "{$pkgDir}/src/Web/Resources/views/components/layouts/index.blade.php",
            "{$pkgDir}/src/Web/Resources/views/components/layouts/header/index.blade.php",
            "{$pkgDir}/src/Web/Resources/views/components/layouts/header/navbar.blade.php",
            "{$pkgDir}/src/Web/Resources/views/components/layouts/footer/index.blade.php",
            "{$pkgDir}/src/Web/Resources/views/components/container/index.blade.php",
            "{$pkgDir}/src/Web/Resources/views/components/section/index.blade.php",
            "{$pkgDir}/src/Web/Resources/views/components/card/index.blade.php",
            "{$pkgDir}/src/Web/Resources/views/components/button/index.blade.php",
            "{$pkgDir}/src/Web/Resources/views/components/modal/index.blade.php",
            "{$pkgDir}/src/Web/Resources/views/components/form/control-group/index.blade.php",
            "{$pkgDir}/src/Web/Resources/views/home/index.blade.php",
            "{$pkgDir}/src/Web/Resources/views/pages/show.blade.php",
            "{$pkgDir}/src/Web/Resources/views/account/dashboard.blade.php",
            "{$pkgDir}/src/Web/Resources/assets/css/app.css",
            "{$pkgDir}/src/Web/Resources/assets/js/app.js",
            "{$pkgDir}/tests/Feature/Web/WebPageTest.php",
        ];

        foreach ($webFiles as $file) {
            $this->assertTrue($this->filesystem->exists($file), "Missing Web file: {$file}");
        }

        $composerData = json_decode((string) file_get_contents("{$pkgDir}/composer.json"), true);
        $this->assertArrayHasKey('capabilities', $composerData['extra']['laraseed']);
        $this->assertArrayHasKey('web', $composerData['extra']['laraseed']['capabilities']);
        $this->assertSame(
            'AcmeTest\\WebTestPkg\\Web\\Providers\\WebServiceProvider',
            $composerData['extra']['laraseed']['capabilities']['web']['provider']
        );
        $this->assertTrue($composerData['extra']['laraseed']['capabilities']['web']['enabled']);

        // Assert valid syntax of WebServiceProvider
        $providerContent = (string) file_get_contents("{$pkgDir}/src/Web/Providers/WebServiceProvider.php");
        $this->assertStringContainsString('namespace AcmeTest\WebTestPkg\Web\Providers;', $providerContent);
        $this->assertStringContainsString('class WebServiceProvider extends ServiceProvider', $providerContent);
        $this->assertStringContainsString("Blade::anonymousComponentPath(__DIR__ . '/../Resources/views/components', 'acmetest_web_test_pkg_web');", $providerContent);
        $this->assertStringContainsString("\$router->aliasMiddleware('acmetest_web_test_pkg_auth', \AcmeTest\WebTestPkg\Web\Http\Middleware\AuthenticateWeb::class);", $providerContent);
    }

    public function test_make_web_preflight_collision_fails_cleanly(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebCollisionPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebCollisionPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebCollisionPkg')->assertExitCode(0);

        // Second run without force must fail due to collision
        $this->artisan('laraseed:make-web AcmeTest/WebCollisionPkg')->assertExitCode(1);

        // Second run with force must succeed cleanly
        $this->artisan('laraseed:make-web AcmeTest/WebCollisionPkg --force')->assertExitCode(0);
    }

    public function test_make_web_generates_optimized_vanilla_frontend_without_vue_dependency(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebOptPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebOptPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebOptPkg')->assertExitCode(0);

        $packageJson = json_decode((string) file_get_contents("{$pkgDir}/package.json"), true);
        $this->assertArrayNotHasKey('vue', $packageJson['devDependencies'] ?? []);
        $this->assertArrayNotHasKey('vue', $packageJson['dependencies'] ?? []);
        $this->assertArrayNotHasKey('@vitejs/plugin-vue', $packageJson['devDependencies'] ?? []);
        $this->assertArrayNotHasKey('@vitejs/plugin-vue', $packageJson['dependencies'] ?? []);

        $assetJs = (string) file_get_contents("{$pkgDir}/src/Web/Resources/assets/js/app.js");
        $this->assertStringNotContainsString('vue/dist/vue.esm-bundler', $assetJs);
        $this->assertStringNotContainsString('createApp', $assetJs);
        $this->assertStringContainsString('class WebStarterKernel', $assetJs);
        $this->assertStringContainsString('window.LaraseedWeb = kernel;', $assetJs);

        $providerCode = (string) file_get_contents("{$pkgDir}/src/Web/Providers/WebServiceProvider.php");
        $this->assertStringNotContainsString('refreshNameLookups', $providerCode);
        $this->assertStringNotContainsString('refreshActionLookups', $providerCode);
    }

    public function test_make_web_dry_run_creates_zero_files_and_leaves_composer_json_unmodified(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebDryRunPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebDryRunPkg')->assertExitCode(0);

        $composerBefore = file_get_contents("{$pkgDir}/composer.json");

        $this->artisan('laraseed:make-web AcmeTest/WebDryRunPkg --dry-run')->assertExitCode(0);

        $composerAfter = file_get_contents("{$pkgDir}/composer.json");
        $this->assertSame($composerBefore, $composerAfter);
        $this->assertFalse($this->filesystem->isDirectory("{$pkgDir}/src/Web"));
    }

    public function test_make_web_fails_when_package_does_not_exist(): void
    {
        $this->artisan('laraseed:make-web AcmeTest/NonExistentWebPkg')->assertExitCode(1);
    }

    public function test_make_web_fails_on_malformed_composer_json_without_partial_generation(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebMalformedJsonPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebMalformedJsonPkg')->assertExitCode(0);

        // Corrupt composer.json
        file_put_contents("{$pkgDir}/composer.json", '{ invalid json');

        $this->artisan('laraseed:make-web AcmeTest/WebMalformedJsonPkg')->assertExitCode(1);

        $this->assertFalse($this->filesystem->isDirectory("{$pkgDir}/src/Web"));
    }

    public function test_make_web_mid_flight_failure_performs_transactional_rollback(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebRollbackPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebRollbackPkg')->assertExitCode(0);

        $composerPath = "{$pkgDir}/composer.json";
        $composerOriginal = file_get_contents($composerPath);

        // Make composer.json read-only to simulate a mid-flight write failure after Web files generation
        chmod($composerPath, 0444);

        try {
            $this->artisan('laraseed:make-web AcmeTest/WebRollbackPkg')->assertExitCode(1);
        } finally {
            chmod($composerPath, 0664);
        }

        $this->assertSame($composerOriginal, file_get_contents($composerPath));
        $this->assertFalse($this->filesystem->isDirectory("{$pkgDir}/src/Web"));
    }

    public function test_web_capability_lifecycle_active_and_disabled_quadrants(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebQuadrantPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebQuadrantPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebQuadrantPkg')->assertExitCode(0);

        $manifestPath = "{$pkgDir}/composer.json";
        $catalog = (new OptionalPackageManifestLoader)->load([$manifestPath]);

        // Quadrant 1: Package ON + Capability ON
        $compQ1 = new OptionalPackageComposition($catalog, ['web_quadrant_pkg']);
        $this->assertSame(
            ['AcmeTest\\WebQuadrantPkg\\Web\\Providers\\WebServiceProvider'],
            $compQ1->capabilityProviders('web')
        );

        // Quadrant 2: Package ON + Capability OFF
        $catalogQ2 = $catalog;
        $catalogQ2['web_quadrant_pkg']['capabilities']['web']['enabled'] = false;
        $compQ2 = new OptionalPackageComposition($catalogQ2, ['web_quadrant_pkg']);
        $this->assertSame([], $compQ2->capabilityProviders('web'));
        $this->assertTrue($compQ2->hasCapability('web_quadrant_pkg', 'web'));

        // Quadrant 3: Package OFF + Capability ON
        $compQ3 = new OptionalPackageComposition($catalog, []);
        $this->assertSame([], $compQ3->capabilityProviders('web'));

        // Quadrant 4: Package OFF + Capability OFF
        $compQ4 = new OptionalPackageComposition($catalogQ2, []);
        $this->assertSame([], $compQ4->capabilityProviders('web'));
    }

    public function test_generated_web_runtime_routes_and_views_render_successfully(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebRuntimePkg');
        $this->artisan('laraseed:make-package AcmeTest/WebRuntimePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebRuntimePkg')->assertExitCode(0);

        // Register WebServiceProvider
        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        $provider = $this->app->register(\AcmeTest\WebRuntimePkg\Web\Providers\WebServiceProvider::class);
        if (method_exists($provider, 'boot')) {
            $this->app->call([$provider, 'boot']);
        }
        $this->app['router']->getRoutes()->refreshNameLookups();

        // Test Home route
        $homeResponse = $this->get(route('acmetest_web_runtime_pkg.web.home'));
        $homeResponse->assertStatus(200);
        $homeResponse->assertSee('Web Runtime Pkg');

        // Test Content page route
        $pageResponse = $this->get(route('acmetest_web_runtime_pkg.web.pages.show', ['page' => 'about']));
        $pageResponse->assertStatus(200);
        $pageResponse->assertSee('about');

        // Test Features page route
        $featuresResponse = $this->get(route('acmetest_web_runtime_pkg.web.pages.show', ['page' => 'features']));
        $featuresResponse->assertStatus(200);
        $featuresResponse->assertSee('features');
    }

    public function test_arabic_and_english_translations_have_exact_parity(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebLangPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebLangPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebLangPkg')->assertExitCode(0);

        $en = require "{$pkgDir}/src/Web/Resources/lang/en/app.php";
        $ar = require "{$pkgDir}/src/Web/Resources/lang/ar/app.php";

        $this->assertSame(array_keys($en['web']), array_keys($ar['web']));
        $this->assertSame(array_keys($en['web']['auth']), array_keys($ar['web']['auth']));
        $this->assertSame(array_keys($en['web']['hero']), array_keys($ar['web']['hero']));
        $this->assertSame(array_keys($en['web']['highlights']), array_keys($ar['web']['highlights']));
        $this->assertSame(array_keys($en['web']['footer']), array_keys($ar['web']['footer']));
    }

    public function test_v2_admin_and_v3_web_capabilities_coexist_in_same_package(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/DualCapPkg');
        $this->artisan('laraseed:make-package AcmeTest/DualCapPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-admin AcmeTest/DualCapPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/DualCapPkg')->assertExitCode(0);

        $composerData = json_decode((string) file_get_contents("{$pkgDir}/composer.json"), true);
        $capabilities = $composerData['extra']['laraseed']['capabilities'];

        $this->assertArrayHasKey('admin', $capabilities);
        $this->assertArrayHasKey('web', $capabilities);
        $this->assertSame('AcmeTest\\DualCapPkg\\Admin\\Providers\\AdminServiceProvider', $capabilities['admin']['provider']);
        $this->assertSame('AcmeTest\\DualCapPkg\\Web\\Providers\\WebServiceProvider', $capabilities['web']['provider']);

        $manifestPath = "{$pkgDir}/composer.json";
        $catalog = (new OptionalPackageManifestLoader)->load([$manifestPath]);
        $composition = new OptionalPackageComposition($catalog, ['dual_cap_pkg']);

        $this->assertSame(
            ['AcmeTest\\DualCapPkg\\Admin\\Providers\\AdminServiceProvider'],
            $composition->capabilityProviders('admin')
        );
        $this->assertSame(
            ['AcmeTest\\DualCapPkg\\Web\\Providers\\WebServiceProvider'],
            $composition->capabilityProviders('web')
        );
    }

    public function test_make_web_does_not_mutate_foundation_or_root_packages(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebBoundaryPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebBoundaryPkg')->assertExitCode(0);

        $bootstrapProvidersBefore = file_get_contents(base_path('bootstrap/providers.php'));
        $configLaraseedBefore = file_get_contents(base_path('config/laraseed.php'));
        $composerJsonBefore = file_get_contents(base_path('composer.json'));

        $this->artisan('laraseed:make-web AcmeTest/WebBoundaryPkg')->assertExitCode(0);

        $this->assertSame($bootstrapProvidersBefore, file_get_contents(base_path('bootstrap/providers.php')));
        $this->assertSame($configLaraseedBefore, file_get_contents(base_path('config/laraseed.php')));
        $this->assertSame($composerJsonBefore, file_get_contents(base_path('composer.json')));
    }

    public function test_package_removability_leaves_zero_residue_in_foundation(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebRemovablePkg');
        $this->artisan('laraseed:make-package AcmeTest/WebRemovablePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebRemovablePkg')->assertExitCode(0);

        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Web/Providers/WebServiceProvider.php"));

        // Remove package directory completely
        $this->filesystem->deleteDirectory($pkgDir);

        $this->assertStringNotContainsString('WebRemovablePkg', file_get_contents(base_path('bootstrap/providers.php')));
        $this->assertStringNotContainsString('WebRemovablePkg', file_get_contents(base_path('config/laraseed.php')));
    }

    public function test_generated_web_assets_build_with_vite_and_produce_production_manifest(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebAssetPkg');
        $publicDir = base_path('public/acme-test-web-asset-pkg');
        if ($this->filesystem->isDirectory($publicDir)) {
            $this->filesystem->deleteDirectory($publicDir);
        }

        $this->artisan('laraseed:make-package AcmeTest/WebAssetPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebAssetPkg')->assertExitCode(0);

        $this->assertTrue($this->filesystem->exists("{$pkgDir}/package.json"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/vite.config.js"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/tailwind.config.js"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/postcss.config.js"));

        // Execute Vite build inside the generated package directory
        $env = $_SERVER;
        $parentNm = realpath(__DIR__ . '/../../../../node_modules');
        if ($parentNm && is_dir($parentNm)) {
            $env['NODE_PATH'] = $parentNm;
        }

        $process = new \Symfony\Component\Process\Process(['npx', 'vite', 'build'], $pkgDir, $env);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful() && (str_contains($process->getErrorOutput(), 'Cannot find package') || str_contains($process->getErrorOutput(), 'UNRESOLVED_IMPORT') || str_contains($process->getErrorOutput(), 'not found'))) {
            $this->markTestSkipped('Vite / npm dependencies are not installed in this environment.');
        }

        $this->assertTrue($process->isSuccessful(), "Vite build failed:\n" . $process->getErrorOutput() . "\n" . $process->getOutput());

        $manifestPath = base_path('public/acme-test-web-asset-pkg/web/build/manifest.json');
        $this->assertTrue($this->filesystem->exists($manifestPath), "Manifest not found at {$manifestPath}");

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('src/Web/Resources/assets/css/app.css', $manifest);
        $this->assertArrayHasKey('src/Web/Resources/assets/js/app.js', $manifest);

        // Register WebServiceProvider and assert asset rendering
        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        $provider = $this->app->register(\AcmeTest\WebAssetPkg\Web\Providers\WebServiceProvider::class);
        if (method_exists($provider, 'boot')) {
            $this->app->call([$provider, 'boot']);
        }
        $this->app['router']->getRoutes()->refreshNameLookups();

        $response = $this->get(route('acmetest_web_asset_pkg.web.home'));
        $response->assertStatus(200);
        $response->assertSee('acme-test-web-asset-pkg/web/build/assets/app-');

        // Cleanup public build artifacts
        if ($this->filesystem->isDirectory($publicDir)) {
            $this->filesystem->deleteDirectory($publicDir);
        }
    }

    public function test_two_web_packages_coexist_with_isolated_prefixes(): void
    {
        $blogDir = $this->trackDirectory('packages/AcmeTest/WebBlogPkg');
        $shopDir = $this->trackDirectory('packages/AcmeTest/WebShopPkg');

        $this->artisan('laraseed:make-package AcmeTest/WebBlogPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebBlogPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-package AcmeTest/WebShopPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebShopPkg')->assertExitCode(0);

        require_once "{$blogDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$blogDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$blogDir}/src/Web/Providers/WebServiceProvider.php";

        require_once "{$shopDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$shopDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$shopDir}/src/Web/Providers/WebServiceProvider.php";

        $blogProvider = $this->app->register(\AcmeTest\WebBlogPkg\Web\Providers\WebServiceProvider::class);
        if (method_exists($blogProvider, 'boot')) {
            $this->app->call([$blogProvider, 'boot']);
        }

        $shopProvider = $this->app->register(\AcmeTest\WebShopPkg\Web\Providers\WebServiceProvider::class);
        if (method_exists($shopProvider, 'boot')) {
            $this->app->call([$shopProvider, 'boot']);
        }

        $this->app['router']->getRoutes()->refreshNameLookups();

        $blogResponse = $this->get('/acme-test-web-blog-pkg');
        $blogResponse->assertStatus(200);
        $blogResponse->assertSee('Web Blog Pkg');

        $shopResponse = $this->get('/acme-test-web-shop-pkg');
        $shopResponse->assertStatus(200);
        $shopResponse->assertSee('Web Shop Pkg');
    }

    public function test_root_route_conflict_fails_with_diagnostic_when_two_packages_claim_root(): void
    {
        $pkgOneDir = $this->trackDirectory('packages/AcmeTest/RootPkgOne');
        $pkgTwoDir = $this->trackDirectory('packages/AcmeTest/RootPkgTwo');

        $this->artisan('laraseed:make-package AcmeTest/RootPkgOne')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/RootPkgOne')->assertExitCode(0);

        $this->artisan('laraseed:make-package AcmeTest/RootPkgTwo')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/RootPkgTwo')->assertExitCode(0);

        require_once "{$pkgOneDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgOneDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgOneDir}/src/Web/Providers/WebServiceProvider.php";

        require_once "{$pkgTwoDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgTwoDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgTwoDir}/src/Web/Providers/WebServiceProvider.php";

        // Configure both packages to claim the root route (prefix => '')
        config(['acmetest_root_pkg_one_web.prefix' => '']);
        config(['acmetest_root_pkg_two_web.prefix' => '']);
        config(['laraseed.web.root_owner' => null]);

        $providerOne = $this->app->register(\AcmeTest\RootPkgOne\Web\Providers\WebServiceProvider::class);
        $this->app->call([$providerOne, 'boot']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Route conflict: Package [acmetest_root_pkg_two] attempted to claim the root route [/], but it is already owned by [acmetest_root_pkg_one].');

        $providerTwo = $this->app->register(\AcmeTest\RootPkgTwo\Web\Providers\WebServiceProvider::class);
        $this->app->call([$providerTwo, 'boot']);
    }

    public function test_responsive_header_mobile_menu_and_dark_mode_render_in_view(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebResponsivePkg');
        $this->artisan('laraseed:make-package AcmeTest/WebResponsivePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebResponsivePkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        $provider = $this->app->register(\AcmeTest\WebResponsivePkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $response = $this->get(route('acmetest_web_responsive_pkg.web.home'));
        $response->assertStatus(200);

        // Assert mobile menu elements and accessibility
        $response->assertSee('id="mobile-menu"', false);
        $response->assertSee('data-action="toggle-mobile-menu"', false);
        $response->assertSee('aria-controls="mobile-menu"', false);
        $response->assertDontSee('onclick=', false);

        // Assert dark mode toggle
        $response->assertSee('aria-label="Toggle Dark Mode"', false);
        $response->assertSee('data-action="toggle-dark-mode"', false);

        // Assert Cairo typography
        $response->assertSee('font-cairo');
    }

    public function test_locale_switching_and_rtl_ltr_directionality(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebLocalePkg');
        $this->artisan('laraseed:make-package AcmeTest/WebLocalePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebLocalePkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        $provider = $this->app->register(\AcmeTest\WebLocalePkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        // Test English locale (LTR)
        $enResponse = $this->get(route('acmetest_web_locale_pkg.web.home', ['locale' => 'en']));
        $enResponse->assertStatus(200);
        $enResponse->assertSee('dir="ltr"', false);
        $enResponse->assertSee('lang="en"', false);

        // Test Arabic locale (RTL)
        $arResponse = $this->get(route('acmetest_web_locale_pkg.web.home', ['locale' => 'ar']));
        $arResponse->assertStatus(200);
        $arResponse->assertSee('dir="rtl"', false);
        $arResponse->assertSee('lang="ar"', false);
    }

    public function test_default_template_selection_uses_starter(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/DefaultTplPkg');
        $this->artisan('laraseed:make-package AcmeTest/DefaultTplPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/DefaultTplPkg')->assertExitCode(0);

        $config = require "{$pkgDir}/src/Web/Config/web.php";
        $this->assertSame('starter', $config['template']);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Web/Resources/views/home/index.blade.php"));
    }

    public function test_explicit_template_starter_selection(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ExplicitStarterPkg');
        $this->artisan('laraseed:make-package AcmeTest/ExplicitStarterPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/ExplicitStarterPkg --template=starter')->assertExitCode(0);

        $config = require "{$pkgDir}/src/Web/Config/web.php";
        $this->assertSame('starter', $config['template']);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Web/Resources/views/home/index.blade.php"));
    }

    public function test_rejection_of_unknown_templates_displays_available_templates(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/UnknownTplPkg');
        $this->artisan('laraseed:make-package AcmeTest/UnknownTplPkg')->assertExitCode(0);

        $composerBefore = file_get_contents("{$pkgDir}/composer.json");

        $this->artisan('laraseed:make-web AcmeTest/UnknownTplPkg --template=nonexistent')
            ->assertExitCode(1);

        $this->assertFalse($this->filesystem->isDirectory("{$pkgDir}/src/Web"));
        $this->assertSame($composerBefore, file_get_contents("{$pkgDir}/composer.json"));
    }

    public function test_configuration_caching_and_route_ownership(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebConfigCachePkg');
        $this->artisan('laraseed:make-package AcmeTest/WebConfigCachePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebConfigCachePkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        // Emulate cached configuration state
        config([
            'acmetest_web_config_cache_pkg_web' => [
                'template'   => 'starter',
                'prefix'     => 'cached-custom-prefix',
                'middleware' => ['web'],
                'auth_guard' => null,
            ],
            'krayin-vite.viters.acmetest_web_config_cache_pkg_web' => [
                'hot_file'                 => 'acmetest_web_config_cache_pkg-web-vite.hot',
                'build_directory'          => 'acme-test-web-config-cache-pkg/web/build',
                'package_assets_directory' => 'src/Web/Resources/assets',
            ],
        ]);

        $provider = $this->app->register(\AcmeTest\WebConfigCachePkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $response = $this->get('/cached-custom-prefix');
        $response->assertStatus(200);
        $response->assertSee('Web Config Cache Pkg');
    }

    public function test_public_mode_allows_unrestricted_guest_access_and_does_not_register_protected_routes(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebPublicAuthPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebPublicAuthPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebPublicAuthPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        $provider = $this->app->register(\AcmeTest\WebPublicAuthPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        // Public home works
        $response = $this->get(route('acmetest_web_public_auth_pkg.web.home'));
        $response->assertStatus(200);

        // Protected dashboard route is NOT registered in public mode
        $this->assertFalse(
            $this->app['router']->getRoutes()->hasNamedRoute('acmetest_web_public_auth_pkg.web.account.dashboard')
        );

        $dashboardResponse = $this->get('/acme-test-web-public-auth-pkg/account/dashboard');
        $dashboardResponse->assertStatus(404);

        // Header does not render login button or user controls
        $response->assertDontSee(trans('acmetest_web_public_auth_pkg_web::app.web.auth.login'));
        $response->assertDontSee(trans('acmetest_web_public_auth_pkg_web::app.web.auth.logout'));
    }

    public function test_auth_mode_guest_is_redirected_to_configured_named_login_route_and_registers_protected_route(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebAuthGuestPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebAuthGuestPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebAuthGuestPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        // Register dummy named login route
        $this->app['router']->get('mock-login', fn() => 'Mock Login Page')->name('mock.login');

        // Configure auth enabled with named login route
        config([
            'acmetest_web_auth_guest_pkg_web.auth' => [
                'enabled'          => true,
                'guard'            => 'user',
                'provider_package' => null,
                'routes'           => [
                    'login'  => 'mock.login',
                    'logout' => null,
                ],
            ],
        ]);

        $provider = $this->app->register(\AcmeTest\WebAuthGuestPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        // Protected dashboard route IS registered
        $this->assertTrue(
            $this->app['router']->getRoutes()->hasNamedRoute('acmetest_web_auth_guest_pkg.web.account.dashboard')
        );

        $response = $this->get(route('acmetest_web_auth_guest_pkg.web.account.dashboard'));
        $response->assertRedirect(route('mock.login'));
    }

    public function test_auth_mode_guest_json_request_receives_401_unauthenticated(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebAuthJsonPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebAuthJsonPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebAuthJsonPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        $this->app['router']->get('mock-login-json', fn() => 'Mock Login')->name('mock.login.json');

        config([
            'acmetest_web_auth_json_pkg_web.auth' => [
                'enabled'          => true,
                'guard'            => 'user',
                'provider_package' => null,
                'routes'           => [
                    'login'  => 'mock.login.json',
                    'logout' => null,
                ],
            ],
        ]);

        $provider = $this->app->register(\AcmeTest\WebAuthJsonPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $response = $this->getJson(route('acmetest_web_auth_json_pkg.web.account.dashboard'));
        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_auth_mode_authenticated_user_accesses_protected_dashboard_route(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebAuthUserPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebAuthUserPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebAuthUserPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        $this->app['router']->get('mock-login-user', fn() => 'Mock Login')->name('mock.login.user');

        config([
            'acmetest_web_auth_user_pkg_web.auth' => [
                'enabled'          => true,
                'guard'            => 'user',
                'provider_package' => null,
                'routes'           => [
                    'login'  => 'mock.login.user',
                    'logout' => null,
                ],
            ],
        ]);

        $provider = $this->app->register(\AcmeTest\WebAuthUserPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $user = new \Webkul\User\Models\User([
            'name'  => 'Sarah Jenkins',
            'email' => 'sarah@example.com',
        ]);

        $response = $this->actingAs($user, 'user')->get(route('acmetest_web_auth_user_pkg.web.account.dashboard'));
        $response->assertStatus(200);
        $response->assertSee('Sarah Jenkins');
        $response->assertSee('Active Session');
    }

    public function test_auth_mode_throws_diagnostic_exception_when_guard_is_undefined(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebAuthUndefinedGuardPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebAuthUndefinedGuardPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebAuthUndefinedGuardPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        config([
            'acmetest_web_auth_undefined_guard_pkg_web.auth' => [
                'enabled'          => true,
                'guard'            => 'unknown_guard',
                'provider_package' => null,
                'routes'           => ['login' => 'dummy'],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Authentication configuration error: Package [acmetest_web_auth_undefined_guard_pkg] specifies guard [unknown_guard], which is not defined in config/auth.php.');

        $provider = $this->app->register(\AcmeTest\WebAuthUndefinedGuardPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
    }

    public function test_auth_mode_throws_diagnostic_exception_when_provider_package_is_not_enabled(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebAuthProviderPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebAuthProviderPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebAuthProviderPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        config([
            'laraseed.optional_packages.enabled' => ['other_pkg'],
            'acmetest_web_auth_provider_pkg_web.auth' => [
                'enabled'          => true,
                'guard'            => 'user',
                'provider_package' => 'missing_customer_pkg',
                'routes'           => ['login' => 'dummy'],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Authentication configuration error: Package [acmetest_web_auth_provider_pkg] requires authentication provider package [missing_customer_pkg], which is not currently enabled in LARASEED_OPTIONAL_PACKAGES.');

        $provider = $this->app->register(\AcmeTest\WebAuthProviderPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
    }

    public function test_auth_mode_throws_diagnostic_exception_when_login_route_is_missing(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebAuthMissingLoginPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebAuthMissingLoginPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebAuthMissingLoginPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        config([
            'acmetest_web_auth_missing_login_pkg_web.auth' => [
                'enabled'          => true,
                'guard'            => 'user',
                'provider_package' => null,
                'routes'           => ['login' => null],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Authentication configuration error: Package [acmetest_web_auth_missing_login_pkg] requires a configured login route when authentication is enabled.');

        $provider = $this->app->register(\AcmeTest\WebAuthMissingLoginPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
    }

    public function test_auth_mode_middleware_throws_diagnostic_exception_when_login_route_does_not_exist(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebAuthMissingLoginRoutePkg');
        $this->artisan('laraseed:make-package AcmeTest/WebAuthMissingLoginRoutePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebAuthMissingLoginRoutePkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        config([
            'acmetest_web_auth_missing_login_route_pkg_web.auth' => [
                'enabled'          => true,
                'guard'            => 'user',
                'provider_package' => null,
                'routes'           => ['login' => 'nonexistent.login.route'],
            ],
        ]);

        $provider = $this->app->register(\AcmeTest\WebAuthMissingLoginRoutePkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Authentication configuration error: Package [acmetest_web_auth_missing_login_route_pkg] requires a valid named login route, but route [nonexistent.login.route] is not defined.');

        $this->withoutExceptionHandling()->get(route('acmetest_web_auth_missing_login_route_pkg.web.account.dashboard'));
    }

    public function test_auth_mode_header_renders_csrf_post_logout_and_no_get_logout(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebAuthHeaderPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebAuthHeaderPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebAuthHeaderPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        $this->app['router']->get('portal/login', fn() => 'Portal Login')->name('portal.login');
        $this->app['router']->post('portal/logout', fn() => 'Portal Logout')->name('portal.logout');

        config([
            'acmetest_web_auth_header_pkg_web.auth' => [
                'enabled'          => true,
                'guard'            => 'user',
                'provider_package' => null,
                'routes'           => [
                    'login'  => 'portal.login',
                    'logout' => 'portal.logout',
                ],
            ],
        ]);

        $provider = $this->app->register(\AcmeTest\WebAuthHeaderPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        // Guest view
        $guestResponse = $this->get(route('acmetest_web_auth_header_pkg.web.home'));
        $guestResponse->assertStatus(200);
        $guestResponse->assertSee(route('portal.login'));
        $guestResponse->assertSee(trans('acmetest_web_auth_header_pkg_web::app.web.auth.login'));
        $guestResponse->assertDontSee(trans('acmetest_web_auth_header_pkg_web::app.web.auth.logout'));

        // Authenticated view
        $user = new \Webkul\User\Models\User([
            'name'  => 'Alex Morgan',
            'email' => 'alex@example.com',
        ]);

        $authResponse = $this->actingAs($user, 'user')->get(route('acmetest_web_auth_header_pkg.web.home'));
        $authResponse->assertStatus(200);
        $authResponse->assertSee(route('acmetest_web_auth_header_pkg.web.account.dashboard'));
        $authResponse->assertSee(trans('acmetest_web_auth_header_pkg_web::app.web.auth.dashboard'));
        $authResponse->assertSee(trans('acmetest_web_auth_header_pkg_web::app.web.auth.logout'));
        $authResponse->assertSee(route('portal.logout'));
        $authResponse->assertSee('name="_token"', false); // CSRF token input in logout form
        $authResponse->assertDontSee('<a href="' . route('portal.logout') . '"', false); // No GET link
    }

    public function test_auth_mode_throws_diagnostic_exception_when_logout_route_does_not_support_post(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebAuthIncompatibleLogoutPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebAuthIncompatibleLogoutPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebAuthIncompatibleLogoutPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        $this->app['router']->get('portal/incompatible-logout', fn() => 'Get Only Logout')->name('portal.incompatible_logout');
        $this->app['router']->get('portal/login-valid', fn() => 'Valid Login')->name('portal.login_valid');

        config([
            'acmetest_web_auth_incompatible_logout_pkg_web.auth' => [
                'enabled'          => true,
                'guard'            => 'user',
                'provider_package' => null,
                'routes'           => [
                    'login'  => 'portal.login_valid',
                    'logout' => 'portal.incompatible_logout',
                ],
            ],
        ]);

        $provider = $this->app->register(\AcmeTest\WebAuthIncompatibleLogoutPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $user = new \Webkul\User\Models\User([
            'name'  => 'Alex Morgan',
            'email' => 'alex@example.com',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Authentication configuration error: Package [acmetest_web_auth_incompatible_logout_pkg] configured logout route [portal.incompatible_logout], but it does not support HTTP POST with CSRF protection.');

        $this->withoutExceptionHandling()->actingAs($user, 'user')->get(route('acmetest_web_auth_incompatible_logout_pkg.web.account.dashboard'));
    }

    public function test_admin_auth_isolation_and_redirection_resolver_routing(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebAdminIsolationPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebAdminIsolationPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebAdminIsolationPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        $this->app['router']->get('portal-auth/login', fn() => 'Portal Login')->name('portal_auth.login');

        config([
            'acmetest_web_admin_isolation_pkg_web.auth' => [
                'enabled'          => true,
                'guard'            => 'user',
                'provider_package' => null,
                'routes'           => [
                    'login'  => 'portal_auth.login',
                    'logout' => null,
                ],
            ],
        ]);

        $provider = $this->app->register(\AcmeTest\WebAdminIsolationPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $resolver = $this->app->make(\Webkul\Core\Contracts\AuthenticationRedirectResolver::class);

        // Web package request resolves to Web package login route
        $webRequest = \Illuminate\Http\Request::create('/acme-test-web-admin-isolation-pkg/account/dashboard', 'GET');
        $this->assertSame(route('portal_auth.login'), $resolver->resolve($webRequest));

        // Admin request resolves to admin login route (priority -100 fallback)
        $adminRequest = \Illuminate\Http\Request::create('/admin/leads', 'GET');
        $this->assertSame(route('admin.session.create'), $resolver->resolve($adminRequest));
    }

    public function test_multiple_web_packages_with_isolated_auth_configurations(): void
    {
        $pkgPublicDir = $this->trackDirectory('packages/AcmeTest/MultiPublicPkg');
        $pkgAuthDir = $this->trackDirectory('packages/AcmeTest/MultiAuthPkg');

        $this->artisan('laraseed:make-package AcmeTest/MultiPublicPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/MultiPublicPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-package AcmeTest/MultiAuthPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/MultiAuthPkg')->assertExitCode(0);

        require_once "{$pkgPublicDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgPublicDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgPublicDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgPublicDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgPublicDir}/src/Web/Providers/WebServiceProvider.php";

        require_once "{$pkgAuthDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgAuthDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgAuthDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgAuthDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgAuthDir}/src/Web/Providers/WebServiceProvider.php";

        $this->app['router']->get('multi/login', fn() => 'Multi Login')->name('multi.login');

        // MultiPublicPkg: auth disabled
        config(['acmetest_multi_public_pkg_web.auth.enabled' => false]);

        // MultiAuthPkg: auth enabled
        config([
            'acmetest_multi_auth_pkg_web.auth' => [
                'enabled'          => true,
                'guard'            => 'user',
                'provider_package' => null,
                'routes'           => ['login' => 'multi.login', 'logout' => null],
            ],
        ]);

        $publicProvider = $this->app->register(\AcmeTest\MultiPublicPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$publicProvider, 'boot']);

        $authProvider = $this->app->register(\AcmeTest\MultiAuthPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$authProvider, 'boot']);

        $this->app['router']->getRoutes()->refreshNameLookups();

        // Public package has no dashboard route registered
        $this->assertFalse(
            $this->app['router']->getRoutes()->hasNamedRoute('acmetest_multi_public_pkg.web.account.dashboard')
        );

        // Auth package has dashboard route registered
        $this->assertTrue(
            $this->app['router']->getRoutes()->hasNamedRoute('acmetest_multi_auth_pkg.web.account.dashboard')
        );

        // Guest accessing auth dashboard is redirected to multi.login
        $response = $this->get(route('acmetest_multi_auth_pkg.web.account.dashboard'));
        $response->assertRedirect(route('multi.login'));
    }

    public function test_root_authenticated_web_package_does_not_intercept_admin_guest_redirects(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/RootWebAuthPkg');
        $this->artisan('laraseed:make-package AcmeTest/RootWebAuthPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/RootWebAuthPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        $this->app['router']->get('root-member/login', fn() => 'Root Member Login')->name('root_member.login');

        // Claim root prefix with authentication enabled
        config([
            'acmetest_root_web_auth_pkg_web.prefix' => '',
            'acmetest_root_web_auth_pkg_web.auth'   => [
                'enabled'          => true,
                'guard'            => 'user',
                'provider_package' => null,
                'routes'           => [
                    'login'  => 'root_member.login',
                    'logout' => null,
                ],
            ],
            'laraseed.web.root_owner' => null,
        ]);

        $provider = $this->app->register(\AcmeTest\RootWebAuthPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        // 1. Guest request to Root Web package protected route redirects to root_member.login
        $webResponse = $this->get('/account/dashboard');
        $webResponse->assertRedirect(route('root_member.login'));

        // 2. Resolver resolution for Admin request does NOT get hijacked by Root Web package
        $resolver = $this->app->make(\Webkul\Core\Contracts\AuthenticationRedirectResolver::class);
        $adminRequest = \Illuminate\Http\Request::create('/admin/leads', 'GET');
        $this->assertSame(route('admin.session.create'), $resolver->resolve($adminRequest));

        // 3. Resolver resolution for root web request resolves to root_member.login
        $rootRequest = \Illuminate\Http\Request::create('/account/dashboard', 'GET');
        $rootRoute = $this->app['router']->getRoutes()->getByName('acmetest_root_web_auth_pkg.web.account.dashboard');
        $rootRequest->setRouteResolver(fn() => $rootRoute);
        $this->assertSame(route('root_member.login'), $resolver->resolve($rootRequest));
    }

    public function test_two_authenticated_web_packages_redirect_isolation(): void
    {
        $pkgOneDir = $this->trackDirectory('packages/AcmeTest/IsoShopPkg');
        $pkgTwoDir = $this->trackDirectory('packages/AcmeTest/IsoPortalPkg');

        $this->artisan('laraseed:make-package AcmeTest/IsoShopPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/IsoShopPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-package AcmeTest/IsoPortalPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/IsoPortalPkg')->assertExitCode(0);

        require_once "{$pkgOneDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgOneDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgOneDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgOneDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgOneDir}/src/Web/Providers/WebServiceProvider.php";

        require_once "{$pkgTwoDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgTwoDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgTwoDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgTwoDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgTwoDir}/src/Web/Providers/WebServiceProvider.php";

        $this->app['router']->get('shop/login', fn() => 'Shop Login')->name('shop.login');
        $this->app['router']->get('portal/login', fn() => 'Portal Login')->name('portal.login');

        config([
            'acmetest_iso_shop_pkg_web.auth' => [
                'enabled'          => true,
                'guard'            => 'user',
                'provider_package' => null,
                'routes'           => ['login' => 'shop.login', 'logout' => null],
            ],
            'acmetest_iso_portal_pkg_web.auth' => [
                'enabled'          => true,
                'guard'            => 'user',
                'provider_package' => null,
                'routes'           => ['login' => 'portal.login', 'logout' => null],
            ],
        ]);

        $shopProvider = $this->app->register(\AcmeTest\IsoShopPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$shopProvider, 'boot']);

        $portalProvider = $this->app->register(\AcmeTest\IsoPortalPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$portalProvider, 'boot']);

        $this->app['router']->getRoutes()->refreshNameLookups();

        // Guest requesting shop dashboard redirects to shop.login
        $shopResponse = $this->get(route('acmetest_iso_shop_pkg.web.account.dashboard'));
        $shopResponse->assertRedirect(route('shop.login'));

        // Guest requesting portal dashboard redirects to portal.login
        $portalResponse = $this->get(route('acmetest_iso_portal_pkg.web.account.dashboard'));
        $portalResponse->assertRedirect(route('portal.login'));
    }

    public function test_guard_with_undefined_user_provider_throws_diagnostic_exception(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/BrokenProviderPkg');
        $this->artisan('laraseed:make-package AcmeTest/BrokenProviderPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/BrokenProviderPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        // Define a guard in config/auth.php with an undefined provider
        config([
            'auth.guards.broken_guard' => [
                'driver'   => 'session',
                'provider' => 'non_existent_provider',
            ],
            'acmetest_broken_provider_pkg_web.auth' => [
                'enabled'          => true,
                'guard'            => 'broken_guard',
                'provider_package' => null,
                'routes'           => ['login' => 'dummy'],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Authentication configuration error: Package [acmetest_broken_provider_pkg] specifies guard [broken_guard] with undefined user provider [non_existent_provider] in config/auth.php.');

        $provider = $this->app->register(\AcmeTest\BrokenProviderPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
    }

    public function test_reusable_ui_components_render_correctly(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebUiPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebUiPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebUiPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        $provider = $this->app->register(\AcmeTest\WebUiPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        // Render card component
        $cardHtml = \Illuminate\Support\Facades\Blade::render(
            '<x-acmetest_web_ui_pkg_web::card title="Card Title" subtitle="Card Subtitle"><p>Card Content</p></x-acmetest_web_ui_pkg_web::card>'
        );
        $this->assertStringContainsString('Card Title', $cardHtml);
        $this->assertStringContainsString('Card Subtitle', $cardHtml);
        $this->assertStringContainsString('Card Content', $cardHtml);
        $this->assertStringContainsString('rounded-2xl', $cardHtml);

        // Render button component (link and button variants)
        $btnLinkHtml = \Illuminate\Support\Facades\Blade::render(
            '<x-acmetest_web_ui_pkg_web::button href="/test-url" variant="primary" size="lg">Click Here</x-acmetest_web_ui_pkg_web::button>'
        );
        $this->assertStringContainsString('<a href="/test-url"', $btnLinkHtml);
        $this->assertStringContainsString('Click Here', $btnLinkHtml);

        $btnSubmitHtml = \Illuminate\Support\Facades\Blade::render(
            '<x-acmetest_web_ui_pkg_web::button type="submit" variant="danger" size="sm">Delete</x-acmetest_web_ui_pkg_web::button>'
        );
        $this->assertStringContainsString('<button type="submit"', $btnSubmitHtml);
        $this->assertStringContainsString('bg-red-600', $btnSubmitHtml);

        // Render modal component
        $modalHtml = \Illuminate\Support\Facades\Blade::render(
            '<x-acmetest_web_ui_pkg_web::modal id="test-modal" title="Modal Header"><p>Modal Body</p></x-acmetest_web_ui_pkg_web::modal>'
        );
        $this->assertStringContainsString('id="test-modal"', $modalHtml);
        $this->assertStringContainsString('Modal Header', $modalHtml);
        $this->assertStringContainsString('Modal Body', $modalHtml);

        // Render form control group
        $formHtml = \Illuminate\Support\Facades\Blade::render(
            '<x-acmetest_web_ui_pkg_web::form.control-group name="email" label="Email Address" :required="true"><input type="email" name="email" /></x-acmetest_web_ui_pkg_web::form.control-group>'
        );
        $this->assertStringContainsString('Email Address', $formHtml);
        $this->assertStringContainsString('*', $formHtml);
        $this->assertStringContainsString('for="email"', $formHtml);
    }

    public function test_dynamic_navigation_and_active_state_rendering(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebNavPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebNavPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebNavPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        $provider = $this->app->register(\AcmeTest\WebNavPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        // 1. Visit Home: Home link should have active styling
        $homeResponse = $this->get(route('acmetest_web_nav_pkg.web.home'));
        $homeResponse->assertStatus(200);
        $homeResponse->assertSee('border-b-2 border-[var(--brand-color)]', false);

        // 2. Visit About page: About link should have active styling
        $aboutResponse = $this->get(route('acmetest_web_nav_pkg.web.pages.show', ['page' => 'about']));
        $aboutResponse->assertStatus(200);
        $aboutResponse->assertSee('border-b-2 border-[var(--brand-color)]', false);
    }

    public function test_branding_customization_via_package_configuration(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebBrandPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebBrandPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebBrandPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        config([
            'acmetest_web_brand_pkg_web.branding' => [
                'name'  => 'Acme Enterprise Portal',
                'color' => '#E11D48',
                'logo'  => '/images/custom-logo.svg',
            ],
        ]);

        $provider = $this->app->register(\AcmeTest\WebBrandPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $response = $this->get(route('acmetest_web_brand_pkg.web.home'));
        $response->assertStatus(200);
        $response->assertSee('Acme Enterprise Portal');
        $response->assertSee('src="/images/custom-logo.svg"', false);
        $response->assertSee(route('acmetest_web_brand_pkg.web.branding.css'), false);
        $response->assertDontSee('<style>', false);

        $cssResponse = $this->get(route('acmetest_web_brand_pkg.web.branding.css'));
        $cssResponse->assertStatus(200);
        $cssResponse->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $cssResponse->assertSee('--brand-color: #E11D48;', false);
    }

    public function test_overlapping_prefix_redirect_isolation_does_not_intercept_sub_admin_routes(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebOverlapShopPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebOverlapShopPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebOverlapShopPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        $this->app['router']->get('shop-custom/login', fn() => 'Shop Login')->name('shop_custom.login');

        // Register an overlapping Admin route at shop-custom/admin/orders
        $this->app['router']->get('shop-custom/admin/orders', fn() => 'Admin Orders')->name('admin.orders.index');

        config([
            'acmetest_web_overlap_shop_pkg_web.prefix' => 'shop-custom',
            'acmetest_web_overlap_shop_pkg_web.auth'   => [
                'enabled'          => true,
                'guard'            => 'user',
                'provider_package' => null,
                'routes'           => [
                    'login'  => 'shop_custom.login',
                    'logout' => null,
                ],
            ],
        ]);

        $provider = $this->app->register(\AcmeTest\WebOverlapShopPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $resolver = $this->app->make(\Webkul\Core\Contracts\AuthenticationRedirectResolver::class);

        // 1. Request to shop-custom/admin/orders (Admin route with overlapping prefix) must resolve to Admin login
        $adminOrderRequest = \Illuminate\Http\Request::create('/shop-custom/admin/orders', 'GET');
        $adminOrderRoute = $this->app['router']->getRoutes()->getByName('admin.orders.index');
        $adminOrderRequest->setRouteResolver(fn() => $adminOrderRoute);
        $this->assertSame(route('admin.session.create'), $resolver->resolve($adminOrderRequest));

        // 2. Request to shop-custom/account/dashboard (Web package route) must resolve to shop_custom.login
        $webRequest = \Illuminate\Http\Request::create('/shop-custom/account/dashboard', 'GET');
        $webRoute = $this->app['router']->getRoutes()->getByName('acmetest_web_overlap_shop_pkg.web.account.dashboard');
        $webRequest->setRouteResolver(fn() => $webRoute);
        $this->assertSame(route('shop_custom.login'), $resolver->resolve($webRequest));
    }

    public function test_custom_page_creation_flow(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebCustomPagePkg');
        $this->artisan('laraseed:make-package AcmeTest/WebCustomPagePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebCustomPagePkg')->assertExitCode(0);

        // Developer creates a new custom page view
        $customViewPath = "{$pkgDir}/src/Web/Resources/views/pricing/index.blade.php";
        $this->filesystem->ensureDirectoryExists(dirname($customViewPath));
        file_put_contents(
            $customViewPath,
            '<x-acmetest_web_custom_page_pkg_web::layouts><x-acmetest_web_custom_page_pkg_web::section title="Pricing Plans"><p>Enterprise Tier $99/mo</p></x-acmetest_web_custom_page_pkg_web::section></x-acmetest_web_custom_page_pkg_web::layouts>'
        );

        // Developer registers a custom route
        $routesPath = "{$pkgDir}/src/Web/Routes/web.php";
        $existingRoutes = file_get_contents($routesPath);
        $newRoutes = str_replace(
            "Route::get('pages/{page?}'",
            "Route::get('pricing', fn() => view('acmetest_web_custom_page_pkg_web::pricing.index'))->name('pricing');\n    Route::get('pages/{page?}'",
            $existingRoutes
        );
        file_put_contents($routesPath, $newRoutes);

        // Developer adds navigation item to web.php config
        config([
            'acmetest_web_custom_page_pkg_web.navigation.pricing' => [
                'name'  => 'Pricing',
                'route' => 'acmetest_web_custom_page_pkg.web.pricing',
                'sort'  => 5,
            ],
        ]);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        $provider = $this->app->register(\AcmeTest\WebCustomPagePkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $response = $this->get(route('acmetest_web_custom_page_pkg.web.pricing'));
        $response->assertStatus(200);
        $response->assertSee('Pricing Plans');
        $response->assertSee('Enterprise Tier $99/mo');
        $response->assertSee('Pricing');
    }

    public function test_unbuilt_assets_render_actionable_diagnostic_banner(): void
    {
        $this->trackDirectory('packages/AcmeTest/UnbuiltTestPkg');
        $this->artisan('laraseed:make-package AcmeTest/UnbuiltTestPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/UnbuiltTestPkg')->assertExitCode(0);

        $provider = $this->app->register(\AcmeTest\UnbuiltTestPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $response = $this->get(route('acmetest_unbuilt_test_pkg.web.home'));
        $response->assertStatus(200);
        $response->assertSee('[Laraseed Diagnostic] Frontend assets for', false);
        $response->assertSee('npm run build', false);
        $response->assertSee('packages/AcmeTest/UnbuiltTestPkg', false);
    }

    public function test_section_and_card_components_support_description_and_subtitle_props(): void
    {
        $this->trackDirectory('packages/AcmeTest/PropsAliasPkg');
        $this->artisan('laraseed:make-package AcmeTest/PropsAliasPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/PropsAliasPkg')->assertExitCode(0);

        $provider = $this->app->register(\AcmeTest\PropsAliasPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);

        // Section with subtitle vs description
        $sectionSub = \Illuminate\Support\Facades\Blade::render(
            '<x-acmetest_props_alias_pkg_web::section title="Title A" subtitle="Subtitle A">Content</x-acmetest_props_alias_pkg_web::section>'
        );
        $this->assertStringContainsString('Subtitle A', $sectionSub);

        $sectionDesc = \Illuminate\Support\Facades\Blade::render(
            '<x-acmetest_props_alias_pkg_web::section title="Title B" description="Description B">Content</x-acmetest_props_alias_pkg_web::section>'
        );
        $this->assertStringContainsString('Description B', $sectionDesc);

        // Card with subtitle vs description
        $cardSub = \Illuminate\Support\Facades\Blade::render(
            '<x-acmetest_props_alias_pkg_web::card title="Card A" subtitle="Card Subtitle A">Content</x-acmetest_props_alias_pkg_web::card>'
        );
        $this->assertStringContainsString('Card Subtitle A', $cardSub);

        $cardDesc = \Illuminate\Support\Facades\Blade::render(
            '<x-acmetest_props_alias_pkg_web::card title="Card B" description="Card Description B">Content</x-acmetest_props_alias_pkg_web::card>'
        );
        $this->assertStringContainsString('Card Description B', $cardDesc);
    }

    public function test_vite_config_contains_laravel_plugin_and_hot_file(): void
    {
        $this->trackDirectory('packages/AcmeTest/ViteHotPkg');
        $this->artisan('laraseed:make-package AcmeTest/ViteHotPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/ViteHotPkg')->assertExitCode(0);

        $viteConfig = file_get_contents(base_path('packages/AcmeTest/ViteHotPkg/vite.config.js'));
        $this->assertStringContainsString('laravel({', $viteConfig);
        $this->assertStringContainsString('acmetest_vite_hot_pkg-web-vite.hot', $viteConfig);
        $this->assertStringContainsString('acme-test-vite-hot-pkg/web/build', $viteConfig);
    }
}


