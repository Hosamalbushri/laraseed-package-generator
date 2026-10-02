<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Templates\WebTemplateCatalog;
use Laraseed\PackageGenerator\Tests\TestCase;

class WebTemplateRegistryTest extends TestCase
{
    protected Filesystem $filesystem;

    protected array $createdDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem;
        $this->createdDirectories = [];

        WebTemplateCatalog::reset();
    }

    protected function tearDown(): void
    {
        WebTemplateCatalog::reset();

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

    public function test_catalog_contains_default_starter_template(): void
    {
        $this->assertTrue(WebTemplateCatalog::has('starter'));
        $this->assertSame('starter', WebTemplateCatalog::defaultTemplateId());

        $template = WebTemplateCatalog::get('starter');
        $this->assertSame('starter', $template['id']);
        $this->assertSame('Web Starter', $template['name']);
        $this->assertNotEmpty($template['description']);
        $this->assertIsArray($template['files']);
        $this->assertArrayHasKey('package.json', $template['files']);
        $this->assertArrayHasKey('src/Web/Providers/WebServiceProvider.php', $template['files']);
    }

    public function test_catalog_get_nonexistent_template_throws_exception_with_available_list(): void
    {
        $this->expectException(PackageGenerationException::class);
        $this->expectExceptionMessage('Invalid template [nonexistent]. Available templates: starter');

        WebTemplateCatalog::get('nonexistent');
    }

    public function test_catalog_registers_custom_template_successfully(): void
    {
        WebTemplateCatalog::register('custom_portal', [
            'name'        => 'Custom Portal',
            'description' => 'A custom portal template',
            'files'       => [
                'src/Web/Config/web.php' => 'templates/starter/config.php.stub',
            ],
        ]);

        $this->assertTrue(WebTemplateCatalog::has('custom_portal'));
        $this->assertContains('custom_portal', WebTemplateCatalog::getAvailableTemplateIds());

        $template = WebTemplateCatalog::get('custom_portal');
        $this->assertSame('custom_portal', $template['id']);
        $this->assertSame('Custom Portal', $template['name']);
        $this->assertSame('A custom portal template', $template['description']);
        $this->assertSame(['src/Web/Config/web.php' => 'templates/starter/config.php.stub'], $template['files']);
    }

    public function test_catalog_rejects_invalid_template_identifiers(): void
    {
        $invalidIds = ['InvalidName', 'portal name', 'portal@web', 'portal#1', 'portal/v1'];

        foreach ($invalidIds as $invalidId) {
            try {
                WebTemplateCatalog::register($invalidId, [
                    'name'        => 'Invalid Template',
                    'description' => 'Testing validation',
                    'files'       => ['file.txt' => 'dummy.stub'],
                ]);
                $this->fail("Expected PackageGenerationException for invalid ID: {$invalidId}");
            } catch (PackageGenerationException $e) {
                $this->assertStringContainsString("Invalid template ID [{$invalidId}]", $e->getMessage());
            }
        }
    }

    public function test_catalog_rejects_duplicate_registration_unless_overwritten(): void
    {
        WebTemplateCatalog::register('unique_template', [
            'name'        => 'Unique',
            'description' => 'First registration',
            'files'       => ['file.txt' => 'stub.stub'],
        ]);

        $this->expectException(PackageGenerationException::class);
        $this->expectExceptionMessage('Web template [unique_template] is already registered.');

        WebTemplateCatalog::register('unique_template', [
            'name'        => 'Duplicate',
            'description' => 'Second registration',
            'files'       => ['file.txt' => 'stub.stub'],
        ]);
    }

    public function test_catalog_allows_overwrite_when_explicitly_flagged(): void
    {
        WebTemplateCatalog::register('overwritable', [
            'name'        => 'Original',
            'description' => 'First version',
            'files'       => ['file.txt' => 'stub1.stub'],
        ]);

        WebTemplateCatalog::register('overwritable', [
            'name'        => 'Updated',
            'description' => 'Second version',
            'files'       => ['file.txt' => 'stub2.stub'],
        ], overwrite: true);

        $template = WebTemplateCatalog::get('overwritable');
        $this->assertSame('Updated', $template['name']);
        $this->assertSame('Second version', $template['description']);
        $this->assertSame(['file.txt' => 'stub2.stub'], $template['files']);
    }

    public function test_catalog_validates_definition_schema(): void
    {
        // Missing name
        try {
            WebTemplateCatalog::register('bad_schema_1', [
                'name'        => '',
                'description' => 'Test',
                'files'       => ['a' => 'b'],
            ]);
            $this->fail('Expected failure for empty name');
        } catch (PackageGenerationException $e) {
            $this->assertStringContainsString("must include a non-empty string 'name'", $e->getMessage());
        }

        // Empty files
        try {
            WebTemplateCatalog::register('bad_schema_2', [
                'name'        => 'Good Name',
                'description' => 'Test',
                'files'       => [],
            ]);
            $this->fail('Expected failure for empty files');
        } catch (PackageGenerationException $e) {
            $this->assertStringContainsString("must include a non-empty array of 'files'", $e->getMessage());
        }

        // Invalid file mapping
        try {
            WebTemplateCatalog::register('bad_schema_3', [
                'name'        => 'Good Name',
                'description' => 'Test',
                'files'       => ['' => 'stub.stub'],
            ]);
            $this->fail('Expected failure for empty file key');
        } catch (PackageGenerationException $e) {
            $this->assertStringContainsString('files mapping must contain non-empty string keys and values', $e->getMessage());
        }
    }

    public function test_catalog_unregister_removes_template(): void
    {
        WebTemplateCatalog::register('temporary_tpl', [
            'name'        => 'Temporary',
            'description' => 'Will be removed',
            'files'       => ['file.txt' => 'stub.stub'],
        ]);

        $this->assertTrue(WebTemplateCatalog::has('temporary_tpl'));

        WebTemplateCatalog::unregister('temporary_tpl');

        $this->assertFalse(WebTemplateCatalog::has('temporary_tpl'));
    }

    public function test_catalog_reset_restores_default_starter_only(): void
    {
        WebTemplateCatalog::register('custom_one', [
            'name'        => 'Custom One',
            'description' => 'Desc',
            'files'       => ['file.txt' => 'stub.stub'],
        ]);

        $this->assertTrue(WebTemplateCatalog::has('custom_one'));

        WebTemplateCatalog::reset();

        $this->assertFalse(WebTemplateCatalog::has('custom_one'));
        $this->assertTrue(WebTemplateCatalog::has('starter'));
        $this->assertSame(['starter'], WebTemplateCatalog::getAvailableTemplateIds());
    }

    public function test_catalog_registers_from_config(): void
    {
        config([
            'laraseed.web_templates' => [
                'config_portal' => [
                    'name'        => 'Config Portal',
                    'description' => 'Loaded from config',
                    'files'       => [
                        'src/Web/Config/web.php' => 'templates/starter/config.php.stub',
                    ],
                ],
            ],
        ]);

        WebTemplateCatalog::registerFromConfig();

        $this->assertTrue(WebTemplateCatalog::has('config_portal'));
        $this->assertSame('Config Portal', WebTemplateCatalog::get('config_portal')['name']);
    }

    public function test_make_web_generates_package_using_dynamically_registered_custom_template(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/CustomTemplatePkg');
        $this->artisan('laraseed:make-package AcmeTest/CustomTemplatePkg')->assertExitCode(0);

        // Create a custom external stub
        $customStubDir = $this->trackDirectory('packages/AcmeTest/CustomStubs');
        $this->filesystem->makeDirectory($customStubDir, 0755, true);
        $customStubFile = "{$customStubDir}/custom_landing.blade.php.stub";
        file_put_contents($customStubFile, '<!-- Custom Template for {{ PACKAGE_TITLE }} ({{ TEMPLATE_ID }}) -->');

        WebTemplateCatalog::register('minimal_landing', [
            'name'        => 'Minimal Landing',
            'description' => 'A lightweight landing page template',
            'files'       => [
                'src/Web/Providers/WebServiceProvider.php'            => 'templates/starter/provider.php.stub',
                'src/Web/Config/web.php'                              => 'templates/starter/config.php.stub',
                'src/Web/Resources/views/landing/custom.blade.php'   => $customStubFile,
            ],
        ]);

        $this->artisan('laraseed:make-web AcmeTest/CustomTemplatePkg --template=minimal_landing')
            ->assertExitCode(0);

        // Verify generated files
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Web/Providers/WebServiceProvider.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Web/Config/web.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Web/Resources/views/landing/custom.blade.php"));

        // Verify content and replacement
        $configContent = require "{$pkgDir}/src/Web/Config/web.php";
        $this->assertSame('minimal_landing', $configContent['template']);

        $customViewContent = (string) file_get_contents("{$pkgDir}/src/Web/Resources/views/landing/custom.blade.php");
        $this->assertStringContainsString('Custom Template for Custom Template Pkg (minimal_landing)', $customViewContent);
    }

    public function test_custom_template_enforces_path_containment_and_rejects_path_traversal(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ContainmentPkg');
        $this->artisan('laraseed:make-package AcmeTest/ContainmentPkg')->assertExitCode(0);

        WebTemplateCatalog::register('malicious_template', [
            'name'        => 'Malicious Template',
            'description' => 'Attempts to escape package directory',
            'files'       => [
                '../../evil.php' => 'templates/starter/config.php.stub',
            ],
        ]);

        $this->artisan('laraseed:make-web AcmeTest/ContainmentPkg --template=malicious_template')
            ->assertExitCode(1);

        $this->assertFalse($this->filesystem->exists(base_path('packages/AcmeTest/evil.php')));
        $this->assertFalse($this->filesystem->exists(base_path('evil.php')));
    }

    public function test_custom_template_dry_run_simulates_without_writing_files(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/CustomDryRunPkg');
        $this->artisan('laraseed:make-package AcmeTest/CustomDryRunPkg')->assertExitCode(0);

        $composerBefore = file_get_contents("{$pkgDir}/composer.json");

        WebTemplateCatalog::register('dry_run_template', [
            'name'        => 'Dry Run Template',
            'description' => 'Test dry run',
            'files'       => [
                'src/Web/Providers/WebServiceProvider.php' => 'templates/starter/provider.php.stub',
                'src/Web/Config/web.php'                   => 'templates/starter/config.php.stub',
            ],
        ]);

        $this->artisan('laraseed:make-web AcmeTest/CustomDryRunPkg --template=dry_run_template --dry-run')
            ->assertExitCode(0);

        $this->assertFalse($this->filesystem->isDirectory("{$pkgDir}/src/Web"));
        $this->assertSame($composerBefore, file_get_contents("{$pkgDir}/composer.json"));
    }

    public function test_duplicate_service_provider_registration_behavior(): void
    {
        // Plain package declares both extra.laraseed.provider and extra.laravel.providers
        // In the Laravel service container, Application::register() is idempotent when registering the same provider class.
        $pkgDir = $this->trackDirectory('packages/AcmeTest/ProviderIdempotencyPkg');
        $this->artisan('laraseed:make-package AcmeTest/ProviderIdempotencyPkg --plain')->assertExitCode(0);

        $manifest = json_decode((string) file_get_contents("{$pkgDir}/composer.json"), true);
        $providerClass = $manifest['extra']['laraseed']['provider'];
        $laravelProviders = $manifest['extra']['laravel']['providers'];

        $this->assertSame('AcmeTest\\ProviderIdempotencyPkg\\Providers\\ProviderIdempotencyPkgServiceProvider', $providerClass);
        $this->assertSame([$providerClass], $laravelProviders);

        // Emulate registering the provider twice in Laravel Application container
        require_once "{$pkgDir}/src/Providers/ProviderIdempotencyPkgServiceProvider.php";

        $instance1 = $this->app->register($providerClass);
        $instance2 = $this->app->register($providerClass);

        // Both registration calls return the exact same provider instance without double registration
        $this->assertSame($instance1, $instance2);
        $this->assertInstanceOf($providerClass, $instance1);
    }
}
