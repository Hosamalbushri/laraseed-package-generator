<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\FilesystemTransaction;
use Laraseed\PackageGenerator\Generators\GenerationPlan;
use Laraseed\PackageGenerator\Generators\MiddlewareGenerator;
use Symfony\Component\HttpFoundation\Response;
use Laraseed\PackageGenerator\Tests\TestCase;

class MiddlewareGeneratorTest extends TestCase
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

    public function test_make_middleware_generates_standard_middleware_class(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/MiddlewarePkg');
        $this->artisan('laraseed:make-package AcmeTest/MiddlewarePkg')->assertExitCode(0);

        $this->artisan('laraseed:make-middleware AcmeTest/MiddlewarePkg EnsureAccountIsActive')
            ->assertExitCode(0);

        $middlewareFile = "{$pkgDir}/src/Http/Middleware/EnsureAccountIsActive.php";
        $this->assertTrue($this->filesystem->exists($middlewareFile));

        $content = (string) file_get_contents($middlewareFile);
        $this->assertStringContainsString('namespace AcmeTest\MiddlewarePkg\Http\Middleware;', $content);
        $this->assertStringContainsString('class EnsureAccountIsActive', $content);
        $this->assertStringContainsString('public function handle(Request $request, Closure $next): Response', $content);
        $this->assertStringContainsString('return $next($request);', $content);
    }

    public function test_make_middleware_dry_run_mode_creates_no_files(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/MiddlewareDryRunPkg');
        $this->artisan('laraseed:make-package AcmeTest/MiddlewareDryRunPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-middleware AcmeTest/MiddlewareDryRunPkg DryRunMiddleware --dry-run')
            ->assertExitCode(0);

        $middlewareFile = "{$pkgDir}/src/Http/Middleware/DryRunMiddleware.php";
        $this->assertFalse($this->filesystem->exists($middlewareFile));
    }

    public function test_make_middleware_collision_fails_without_force_and_overwrites_with_force(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/MiddlewareCollisionPkg');
        $this->artisan('laraseed:make-package AcmeTest/MiddlewareCollisionPkg')->assertExitCode(0);

        $this->artisan('laraseed:make-middleware AcmeTest/MiddlewareCollisionPkg TestMiddleware')
            ->assertExitCode(0);

        // Second run without force must fail due to collision
        $this->artisan('laraseed:make-middleware AcmeTest/MiddlewareCollisionPkg TestMiddleware')
            ->assertExitCode(1);

        // Second run with force must succeed
        $this->artisan('laraseed:make-middleware AcmeTest/MiddlewareCollisionPkg TestMiddleware --force')
            ->assertExitCode(0);
    }

    public function test_make_middleware_rejects_invalid_identifiers_and_path_traversal(): void
    {
        $this->trackDirectory('packages/AcmeTest/MiddlewareInvalidPkg');
        $this->artisan('laraseed:make-package AcmeTest/MiddlewareInvalidPkg')->assertExitCode(0);

        $invalidNames = [
            '../SneakyMiddleware',
            'Foo/BarMiddleware',
            'Invalid-Name',
            '123InvalidStart',
            'Invalid Space',
            'Invalid@Symbol',
        ];

        foreach ($invalidNames as $name) {
            $this->artisan("laraseed:make-middleware AcmeTest/MiddlewareInvalidPkg \"{$name}\"")
                ->assertExitCode(1);
        }
    }

    public function test_make_middleware_transactional_rollback_on_injected_failure(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/MiddlewareTxPkg');
        $this->artisan('laraseed:make-package AcmeTest/MiddlewareTxPkg')->assertExitCode(0);

        $generator = app(MiddlewareGenerator::class);

        // Intentionally corrupt destination to trigger failure mid-flight or test transaction rollback
        $tx = new FilesystemTransaction(new Filesystem, base_path());
        $plan = new GenerationPlan(
            'packages/AcmeTest/MiddlewareTxPkg',
            base_path(),
            ['src/Http/Middleware/TxTestMiddleware.php' => '<?php // test']
        );

        try {
            $tx->run(function (FilesystemTransaction $currentTx) use ($plan) {
                $currentTx->executePlan($plan, false);
                throw new \RuntimeException('Simulated mid-flight crash during middleware generation');
            });
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Generation failed with transactional rollback: Simulated mid-flight crash during middleware generation', $e->getMessage());
        }

        // Verify transaction rolled back created file cleanly
        $this->assertFalse($this->filesystem->exists("{$pkgDir}/src/Http/Middleware/TxTestMiddleware.php"));
    }

    public function test_generated_middleware_executes_in_laravel_http_pipeline(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/MiddlewarePipelinePkg');
        $this->artisan('laraseed:make-package AcmeTest/MiddlewarePipelinePkg')->assertExitCode(0);

        $this->artisan('laraseed:make-middleware AcmeTest/MiddlewarePipelinePkg CustomHeaderMiddleware')
            ->assertExitCode(0);

        $middlewarePath = "{$pkgDir}/src/Http/Middleware/CustomHeaderMiddleware.php";
        $this->assertTrue(file_exists($middlewarePath));

        require_once $middlewarePath;

        $middlewareClass = 'AcmeTest\MiddlewarePipelinePkg\Http\Middleware\CustomHeaderMiddleware';
        $this->assertTrue(class_exists($middlewareClass));

        // Instantiate and execute in pipeline
        $middleware = new $middlewareClass();
        $request = Request::create('/test-middleware', 'GET');

        $response = $middleware->handle($request, function (Request $req) {
            return response()->json(['status' => 'passed', 'uri' => $req->getRequestUri()]);
        });

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('passed', (string) $response->getContent());
    }
}
