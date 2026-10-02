<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Laraseed\PackageGenerator\Tests\TestCase;
use Symfony\Component\Process\Process;

class RootRoutingDeterminismAndProductionVerificationTest extends TestCase
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

        // Always clean any existing cache
        $this->clearBootstrapCache();
        $this->runArtisanCommand(['optimize:clear']);
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
        $this->runArtisanCommand(['optimize:clear']);

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

    protected array $activeTestEnv = [];

    protected function sanitizeEnv(array $extraEnv = []): array
    {
        $env = $_SERVER;
        $cleanTestEnv = [];
        foreach ($this->activeTestEnv as $k => $v) {
            $cleanTestEnv[$k] = trim((string) $v, '"\'');
        }

        return array_merge($env, $cleanTestEnv, $extraEnv);
    }

    protected function runArtisanCommand(array $command, array $extraEnv = []): Process
    {
        $env = $this->sanitizeEnv($extraEnv);
        $cmd = array_merge(['php', 'artisan'], $command);

        $process = new Process($cmd, base_path(), $env, timeout: 60);
        $process->run();

        return $process;
    }

    protected function dispatchSubprocessHttp(string $uri, string $method = 'GET', array $extraEnv = []): array
    {
        $phpCode = <<<'PHP'
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$uri = $argv[1] ?? '/';
$method = $argv[2] ?? 'GET';

$request = Illuminate\Http\Request::create($uri, $method);
$response = $kernel->handle($request);

echo json_encode([
    'status'  => $response->getStatusCode(),
    'content' => $response->getContent(),
    'headers' => $response->headers->all(),
]);

$kernel->terminate($request, $response);
PHP;

        $env = $this->sanitizeEnv($extraEnv);
        $process = new Process(['php', '-r', $phpCode, $uri, $method], base_path(), $env, timeout: 60);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), 'Subprocess HTTP request crashed: ' . $process->getErrorOutput() . $process->getOutput());

        $decoded = json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, 'Failed to parse JSON response from subprocess: ' . $process->getOutput());

        return $decoded;
    }

    protected function dispatchSubprocessEval(string $expression, array $extraEnv = []): mixed
    {
        $phpCode = sprintf(
            'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo json_encode(%s);',
            $expression
        );

        $env = $this->sanitizeEnv($extraEnv);
        $process = new Process(['php', '-r', $phpCode], base_path(), $env, timeout: 60);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), 'Subprocess eval crashed: ' . $process->getErrorOutput() . $process->getOutput());

        return json_decode($process->getOutput(), true);
    }

    protected function writeTestEnv(array $variables): void
    {
        $this->activeTestEnv = $variables;
        $dbPath = database_path('runtime-audit.sqlite');
        $content = "APP_NAME=Laraseed\nAPP_ENV=testing\nAPP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=\nAPP_DEBUG=true\nAPP_URL=http://127.0.0.1:8000\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\n";
        foreach ($variables as $key => $val) {
            $content .= "{$key}={$val}\n";
        }

        file_put_contents(base_path('.env'), $content);
    }



    /**
     * Scenario 1: Zero active Web packages under production route & config cache.
     */
    public function test_production_cache_zero_active_web_packages_serves_fallback(): void
    {
        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES' => '""',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '""',
        ]);

        $procConfig = $this->runArtisanCommand(['config:cache']);
        $this->assertSame(0, $procConfig->getExitCode(), $procConfig->getErrorOutput());

        $procRoute = $this->runArtisanCommand(['route:cache']);
        $this->assertSame(0, $procRoute->getExitCode(), $procRoute->getErrorOutput());

        // Execute real HTTP request in fresh process
        $response = $this->dispatchSubprocessHttp('/');
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Platform Active', $response['content']);
        $this->assertStringContainsString('The application core is online.', $response['content']);

        // Inspect route action in fresh process
        $routeAction = $this->dispatchSubprocessEval("app('router')->getRoutes()->getByName('laraseed.web.entry')?->getActionName()");
        $this->assertSame('App\Http\Controllers\WebEntryPointController@index', $routeAction);
    }

    /**
     * Scenario 2: One active default Web package under production route & config cache.
     */
    public function test_production_cache_one_active_default_web_package_serves_homepage_directly(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeProd/PortalMain');
        $this->artisan('laraseed:make-package AcmeProd/PortalMain')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeProd/PortalMain')->assertExitCode(0);

        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '"portal_main"',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '"portal_main"',
        ]);

        $procConfig = $this->runArtisanCommand(['config:cache']);
        $this->assertSame(0, $procConfig->getExitCode(), $procConfig->getErrorOutput());

        $procRoute = $this->runArtisanCommand(['route:cache']);
        $this->assertSame(0, $procRoute->getExitCode(), $procRoute->getErrorOutput());

        // 1. GET / directly renders package homepage (HTTP 200, zero redirects)
        $homeResponse = $this->dispatchSubprocessHttp('/');
        $this->assertSame(200, $homeResponse['status']);
        $this->assertStringContainsString('PortalMain', $homeResponse['content']);

        // 2. GET /pages/about serves about page without package slug
        $aboutResponse = $this->dispatchSubprocessHttp('/pages/about');
        $this->assertSame(200, $aboutResponse['status']);
        $this->assertStringContainsString('about', $aboutResponse['content']);

        // 3. GET /branding.css serves stylesheet with correct headers
        $cssResponse = $this->dispatchSubprocessHttp('/branding.css');
        $this->assertSame(200, $cssResponse['status']);
        $contentType = $cssResponse['headers']['content-type'][0] ?? '';
        $this->assertStringContainsString('text/css', $contentType);

        // 4. Named URLs generate root-relative paths
        $homeUrl = $this->dispatchSubprocessEval("route('acmeprod_portal_main.web.home')");
        $this->assertSame('http://127.0.0.1:8000', rtrim($homeUrl, '/'));

        $aboutUrl = $this->dispatchSubprocessEval("route('acmeprod_portal_main.web.pages.show', ['page' => 'about'])");
        $this->assertSame('http://127.0.0.1:8000/pages/about', $aboutUrl);
    }

    /**
     * Scenario 3: Multiple active Web packages under production cache (Default vs Non-Default isolation).
     */
    public function test_production_cache_multiple_web_packages_isolation(): void
    {
        $pkg1 = $this->trackDirectory('packages/AcmeProd/PortalSite');
        $pkg2 = $this->trackDirectory('packages/AcmeProd/StoreSite');
        $this->artisan('laraseed:make-package AcmeProd/PortalSite')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeProd/PortalSite')->assertExitCode(0);
        $this->artisan('laraseed:make-package AcmeProd/StoreSite')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeProd/StoreSite')->assertExitCode(0);

        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '"portal_site,store_site"',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '"portal_site"',
        ]);

        $procConfig = $this->runArtisanCommand(['config:cache']);
        $this->assertSame(0, $procConfig->getExitCode(), $procConfig->getErrorOutput());

        $procRoute = $this->runArtisanCommand(['route:cache']);
        $this->assertSame(0, $procRoute->getExitCode(), $procRoute->getErrorOutput());

        // 1. Default package (PortalSite) is root-mounted
        $respRoot = $this->dispatchSubprocessHttp('/');
        $this->assertSame(200, $respRoot['status']);
        $this->assertStringContainsString('PortalSite', $respRoot['content']);

        $respPortalAbout = $this->dispatchSubprocessHttp('/pages/about');
        $this->assertSame(200, $respPortalAbout['status']);

        // 2. Non-default package (StoreSite) is mounted under its isolated prefix
        $respStoreRoot = $this->dispatchSubprocessHttp('/acme-prod-store-site');
        $this->assertSame(200, $respStoreRoot['status']);
        $this->assertStringContainsString('StoreSite', $respStoreRoot['content']);

        $respStoreAbout = $this->dispatchSubprocessHttp('/acme-prod-store-site/pages/about');
        $this->assertSame(200, $respStoreAbout['status']);

        // 3. Named URL verification
        $portalHomeUrl = $this->dispatchSubprocessEval("route('acmeprod_portal_site.web.home')");
        $this->assertSame('http://127.0.0.1:8000', rtrim($portalHomeUrl, '/'));

        $storeHomeUrl = $this->dispatchSubprocessEval("route('acmeprod_store_site.web.home')");
        $this->assertSame('http://127.0.0.1:8000/acme-prod-store-site', $storeHomeUrl);
    }

    /**
     * Scenario 4: Switching default package and rebuilding cache updates production routes deterministically.
     */
    public function test_production_cache_switching_default_package(): void
    {
        $pkg1 = $this->trackDirectory('packages/AcmeProd/AlphaSite');
        $pkg2 = $this->trackDirectory('packages/AcmeProd/BetaSite');
        $this->artisan('laraseed:make-package AcmeProd/AlphaSite')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeProd/AlphaSite')->assertExitCode(0);
        $this->artisan('laraseed:make-package AcmeProd/BetaSite')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeProd/BetaSite')->assertExitCode(0);

        // Phase 1: AlphaSite is default
        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '"alpha_site,beta_site"',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '"alpha_site"',
        ]);

        $this->runArtisanCommand(['config:cache']);
        $this->runArtisanCommand(['route:cache']);

        $respAlphaRoot = $this->dispatchSubprocessHttp('/');
        $this->assertSame(200, $respAlphaRoot['status']);
        $this->assertStringContainsString('AlphaSite', $respAlphaRoot['content']);

        // Phase 2: Switch default to BetaSite
        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '"alpha_site,beta_site"',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '"beta_site"',
        ]);

        $this->runArtisanCommand(['config:cache']);
        $this->runArtisanCommand(['route:cache']);

        // Fresh HTTP requests
        $respBetaRoot = $this->dispatchSubprocessHttp('/');
        $this->assertSame(200, $respBetaRoot['status']);
        $this->assertStringContainsString('BetaSite', $respBetaRoot['content']);

        $respAlphaPrefixed = $this->dispatchSubprocessHttp('/acme-prod-alpha-site');
        $this->assertSame(200, $respAlphaPrefixed['status']);
        $this->assertStringContainsString('AlphaSite', $respAlphaPrefixed['content']);
    }

    /**
     * Scenario 5: Clearing default package restores fallback landing view at root under cache.
     */
    public function test_production_cache_clearing_default_package_restores_fallback(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeProd/SinglePkg');
        $this->artisan('laraseed:make-package AcmeProd/SinglePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeProd/SinglePkg')->assertExitCode(0);

        // Phase 1: Default is SinglePkg
        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '"single_pkg"',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '"single_pkg"',
        ]);
        $this->runArtisanCommand(['config:cache']);
        $this->runArtisanCommand(['route:cache']);

        $this->assertSame(200, $this->dispatchSubprocessHttp('/')['status']);

        // Phase 2: Clear default
        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '"single_pkg"',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '""',
        ]);
        $this->runArtisanCommand(['config:cache']);
        $this->runArtisanCommand(['route:cache']);

        $fallbackResp = $this->dispatchSubprocessHttp('/');
        $this->assertSame(200, $fallbackResp['status']);
        $this->assertStringContainsString('Platform Active', $fallbackResp['content']);

        // Package is still accessible at its isolated prefix
        $prefixedResp = $this->dispatchSubprocessHttp('/acme-prod-single-pkg');
        $this->assertSame(200, $prefixedResp['status']);
        $this->assertStringContainsString('SinglePkg', $prefixedResp['content']);
    }

    /**
     * Scenario 6: Provider registration order independence (A,B vs B,A in LARASEED_OPTIONAL_PACKAGES).
     */
    public function test_provider_registration_order_does_not_affect_root_ownership(): void
    {
        $pkg1 = $this->trackDirectory('packages/AcmeProd/OrderOne');
        $pkg2 = $this->trackDirectory('packages/AcmeProd/OrderTwo');
        $this->artisan('laraseed:make-package AcmeProd/OrderOne')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeProd/OrderOne')->assertExitCode(0);
        $this->artisan('laraseed:make-package AcmeProd/OrderTwo')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeProd/OrderTwo')->assertExitCode(0);

        // Case A: Order is OrderOne, OrderTwo — Default is OrderTwo (the second in list)
        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '"order_one,order_two"',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '"order_two"',
        ]);
        $this->runArtisanCommand(['config:cache']);
        $this->runArtisanCommand(['route:cache']);

        $respA = $this->dispatchSubprocessHttp('/');
        $this->assertSame(200, $respA['status']);
        $this->assertStringContainsString('OrderTwo', $respA['content']);

        // Case B: Order is OrderTwo, OrderOne — Default is STILL OrderTwo (now the first in list)
        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '"order_two,order_one"',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '"order_two"',
        ]);
        $this->runArtisanCommand(['config:cache']);
        $this->runArtisanCommand(['route:cache']);

        $respB = $this->dispatchSubprocessHttp('/');
        $this->assertSame(200, $respB['status']);
        $this->assertStringContainsString('OrderTwo', $respB['content']);
    }

    /**
     * Scenario 7: Disabled package configured as default does not crash bootstrap and serves fallback.
     */
    public function test_disabled_package_as_default_serves_fallback_under_production_cache(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeProd/DormantPkg');
        $this->artisan('laraseed:make-package AcmeProd/DormantPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeProd/DormantPkg')->assertExitCode(0);

        // Package is disabled in LARASEED_OPTIONAL_PACKAGES but set in LARASEED_DEFAULT_WEB_PACKAGE
        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '""',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '"dormant_pkg"',
        ]);

        $this->runArtisanCommand(['config:cache']);
        $this->runArtisanCommand(['route:cache']);

        $response = $this->dispatchSubprocessHttp('/');
        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Platform Active', $response['content']);
    }

    /**
     * Scenario 8: Admin and installer protected routes are never intercepted by root-mounted packages.
     */
    public function test_protected_routes_integrity_under_production_cache(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeProd/AdminProtect');
        $this->artisan('laraseed:make-package AcmeProd/AdminProtect')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeProd/AdminProtect')->assertExitCode(0);

        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '"admin_protect"',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '"admin_protect"',
        ]);

        $this->runArtisanCommand(['config:cache']);
        $this->runArtisanCommand(['route:cache']);

        // 1. Admin login route remains HTTP 200
        $adminLogin = $this->dispatchSubprocessHttp('/admin/login');
        $this->assertSame(200, $adminLogin['status']);

        // 2. Admin root route redirects to admin/login (HTTP 302)
        $adminRoot = $this->dispatchSubprocessHttp('/admin');
        $this->assertSame(302, $adminRoot['status']);
        $this->assertStringContainsString('/admin/login', $adminRoot['headers']['location'][0] ?? '');

        // 3. Application root serves package homepage
        $rootResp = $this->dispatchSubprocessHttp('/');
        $this->assertSame(200, $rootResp['status']);
        $this->assertStringContainsString('AdminProtect', $rootResp['content']);
    }
}
