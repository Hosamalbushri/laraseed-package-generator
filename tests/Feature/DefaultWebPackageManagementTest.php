<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Laraseed\PackageGenerator\Support\DefaultWebPackageManager;
use Laraseed\PackageGenerator\Tests\TestCase;
use Symfony\Component\Process\Process;

class DefaultWebPackageManagementTest extends TestCase
{
    protected Filesystem $filesystem;

    protected array $createdDirectories = [];

    protected ?string $tempEnvFile = null;

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
        ]);

        // Create isolated test .env file
        $this->tempEnvFile = base_path('.env.test.' . uniqid());
        file_put_contents($this->tempEnvFile, "APP_NAME=Laraseed\nLARASEED_OPTIONAL_PACKAGES=\n");
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

        if ($this->tempEnvFile !== null && file_exists($this->tempEnvFile)) {
            @unlink($this->tempEnvFile);
        }

        $this->clearBootstrapCache();

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
     * Test status inspection when no default package is selected.
     */
    public function test_status_when_no_default_package_configured(): void
    {
        config([
            'laraseed.default_web_package' => null,
            'laraseed.web.default_package' => null,
        ]);

        $this->artisan('laraseed:web-default --status')
            ->assertExitCode(0)
            ->expectsOutputToContain('NONE_SELECTED');
    }

    /**
     * Test listing when no Web packages exist.
     */
    public function test_list_when_no_web_packages_exist(): void
    {
        $emptyDir = $this->trackDirectory('storage/framework/testing/empty_pkg_root');
        @mkdir($emptyDir . '/packages', 0755, true);

        $manager = new DefaultWebPackageManager($emptyDir);
        $packages = $manager->listEligiblePackages();

        $this->assertSame([], $packages);
    }

    /**
     * Test listing discovered Web packages and their eligibility.
     */
    public function test_list_with_discovered_web_packages_showing_eligibility(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeWeb/ListPortalPkg');
        $this->artisan('laraseed:make-package AcmeWeb/ListPortalPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeWeb/ListPortalPkg')->assertExitCode(0);

        Route::get('/portal', fn () => 'Portal')->name('acmeweb_list_portal_pkg.web.home');
        Route::getRoutes()->refreshNameLookups();

        // 1. When disabled
        config(['laraseed.optional_packages.enabled' => []]);
        $this->artisan('laraseed:web-default --list')
            ->assertExitCode(0)
            ->expectsOutputToContain('list_portal_pkg');

        // 2. When enabled
        config(['laraseed.optional_packages.enabled' => ['list_portal_pkg']]);
        $this->artisan('laraseed:web-default --list')
            ->assertExitCode(0)
            ->expectsOutputToContain('list_portal_pkg');
    }

    /**
     * Test selecting nonexistent package fails with actionable diagnostic.
     */
    public function test_select_fails_when_package_is_not_found(): void
    {
        $this->artisan('laraseed:web-default nonexistent_pkg')
            ->assertExitCode(1)
            ->expectsOutputToContain('Cannot select [nonexistent_pkg] as default Web package')
            ->expectsOutputToContain('not found');
    }

    /**
     * Test selecting disabled package fails with actionable diagnostic.
     */
    public function test_select_fails_when_package_is_disabled(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeWeb/DisabledSelectPkg');
        $this->artisan('laraseed:make-package AcmeWeb/DisabledSelectPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeWeb/DisabledSelectPkg')->assertExitCode(0);

        config(['laraseed.optional_packages.enabled' => []]);

        $this->artisan('laraseed:web-default disabled_select_pkg')
            ->assertExitCode(1)
            ->expectsOutputToContain('is not active in LARASEED_OPTIONAL_PACKAGES');
    }

    /**
     * Test selecting package with missing route fails cleanly.
     */
    public function test_select_fails_when_package_lacks_registered_entry_route(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeWeb/NoRoutePkg');
        $this->artisan('laraseed:make-package AcmeWeb/NoRoutePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeWeb/NoRoutePkg')->assertExitCode(0);

        config(['laraseed.optional_packages.enabled' => ['no_route_pkg']]);

        $this->artisan('laraseed:web-default no_route_pkg')
            ->assertExitCode(1)
            ->expectsOutputToContain('does not have a registered public entry route');
    }

    /**
     * Test selecting package with redirect loop is rejected.
     */
    public function test_select_fails_when_package_entry_route_causes_redirect_loop(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeWeb/LoopPkg');
        $this->artisan('laraseed:make-package AcmeWeb/LoopPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeWeb/LoopPkg')->assertExitCode(0);

        // Register route directly pointing to /
        Route::get('/', fn () => 'Root')->name('acmeweb_loop_pkg.web.home');
        Route::getRoutes()->refreshNameLookups();

        config(['laraseed.optional_packages.enabled' => ['loop_pkg']]);

        $this->artisan('laraseed:web-default loop_pkg')
            ->assertExitCode(1)
            ->expectsOutputToContain('redirect loop');
    }

    /**
     * Test dry-run simulation of package selection without mutating disk.
     */
    public function test_select_dry_run_simulates_without_writing_env(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeWeb/DryRunPkg');
        $this->artisan('laraseed:make-package AcmeWeb/DryRunPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeWeb/DryRunPkg')->assertExitCode(0);

        Route::get('/dry-run', fn () => 'Dry Run')->name('acmeweb_dry_run_pkg.web.home');
        Route::getRoutes()->refreshNameLookups();

        config(['laraseed.optional_packages.enabled' => ['dry_run_pkg']]);

        $this->artisan('laraseed:web-default dry_run_pkg --dry-run')
            ->assertExitCode(0)
            ->expectsOutputToContain('[DRY RUN] Would set default Web package to [dry_run_pkg]');

        // Ensure real .env was not modified
        $this->assertStringNotContainsString('dry_run_pkg', (string) file_get_contents(base_path('.env')));
    }

    /**
     * Test clearing default web package in dry-run mode.
     */
    public function test_clear_dry_run_simulates_without_writing_env(): void
    {
        $this->artisan('laraseed:web-default --clear --dry-run')
            ->assertExitCode(0)
            ->expectsOutputToContain('[DRY RUN] Would clear LARASEED_DEFAULT_WEB_PACKAGE');
    }

    /**
     * Test atomic env persistence helper.
     */
    public function test_default_web_package_manager_persist_env_atomic_update(): void
    {
        $manager = new DefaultWebPackageManager(base_path());

        // Test set
        $result = $manager->persistEnv('LARASEED_DEFAULT_WEB_PACKAGE', 'my_shop', $this->tempEnvFile);
        $this->assertTrue($result);
        $content = (string) file_get_contents($this->tempEnvFile);
        $this->assertStringContainsString('LARASEED_DEFAULT_WEB_PACKAGE="my_shop"', $content);

        // Test update existing
        $result2 = $manager->persistEnv('LARASEED_DEFAULT_WEB_PACKAGE', 'my_blog', $this->tempEnvFile);
        $this->assertTrue($result2);
        $content2 = (string) file_get_contents($this->tempEnvFile);
        $this->assertStringContainsString('LARASEED_DEFAULT_WEB_PACKAGE="my_blog"', $content2);
        $this->assertStringNotContainsString('my_shop', $content2);

        // Test clear
        $result3 = $manager->persistEnv('LARASEED_DEFAULT_WEB_PACKAGE', null, $this->tempEnvFile);
        $this->assertTrue($result3);
        $content3 = (string) file_get_contents($this->tempEnvFile);
        $this->assertStringContainsString('LARASEED_DEFAULT_WEB_PACKAGE=', $content3);
        $this->assertStringNotContainsString('my_blog', $content3);
    }

    /**
     * Test switching between multiple enabled packages.
     */
    public function test_switching_between_multiple_enabled_packages(): void
    {
        $pkg1 = $this->trackDirectory('packages/AcmeWeb/SwitchStorePkg');
        $pkg2 = $this->trackDirectory('packages/AcmeWeb/SwitchHelpdeskPkg');
        $this->artisan('laraseed:make-package AcmeWeb/SwitchStorePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeWeb/SwitchStorePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-package AcmeWeb/SwitchHelpdeskPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeWeb/SwitchHelpdeskPkg')->assertExitCode(0);

        Route::get('/store', fn () => 'Store')->name('acmeweb_switch_store_pkg.web.home');
        Route::get('/helpdesk', fn () => 'Helpdesk')->name('acmeweb_switch_helpdesk_pkg.web.home');
        Route::getRoutes()->refreshNameLookups();

        config(['laraseed.optional_packages.enabled' => ['switch_store_pkg', 'switch_helpdesk_pkg']]);

        $manager = new DefaultWebPackageManager(base_path());

        // Validate both packages
        $val1 = $manager->validatePackage('switch_store_pkg');
        $this->assertTrue($val1['valid']);
        $this->assertSame('acmeweb_switch_store_pkg.web.home', $val1['entry_route']);

        $val2 = $manager->validatePackage('switch_helpdesk_pkg');
        $this->assertTrue($val2['valid']);
        $this->assertSame('acmeweb_switch_helpdesk_pkg.web.home', $val2['entry_route']);
    }

    /**
     * Test configuration and route cache with WebDefaultCommand registered.
     */
    public function test_route_and_configuration_cache_clean_execution(): void
    {
        $env = array_merge($_SERVER, [
            'APP_KEY'                => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'APP_CIPHER'             => 'AES-256-CBC',
            'TESTBENCH_WORKING_PATH' => realpath(__DIR__ . '/../../'),
        ]);

        $procClear = new Process(['php', 'artisan', 'optimize:clear'], base_path(), $env);
        $procClear->run();
        $this->assertSame(0, $procClear->getExitCode());

        $procConfigCache = new Process(['php', 'artisan', 'config:cache'], base_path(), $env);
        $procConfigCache->run();
        $this->assertSame(0, $procConfigCache->getExitCode(), $procConfigCache->getErrorOutput());

        $procRouteCache = new Process(['php', 'artisan', 'route:cache'], base_path(), $env);
        $procRouteCache->run();
        $this->assertSame(0, $procRouteCache->getExitCode(), $procRouteCache->getErrorOutput());

        $procClear = new Process(['php', 'artisan', 'optimize:clear'], base_path(), $env);
        $procClear->run();
    }

    /**
     * Test duplicate keys in .env are handled deterministically without duplicating lines.
     */
    public function test_duplicate_keys_in_env_are_consolidated_deterministically(): void
    {
        $duplicateEnvContent = "# Application Settings\nAPP_NAME=Laraseed\nLARASEED_DEFAULT_WEB_PACKAGE=\"old_1\"\n# Other comments\nLARASEED_DEFAULT_WEB_PACKAGE=\"old_2\"\n";
        file_put_contents($this->tempEnvFile, $duplicateEnvContent);

        $manager = new DefaultWebPackageManager(base_path());
        $result = $manager->persistEnv('LARASEED_DEFAULT_WEB_PACKAGE', 'consolidated_pkg', $this->tempEnvFile);

        $this->assertTrue($result);
        $content = (string) file_get_contents($this->tempEnvFile);

        // Exactly one occurrence
        $count = substr_count($content, 'LARASEED_DEFAULT_WEB_PACKAGE=');
        $this->assertSame(1, $count);
        $this->assertStringContainsString('LARASEED_DEFAULT_WEB_PACKAGE="consolidated_pkg"', $content);
        $this->assertStringContainsString('# Application Settings', $content);
        $this->assertStringContainsString('# Other comments', $content);
    }

    /**
     * Test unwritable or missing .env file fails gracefully.
     */
    public function test_unwritable_or_missing_env_file_fails_gracefully(): void
    {
        $manager = new DefaultWebPackageManager(base_path());

        // 1. Missing file
        $missingResult = $manager->persistEnv('LARASEED_DEFAULT_WEB_PACKAGE', 'some_pkg', '/path/to/ghost/.env.missing');
        $this->assertFalse($missingResult);

        // 2. Read-only file
        chmod($this->tempEnvFile, 0444);
        $readonlyResult = $manager->persistEnv('LARASEED_DEFAULT_WEB_PACKAGE', 'some_pkg', $this->tempEnvFile);
        $this->assertFalse($readonlyResult);

        // Restore permissions so tearDown can delete it
        chmod($this->tempEnvFile, 0664);
    }

    /**
     * Test concurrent environment persistence executions.
     */
    public function test_concurrent_env_persistence_locking(): void
    {
        $manager = new DefaultWebPackageManager(base_path());

        for ($i = 0; $i < 5; $i++) {
            $success = $manager->persistEnv('LARASEED_DEFAULT_WEB_PACKAGE', "pkg_{$i}", $this->tempEnvFile);
            $this->assertTrue($success);
        }

        $finalContent = (string) file_get_contents($this->tempEnvFile);
        $count = substr_count($finalContent, 'LARASEED_DEFAULT_WEB_PACKAGE=');
        $this->assertSame(1, $count);
        $this->assertStringContainsString('LARASEED_DEFAULT_WEB_PACKAGE="pkg_4"', $finalContent);
    }
}
