<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Laraseed\PackageGenerator\Tests\TestCase;

class PackageDiscoveryAndBootstrapTest extends TestCase
{
    protected Filesystem $filesystem;

    protected array $createdDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem;
        $this->createdDirectories = [];
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

    protected function runArtisanProcess(array $args, array $customEnv = []): Process
    {
        $packageRoot = realpath(__DIR__ . '/../../');
        $env = array_merge($_SERVER, [
            'APP_KEY'                => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'APP_CIPHER'             => 'AES-256-CBC',
            'TESTBENCH_WORKING_PATH' => $packageRoot,
        ], $customEnv);

        $process = new Process(array_merge(['php', 'artisan'], $args), base_path(), $env);
        $process->run();

        return $process;
    }

    /**
     * 1. Freshly generated package does not crash Artisan bootstrap before composer dump-autoload.
     */
    public function test_fresh_package_artisan_bootstrap_without_composer_dump_autoload(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeDiscov/FreshPkg');
        $this->artisan('laraseed:make-package AcmeDiscov/FreshPkg')->assertExitCode(0);

        // Execute real independent CLI process without composer dump-autoload
        $process = $this->runArtisanProcess(['list', '--raw']);

        $this->assertSame(0, $process->getExitCode(), 'Artisan failed to start after fresh package generation: ' . $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString('laraseed:make-package', $process->getOutput());
    }

    /**
     * 2. Composer dump-autoload succeeds and post-autoload-dump discovery hook completes cleanly.
     */
    public function test_composer_dump_autoload_recovery_succeeds_with_unregistered_packages(): void
    {
        $this->trackDirectory('packages/AcmeDiscov/DumpPkg');
        $this->artisan('laraseed:make-package AcmeDiscov/DumpPkg')->assertExitCode(0);

        $packageRoot = realpath(__DIR__ . '/../../');
        $env = array_merge($_SERVER, ['TESTBENCH_WORKING_PATH' => $packageRoot]);
        $process = new Process(['composer', 'dump-autoload', '--no-interaction'], base_path(), $env);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), 'composer dump-autoload failed: ' . $process->getErrorOutput() . $process->getOutput());
    }

    /**
     * 3. Inactive package with an unavailable or missing provider does not crash application bootstrap.
     */
    public function test_inactive_package_with_missing_provider_does_not_crash_bootstrap(): void
    {
        $brokenDir = $this->trackDirectory('packages/AcmeDiscov/BrokenInactivePkg');
        @mkdir("{$brokenDir}/src", 0755, true);
        file_put_contents("{$brokenDir}/composer.json", json_encode([
            'name' => 'acme-discov/broken-inactive-pkg',
            'autoload' => ['psr-4' => ['AcmeDiscov\\BrokenInactivePkg\\' => 'src/']],
            'extra' => [
                'laraseed' => [
                    'id' => 'broken_inactive_pkg',
                    'type' => 'optional',
                    'provider' => 'AcmeDiscov\\BrokenInactivePkg\\Providers\\NonExistentProviderClass',
                ],
            ],
        ]));

        $process = $this->runArtisanProcess(['list', '--raw'], ['LARASEED_OPTIONAL_PACKAGES' => '']);

        $this->assertSame(0, $process->getExitCode(), 'Inactive broken package caused bootstrap crash: ' . $process->getErrorOutput() . $process->getOutput());
    }

    /**
     * 4. Activating a package with a missing provider fails with an actionable diagnostic.
     */
    public function test_active_package_with_missing_provider_throws_actionable_diagnostic(): void
    {
        $brokenDir = $this->trackDirectory('packages/AcmeDiscov/BrokenActivePkg');
        @mkdir("{$brokenDir}/src", 0755, true);
        file_put_contents("{$brokenDir}/composer.json", json_encode([
            'name' => 'acme-discov/broken-active-pkg',
            'autoload' => ['psr-4' => ['AcmeDiscov\\BrokenActivePkg\\' => 'src/']],
            'extra' => [
                'laraseed' => [
                    'id' => 'broken_active_pkg',
                    'type' => 'optional',
                    'provider' => 'AcmeDiscov\\BrokenActivePkg\\Providers\\NonExistentProviderClass',
                ],
            ],
        ]));

        $process = $this->runArtisanProcess(['list'], ['LARASEED_OPTIONAL_PACKAGES' => 'broken_active_pkg']);

        $this->assertNotSame(0, $process->getExitCode(), 'Active package with missing provider should fail.');
        $this->assertStringContainsString('Optional package [broken_active_pkg] declares an invalid provider class', $process->getOutput() . $process->getErrorOutput());
    }

    /**
     * 5. Package activation and deactivation lifecycle.
     */
    public function test_package_activation_and_deactivation_lifecycle(): void
    {
        $this->trackDirectory('packages/AcmeDiscov/LifecyclePkg');
        $this->artisan('laraseed:make-package AcmeDiscov/LifecyclePkg')->assertExitCode(0);

        // When deactivated (empty env)
        $procDeactivated = $this->runArtisanProcess(['config:show', 'laraseed.optional_packages.enabled'], ['LARASEED_OPTIONAL_PACKAGES' => '']);
        $this->assertSame(0, $procDeactivated->getExitCode());
        $this->assertStringNotContainsString('lifecycle_pkg', $procDeactivated->getOutput());

        // When activated (env contains lifecycle_pkg)
        $procActivated = $this->runArtisanProcess(['config:show', 'laraseed.optional_packages.enabled'], ['LARASEED_OPTIONAL_PACKAGES' => 'lifecycle_pkg']);
        $this->assertSame(0, $procActivated->getExitCode());
        $this->assertStringContainsString('lifecycle_pkg', $procActivated->getOutput());
    }

    /**
     * 6. Security: PSR-4 paths attempting traversal outside packages directory are ignored.
     */
    public function test_security_psr4_path_traversal_is_ignored(): void
    {
        $traversalDir = $this->trackDirectory('packages/AcmeDiscov/TraversalPkg');
        @mkdir("{$traversalDir}/src", 0755, true);
        file_put_contents("{$traversalDir}/composer.json", json_encode([
            'name' => 'acme-discov/traversal-pkg',
            'autoload' => [
                'psr-4' => [
                    'AcmeDiscov\\TraversalPkg\\' => '../../app/',
                ],
            ],
            'extra' => [
                'laraseed' => [
                    'id' => 'traversal_pkg',
                    'type' => 'optional',
                    'provider' => 'AcmeDiscov\\TraversalPkg\\Providers\\SomeProvider',
                ],
            ],
        ]));

        $process = $this->runArtisanProcess(['list', '--raw'], ['LARASEED_OPTIONAL_PACKAGES' => '']);

        $this->assertSame(0, $process->getExitCode());
    }

    /**
     * 7. Multiple optional packages coexistence and discovery.
     */
    public function test_multiple_optional_packages_coexistence_and_discovery(): void
    {
        $this->trackDirectory('packages/AcmeDiscov/MultiPkgOne');
        $this->trackDirectory('packages/AcmeDiscov/MultiPkgTwo');

        $this->artisan('laraseed:make-package AcmeDiscov/MultiPkgOne')->assertExitCode(0);
        $this->artisan('laraseed:make-package AcmeDiscov/MultiPkgTwo')->assertExitCode(0);

        $process = $this->runArtisanProcess(['config:show', 'laraseed.optional_packages.enabled'], [
            'LARASEED_OPTIONAL_PACKAGES' => 'multi_pkg_one,multi_pkg_two',
        ]);

        $this->assertSame(0, $process->getExitCode());
        $output = $process->getOutput();
        $this->assertStringContainsString('multi_pkg_one', $output);
        $this->assertStringContainsString('multi_pkg_two', $output);
    }
}
