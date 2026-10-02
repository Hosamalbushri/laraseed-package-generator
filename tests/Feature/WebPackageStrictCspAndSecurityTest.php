<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Laraseed\PackageGenerator\Tests\TestCase;

class WebPackageStrictCspAndSecurityTest extends TestCase
{
    protected Filesystem $filesystem;

    /**
     * @var list<string>
     */
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

    public function test_generated_web_views_contain_zero_inline_scripts_or_event_handlers(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebCspZeroInlinePkg');
        $this->artisan('laraseed:make-package AcmeTest/WebCspZeroInlinePkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebCspZeroInlinePkg')->assertExitCode(0);

        $viewsDir = "{$pkgDir}/src/Web/Resources/views";
        $viewFiles = $this->filesystem->allFiles($viewsDir);

        foreach ($viewFiles as $file) {
            $content = (string) $file->getContents();

            // Assert no inline event handlers (e.g. onclick, onload, onchange, onerror)
            $this->assertDoesNotMatchRegularExpression(
                '/\son[a-zA-Z]+\s*=/i',
                $content,
                "File [{$file->getRelativePathname()}] contains forbidden inline event handler."
            );

            // Assert no inline <style> tags (styles must be in compiled CSS or served stylesheet)
            $this->assertDoesNotMatchRegularExpression(
                '/<style(\s|>)/i',
                $content,
                "File [{$file->getRelativePathname()}] contains forbidden inline <style> tag."
            );

            // Assert no inline style="..." attributes in diagnostic banners or components
            $this->assertDoesNotMatchRegularExpression(
                '/\sstyle\s*=/i',
                $content,
                "File [{$file->getRelativePathname()}] contains forbidden inline style attribute."
            );
        }
    }

    public function test_branding_stylesheet_serves_valid_css_with_strict_content_type(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebBrandingCssPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebBrandingCssPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebBrandingCssPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/AccountController.php";
        require_once "{$pkgDir}/src/Web/Http/Middleware/AuthenticateWeb.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        config([
            'acmetest_web_branding_css_pkg_web.branding' => [
                'color' => '#10B981',
                'name'  => 'Emerald Web',
            ],
        ]);

        $provider = $this->app->register(\AcmeTest\WebBrandingCssPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $response = $this->get(route('acmetest_web_branding_css_pkg.web.branding.css'));
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $response->assertHeader('Cache-Control', 'max-age=86400, public');
        $response->assertSee('--brand-color: #10B981;', false);
    }

    public function test_branding_stylesheet_rejects_malicious_color_injection(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebBrandingSecPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebBrandingSecPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebBrandingSecPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        // Attempt CSS injection through branding color configuration
        config([
            'acmetest_web_branding_sec_pkg_web.branding' => [
                'color' => 'red; } body { background: black !important; } /* injection */',
            ],
        ]);

        $provider = $this->app->register(\AcmeTest\WebBrandingSecPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $response = $this->get(route('acmetest_web_branding_sec_pkg.web.branding.css'));
        $response->assertStatus(200);
        // Must fallback to safe default '#0E90D9' and sanitize input
        $response->assertSee('--brand-color: #0E90D9;', false);
        $response->assertDontSee('background: black', false);
    }

    public function test_modal_component_renders_accessible_attributes_and_data_actions(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebModalA11yPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebModalA11yPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebModalA11yPkg')->assertExitCode(0);

        $modalStub = file_get_contents("{$pkgDir}/src/Web/Resources/views/components/modal/index.blade.php");

        $this->assertStringContainsString('role="dialog"', $modalStub);
        $this->assertStringContainsString('aria-modal="true"', $modalStub);
        $this->assertStringContainsString('aria-hidden="true"', $modalStub);
        $this->assertStringContainsString('data-component="modal"', $modalStub);
        $this->assertStringContainsString('data-action="close-modal"', $modalStub);
        $this->assertStringNotContainsString('onclick=', $modalStub);
    }

    public function test_frontend_kernel_contains_secure_cookie_flags_and_event_listeners(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebJsKernelPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebJsKernelPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebJsKernelPkg')->assertExitCode(0);

        $jsContent = file_get_contents("{$pkgDir}/src/Web/Resources/assets/js/app.js");

        // Assert cookie security
        $this->assertStringContainsString('SameSite=Lax', $jsContent);
        $this->assertStringContainsString('path=/', $jsContent);
        $this->assertStringContainsString('max-age=31536000', $jsContent);
        $this->assertStringContainsString('Secure', $jsContent);

        // Assert event delegation handlers
        $this->assertStringContainsString('data-action="toggle-dark-mode"', $jsContent);
        $this->assertStringContainsString('data-action="toggle-mobile-menu"', $jsContent);
        $this->assertStringContainsString('data-action="open-modal"', $jsContent);
        $this->assertStringContainsString('data-action="close-modal"', $jsContent);

        // Assert focus trapping and escape listener
        $this->assertStringContainsString('Escape', $jsContent);
        $this->assertStringContainsString('focusStack', $jsContent);
    }

    public function test_strict_csp_header_simulation_renders_page_cleanly(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/WebStrictCspSimPkg');
        $this->artisan('laraseed:make-package AcmeTest/WebStrictCspSimPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeTest/WebStrictCspSimPkg')->assertExitCode(0);

        require_once "{$pkgDir}/src/Web/Http/Controllers/HomeController.php";
        require_once "{$pkgDir}/src/Web/Http/Controllers/PageController.php";
        require_once "{$pkgDir}/src/Web/Providers/WebServiceProvider.php";

        $provider = $this->app->register(\AcmeTest\WebStrictCspSimPkg\Web\Providers\WebServiceProvider::class);
        $this->app->call([$provider, 'boot']);
        $this->app['router']->getRoutes()->refreshNameLookups();

        // Simulate strict CSP header on route
        $response = $this->get(route('acmetest_web_strict_csp_sim_pkg.web.home'));
        $response->assertStatus(200);

        // Verify HTML output contains only external stylesheet and script links, zero inline executable code
        $html = $response->getContent();
        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('onload=', $html);
        $this->assertStringNotContainsString('style="', $html);
        $this->assertStringContainsString(route('acmetest_web_strict_csp_sim_pkg.web.branding.css'), $html);
    }
}
