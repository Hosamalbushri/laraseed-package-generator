<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Laraseed\PackageGenerator\Generators\FilesystemTransaction;
use Laraseed\PackageGenerator\Generators\GenerationPlan;
use Laraseed\PackageGenerator\Generators\PackageGenerator;
use Laraseed\PackageGenerator\Tests\TestCase;
use Webkul\Core\Packages\OptionalPackageManifestLoader;

class PlainPackageGeneratorTest extends TestCase
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

    public function test_plain_package_generates_minimal_library_structure(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/PlainTools');
        $this->artisan('laraseed:make-package AcmeTest/PlainTools --plain')
            ->assertExitCode(0);

        // Required minimal files must exist
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/composer.json"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Providers/PlainToolsServiceProvider.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Config/plain_tools.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/tests/TestCase.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/tests/Feature/PackageTest.php"));

        // Concord and presentation files must NOT exist
        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/Providers/ModuleServiceProvider.php"));
        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/Resources/lang/en/app.php"));
        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/Resources/lang/ar/app.php"));
        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/Routes/web.php"));
        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/Routes/api.php"));
    }

    public function test_plain_package_composer_json_is_valid_and_has_clean_metadata(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/PlainMeta');
        $this->artisan('laraseed:make-package AcmeTest/PlainMeta --plain')
            ->assertExitCode(0);

        $composerFile = "{$pkgDir}/composer.json";
        $this->assertTrue($this->filesystem->exists($composerFile));

        $manifest = json_decode((string) file_get_contents($composerFile), true);
        $this->assertIsArray($manifest);
        $this->assertSame('acme-test/plain-meta', $manifest['name']);
        $this->assertSame('library', $manifest['type']);
        $this->assertSame('MIT', $manifest['license']);

        // Autoloading
        $this->assertSame('src/', $manifest['autoload']['psr-4']['AcmeTest\\PlainMeta\\']);
        $this->assertSame('tests/', $manifest['autoload-dev']['psr-4']['AcmeTest\\PlainMeta\\Tests\\']);

        // Extra Laraseed & Laravel metadata
        $this->assertSame('plain_meta', $manifest['extra']['laraseed']['id']);
        $this->assertSame('optional', $manifest['extra']['laraseed']['type']);
        $this->assertSame('AcmeTest\\PlainMeta\\Providers\\PlainMetaServiceProvider', $manifest['extra']['laraseed']['provider']);
        $this->assertArrayNotHasKey('concord_module', $manifest['extra']['laraseed']);

        // Laravel package auto-discovery
        $this->assertSame(['AcmeTest\\PlainMeta\\Providers\\PlainMetaServiceProvider'], $manifest['extra']['laravel']['providers']);
    }

    public function test_plain_package_manifest_loader_compatibility(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/PlainLoader');
        $this->artisan('laraseed:make-package AcmeTest/PlainLoader --plain')
            ->assertExitCode(0);

        $manifestFile = "{$pkgDir}/composer.json";
        $loader = new OptionalPackageManifestLoader();
        $packages = $loader->load([$manifestFile]);

        $this->assertArrayHasKey('plain_loader', $packages);
        $this->assertSame('plain_loader', $packages['plain_loader']['id']);
        $this->assertSame('acme-test/plain-loader', $packages['plain_loader']['composer_name']);
        $this->assertSame('AcmeTest\\PlainLoader\\Providers\\PlainLoaderServiceProvider', $packages['plain_loader']['provider']);
        $this->assertNull($packages['plain_loader']['concord_module']);
    }

    public function test_plain_service_provider_boots_cleanly_and_registers_config(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/PlainBoot');
        $this->artisan('laraseed:make-package AcmeTest/PlainBoot --plain')
            ->assertExitCode(0);

        // Put sample config data into plain_boot.php
        file_put_contents(
            "{$pkgDir}/src/Config/plain_boot.php",
            "<?php return ['custom_key' => 'custom_value_123'];"
        );

        $providerFile = "{$pkgDir}/src/Providers/PlainBootServiceProvider.php";
        $this->assertTrue(file_exists($providerFile));

        require_once $providerFile;

        $providerClass = 'AcmeTest\\PlainBoot\\Providers\\PlainBootServiceProvider';
        $this->assertTrue(class_exists($providerClass));
        $this->assertTrue(is_subclass_of($providerClass, ServiceProvider::class));

        /** @var ServiceProvider $provider */
        $provider = new $providerClass(app());
        $provider->register();

        $this->assertSame('custom_value_123', config('plain_boot.custom_key'));
    }

    public function test_default_package_generation_without_plain_retains_all_10_files(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/StandardPkg');
        $this->artisan('laraseed:make-package AcmeTest/StandardPkg')
            ->assertExitCode(0);

        $this->assertTrue($this->filesystem->exists("{$pkgDir}/composer.json"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Providers/StandardPkgServiceProvider.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Providers/ModuleServiceProvider.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Config/standard_pkg.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Resources/lang/en/app.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Resources/lang/ar/app.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Routes/web.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Routes/api.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/tests/TestCase.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/tests/Feature/PackageTest.php"));

        $manifest = json_decode((string) file_get_contents("{$pkgDir}/composer.json"), true);
        $this->assertArrayHasKey('concord_module', $manifest['extra']['laraseed']);
    }

    public function test_plain_package_dry_run_creates_zero_files(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/PlainDry');
        $this->artisan('laraseed:make-package AcmeTest/PlainDry --plain --dry-run')
            ->assertExitCode(0);

        $this->assertFalse($this->filesystem->isDirectory($pkgDir));
    }

    public function test_plain_package_collision_preflight_and_force(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/PlainForce');
        $this->artisan('laraseed:make-package AcmeTest/PlainForce --plain')
            ->assertExitCode(0);

        // Second run without --force should fail
        $this->artisan('laraseed:make-package AcmeTest/PlainForce --plain')
            ->assertExitCode(1);

        // Third run with --force should succeed
        $this->artisan('laraseed:make-package AcmeTest/PlainForce --plain --force')
            ->assertExitCode(0);
    }

    public function test_plain_package_rejects_invalid_identifiers_and_traversal(): void
    {
        $this->artisan('laraseed:make-package 123Bad/Pkg --plain')->assertExitCode(1);
        $this->artisan('laraseed:make-package AcmeTest/../BadPkg --plain')->assertExitCode(1);
        $this->artisan('laraseed:make-package "Invalid Name/Tools" --plain')->assertExitCode(1);
    }

    public function test_plain_package_transactional_rollback_and_restoration(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/PlainTxRestore');
        $this->artisan('laraseed:make-package AcmeTest/PlainTxRestore --plain')->assertExitCode(0);

        $existingComposer = "{$pkgDir}/composer.json";
        file_put_contents($existingComposer, '{"name": "original/plain-composer"}');

        $tx = new FilesystemTransaction($this->filesystem);
        $plan = new GenerationPlan(
            'packages/AcmeTest/PlainTxRestore',
            base_path(),
            [
                'composer.json' => '{"name": "new/overwritten"}',
                'src/ExtraFile.php' => '<?php // extra file',
            ]
        );

        try {
            $tx->run(function (FilesystemTransaction $currentTx) use ($plan) {
                $currentTx->executePlan($plan, true);
                throw new \RuntimeException('Simulated crash during plain package writing');
            });
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Simulated crash during plain package writing', $e->getMessage());
        }

        // New file removed
        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/ExtraFile.php"));

        // Overwritten file restored
        $this->assertTrue($this->filesystem->exists($existingComposer));
        $this->assertSame('{"name": "original/plain-composer"}', file_get_contents($existingComposer));
    }
}
