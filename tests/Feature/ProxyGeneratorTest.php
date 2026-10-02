<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Konekt\Concord\Proxies\ModelProxy;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\FilesystemTransaction;
use Laraseed\PackageGenerator\Generators\GenerationPlan;
use Laraseed\PackageGenerator\Generators\ModelGenerator;
use Laraseed\PackageGenerator\Generators\ProxyGenerator;
use Laraseed\PackageGenerator\Tests\TestCase;

class ProxyGeneratorTest extends TestCase
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

    // 1. Standalone make-proxy tests

    public function test_make_proxy_generates_standard_proxy_from_model_name(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ProxyPkg');
        $this->artisan('laraseed:make-package AcmeTest/ProxyPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-proxy AcmeTest/ProxyPkg Post')
            ->assertExitCode(0);

        $proxyFile = "{$pkgDir}/src/Models/PostProxy.php";
        $this->assertTrue($this->filesystem->exists($proxyFile));

        $content = (string) file_get_contents($proxyFile);
        $this->assertStringContainsString('namespace AcmeTest\ProxyPkg\Models;', $content);
        $this->assertStringContainsString('use Konekt\Concord\Proxies\ModelProxy;', $content);
        $this->assertStringContainsString('class PostProxy extends ModelProxy', $content);
    }

    public function test_make_proxy_handles_explicit_proxy_suffix(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ProxySuffixPkg');
        $this->artisan('laraseed:make-package AcmeTest/ProxySuffixPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-proxy AcmeTest/ProxySuffixPkg PostProxy')
            ->assertExitCode(0);

        $proxyFile = "{$pkgDir}/src/Models/PostProxy.php";
        $this->assertTrue($this->filesystem->exists($proxyFile));

        $content = (string) file_get_contents($proxyFile);
        $this->assertStringContainsString('class PostProxy extends ModelProxy', $content);
        $this->assertStringNotContainsString('PostProxyProxy', $content);
    }

    public function test_make_proxy_dry_run_creates_no_files(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ProxyDryPkg');
        $this->artisan('laraseed:make-package AcmeTest/ProxyDryPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-proxy AcmeTest/ProxyDryPkg Post --dry-run')
            ->assertExitCode(0);

        $proxyFile = "{$pkgDir}/src/Models/PostProxy.php";
        $this->assertFalse($this->filesystem->exists($proxyFile));
    }

    public function test_make_proxy_collision_preflight_and_force(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ProxyForcePkg');
        $this->artisan('laraseed:make-package AcmeTest/ProxyForcePkg')->assertExitCode(0);

        $this->artisan('laraseed:make-proxy AcmeTest/ProxyForcePkg Post')
            ->assertExitCode(0);

        // Second run without --force should fail
        $this->artisan('laraseed:make-proxy AcmeTest/ProxyForcePkg Post')
            ->assertExitCode(1);

        // Third run with --force should succeed
        $this->artisan('laraseed:make-proxy AcmeTest/ProxyForcePkg Post --force')
            ->assertExitCode(0);
    }

    public function test_make_proxy_rejects_invalid_class_and_traversal(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/BadProxyPkg');
        $this->artisan('laraseed:make-package AcmeTest/BadProxyPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-proxy AcmeTest/BadProxyPkg 123Invalid')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-proxy AcmeTest/BadProxyPkg ../Bad')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-proxy AcmeTest/BadProxyPkg "Invalid Name"')
            ->assertExitCode(1);
    }

    // 2. Backward Compatibility & Basic make-model behavior

    public function test_make_model_standard_creates_eloquent_model_only(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ModelStandardPkg');
        $this->artisan('laraseed:make-package AcmeTest/ModelStandardPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-model AcmeTest/ModelStandardPkg SimpleItem')
            ->assertExitCode(0);

        $contractFile = "{$pkgDir}/src/Contracts/SimpleItem.php";
        $modelFile = "{$pkgDir}/src/Models/SimpleItem.php";
        $proxyFile = "{$pkgDir}/src/Models/SimpleItemProxy.php";

        $this->assertTrue($this->filesystem->exists($modelFile));
        $this->assertFalse($this->filesystem->exists($contractFile));
        $this->assertFalse($this->filesystem->exists($proxyFile));

        $modelContent = (string) file_get_contents($modelFile);
        $this->assertStringContainsString('class SimpleItem extends Model', $modelContent);
        $this->assertStringNotContainsString('implements', $modelContent);
        $this->assertStringNotContainsString('Contracts', $modelContent);
    }

    // 3. Contract-only generation mode

    public function test_make_model_with_contract_flag_creates_model_and_contract_without_proxy(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ModelContractPkg');
        $this->artisan('laraseed:make-package AcmeTest/ModelContractPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-model AcmeTest/ModelContractPkg Customer --contract')
            ->assertExitCode(0);

        $contractFile = "{$pkgDir}/src/Contracts/Customer.php";
        $modelFile = "{$pkgDir}/src/Models/Customer.php";
        $proxyFile = "{$pkgDir}/src/Models/CustomerProxy.php";

        $this->assertTrue($this->filesystem->exists($contractFile));
        $this->assertTrue($this->filesystem->exists($modelFile));
        $this->assertFalse($this->filesystem->exists($proxyFile));

        $contractContent = (string) file_get_contents($contractFile);
        $this->assertStringContainsString('namespace AcmeTest\ModelContractPkg\Contracts;', $contractContent);
        $this->assertStringContainsString('interface Customer', $contractContent);

        $modelContent = (string) file_get_contents($modelFile);
        $this->assertStringContainsString('namespace AcmeTest\ModelContractPkg\Models;', $modelContent);
        $this->assertStringContainsString('use AcmeTest\ModelContractPkg\Contracts\Customer as CustomerContract;', $modelContent);
        $this->assertStringContainsString('class Customer extends Model implements CustomerContract', $modelContent);
    }

    // 4. Composite Model, Contract, and Proxy generation mode (--proxy)

    public function test_make_model_with_proxy_flag_creates_model_contract_and_proxy_atomically(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ModelProxyPkg');
        $this->artisan('laraseed:make-package AcmeTest/ModelProxyPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-model AcmeTest/ModelProxyPkg Post --proxy')
            ->assertExitCode(0);

        $contractFile = "{$pkgDir}/src/Contracts/Post.php";
        $modelFile = "{$pkgDir}/src/Models/Post.php";
        $proxyFile = "{$pkgDir}/src/Models/PostProxy.php";

        $this->assertTrue($this->filesystem->exists($contractFile));
        $this->assertTrue($this->filesystem->exists($modelFile));
        $this->assertTrue($this->filesystem->exists($proxyFile));

        $contractContent = (string) file_get_contents($contractFile);
        $this->assertStringContainsString('namespace AcmeTest\ModelProxyPkg\Contracts;', $contractContent);
        $this->assertStringContainsString('interface Post', $contractContent);

        $modelContent = (string) file_get_contents($modelFile);
        $this->assertStringContainsString('namespace AcmeTest\ModelProxyPkg\Models;', $modelContent);
        $this->assertStringContainsString('use AcmeTest\ModelProxyPkg\Contracts\Post as PostContract;', $modelContent);
        $this->assertStringContainsString('class Post extends Model implements PostContract', $modelContent);

        $proxyContent = (string) file_get_contents($proxyFile);
        $this->assertStringContainsString('namespace AcmeTest\ModelProxyPkg\Models;', $proxyContent);
        $this->assertStringContainsString('use Konekt\Concord\Proxies\ModelProxy;', $proxyContent);
        $this->assertStringContainsString('class PostProxy extends ModelProxy', $proxyContent);
    }

    public function test_make_model_dry_run_with_proxy_creates_zero_files(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ModelDryProxyPkg');
        $this->artisan('laraseed:make-package AcmeTest/ModelDryProxyPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-model AcmeTest/ModelDryProxyPkg Post --proxy --dry-run')
            ->assertExitCode(0);

        $contractFile = "{$pkgDir}/src/Contracts/Post.php";
        $modelFile = "{$pkgDir}/src/Models/Post.php";
        $proxyFile = "{$pkgDir}/src/Models/PostProxy.php";

        $this->assertFalse($this->filesystem->exists($contractFile));
        $this->assertFalse($this->filesystem->exists($modelFile));
        $this->assertFalse($this->filesystem->exists($proxyFile));
    }

    // 5. Collision preflight across first, middle, and final files

    public function test_make_model_collision_in_first_file_contract_aborts_entire_plan(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ColFirstPkg');
        $this->artisan('laraseed:make-package AcmeTest/ColFirstPkg')->assertExitCode(0);

        // Pre-create Contract file (first in logical group)
        $contractFile = "{$pkgDir}/src/Contracts/Post.php";
        $this->filesystem->ensureDirectoryExists(dirname($contractFile));
        file_put_contents($contractFile, '<?php // existing contract');

        $this->artisan('laraseed:make-model AcmeTest/ColFirstPkg Post --proxy')
            ->assertExitCode(1);

        $modelFile = "{$pkgDir}/src/Models/Post.php";
        $proxyFile = "{$pkgDir}/src/Models/PostProxy.php";

        $this->assertFalse($this->filesystem->exists($modelFile));
        $this->assertFalse($this->filesystem->exists($proxyFile));
        $this->assertSame('<?php // existing contract', file_get_contents($contractFile));
    }

    public function test_make_model_collision_in_middle_file_model_aborts_entire_plan(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ColMidPkg');
        $this->artisan('laraseed:make-package AcmeTest/ColMidPkg')->assertExitCode(0);

        // Pre-create Model file (middle)
        $modelFile = "{$pkgDir}/src/Models/Post.php";
        $this->filesystem->ensureDirectoryExists(dirname($modelFile));
        file_put_contents($modelFile, '<?php // existing model');

        $this->artisan('laraseed:make-model AcmeTest/ColMidPkg Post --proxy')
            ->assertExitCode(1);

        $contractFile = "{$pkgDir}/src/Contracts/Post.php";
        $proxyFile = "{$pkgDir}/src/Models/PostProxy.php";

        $this->assertFalse($this->filesystem->exists($contractFile));
        $this->assertFalse($this->filesystem->exists($proxyFile));
        $this->assertSame('<?php // existing model', file_get_contents($modelFile));
    }

    public function test_make_model_collision_in_final_file_proxy_aborts_entire_plan(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ColFinalPkg');
        $this->artisan('laraseed:make-package AcmeTest/ColFinalPkg')->assertExitCode(0);

        // Pre-create Proxy file (final)
        $proxyFile = "{$pkgDir}/src/Models/PostProxy.php";
        $this->filesystem->ensureDirectoryExists(dirname($proxyFile));
        file_put_contents($proxyFile, '<?php // existing proxy');

        $this->artisan('laraseed:make-model AcmeTest/ColFinalPkg Post --proxy')
            ->assertExitCode(1);

        $contractFile = "{$pkgDir}/src/Contracts/Post.php";
        $modelFile = "{$pkgDir}/src/Models/Post.php";

        $this->assertFalse($this->filesystem->exists($contractFile));
        $this->assertFalse($this->filesystem->exists($modelFile));
        $this->assertSame('<?php // existing proxy', file_get_contents($proxyFile));
    }

    public function test_make_model_with_force_overwrites_all_files(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ForceAllPkg');
        $this->artisan('laraseed:make-package AcmeTest/ForceAllPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-model AcmeTest/ForceAllPkg Post --proxy')->assertExitCode(0);

        // Mutate files to track overwrite
        file_put_contents("{$pkgDir}/src/Contracts/Post.php", '<?php // old 1');
        file_put_contents("{$pkgDir}/src/Models/Post.php", '<?php // old 2');
        file_put_contents("{$pkgDir}/src/Models/PostProxy.php", '<?php // old 3');

        // Re-run with --force
        $this->artisan('laraseed:make-model AcmeTest/ForceAllPkg Post --proxy --force')
            ->assertExitCode(0);

        $this->assertStringContainsString('interface Post', (string) file_get_contents("{$pkgDir}/src/Contracts/Post.php"));
        $this->assertStringContainsString('class Post extends Model', (string) file_get_contents("{$pkgDir}/src/Models/Post.php"));
        $this->assertStringContainsString('class PostProxy extends ModelProxy', (string) file_get_contents("{$pkgDir}/src/Models/PostProxy.php"));
    }

    // 6. Multi-file Transaction Rollback & Overwrite Restoration

    public function test_composite_generation_transactional_rollback_and_restoration_on_failure(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/TxRestorePkg');
        $this->artisan('laraseed:make-package AcmeTest/TxRestorePkg')->assertExitCode(0);

        // Existing file to be overwritten
        $existingContract = "{$pkgDir}/src/Contracts/Post.php";
        $this->filesystem->ensureDirectoryExists(dirname($existingContract));
        file_put_contents($existingContract, '<?php // ORIGINAL CONTRACT CONTENT');

        $tx = new FilesystemTransaction($this->filesystem);
        $plan = new GenerationPlan(
            'packages/AcmeTest/TxRestorePkg',
            base_path(),
            [
                'src/Contracts/Post.php' => '<?php // NEW CONTRACT',
                'src/Models/Post.php' => '<?php // NEW MODEL',
                'src/Models/PostProxy.php' => '<?php // NEW PROXY',
            ]
        );

        try {
            $tx->run(function (FilesystemTransaction $currentTx) use ($plan) {
                $currentTx->executePlan($plan, true);
                throw new \RuntimeException('Injected crash during composite writing');
            });
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Injected crash during composite writing', $e->getMessage());
        }

        // Newly created files must be removed
        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/Models/Post.php"));
        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/Models/PostProxy.php"));

        // Overwritten file must be restored to original content
        $this->assertTrue($this->filesystem->exists($existingContract));
        $this->assertSame('<?php // ORIGINAL CONTRACT CONTENT', file_get_contents($existingContract));
    }

    // 7. Security, Containment, and Identifiers

    public function test_make_model_rejects_invalid_identifiers_and_traversal_in_proxy_mode(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/BadModelPkg');
        $this->artisan('laraseed:make-package AcmeTest/BadModelPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-model AcmeTest/BadModelPkg 123Bad --proxy')->assertExitCode(1);
        $this->artisan('laraseed:make-model AcmeTest/BadModelPkg ../Traversal --proxy')->assertExitCode(1);
        $this->artisan('laraseed:make-model AcmeTest/BadModelPkg "Invalid Name" --proxy')->assertExitCode(1);
    }

    public function test_make_model_symlink_containment_in_proxy_mode(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/SymlinkProxyPkg');
        $this->artisan('laraseed:make-package AcmeTest/SymlinkProxyPkg')->assertExitCode(0);

        $target = sys_get_temp_dir() . '/outside_model_target_' . uniqid() . '.php';
        file_put_contents($target, '<?php // outside');
        $symlinkPath = "{$pkgDir}/src/Contracts/Post.php";
        $this->filesystem->ensureDirectoryExists(dirname($symlinkPath));
        symlink($target, $symlinkPath);

        try {
            $this->artisan('laraseed:make-model AcmeTest/SymlinkProxyPkg Post --proxy --force')
                ->assertExitCode(1);
        } finally {
            if (file_exists($symlinkPath)) {
                @unlink($symlinkPath);
            }
            if (file_exists($target)) {
                @unlink($target);
            }
        }
    }

    // 8. Runtime Concord Model Resolution & Semantic Verification

    public function test_generated_composite_artifacts_execute_and_resolve_via_concord(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/RuntimeConcordPkg');
        $this->artisan('laraseed:make-package AcmeTest/RuntimeConcordPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-model AcmeTest/RuntimeConcordPkg Article --proxy')->assertExitCode(0);

        $contractFile = "{$pkgDir}/src/Contracts/Article.php";
        $modelFile = "{$pkgDir}/src/Models/Article.php";
        $proxyFile = "{$pkgDir}/src/Models/ArticleProxy.php";

        $this->assertTrue(file_exists($contractFile));
        $this->assertTrue(file_exists($modelFile));
        $this->assertTrue(file_exists($proxyFile));

        require_once $contractFile;
        require_once $modelFile;
        require_once $proxyFile;

        $contractClass = 'AcmeTest\\RuntimeConcordPkg\\Contracts\\Article';
        $modelClass = 'AcmeTest\\RuntimeConcordPkg\\Models\\Article';
        $proxyClass = 'AcmeTest\\RuntimeConcordPkg\\Models\\ArticleProxy';

        // 1. Assert PHP types
        $this->assertTrue(interface_exists($contractClass));
        $this->assertTrue(class_exists($modelClass));
        $this->assertTrue(class_exists($proxyClass));

        // 2. Assert Model implements Contract
        $modelInstance = new $modelClass;
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Model::class, $modelInstance);
        $this->assertInstanceOf($contractClass, $modelInstance);

        // 3. Assert Proxy inherits Concord ModelProxy
        $this->assertTrue(is_subclass_of($proxyClass, ModelProxy::class));

        // 4. Register with Concord runtime
        app('concord')->registerModel($contractClass, $modelClass);

        // 5. Assert Concord conventions and resolution
        $this->assertSame($modelClass, app('concord')->model($contractClass));
        $this->assertSame($contractClass, app('concord')->getConvention()->contractForModel($modelClass));
        $this->assertSame($modelClass, app('concord')->getConvention()->modelForProxy($proxyClass));
        $this->assertSame($proxyClass, app('concord')->getConvention()->proxyForModel($modelClass));

        // 6. Assert Proxy static resolution
        $this->assertSame($modelClass, $proxyClass::modelClass());
    }
}
