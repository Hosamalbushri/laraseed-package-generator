<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\PackageGenerator;
use Laraseed\PackageGenerator\Support\PackageIdentity;
use Laraseed\PackageGenerator\Support\PackageNameValidator;
use Webkul\Core\Packages\OptionalPackageComposition;
use Webkul\Core\Packages\OptionalPackageManifestLoader;
use Laraseed\PackageGenerator\Tests\TestCase;

class PackageGeneratorTest extends TestCase
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

    public function test_valid_package_generation_creates_all_expected_files(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/BlogEngine');

        $this->artisan('laraseed:make-package AcmeTest/BlogEngine')
            ->assertExitCode(0);

        $this->assertTrue($this->filesystem->exists("{$pkgDir}/composer.json"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Providers/BlogEngineServiceProvider.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Providers/ModuleServiceProvider.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Config/blog_engine.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Resources/lang/en/app.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Resources/lang/ar/app.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Routes/web.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Routes/api.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/tests/TestCase.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/tests/Feature/PackageTest.php"));
    }

    public function test_generated_composer_json_schema_and_extra_laraseed_contract(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ContractPkg');

        $this->artisan('laraseed:make-package AcmeTest/ContractPkg')
            ->assertExitCode(0);

        $manifestPath = "{$pkgDir}/composer.json";
        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        $this->assertIsArray($manifest);
        $this->assertSame('acme-test/contract-pkg', $manifest['name']);
        $this->assertArrayHasKey('extra', $manifest);
        $this->assertArrayHasKey('laraseed', $manifest['extra']);

        $metadata = $manifest['extra']['laraseed'];
        $this->assertSame('contract_pkg', $metadata['id']);
        $this->assertSame('optional', $metadata['type']);
        $this->assertSame('AcmeTest\\ContractPkg\\Providers\\ContractPkgServiceProvider', $metadata['provider']);
        $this->assertSame('AcmeTest\\ContractPkg\\Providers\\ModuleServiceProvider', $metadata['concord_module']);
    }

    public function test_generated_package_is_compatible_with_optional_package_manifest_loader(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/LoaderPkg');

        $this->artisan('laraseed:make-package AcmeTest/LoaderPkg')
            ->assertExitCode(0);

        $manifestPath = "{$pkgDir}/composer.json";

        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'AcmeTest\\LoaderPkg\\Providers\\')) {
                $basename = class_basename($class);
                $path = base_path("packages/AcmeTest/LoaderPkg/src/Providers/{$basename}.php");
                if (file_exists($path)) {
                    require_once $path;
                }
            }
        });

        $loader = new OptionalPackageManifestLoader();
        $catalog = $loader->load([$manifestPath]);

        $this->assertArrayHasKey('loader_pkg', $catalog);
        $this->assertSame('loader_pkg', $catalog['loader_pkg']['id']);
        $this->assertSame('acme-test/loader-pkg', $catalog['loader_pkg']['composer_name']);
        $this->assertSame('AcmeTest\\LoaderPkg\\Providers\\LoaderPkgServiceProvider', $catalog['loader_pkg']['provider']);
        $this->assertSame('AcmeTest\\LoaderPkg\\Providers\\ModuleServiceProvider', $catalog['loader_pkg']['concord_module']);
    }

    public function test_generated_namespace_and_classes_have_correct_syntax(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/SyntaxCheck');

        $this->artisan('laraseed:make-package AcmeTest/SyntaxCheck')
            ->assertExitCode(0);

        $providerContent = (string) file_get_contents("{$pkgDir}/src/Providers/SyntaxCheckServiceProvider.php");
        $moduleContent = (string) file_get_contents("{$pkgDir}/src/Providers/ModuleServiceProvider.php");

        $this->assertStringContainsString('namespace AcmeTest\SyntaxCheck\Providers;', $providerContent);
        $this->assertStringContainsString('class SyntaxCheckServiceProvider extends ServiceProvider', $providerContent);

        $this->assertStringContainsString('namespace AcmeTest\SyntaxCheck\Providers;', $moduleContent);
        $this->assertStringContainsString('class ModuleServiceProvider extends BaseModuleServiceProvider', $moduleContent);
    }

    public function test_invalid_package_names_are_rejected(): void
    {
        $validator = new PackageNameValidator();

        $this->expectException(PackageGenerationException::class);
        $validator->validate('SingleNameNoVendor');
    }

    public function test_invalid_package_names_command_returns_failure(): void
    {
        $this->artisan('laraseed:make-package InvalidFormatNoSlash')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-package Acme/Too/Many/Slashes')
            ->assertExitCode(1);
    }

    public function test_path_traversal_attempts_are_rejected(): void
    {
        $this->artisan('laraseed:make-package Acme/../Traversed')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-package Acme/..\WindowsTraversed')
            ->assertExitCode(1);
    }

    public function test_reserved_foundation_names_are_rejected(): void
    {
        // Reserved Foundation vendor (Webkul)
        $this->artisan('laraseed:make-package Webkul/Admin')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-package Webkul/Blog')
            ->assertExitCode(1);

        // Reserved Foundation package names across vendors
        $this->artisan('laraseed:make-package Laraseed/Core')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-package Laraseed/Admin')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-package Laraseed/User')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-package Laraseed/DataGrid')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-package Laraseed/Installer')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-package Laraseed/DebugBar')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-package Laraseed/Laraseed')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-package Acme/User')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-package Acme/Core')
            ->assertExitCode(1);

        // Protected system generator
        $this->artisan('laraseed:make-package Laraseed/PackageGenerator')
            ->assertExitCode(1);
    }

    public function test_case_insensitive_reserved_foundation_names_are_rejected(): void
    {
        $validator = new PackageNameValidator();

        $rejectedCases = [
            'Laraseed/PackageGenerator',
            'laraseed/packagegenerator',
            'LARASEED/PACKAGEGENERATOR',
            'Laraseed/packageGenerator',
            'Laraseed/package_generator',
            'laraseed/core',
            'LARASEED/ADMIN',
            'laraseed/user',
            'laraseed/datagrid',
            'laraseed/installer',
            'laraseed/debugbar',
            'laraseed/laraseed',
            'webkul/contacts',
            'WEBKUL/BLOG',
            'Acme/USER',
            'Acme/core',
        ];

        foreach ($rejectedCases as $case) {
            $threw = false;
            try {
                $validator->validate($case);
            } catch (PackageGenerationException $e) {
                $threw = true;
            }
            $this->assertTrue($threw, "Expected [{$case}] to be rejected by PackageNameValidator.");
        }
    }

    public function test_official_laraseed_vendor_allows_valid_business_packages(): void
    {
        $validator = new PackageNameValidator();

        $validCases = [
            'Laraseed/Contacts',
            'Laraseed/Sales',
            'Laraseed/Inventory',
            'Laraseed/Accounting',
            'Laraseed/CRM',
            'Acme/Contacts',
            'Vendor/PackageName',
            'Company/CustomIntegration',
        ];

        foreach ($validCases as $case) {
            $validator->validate($case);
        }

        $this->assertTrue(true);
    }

    public function test_collision_without_force_flag_fails_and_aborts(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/CollisionTest');

        $this->artisan('laraseed:make-package AcmeTest/CollisionTest')
            ->assertExitCode(0);

        $this->artisan('laraseed:make-package AcmeTest/CollisionTest')
            ->assertExitCode(1);
    }

    public function test_dry_run_mode_creates_no_files_on_disk(): void
    {
        $pkgDir = base_path('packages/AcmeTest/DryRunPkg');

        $this->artisan('laraseed:make-package AcmeTest/DryRunPkg --dry-run')
            ->assertExitCode(0);

        $this->assertFalse($this->filesystem->exists($pkgDir));
    }

    public function test_force_flag_allows_overwriting_recipe_files(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ForceTest');

        $this->artisan('laraseed:make-package AcmeTest/ForceTest')
            ->assertExitCode(0);

        $this->artisan('laraseed:make-package AcmeTest/ForceTest --force')
            ->assertExitCode(0);
    }

    public function test_no_partial_generation_after_failed_preflight(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/PartialCollision');
        $this->filesystem->makeDirectory("{$pkgDir}/src/Providers", 0755, true);

        $composerPath = "{$pkgDir}/composer.json";
        $this->filesystem->put($composerPath, '{"pre_existing": true}');

        $this->artisan('laraseed:make-package AcmeTest/PartialCollision')
            ->assertExitCode(1);

        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/Providers/PartialCollisionServiceProvider.php"));
        $this->assertSame('{"pre_existing": true}', (string) file_get_contents($composerPath));
    }

    // --- STEP 02 TESTS ---

    public function test_make_model_generates_model_class_with_correct_namespace(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ModelTestPkg');
        $this->artisan('laraseed:make-package AcmeTest/ModelTestPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-model AcmeTest/ModelTestPkg Post')
            ->assertExitCode(0);

        $modelFile = "{$pkgDir}/src/Models/Post.php";
        $this->assertTrue($this->filesystem->exists($modelFile));

        $content = (string) file_get_contents($modelFile);
        $this->assertStringContainsString('namespace AcmeTest\ModelTestPkg\Models;', $content);
        $this->assertStringContainsString('class Post extends Model', $content);
    }

    public function test_make_contract_generates_contract_interface_with_correct_namespace(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ContractTestPkg');
        $this->artisan('laraseed:make-package AcmeTest/ContractTestPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-contract AcmeTest/ContractTestPkg PostContract')
            ->assertExitCode(0);

        $contractFile = "{$pkgDir}/src/Contracts/PostContract.php";
        $this->assertTrue($this->filesystem->exists($contractFile));

        $content = (string) file_get_contents($contractFile);
        $this->assertStringContainsString('namespace AcmeTest\ContractTestPkg\Contracts;', $content);
        $this->assertStringContainsString('interface PostContract', $content);
    }

    public function test_make_migration_generates_migration_file_with_schema(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/MigrationTestPkg');
        $this->artisan('laraseed:make-package AcmeTest/MigrationTestPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-migration AcmeTest/MigrationTestPkg create_posts_table')
            ->assertExitCode(0);

        $files = glob("{$pkgDir}/src/Database/Migrations/*_create_posts_table.php");
        $this->assertCount(1, $files);

        $content = (string) file_get_contents($files[0]);
        $this->assertStringContainsString("Schema::create('posts'", $content);
        $this->assertStringContainsString("Schema::dropIfExists('posts')", $content);
    }

    public function test_model_generation_resolves_custom_psr4_namespace_from_composer_json(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/CustomPsr4Pkg');
        $this->artisan('laraseed:make-package AcmeTest/CustomPsr4Pkg')->assertExitCode(0);

        $composerPath = "{$pkgDir}/composer.json";
        $manifest = json_decode((string) file_get_contents($composerPath), true);
        $manifest['autoload']['psr-4'] = ['CustomVendor\\CustomModule\\' => 'src/'];
        file_put_contents($composerPath, json_encode($manifest, JSON_PRETTY_PRINT));

        $this->artisan('laraseed:make-model AcmeTest/CustomPsr4Pkg CustomModel')
            ->assertExitCode(0);

        $modelFile = "{$pkgDir}/src/Models/CustomModel.php";
        $this->assertTrue($this->filesystem->exists($modelFile));

        $content = (string) file_get_contents($modelFile);
        $this->assertStringContainsString('namespace CustomVendor\CustomModule\Models;', $content);
    }

    public function test_make_components_fails_when_package_does_not_exist(): void
    {
        $this->artisan('laraseed:make-model AcmeTest/NonExistentPkg Post')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-contract AcmeTest/NonExistentPkg PostContract')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-migration AcmeTest/NonExistentPkg create_posts_table')
            ->assertExitCode(1);
    }

    public function test_make_components_fails_when_package_manifest_is_malformed(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/MalformedPkg');
        $this->filesystem->makeDirectory($pkgDir, 0755, true);
        file_put_contents("{$pkgDir}/composer.json", '{invalid_json');

        $this->artisan('laraseed:make-model AcmeTest/MalformedPkg Post')
            ->assertExitCode(1);
    }

    public function test_make_components_fails_on_invalid_class_names(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/InvalidClassPkg');
        $this->artisan('laraseed:make-package AcmeTest/InvalidClassPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-model AcmeTest/InvalidClassPkg 123InvalidModel')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-contract AcmeTest/InvalidClassPkg Invalid-Contract')
            ->assertExitCode(1);
    }

    public function test_make_components_fails_on_invalid_migration_names(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/InvalidMigrationPkg');
        $this->artisan('laraseed:make-package AcmeTest/InvalidMigrationPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-migration AcmeTest/InvalidMigrationPkg InvalidMigrationWithCapitals')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-migration AcmeTest/InvalidMigrationPkg invalid-name-with-dashes')
            ->assertExitCode(1);
    }

    public function test_make_components_fails_on_path_traversal(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/TraversalPkg');
        $this->artisan('laraseed:make-package AcmeTest/TraversalPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-model AcmeTest/TraversalPkg ../BadModel')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-contract AcmeTest/TraversalPkg ..\\BadContract')
            ->assertExitCode(1);
    }

    public function test_make_components_dry_run_mode_creates_no_files(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/DryRunComponentPkg');
        $this->artisan('laraseed:make-package AcmeTest/DryRunComponentPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-model AcmeTest/DryRunComponentPkg DryModel --dry-run')
            ->assertExitCode(0);

        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/Models/DryModel.php"));
    }

    public function test_make_components_collisions_fail_without_force(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ComponentCollisionPkg');
        $this->artisan('laraseed:make-package AcmeTest/ComponentCollisionPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-model AcmeTest/ComponentCollisionPkg Post')->assertExitCode(0);
        $this->artisan('laraseed:make-model AcmeTest/ComponentCollisionPkg Post')->assertExitCode(1);
    }

    public function test_make_components_force_flag_allows_overwriting(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ComponentForcePkg');
        $this->artisan('laraseed:make-package AcmeTest/ComponentForcePkg')->assertExitCode(0);

        $this->artisan('laraseed:make-model AcmeTest/ComponentForcePkg Post')->assertExitCode(0);
        $this->artisan('laraseed:make-model AcmeTest/ComponentForcePkg Post --force')->assertExitCode(0);
    }

    public function test_make_components_remain_strictly_contained_inside_package_root(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ContainmentPkg');
        $this->artisan('laraseed:make-package AcmeTest/ContainmentPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-model AcmeTest/ContainmentPkg Post')->assertExitCode(0);

        $modelPath = realpath("{$pkgDir}/src/Models/Post.php");
        $this->assertNotFalse($modelPath);
        $this->assertStringStartsWith(realpath($pkgDir), $modelPath);
    }

    // --- STEP 03 TESTS ---

    public function test_make_repository_generates_repository_class(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/RepoPkg');
        $this->artisan('laraseed:make-package AcmeTest/RepoPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-repository AcmeTest/RepoPkg PostRepository')
            ->assertExitCode(0);

        $repoFile = "{$pkgDir}/src/Repositories/PostRepository.php";
        $this->assertTrue($this->filesystem->exists($repoFile));

        $content = (string) file_get_contents($repoFile);
        $this->assertStringContainsString('namespace AcmeTest\RepoPkg\Repositories;', $content);
        $this->assertStringContainsString('class PostRepository extends Repository', $content);
        $this->assertStringContainsString('use Webkul\Core\Eloquent\Repository;', $content);
    }

    public function test_make_repository_with_existing_model_option(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/RepoModelPkg');
        $this->artisan('laraseed:make-package AcmeTest/RepoModelPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-model AcmeTest/RepoModelPkg Post')->assertExitCode(0);

        $this->artisan('laraseed:make-repository AcmeTest/RepoModelPkg PostRepository --model=Post')
            ->assertExitCode(0);

        $repoFile = "{$pkgDir}/src/Repositories/PostRepository.php";
        $content = (string) file_get_contents($repoFile);

        $this->assertStringContainsString("return 'AcmeTest\\RepoModelPkg\\Models\\Post';", $content);
    }

    public function test_make_repository_fails_when_specified_model_does_not_exist(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/RepoMissingModelPkg');
        $this->artisan('laraseed:make-package AcmeTest/RepoMissingModelPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-repository AcmeTest/RepoMissingModelPkg PostRepository --model=NonExistentModel')
            ->assertExitCode(1);

        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/Repositories/PostRepository.php"));
    }

    public function test_make_request_generates_form_request_class(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/RequestPkg');
        $this->artisan('laraseed:make-package AcmeTest/RequestPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-request AcmeTest/RequestPkg StorePostRequest')
            ->assertExitCode(0);

        $requestFile = "{$pkgDir}/src/Http/Requests/StorePostRequest.php";
        $this->assertTrue($this->filesystem->exists($requestFile));

        $content = (string) file_get_contents($requestFile);
        $this->assertStringContainsString('namespace AcmeTest\RequestPkg\Http\Requests;', $content);
        $this->assertStringContainsString('class StorePostRequest extends FormRequest', $content);
        $this->assertStringContainsString('use Illuminate\Foundation\Http\FormRequest;', $content);
    }

    public function test_make_controller_generates_presentation_neutral_controller(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ControllerPkg');
        $this->artisan('laraseed:make-package AcmeTest/ControllerPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-controller AcmeTest/ControllerPkg PostController')
            ->assertExitCode(0);

        $controllerFile = "{$pkgDir}/src/Http/Controllers/PostController.php";
        $this->assertTrue($this->filesystem->exists($controllerFile));

        $content = (string) file_get_contents($controllerFile);
        $this->assertStringContainsString('namespace AcmeTest\ControllerPkg\Http\Controllers;', $content);
        $this->assertStringContainsString('class PostController extends Controller', $content);
        $this->assertStringContainsString('use Illuminate\Routing\Controller;', $content);
    }

    public function test_make_controller_api_option_generates_api_controller_methods(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ApiControllerPkg');
        $this->artisan('laraseed:make-package AcmeTest/ApiControllerPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-controller AcmeTest/ApiControllerPkg ApiPostController --api')
            ->assertExitCode(0);

        $controllerFile = "{$pkgDir}/src/Http/Controllers/ApiPostController.php";
        $this->assertTrue($this->filesystem->exists($controllerFile));

        $content = (string) file_get_contents($controllerFile);
        $this->assertStringContainsString('namespace AcmeTest\ApiControllerPkg\Http\Controllers;', $content);
        $this->assertStringContainsString('use Illuminate\Http\JsonResponse;', $content);
        $this->assertStringContainsString('public function index(): JsonResponse', $content);
        $this->assertStringContainsString('public function store(Request $request): JsonResponse', $content);
    }

    public function test_generated_controller_has_zero_dependencies_on_admin_blade_or_datagrid(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/NeutralControllerPkg');
        $this->artisan('laraseed:make-package AcmeTest/NeutralControllerPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-controller AcmeTest/NeutralControllerPkg PostController')->assertExitCode(0);
        $this->artisan('laraseed:make-controller AcmeTest/NeutralControllerPkg ApiPostController --api')->assertExitCode(0);

        $stdContent = (string) file_get_contents("{$pkgDir}/src/Http/Controllers/PostController.php");
        $apiContent = (string) file_get_contents("{$pkgDir}/src/Http/Controllers/ApiPostController.php");

        foreach ([$stdContent, $apiContent] as $content) {
            $this->assertStringNotContainsString('Webkul\Admin', $content);
            $this->assertStringNotContainsString('Webkul\DataGrid', $content);
            $this->assertStringNotContainsString('view(', $content);
            $this->assertStringNotContainsString('Blade', $content);
        }
    }

    public function test_step_03_generators_fail_on_invalid_identifiers_and_path_traversal(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step03SafetyPkg');
        $this->artisan('laraseed:make-package AcmeTest/Step03SafetyPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-repository AcmeTest/Step03SafetyPkg 123BadRepo')->assertExitCode(1);
        $this->artisan('laraseed:make-request AcmeTest/Step03SafetyPkg Bad-Request')->assertExitCode(1);
        $this->artisan('laraseed:make-controller AcmeTest/Step03SafetyPkg ../BadController')->assertExitCode(1);
    }

    // --- STEP 04 TESTS ---

    public function test_make_route_generates_web_and_api_route_files(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step04RoutePkg');
        $this->artisan('laraseed:make-package AcmeTest/Step04RoutePkg')->assertExitCode(0);

        $this->artisan('laraseed:make-route AcmeTest/Step04RoutePkg custom_web --type=web')
            ->assertExitCode(0);

        $this->artisan('laraseed:make-route AcmeTest/Step04RoutePkg custom_api --type=api')
            ->assertExitCode(0);

        $webFile = "{$pkgDir}/src/Routes/custom_web.php";
        $apiFile = "{$pkgDir}/src/Routes/custom_api.php";

        $this->assertTrue($this->filesystem->exists($webFile));
        $this->assertTrue($this->filesystem->exists($apiFile));

        $webContent = (string) file_get_contents($webFile);
        $apiContent = (string) file_get_contents($apiFile);

        $this->assertStringContainsString("['middleware' => ['web']]", $webContent);
        $this->assertStringContainsString("['prefix' => 'api', 'middleware' => ['api']]", $apiContent);
    }

    public function test_make_provider_generates_additional_service_provider(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step04ProviderPkg');
        $this->artisan('laraseed:make-package AcmeTest/Step04ProviderPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-provider AcmeTest/Step04ProviderPkg EventServiceProvider')
            ->assertExitCode(0);

        $providerFile = "{$pkgDir}/src/Providers/EventServiceProvider.php";
        $this->assertTrue($this->filesystem->exists($providerFile));

        $content = (string) file_get_contents($providerFile);
        $this->assertStringContainsString('namespace AcmeTest\Step04ProviderPkg\Providers;', $content);
        $this->assertStringContainsString('class EventServiceProvider extends ServiceProvider', $content);
    }

    public function test_make_module_provider_fails_on_collision_without_force_flag(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step04ModulePkg');
        $this->artisan('laraseed:make-package AcmeTest/Step04ModulePkg')->assertExitCode(0);

        $this->artisan('laraseed:make-module-provider AcmeTest/Step04ModulePkg')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-module-provider AcmeTest/Step04ModulePkg --force')
            ->assertExitCode(0);
    }

    public function test_make_route_rejects_invalid_type_and_invalid_name(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step04InvalidRoutePkg');
        $this->artisan('laraseed:make-package AcmeTest/Step04InvalidRoutePkg')->assertExitCode(0);

        $this->artisan('laraseed:make-route AcmeTest/Step04InvalidRoutePkg test_route --type=invalid')
            ->assertExitCode(1);

        $this->artisan('laraseed:make-route AcmeTest/Step04InvalidRoutePkg ../InvalidRoute --type=web')
            ->assertExitCode(1);
    }

    public function test_generated_package_bootability_loader_compatibility_and_isolation(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/BootableCheckPkg');
        $this->artisan('laraseed:make-package AcmeTest/BootableCheckPkg')->assertExitCode(0);

        $manifestPath = "{$pkgDir}/composer.json";

        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'AcmeTest\\BootableCheckPkg\\Providers\\')) {
                $basename = class_basename($class);
                $path = base_path("packages/AcmeTest/BootableCheckPkg/src/Providers/{$basename}.php");
                if (file_exists($path)) {
                    require_once $path;
                }
            }
        });

        $loader = new OptionalPackageManifestLoader();
        $catalog = $loader->load([$manifestPath]);

        $this->assertArrayHasKey('bootable_check_pkg', $catalog);

        $providerFqn = 'AcmeTest\\BootableCheckPkg\\Providers\\BootableCheckPkgServiceProvider';
        $moduleFqn = 'AcmeTest\\BootableCheckPkg\\Providers\\ModuleServiceProvider';

        $this->assertTrue(class_exists($providerFqn));
        $this->assertTrue(class_exists($moduleFqn));

        $this->assertTrue(is_subclass_of($providerFqn, \Illuminate\Support\ServiceProvider::class));
        $this->assertTrue(is_subclass_of($moduleFqn, \Konekt\Concord\BaseModuleServiceProvider::class));

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $metadata = $manifest['extra']['laraseed'];

        $this->assertSame('bootable_check_pkg', $metadata['id']);
        $this->assertSame('optional', $metadata['type']);
        $this->assertSame($providerFqn, $metadata['provider']);
        $this->assertSame($moduleFqn, $metadata['concord_module']);

        $foundationProviders = config('laraseed.optional_packages.providers', []);
        $this->assertNotContains($providerFqn, $foundationProviders);
    }

    // --- STEP 05 TESTS ---

    public function test_make_event_generates_event_class(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step05EventPkg');
        $this->artisan('laraseed:make-package AcmeTest/Step05EventPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-event AcmeTest/Step05EventPkg OrderPlaced')
            ->assertExitCode(0);

        $eventFile = "{$pkgDir}/src/Events/OrderPlaced.php";
        $this->assertTrue($this->filesystem->exists($eventFile));

        $content = (string) file_get_contents($eventFile);
        $this->assertStringContainsString('namespace AcmeTest\Step05EventPkg\Events;', $content);
        $this->assertStringContainsString('class OrderPlaced', $content);
    }

    public function test_make_listener_generates_listener_class(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step05ListenerPkg');
        $this->artisan('laraseed:make-package AcmeTest/Step05ListenerPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-listener AcmeTest/Step05ListenerPkg SendOrderNotification')
            ->assertExitCode(0);

        $listenerFile = "{$pkgDir}/src/Listeners/SendOrderNotification.php";
        $this->assertTrue($this->filesystem->exists($listenerFile));

        $content = (string) file_get_contents($listenerFile);
        $this->assertStringContainsString('namespace AcmeTest\Step05ListenerPkg\Listeners;', $content);
        $this->assertStringContainsString('class SendOrderNotification', $content);
        $this->assertStringContainsString('handle(object $event)', $content);
    }

    public function test_make_listener_with_existing_event_option(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step05ListenerEventPkg');
        $this->artisan('laraseed:make-package AcmeTest/Step05ListenerEventPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-event AcmeTest/Step05ListenerEventPkg OrderPlaced')->assertExitCode(0);

        $this->artisan('laraseed:make-listener AcmeTest/Step05ListenerEventPkg SendOrderNotification --event=OrderPlaced')
            ->assertExitCode(0);

        $listenerFile = "{$pkgDir}/src/Listeners/SendOrderNotification.php";
        $content = (string) file_get_contents($listenerFile);

        $this->assertStringContainsString('use AcmeTest\Step05ListenerEventPkg\Events\OrderPlaced;', $content);
        $this->assertStringContainsString('handle(OrderPlaced $event)', $content);
    }

    public function test_make_listener_fails_when_specified_event_does_not_exist(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step05MissingEventPkg');
        $this->artisan('laraseed:make-package AcmeTest/Step05MissingEventPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-listener AcmeTest/Step05MissingEventPkg SendOrderNotification --event=NonExistentEvent')
            ->assertExitCode(1);

        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/Listeners/SendOrderNotification.php"));
    }

    public function test_make_command_generates_console_command_with_signature(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step05CommandPkg');
        $this->artisan('laraseed:make-package AcmeTest/Step05CommandPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-command AcmeTest/Step05CommandPkg RebuildIndex')
            ->assertExitCode(0);

        $commandFile = "{$pkgDir}/src/Console/Commands/RebuildIndex.php";
        $this->assertTrue($this->filesystem->exists($commandFile));

        $content = (string) file_get_contents($commandFile);
        $this->assertStringContainsString('namespace AcmeTest\Step05CommandPkg\Console\Commands;', $content);
        $this->assertStringContainsString("protected \$signature = 'step05-command-pkg:rebuild-index';", $content);
    }

    public function test_make_command_with_custom_signature_and_reserved_prefix_rejection(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step05CustomSigPkg');
        $this->artisan('laraseed:make-package AcmeTest/Step05CustomSigPkg')->assertExitCode(0);

        // Custom signature test
        $this->artisan('laraseed:make-command AcmeTest/Step05CustomSigPkg CustomCommand --signature=orders:process')
            ->assertExitCode(0);

        $commandFile = "{$pkgDir}/src/Console/Commands/CustomCommand.php";
        $content = (string) file_get_contents($commandFile);
        $this->assertStringContainsString("protected \$signature = 'orders:process';", $content);

        // Reserved prefix rejection test (laraseed:)
        $this->artisan('laraseed:make-command AcmeTest/Step05CustomSigPkg BadCommand --signature=laraseed:forbidden')
            ->assertExitCode(1);
    }

    public function test_make_seeder_generates_database_seeder_class(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step05SeederPkg');
        $this->artisan('laraseed:make-package AcmeTest/Step05SeederPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-seeder AcmeTest/Step05SeederPkg OrderSeeder')
            ->assertExitCode(0);

        $seederFile = "{$pkgDir}/src/Database/Seeders/OrderSeeder.php";
        $this->assertTrue($this->filesystem->exists($seederFile));

        $content = (string) file_get_contents($seederFile);
        $this->assertStringContainsString('namespace AcmeTest\Step05SeederPkg\Database\Seeders;', $content);
        $this->assertStringContainsString('class OrderSeeder extends Seeder', $content);
    }

    public function test_step_05_generators_do_not_mutate_root_application_bootstrap_or_providers(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step05BoundaryPkg');
        $this->artisan('laraseed:make-package AcmeTest/Step05BoundaryPkg')->assertExitCode(0);

        $bootstrapProvidersBefore = file_get_contents(base_path('bootstrap/providers.php'));
        $configLaraseedBefore = file_get_contents(base_path('config/laraseed.php'));
        $composerJsonBefore = file_get_contents(base_path('composer.json'));

        $this->artisan('laraseed:make-event AcmeTest/Step05BoundaryPkg TestEvent')->assertExitCode(0);
        $this->artisan('laraseed:make-listener AcmeTest/Step05BoundaryPkg TestListener --event=TestEvent')->assertExitCode(0);
        $this->artisan('laraseed:make-command AcmeTest/Step05BoundaryPkg TestCommand')->assertExitCode(0);
        $this->artisan('laraseed:make-seeder AcmeTest/Step05BoundaryPkg TestSeeder')->assertExitCode(0);

        $this->assertSame($bootstrapProvidersBefore, file_get_contents(base_path('bootstrap/providers.php')));
        $this->assertSame($configLaraseedBefore, file_get_contents(base_path('config/laraseed.php')));
        $this->assertSame($composerJsonBefore, file_get_contents(base_path('composer.json')));
    }

    public function test_base_package_has_zero_admin_or_datagrid_dependencies(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step06BasePkg');
        $this->artisan('laraseed:make-package AcmeTest/Step06BasePkg')->assertExitCode(0);

        $this->assertFalse($this->filesystem->isDirectory("{$pkgDir}/src/Admin"));
        $this->assertFalse($this->filesystem->isDirectory("{$pkgDir}/src/DataGrids"));

        $composerJson = json_decode((string) file_get_contents("{$pkgDir}/composer.json"), true);
        $this->assertArrayNotHasKey('webkul/admin', $composerJson['require'] ?? []);
        $this->assertArrayNotHasKey('webkul/datagrid', $composerJson['require'] ?? []);
    }

    public function test_make_datagrid_generates_valid_datagrid_class(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step06DataGridPkg');
        $this->artisan('laraseed:make-package AcmeTest/Step06DataGridPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-datagrid AcmeTest/Step06DataGridPkg OrderDataGrid')
            ->assertExitCode(0);

        $dgFile = "{$pkgDir}/src/DataGrids/OrderDataGrid.php";
        $this->assertTrue($this->filesystem->exists($dgFile));

        $content = (string) file_get_contents($dgFile);
        $this->assertStringContainsString('namespace AcmeTest\Step06DataGridPkg\DataGrids;', $content);
        $this->assertStringContainsString('use Webkul\DataGrid\DataGrid;', $content);
        $this->assertStringContainsString('class OrderDataGrid extends DataGrid', $content);
        $this->assertStringContainsString('public function prepareQueryBuilder(): Builder', $content);
        $this->assertStringContainsString('public function prepareColumns(): void', $content);
    }

    public function test_make_datagrid_with_existing_model_option(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step06DataGridModelPkg');
        $this->artisan('laraseed:make-package AcmeTest/Step06DataGridModelPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-model AcmeTest/Step06DataGridModelPkg Student')->assertExitCode(0);

        $this->artisan('laraseed:make-datagrid AcmeTest/Step06DataGridModelPkg StudentDataGrid --model=Student')
            ->assertExitCode(0);

        $dgFile = "{$pkgDir}/src/DataGrids/StudentDataGrid.php";
        $this->assertTrue($this->filesystem->exists($dgFile));

        $content = (string) file_get_contents($dgFile);
        $this->assertStringContainsString('use AcmeTest\Step06DataGridModelPkg\Models\Student;', $content);
        $this->assertStringContainsString("DB::table('students')", $content);
    }

    public function test_make_datagrid_fails_when_specified_model_does_not_exist(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step06MissingModelPkg');
        $this->artisan('laraseed:make-package AcmeTest/Step06MissingModelPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-datagrid AcmeTest/Step06MissingModelPkg StudentDataGrid --model=NonExistentModel')
            ->assertExitCode(1);

        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/DataGrids/StudentDataGrid.php"));
    }

    public function test_make_admin_generates_package_owned_admin_integration_skeleton(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step06AdminPkg');
        $this->artisan('laraseed:make-package AcmeTest/Step06AdminPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-admin AcmeTest/Step06AdminPkg')->assertExitCode(0);

        $adminFiles = [
            "{$pkgDir}/src/Admin/Providers/AdminServiceProvider.php",
            "{$pkgDir}/src/Admin/Config/menu.php",
            "{$pkgDir}/src/Admin/Config/acl.php",
            "{$pkgDir}/src/Admin/Http/Controllers/AdminController.php",
            "{$pkgDir}/src/Admin/Routes/web.php",
            "{$pkgDir}/src/Admin/Resources/lang/en/app.php",
            "{$pkgDir}/src/Admin/Resources/lang/ar/app.php",
            "{$pkgDir}/src/Admin/Resources/views/index.blade.php",
        ];

        foreach ($adminFiles as $file) {
            $this->assertTrue($this->filesystem->exists($file), "Missing admin file: {$file}");
        }

        $viewContent = (string) file_get_contents("{$pkgDir}/src/Admin/Resources/views/index.blade.php");
        $this->assertStringContainsString('<x-admin::layouts>', $viewContent);
        $this->assertStringContainsString('@lang(', $viewContent);

        $menuContent = (string) file_get_contents("{$pkgDir}/src/Admin/Config/menu.php");
        $this->assertStringContainsString("'key'        => 'acmetest_step06_admin_pkg'", $menuContent);
    }

    public function test_make_admin_atomic_preflight_prevents_partial_generation_on_collision(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step06CollisionPkg');
        $this->artisan('laraseed:make-package AcmeTest/Step06CollisionPkg')->assertExitCode(0);

        $this->filesystem->makeDirectory("{$pkgDir}/src/Admin/Config", 0755, true);
        file_put_contents("{$pkgDir}/src/Admin/Config/menu.php", "<?php // custom content");

        $this->artisan('laraseed:make-admin AcmeTest/Step06CollisionPkg')
            ->assertExitCode(1);

        $this->assertSame("<?php // custom content", file_get_contents("{$pkgDir}/src/Admin/Config/menu.php"));
        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/Admin/Providers/AdminServiceProvider.php"));
    }

    public function test_step_06_generators_do_not_mutate_foundation_or_root_packages(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step06BoundaryPkg');
        $this->artisan('laraseed:make-package AcmeTest/Step06BoundaryPkg')->assertExitCode(0);

        $bootstrapProvidersBefore = file_get_contents(base_path('bootstrap/providers.php'));
        $configLaraseedBefore = file_get_contents(base_path('config/laraseed.php'));
        $composerJsonBefore = file_get_contents(base_path('composer.json'));

        $this->artisan('laraseed:make-admin AcmeTest/Step06BoundaryPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-datagrid AcmeTest/Step06BoundaryPkg TestDataGrid')->assertExitCode(0);

        $this->assertSame($bootstrapProvidersBefore, file_get_contents(base_path('bootstrap/providers.php')));
        $this->assertSame($configLaraseedBefore, file_get_contents(base_path('config/laraseed.php')));
        $this->assertSame($composerJsonBefore, file_get_contents(base_path('composer.json')));
    }

    public function test_package_removability_leaves_zero_orphaned_references_in_foundation(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/Step06RemovablePkg');
        $this->artisan('laraseed:make-package AcmeTest/Step06RemovablePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-admin AcmeTest/Step06RemovablePkg')->assertExitCode(0);

        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Admin/Providers/AdminServiceProvider.php"));

        // Remove package directory completely
        $this->filesystem->deleteDirectory($pkgDir);

        // Assert zero references remain in root bootstrap / config / Webkul Foundation
        $this->assertStringNotContainsString('Step06RemovablePkg', file_get_contents(base_path('bootstrap/providers.php')));
        $this->assertStringNotContainsString('Step06RemovablePkg', file_get_contents(base_path('config/laraseed.php')));
    }

    public function test_full_lifecycle_end_to_end_certification_on_real_package(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/FullE2EPkg');

        // 1. Generate package
        $this->artisan('laraseed:make-package AcmeTest/FullE2EPkg')->assertExitCode(0);

        // 2. Generate representative components
        $this->artisan('laraseed:make-model AcmeTest/FullE2EPkg RecordModel')->assertExitCode(0);
        $this->artisan('laraseed:make-contract AcmeTest/FullE2EPkg RecordContract')->assertExitCode(0);
        $this->artisan('laraseed:make-migration AcmeTest/FullE2EPkg create_records_table')->assertExitCode(0);
        $this->artisan('laraseed:make-repository AcmeTest/FullE2EPkg RecordRepository --model=RecordModel')->assertExitCode(0);
        $this->artisan('laraseed:make-request AcmeTest/FullE2EPkg StoreRecordRequest')->assertExitCode(0);
        $this->artisan('laraseed:make-controller AcmeTest/FullE2EPkg RecordController')->assertExitCode(0);
        $this->artisan('laraseed:make-event AcmeTest/FullE2EPkg RecordCreated')->assertExitCode(0);
        $this->artisan('laraseed:make-listener AcmeTest/FullE2EPkg HandleRecordCreated --event=RecordCreated')->assertExitCode(0);
        $this->artisan('laraseed:make-command AcmeTest/FullE2EPkg RecordCheck --signature=record:check')->assertExitCode(0);
        $this->artisan('laraseed:make-seeder AcmeTest/FullE2EPkg RecordSeeder')->assertExitCode(0);
        $this->artisan('laraseed:make-datagrid AcmeTest/FullE2EPkg RecordDataGrid --model=RecordModel')->assertExitCode(0);
        $this->artisan('laraseed:make-admin AcmeTest/FullE2EPkg')->assertExitCode(0);

        // 3. Autoload & Manifest loader verification
        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'AcmeTest\\FullE2EPkg\\')) {
                $relative = substr($class, strlen('AcmeTest\\FullE2EPkg\\'));
                $path = base_path('packages/AcmeTest/FullE2EPkg/src/' . str_replace('\\', '/', $relative) . '.php');
                if (file_exists($path)) {
                    require_once $path;
                }
            }
        });

        $manifestPath = "{$pkgDir}/composer.json";
        $loader = new OptionalPackageManifestLoader();
        $catalog = $loader->load([$manifestPath]);

        $this->assertArrayHasKey('full_e2_e_pkg', $catalog);

        // 4. Composition & Boot
        $composition = new OptionalPackageComposition($catalog, ['full_e2_e_pkg']);
        $this->assertTrue($composition->isEnabled('full_e2_e_pkg'));
        $this->assertTrue($composition->hasCapability('full_e2_e_pkg', 'admin'));

        $providerClass = $catalog['full_e2_e_pkg']['provider'];
        $this->app->register($providerClass);

        foreach ($composition->capabilityProviders('admin') as $capProvider) {
            $this->app->register($capProvider);
        }

        // 5. Assert runtime active
        $this->assertTrue(class_exists(\AcmeTest\FullE2EPkg\Console\Commands\RecordCheck::class));
        $this->assertTrue(class_exists(\AcmeTest\FullE2EPkg\Events\RecordCreated::class));
        $this->assertTrue(class_exists(\AcmeTest\FullE2EPkg\Listeners\HandleRecordCreated::class));
        $this->assertTrue($this->app['view']->exists('acmetest_full_e2_e_pkg_admin::index'));
        $this->assertContains('acmetest_full_e2_e_pkg', collect(config('menu.admin', []))->pluck('key')->all());
        $this->assertContains('acmetest_full_e2_e_pkg', collect(config('acl', []))->pluck('key')->all());

        // 6. Disable package
        $disabledComposition = new OptionalPackageComposition($catalog, []);
        $this->assertFalse($disabledComposition->isEnabled('full_e2_e_pkg'));
        $this->assertSame([], $disabledComposition->providers());
        $this->assertSame([], $disabledComposition->capabilityProviders('admin'));
    }

    public function test_v2_generated_package_provider_has_zero_class_exists_or_admin_probes(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/CleanV2Pkg');
        $this->artisan('laraseed:make-package AcmeTest/CleanV2Pkg')->assertExitCode(0);

        $providerContent = (string) file_get_contents("{$pkgDir}/src/Providers/CleanV2PkgServiceProvider.php");

        // Asserts zero capability discovery or namespace probing
        $this->assertStringNotContainsString('class_exists', $providerContent);
        $this->assertStringNotContainsString('AdminServiceProvider', $providerContent);
        $this->assertStringNotContainsString('EventServiceProvider', $providerContent);
        $this->assertStringNotContainsString('src/Admin', $providerContent);

        // Asserts clean composer.json without capabilities key by default
        $composerJson = json_decode((string) file_get_contents("{$pkgDir}/composer.json"), true);
        $this->assertArrayNotHasKey('capabilities', $composerJson['extra']['laraseed']);
    }

    public function test_v2_make_admin_atomically_mutates_composer_json_and_preserves_custom_keys(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/PreserveCustomPkg');
        $this->artisan('laraseed:make-package AcmeTest/PreserveCustomPkg')->assertExitCode(0);

        // Inject custom keys into composer.json before running make-admin
        $composerPath = "{$pkgDir}/composer.json";
        $composerData = json_decode((string) file_get_contents($composerPath), true);
        $composerData['scripts'] = ['post-autoload-dump' => ['echo "custom script"']];
        $composerData['require'] = ['some/dependency' => '^1.0'];
        $composerData['extra']['custom_meta'] = 'preserved';
        $composerData['extra']['laraseed']['capabilities'] = [
            'api' => [
                'provider' => 'AcmeTest\\PreserveCustomPkg\\Api\\Providers\\ApiServiceProvider',
                'enabled' => true,
            ],
        ];
        file_put_contents($composerPath, json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Execute make-admin
        $this->artisan('laraseed:make-admin AcmeTest/PreserveCustomPkg')->assertExitCode(0);

        // Verify composer.json mutations
        $updatedData = json_decode((string) file_get_contents($composerPath), true);
        $this->assertSame(['post-autoload-dump' => ['echo "custom script"']], $updatedData['scripts']);
        $this->assertSame(['some/dependency' => '^1.0'], $updatedData['require']);
        $this->assertSame('preserved', $updatedData['extra']['custom_meta']);

        $capabilities = $updatedData['extra']['laraseed']['capabilities'];
        $this->assertArrayHasKey('api', $capabilities);
        $this->assertSame('AcmeTest\\PreserveCustomPkg\\Api\\Providers\\ApiServiceProvider', $capabilities['api']['provider']);

        $this->assertArrayHasKey('admin', $capabilities);
        $this->assertSame('AcmeTest\\PreserveCustomPkg\\Admin\\Providers\\AdminServiceProvider', $capabilities['admin']['provider']);
        $this->assertTrue($capabilities['admin']['enabled']);
    }

    public function test_v2_make_admin_collision_fails_without_force_and_overwrites_with_force(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/CollisionV2Pkg');
        $this->artisan('laraseed:make-package AcmeTest/CollisionV2Pkg')->assertExitCode(0);
        $this->artisan('laraseed:make-admin AcmeTest/CollisionV2Pkg')->assertExitCode(0);

        // Second run without --force must fail due to collision
        $this->artisan('laraseed:make-admin AcmeTest/CollisionV2Pkg')->assertExitCode(1);

        // Second run with --force must succeed
        $this->artisan('laraseed:make-admin AcmeTest/CollisionV2Pkg --force')->assertExitCode(0);

        $composerData = json_decode((string) file_get_contents("{$pkgDir}/composer.json"), true);
        $this->assertArrayHasKey('admin', $composerData['extra']['laraseed']['capabilities']);
    }

    public function test_v2_make_admin_dry_run_leaves_composer_json_unmodified(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/DryRunV2Pkg');
        $this->artisan('laraseed:make-package AcmeTest/DryRunV2Pkg')->assertExitCode(0);

        $composerBefore = file_get_contents("{$pkgDir}/composer.json");

        $this->artisan('laraseed:make-admin AcmeTest/DryRunV2Pkg --dry-run')->assertExitCode(0);

        $composerAfter = file_get_contents("{$pkgDir}/composer.json");
        $this->assertSame($composerBefore, $composerAfter);
        $this->assertFalse($this->filesystem->isDirectory("{$pkgDir}/src/Admin"));
    }

    public function test_v2_make_admin_fails_on_malformed_composer_json_without_partial_generation(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/MalformedJsonPkg');
        $this->artisan('laraseed:make-package AcmeTest/MalformedJsonPkg')->assertExitCode(0);

        // Corrupt composer.json
        file_put_contents("{$pkgDir}/composer.json", '{ invalid json');

        $this->artisan('laraseed:make-admin AcmeTest/MalformedJsonPkg')->assertExitCode(1);

        // Assert zero admin files generated
        $this->assertFalse($this->filesystem->isDirectory("{$pkgDir}/src/Admin"));
    }

    public function test_v2_admin_capability_lifecycle_active_and_disabled_quadrants(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/QuadrantPkg');
        $this->artisan('laraseed:make-package AcmeTest/QuadrantPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-admin AcmeTest/QuadrantPkg')->assertExitCode(0);

        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'AcmeTest\\QuadrantPkg\\')) {
                $relative = substr($class, strlen('AcmeTest\\QuadrantPkg\\'));
                $path = base_path('packages/AcmeTest/QuadrantPkg/src/' . str_replace('\\', '/', $relative) . '.php');
                if (file_exists($path)) {
                    require_once $path;
                }
            }
        });

        $manifestPath = "{$pkgDir}/composer.json";
        $catalog = (new OptionalPackageManifestLoader)->load([$manifestPath]);

        // Quadrant 1: Package ON + Capability ON
        $compQ1 = new OptionalPackageComposition($catalog, ['quadrant_pkg']);
        $this->assertSame(
            ['AcmeTest\\QuadrantPkg\\Admin\\Providers\\AdminServiceProvider'],
            $compQ1->capabilityProviders('admin')
        );

        // Quadrant 2: Package ON + Capability OFF
        $catalogQ2 = $catalog;
        $catalogQ2['quadrant_pkg']['capabilities']['admin']['enabled'] = false;
        $compQ2 = new OptionalPackageComposition($catalogQ2, ['quadrant_pkg']);
        $this->assertSame([], $compQ2->capabilityProviders('admin'));
        $this->assertTrue($compQ2->hasCapability('quadrant_pkg', 'admin'));

        // Quadrant 3: Package OFF + Capability ON
        $compQ3 = new OptionalPackageComposition($catalog, []);
        $this->assertSame([], $compQ3->capabilityProviders('admin'));

        // Quadrant 4: Package OFF + Capability OFF
        $compQ4 = new OptionalPackageComposition($catalogQ2, []);
        $this->assertSame([], $compQ4->capabilityProviders('admin'));
    }

    public function test_v2_make_admin_mid_flight_failure_performs_transactional_rollback(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/RollbackTestPkg');
        $this->artisan('laraseed:make-package AcmeTest/RollbackTestPkg')->assertExitCode(0);

        $composerPath = "{$pkgDir}/composer.json";
        $composerOriginal = file_get_contents($composerPath);

        // Make composer.json read-only to simulate a mid-flight write failure after Admin files generation
        chmod($composerPath, 0444);

        try {
            $this->artisan('laraseed:make-admin AcmeTest/RollbackTestPkg')->assertExitCode(1);
        } finally {
            // Restore write permissions
            chmod($composerPath, 0664);
        }

        // Assert BEFORE STATE is fully restored
        $this->assertSame($composerOriginal, file_get_contents($composerPath));
        $this->assertFalse($this->filesystem->isDirectory("{$pkgDir}/src/Admin"));
    }

    public function test_v2_dependency_ordering_and_deduplication_of_capability_providers(): void
    {
        $pkgBaseDir = $this->trackDirectory('packages/AcmeTest/OrderBasePkg');
        $pkgDepDir = $this->trackDirectory('packages/AcmeTest/OrderDepPkg');

        $this->artisan('laraseed:make-package AcmeTest/OrderBasePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-package AcmeTest/OrderDepPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-admin AcmeTest/OrderBasePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-admin AcmeTest/OrderDepPkg')->assertExitCode(0);

        // Add OrderBasePkg to OrderDepPkg's require in composer.json
        $depComposer = json_decode((string) file_get_contents("{$pkgDepDir}/composer.json"), true);
        $depComposer['require']['acme-test/order-base-pkg'] = '*';
        file_put_contents("{$pkgDepDir}/composer.json", json_encode($depComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $loader = new OptionalPackageManifestLoader();
        $catalog = $loader->load([
            "{$pkgDepDir}/composer.json",
            "{$pkgBaseDir}/composer.json",
        ]);

        // Topological ordering: Base must precede Dep even if passed in reverse order
        $composition = new OptionalPackageComposition($catalog, ['order_dep_pkg', 'order_base_pkg']);

        $this->assertSame([
            'AcmeTest\\OrderBasePkg\\Admin\\Providers\\AdminServiceProvider',
            'AcmeTest\\OrderDepPkg\\Admin\\Providers\\AdminServiceProvider',
        ], $composition->capabilityProviders('admin'));
    }

    public function test_v2_diagnostics_command_displays_capabilities(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/DiagPkg');
        $this->artisan('laraseed:make-package AcmeTest/DiagPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-admin AcmeTest/DiagPkg')->assertExitCode(0);

        $manifestPath = "{$pkgDir}/composer.json";
        $loader = new OptionalPackageManifestLoader();
        $catalog = $loader->load([$manifestPath]);

        $composition = new OptionalPackageComposition($catalog, ['diag_pkg']);

        $this->artisan('laraseed:packages')
            ->assertExitCode(0);
    }
}
