<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Laraseed\PackageGenerator\Tests\TestCase;
use Symfony\Component\Process\Process;

class RootMountedDefaultWebPackageTest extends TestCase
{
    protected Filesystem $filesystem;

    protected array $createdDirectories = [];

    protected ?string $envBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem;
        $this->createdDirectories = [];

        if (file_exists(base_path('.env'))) {
            $this->envBackup = (string) file_get_contents(base_path('.env'));
        }

        $this->clearBootstrapCache();

        config([
            'laraseed.default_web_package' => null,
            'laraseed.web.default_package' => null,
            'laraseed.web.root_owner'      => null,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDirectories as $dir) {
            if ($this->filesystem->isDirectory($dir)) {
                $this->filesystem->deleteDirectory($dir);
            }
        }

        if ($this->envBackup !== null) {
            file_put_contents(base_path('.env'), $this->envBackup);
        }

        $this->clearBootstrapCache();

        config([
            'laraseed.default_web_package' => null,
            'laraseed.web.default_package' => null,
            'laraseed.web.root_owner'      => null,
        ]);

        parent::tearDown();
    }

    protected function clearBootstrapCache(): void
    {
        $cacheDir = base_path('bootstrap/cache');
        foreach (['config.php', 'routes-v7.php', 'events.php', 'packages.php', 'services.php'] as $file) {
            $path = $cacheDir . DIRECTORY_SEPARATOR . $file;
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    protected function trackDirectory(string $path): string
    {
        $fullPath = base_path($path);
        $this->createdDirectories[] = $fullPath;

        return $fullPath;
    }

    /**
     * Scenario 1: Empty application with zero optional Web packages serves fallback view at /
     */
    public function test_empty_app_serves_fallback_landing_page_at_root(): void
    {
        config([
            'laraseed.default_web_package' => null,
            'laraseed.web.default_package' => null,
            'laraseed.web.root_owner'      => null,
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertViewIs('web.fallback');
        $response->assertSee('Platform Active');
        $response->assertSee('The application core is online.');
        $response->assertDontSee('Fatal error');
    }

    /**
     * Scenario 2: Default Web package homepage responds directly at / (HTTP 200, zero redirects).
     */
    public function test_default_web_package_homepage_serves_directly_at_root_with_200_ok(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeMount/CampusHubPkg');
        $this->artisan('laraseed:make-package AcmeMount/CampusHubPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeMount/CampusHubPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        config([
            'laraseed.optional_packages.enabled' => ['campus_hub_pkg'],
            'laraseed.default_web_package'       => 'campus_hub_pkg',
            'laraseed.web.root_owner'            => null,
        ]);

        $provider = $this->app->register(\AcmeMount\CampusHubPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        // 1. GET / directly renders the package homepage without any redirect
        $response = $this->get('/');
        $response->assertOk();
        $response->assertViewIs('acmemount_campus_hub_pkg_web::home.index');
        $response->assertSee(trans('acmemount_campus_hub_pkg_web::app.web.title'));

        // 2. Assert root owner is claimed by this package
        $this->assertSame('acmemount_campus_hub_pkg', config('laraseed.web.root_owner'));
    }

    /**
     * Scenario 3: Default Web package internal pages serve under root without package prefix.
     */
    public function test_default_web_package_internal_pages_serve_under_root_without_package_slug(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeMount/PortalPagesPkg');
        $this->artisan('laraseed:make-package AcmeMount/PortalPagesPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeMount/PortalPagesPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        config([
            'laraseed.optional_packages.enabled' => ['portal_pages_pkg'],
            'laraseed.default_web_package'       => 'portal_pages_pkg',
            'laraseed.web.root_owner'            => null,
        ]);

        $provider = $this->app->register(\AcmeMount\PortalPagesPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        // 1. Pages show route: /pages/about
        $pageResponse = $this->get('/pages/about');
        $pageResponse->assertOk();
        $pageResponse->assertViewIs('acmemount_portal_pages_pkg_web::pages.show');
        $pageResponse->assertSee('about');

        // 2. Branding stylesheet: /branding.css
        $cssResponse = $this->get('/branding.css');
        $cssResponse->assertOk();
        $cssResponse->assertHeader('Content-Type', 'text/css; charset=UTF-8');
    }

    /**
     * Scenario 4: Default Web package named routes generate root-based URLs.
     */
    public function test_default_web_package_named_routes_generate_root_based_urls(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeMount/UrlCheckPkg');
        $this->artisan('laraseed:make-package AcmeMount/UrlCheckPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeMount/UrlCheckPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        config([
            'laraseed.optional_packages.enabled' => ['url_check_pkg'],
            'laraseed.default_web_package'       => 'url_check_pkg',
            'laraseed.web.root_owner'            => null,
        ]);

        $provider = $this->app->register(\AcmeMount\UrlCheckPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        // Home URL
        $homeUrl = route('acmemount_url_check_pkg.web.home');
        $this->assertSame(rtrim(url('/'), '/'), rtrim($homeUrl, '/'));

        // Page URL
        $pageUrl = route('acmemount_url_check_pkg.web.pages.show', ['page' => 'contact']);
        $this->assertSame(url('/pages/contact'), $pageUrl);

        // Branding URL
        $cssUrl = route('acmemount_url_check_pkg.web.branding.css');
        $this->assertSame(url('/branding.css'), $cssUrl);
    }

    /**
     * Scenario 5: Non-default packages retain isolated prefix URLs.
     */
    public function test_non_default_packages_retain_isolated_prefix_urls(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeMount/StoreFrontPkg');
        $this->artisan('laraseed:make-package AcmeMount/StoreFrontPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeMount/StoreFrontPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        // Not the default package
        config([
            'laraseed.optional_packages.enabled' => ['store_front_pkg'],
            'laraseed.default_web_package'       => 'other_pkg',
            'laraseed.web.root_owner'            => null,
        ]);

        $provider = $this->app->register(\AcmeMount\StoreFrontPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        // Must retain isolated slug prefix
        $homeUrl = route('acmemount_store_front_pkg.web.home');
        $this->assertSame(url('/acme-mount-store-front-pkg'), $homeUrl);

        $response = $this->get('/acme-mount-store-front-pkg');
        $response->assertOk();
        $response->assertViewIs('acmemount_store_front_pkg_web::home.index');

        $pageResponse = $this->get('/acme-mount-store-front-pkg/pages/about');
        $pageResponse->assertOk();
        $pageResponse->assertViewIs('acmemount_store_front_pkg_web::pages.show');
    }

    /**
     * Scenario 6: Switching defaults moves root mount to the new default package.
     */
    public function test_switching_defaults_moves_root_mount(): void
    {
        $pkg1 = $this->trackDirectory('packages/AcmeMount/MainSitePkg');
        $pkg2 = $this->trackDirectory('packages/AcmeMount/AltSitePkg');
        $this->artisan('laraseed:make-package AcmeMount/MainSitePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeMount/MainSitePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-package AcmeMount/AltSitePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeMount/AltSitePkg')->assertExitCode(0);

        require_once "{$pkg1}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkg1}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkg1}/src/Web/Providers/WebServiceProvider.php";

        require_once "{$pkg2}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkg2}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkg2}/src/Web/Providers/WebServiceProvider.php";

        // Phase 1: MainSite is default
        config([
            'laraseed.optional_packages.enabled' => ['main_site_pkg', 'alt_site_pkg'],
            'laraseed.default_web_package'       => 'main_site_pkg',
            'laraseed.web.root_owner'            => null,
        ]);

        $p1 = $this->app->register(\AcmeMount\MainSitePkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$p1, 'boot']);
        $p2 = $this->app->register(\AcmeMount\AltSitePkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$p2, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->assertSame('acmemount_main_site_pkg', config('laraseed.web.root_owner'));
        $this->assertSame(rtrim(url('/'), '/'), rtrim(route('acmemount_main_site_pkg.web.home'), '/'));
        $this->assertSame(url('/acme-mount-alt-site-pkg'), route('acmemount_alt_site_pkg.web.home'));

        $this->get('/')->assertOk()->assertViewIs('acmemount_main_site_pkg_web::home.index');
        $this->get('/acme-mount-alt-site-pkg')->assertOk()->assertViewIs('acmemount_alt_site_pkg_web::home.index');
    }

    /**
     * Scenario 7: Clearing default restores fallback page at /.
     */
    public function test_clearing_default_restores_fallback_page(): void
    {
        config([
            'laraseed.default_web_package' => null,
            'laraseed.web.default_package' => null,
            'laraseed.web.root_owner'      => null,
        ]);

        $this->artisan('laraseed:web-default --clear')
            ->assertExitCode(0)
            ->expectsOutputToContain('Root URL [/] will now serve the fallback landing view');

        $response = $this->get('/');
        $response->assertOk();
        $response->assertViewIs('web.fallback');
    }

    /**
     * Scenario 8: Disabled packages cannot remain root-mounted or serve /.
     */
    public function test_disabled_package_cannot_serve_root(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeMount/GhostPkgMnt');
        $this->artisan('laraseed:make-package AcmeMount/GhostPkgMnt')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeMount/GhostPkgMnt')->assertExitCode(0);

        // Package is disabled in LARASEED_OPTIONAL_PACKAGES
        config([
            'laraseed.optional_packages.enabled' => [],
            'laraseed.default_web_package'       => 'ghost_pkg_mnt',
            'laraseed.web.root_owner'            => null,
        ]);

        $response = $this->get('/');
        $response->assertOk();
        $response->assertViewIs('web.fallback');
    }

    /**
     * Scenario 9: Protected core routes cannot be overwritten or shadowed.
     */
    public function test_protected_routes_remain_fully_functional(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeMount/SecureRootPkg');
        $this->artisan('laraseed:make-package AcmeMount/SecureRootPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeMount/SecureRootPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        config([
            'laraseed.optional_packages.enabled' => ['secure_root_pkg'],
            'laraseed.default_web_package'       => 'secure_root_pkg',
            'laraseed.web.root_owner'            => null,
        ]);

        $provider = $this->app->register(\AcmeMount\SecureRootPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        // 1. Admin login remains accessible
        $adminLogin = $this->get('/admin/login');
        $adminLogin->assertOk();

        // 2. Admin redirect remains functional
        $adminRedirect = $this->get('/admin');
        $adminRedirect->assertRedirect('/admin/login');

        // 3. Root URL serves package homepage
        $rootResp = $this->get('/');
        $rootResp->assertOk();
        $rootResp->assertViewIs('acmemount_secure_root_pkg_web::home.index');
    }

    /**
     * Scenario 10: Attempting to claim root route when already owned throws RuntimeException.
     */
    public function test_multiple_root_claims_throw_runtime_exception(): void
    {
        $pkg1 = $this->trackDirectory('packages/AcmeMount/ClaimOnePkg');
        $pkg2 = $this->trackDirectory('packages/AcmeMount/ClaimTwoPkg');
        $this->artisan('laraseed:make-package AcmeMount/ClaimOnePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeMount/ClaimOnePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-package AcmeMount/ClaimTwoPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeMount/ClaimTwoPkg')->assertExitCode(0);

        require_once "{$pkg1}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkg1}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkg1}/src/Web/Providers/WebServiceProvider.php";

        require_once "{$pkg2}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkg2}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkg2}/src/Web/Providers/WebServiceProvider.php";

        // ClaimOne claims root
        config([
            'acmemount_claim_one_pkg_web.prefix' => '',
            'acmemount_claim_two_pkg_web.prefix' => '',
            'laraseed.web.root_owner'            => null,
        ]);

        $p1 = $this->app->register(\AcmeMount\ClaimOnePkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$p1, 'boot']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('attempted to claim the root route [/], but it is already owned by [acmemount_claim_one_pkg]');

        // ClaimTwo also attempts to claim root
        $p2 = $this->app->register(\AcmeMount\ClaimTwoPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$p2, 'boot']);
    }

    /**
     * Scenario 11: Collision with protected system route prefixes throws RuntimeException.
     */
    public function test_collision_with_reserved_system_prefix_throws_runtime_exception(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeMount/AdminCollisionPkg');
        $this->artisan('laraseed:make-package AcmeMount/AdminCollisionPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeMount/AdminCollisionPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        config([
            'acmemount_admin_collision_pkg_web.prefix' => 'admin',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('conflicts with protected system route prefix');

        $provider = $this->app->register(\AcmeMount\AdminCollisionPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
    }

    /**
     * Scenario 12: Subprocess config:cache and route:cache execute cleanly with default web package.
     */
    public function test_subprocess_config_cache_and_route_cache_with_default_package(): void
    {
        $procClear = new Process(['php', 'artisan', 'optimize:clear'], base_path());
        $procClear->run();
        $this->assertSame(0, $procClear->getExitCode());

        $procConfigCache = new Process(['php', 'artisan', 'config:cache'], base_path());
        $procConfigCache->run();
        $this->assertSame(0, $procConfigCache->getExitCode(), $procConfigCache->getErrorOutput());

        $procRouteCache = new Process(['php', 'artisan', 'route:cache'], base_path());
        $procRouteCache->run();
        $this->assertSame(0, $procRouteCache->getExitCode(), $procRouteCache->getErrorOutput());

        $procClear = new Process(['php', 'artisan', 'optimize:clear'], base_path());
        $procClear->run();
    }
}
