<?php

namespace Laraseed\PackageGenerator\Tests;

use Laraseed\PackageGenerator\Providers\PackageGeneratorServiceProvider;
if (class_exists(\Orchestra\Testbench\TestCase::class)) {
    abstract class BaseTestCase extends \Orchestra\Testbench\TestCase {}
} elseif (class_exists(\Tests\TestCase::class)) {
    abstract class BaseTestCase extends \Tests\TestCase {}
} else {
    abstract class BaseTestCase extends \PHPUnit\Framework\TestCase {}
}

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $packageRoot = realpath(__DIR__ . '/../');
        if ($packageRoot) {
            putenv("TESTBENCH_WORKING_PATH={$packageRoot}");
            $_SERVER['TESTBENCH_WORKING_PATH'] = $packageRoot;
            $_ENV['TESTBENCH_WORKING_PATH'] = $packageRoot;
        }

        require_once __DIR__ . '/helpers.php';

        parent::setUp();

        // Register diagnostics command if running standalone
        if (class_exists(\Webkul\Core\Console\Commands\PackageDiagnosticsCommand::class)) {
            $this->app['Illuminate\Contracts\Console\Kernel']->registerCommand(
                $this->app->make(\Webkul\Core\Console\Commands\PackageDiagnosticsCommand::class)
            );
        }

        // If running inside standard Laravel testcase (not Orchestra Testbench), apply test environment setup
        if (! class_exists(\Orchestra\Testbench\TestCase::class) || ! $this instanceof \Orchestra\Testbench\TestCase) {
            $this->defineEnvironment($this->app);
        }

        // Ensure host structure files exist in testbench application for boundary assertions and subprocesses
        $this->ensureTestbenchHostFiles();
    }

    protected function tearDown(): void
    {
        // If testbench created a dummy vendor directory inside orchestra/testbench-core/laravel/vendor, clean it up
        $testbenchVendor = base_path('vendor');
        if (file_exists($testbenchVendor) && is_link($testbenchVendor)) {
            @unlink($testbenchVendor);
        }

        parent::tearDown();
    }

    public function getSubprocessAutoloadPath(): string
    {
        $packageRoot = getenv('TESTBENCH_WORKING_PATH') ?: realpath(__DIR__ . '/../');
        $candidates = array_filter([
            $packageRoot ? realpath($packageRoot . '/vendor/autoload.php') : null,
            realpath(__DIR__ . '/../vendor/autoload.php'),
            realpath(__DIR__ . '/../../vendor/autoload.php'),
            realpath(__DIR__ . '/../../../vendor/autoload.php'),
            realpath(__DIR__ . '/../../../../vendor/autoload.php'),
            realpath(base_path('vendor/autoload.php')),
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate && file_exists($candidate)) {
                return $candidate;
            }
        }

        return realpath(__DIR__ . '/../vendor/autoload.php') ?: base_path('vendor/autoload.php');
    }

    public function getSubprocessHelpersPath(): string
    {
        $packageRoot = getenv('TESTBENCH_WORKING_PATH') ?: realpath(__DIR__ . '/../');
        $candidates = array_filter([
            $packageRoot ? realpath($packageRoot . '/tests/helpers.php') : null,
            realpath(__DIR__ . '/helpers.php'),
            realpath(__DIR__ . '/../tests/helpers.php'),
            realpath(__DIR__ . '/../../tests/helpers.php'),
        ]);

        foreach ($candidates as $cand) {
            if ($cand && file_exists($cand)) {
                return $cand;
            }
        }

        return realpath(__DIR__ . '/helpers.php') ?: (__DIR__ . '/helpers.php');
    }

    /**
     * Ensure dummy host files exist if running in an isolated testbench environment.
     */
    protected function ensureTestbenchHostFiles(): void
    {
        // 1. Ensure bootstrap/autoload.php exists and correctly loads root autoloader and helpers
        $autoloadPhp = base_path('bootstrap/autoload.php');
        @mkdir(dirname($autoloadPhp), 0755, true);
        $autoloadContent = <<<'PHP'
<?php

if (! defined('TESTBENCH_WORKING_PATH') && is_string(getenv('TESTBENCH_WORKING_PATH'))) {
    define('TESTBENCH_WORKING_PATH', getenv('TESTBENCH_WORKING_PATH'));
}

$candidates = array_filter([
    defined('TESTBENCH_WORKING_PATH') ? TESTBENCH_WORKING_PATH . '/vendor/autoload.php' : null,
    dirname(__DIR__, 5) . '/vendor/autoload.php',
    dirname(__DIR__, 7) . '/vendor/autoload.php',
    dirname(__DIR__, 4) . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
]);

foreach ($candidates as $cand) {
    if ($cand && file_exists($cand)) {
        require_once $cand;
        break;
    }
}

$helpersCandidates = array_filter([
    defined('TESTBENCH_WORKING_PATH') ? TESTBENCH_WORKING_PATH . '/tests/helpers.php' : null,
    dirname(__DIR__, 5) . '/tests/helpers.php',
    dirname(__DIR__, 7) . '/tests/helpers.php',
    dirname(__DIR__, 4) . '/tests/helpers.php',
    dirname(__DIR__, 2) . '/tests/helpers.php',
]);

foreach ($helpersCandidates as $helper) {
    if ($helper && file_exists($helper)) {
        require_once $helper;
        break;
    }
}
PHP;
        file_put_contents($autoloadPhp, $autoloadContent);

        // 2. Ensure base_path('artisan') exists and is executable across all Testbench versions
        $artisanFile = base_path('artisan');
        $artisanContent = <<<'PHP'
#!/usr/bin/env php
<?php

use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

define('LARAVEL_START', microtime(true));

if (file_exists(__DIR__ . '/bootstrap/autoload.php')) {
    require_once __DIR__ . '/bootstrap/autoload.php';
}

/** @var Application $app */
$app = require_once __DIR__ . '/bootstrap/app.php';

if (method_exists($app, 'handleCommand')) {
    $status = $app->handleCommand(new ArgvInput);
    exit($status);
}

$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$status = $kernel->handle(
    $input = new ArgvInput,
    new ConsoleOutput
);
$kernel->terminate($input, $status);
exit($status);
PHP;
        file_put_contents($artisanFile, $artisanContent);
        @chmod($artisanFile, 0755);

        // 3. Ensure tests/TestCase.php exists so composer dump-autoload does not fail on default classmap
        $dummyTest = base_path('tests/TestCase.php');
        if (! file_exists($dummyTest)) {
            @mkdir(dirname($dummyTest), 0755, true);
            file_put_contents($dummyTest, "<?php\n\nnamespace Tests;\n\nclass TestCase {}\n");
        }

        $appServiceProviderFile = base_path('app/Providers/AppServiceProvider.php');
        if (! file_exists($appServiceProviderFile)) {
            @mkdir(dirname($appServiceProviderFile), 0755, true);
            $appServiceProviderContent = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (file_exists(base_path('routes/web.php'))) {
            $this->loadRoutesFrom(base_path('routes/web.php'));
        }
    }
}
PHP;
            file_put_contents($appServiceProviderFile, $appServiceProviderContent);
        }

        $controllerFile = base_path('app/Http/Controllers/WebEntryPointController.php');
        if (! file_exists($controllerFile)) {
            @mkdir(dirname($controllerFile), 0755, true);
            $controllerContent = <<<'PHP'
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class WebEntryPointController extends Controller
{
    public function index(Request $request): Response|RedirectResponse|View
    {
        $defaultPackage = config('laraseed.default_web_package') ?? config('laraseed.web.default_package');
        if (! $defaultPackage) {
            return view('web.fallback');
        }

        $enabledPackages = (array) config('laraseed.optional_packages.enabled', []);
        if (! in_array($defaultPackage, $enabledPackages, true)) {
            return view('web.fallback');
        }

        $packageKey = str_replace('-', '_', strtolower($defaultPackage));
        $candidates = [
            "{$packageKey}.web.home",
            "{$defaultPackage}.web.home",
        ];

        foreach ($candidates as $candidate) {
            if (Route::has($candidate)) {
                return redirect()->route($candidate);
            }
        }

        return view('web.fallback');
    }
}
PHP;
            file_put_contents($controllerFile, $controllerContent);
        }

        $providersFile = base_path('bootstrap/providers.php');
        $providersContent = <<<'PHP'
<?php

if (file_exists(base_path('app/Providers/AppServiceProvider.php'))) {
    require_once base_path('app/Providers/AppServiceProvider.php');
}

$optionalProviders = config('laraseed.optional_packages.providers', []);

return [
    \App\Providers\AppServiceProvider::class,
    \Laraseed\PackageGenerator\Providers\PackageGeneratorServiceProvider::class,
    ...$optionalProviders,
];
PHP;
        file_put_contents($providersFile, $providersContent);

        $laraseedConfigFile = base_path('config/laraseed.php');
        $laraseedConfigContent = <<<'PHP'
<?php

if (! class_exists(\Webkul\Core\Packages\OptionalPackageManifestLoader::class, false)) {
    $candidates = array_filter([
        (getenv('TESTBENCH_WORKING_PATH') ? rtrim(getenv('TESTBENCH_WORKING_PATH'), '/') . '/tests/helpers.php' : null),
        dirname(__DIR__, 5) . '/tests/helpers.php',
        dirname(__DIR__, 7) . '/tests/helpers.php',
        dirname(__DIR__, 4) . '/tests/helpers.php',
        dirname(__DIR__, 3) . '/tests/helpers.php',
        dirname(__DIR__, 2) . '/tests/helpers.php',
        __DIR__ . '/../../tests/helpers.php',
    ]);
    foreach ($candidates as $cand) {
        if ($cand && file_exists($cand)) {
            require_once $cand;
            break;
        }
    }
}

$enabledStr = env('LARASEED_OPTIONAL_PACKAGES', '');
$enabledList = array_values(array_filter(array_map('trim', explode(',', (string) $enabledStr)), fn ($v) => $v !== ''));

$packagesDir = base_path('packages');
$manifestPaths = [];
if (is_dir($packagesDir)) {
    $composerLoader = null;
    foreach (spl_autoload_functions() as $autoloader) {
        if (is_array($autoloader) && $autoloader[0] instanceof \Composer\Autoload\ClassLoader) {
            $composerLoader = $autoloader[0];
            break;
        }
    }

    if ($composerLoader !== null) {
        $composerLoader->addPsr4('App\\', base_path('app'));
        $prefixes = $composerLoader->getPrefixesPsr4();
        $directories = glob($packagesDir . '/*/*', GLOB_ONLYDIR) ?: [];
        foreach ($directories as $dir) {
            $manifestPath = $dir . '/composer.json';
            if (file_exists($manifestPath)) {
                $manifest = json_decode(file_get_contents($manifestPath), true);
                if (is_array($manifest)) {
                    $psr4 = $manifest['autoload']['psr-4'] ?? [];
                    if (is_array($psr4)) {
                        foreach ($psr4 as $prefix => $relativePaths) {
                            $paths = is_array($relativePaths) ? $relativePaths : [$relativePaths];
                            foreach ($paths as $relPath) {
                                $targetDir = realpath($dir . '/' . trim($relPath, '/\\'));
                                if ($targetDir && str_starts_with($targetDir, realpath($packagesDir))) {
                                    $existingPaths = $prefixes[$prefix] ?? [];
                                    if (! in_array($targetDir, $existingPaths, true)) {
                                        $composerLoader->addPsr4($prefix, $targetDir);
                                    }
                                }
                            }
                        }
                    }

                    // Check if package is an optional package declaring extra.laraseed
                    $pkgMeta = $manifest['extra']['laraseed'] ?? null;
                    if (is_array($pkgMeta) && ($pkgMeta['type'] ?? null) === 'optional') {
                        $pkgId = $pkgMeta['id'] ?? null;
                        $providerClass = $pkgMeta['provider'] ?? null;
                        if ($pkgId && in_array($pkgId, $enabledList, true) && $providerClass && ! class_exists($providerClass)) {
                            throw new \RuntimeException(sprintf(
                                'Optional package [%s] declares an invalid provider class [%s].',
                                $pkgId,
                                $providerClass
                            ));
                        }

                        $manifestPaths[] = $manifestPath;
                    }
                }
            }
        }
    }
}

$catalog = (new \Webkul\Core\Packages\OptionalPackageManifestLoader)->load($manifestPaths);
$knownEnabled = array_values(array_filter($enabledList, fn ($id) => isset($catalog[$id])));
$composition = new \Webkul\Core\Packages\OptionalPackageComposition($catalog, $knownEnabled);

return [
    'default_web_package' => env('LARASEED_DEFAULT_WEB_PACKAGE', null),
    'web' => [
        'default_package' => env('LARASEED_DEFAULT_WEB_PACKAGE', null),
    ],
    'optional_packages' => [
        'enabled' => $composition->enabledPackages(),
        'catalog' => $composition->packages(),
        'providers' => array_values(array_unique(array_merge(
            $composition->providers(),
            $composition->capabilityProviders('web'),
        ))),
        'concord_modules' => $composition->concordModules(),
        'dependencies' => $composition->dependencyGraph(),
    ],
];
PHP;
        file_put_contents($laraseedConfigFile, $laraseedConfigContent);

        $composerJsonFile = base_path('composer.json');
        if (! file_exists($composerJsonFile)) {
            file_put_contents($composerJsonFile, "{\n    \"name\": \"laravel/laravel\"\n}\n");
        }

        $viewFile = base_path('resources/views/web/fallback.blade.php');
        if (! file_exists($viewFile)) {
            @mkdir(dirname($viewFile), 0755, true);
            file_put_contents($viewFile, "<!DOCTYPE html><html><body><main class=\"container\" role=\"status\"><div role=\"status\"><span>Platform Active</span></div><h1>Platform Active</h1><p>The application core is online.</p></main></body></html>\n");
        }

        $routesWebFile = base_path('routes/web.php');
        $routesWebContent = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('admin/login', fn () => 'Admin Login')->name('admin.session.create');
Route::get('admin', fn () => redirect()->route('admin.session.create'))->name('admin');
Route::get('install', fn () => 'Installer')->name('installer.index');
Route::get('api/health', fn () => response()->json(['status' => 'ok']));
Route::get('up', fn () => response('OK', 200));

if (! config('laraseed.web.root_owner')) {
    Route::get('/', [\App\Http\Controllers\WebEntryPointController::class, 'index'])->name('laraseed.web.entry');
}
PHP;
        file_put_contents($routesWebFile, $routesWebContent);
    }

    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PackageGeneratorServiceProvider::class,
        ];
    }

    /**
     * Define routes setup.
     *
     * @param  \Illuminate\Routing\Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('admin/login', fn () => 'Admin Login')->name('admin.session.create');
        $router->get('admin', fn () => redirect()->route('admin.session.create'))->name('admin');
        if (! config('laraseed.web.root_owner')) {
            $router->get('/', [\App\Http\Controllers\WebEntryPointController::class, 'index'])->name('laraseed.web.entry');
        }
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('laraseed.default_web_package', null);

        $app->singleton(\Webkul\Core\Packages\OptionalPackageComposition::class, fn ($app) => new \Webkul\Core\Packages\OptionalPackageComposition(
            $app['config']->get('laraseed.optional_packages.catalog', []),
            $app['config']->get('laraseed.optional_packages.enabled', []),
        ));

        $app->singleton('concord', function () {
            return new class {
                protected array $models = [];

                public function registerModel(string $contract, string $model): void
                {
                    $this->models[$contract] = $model;
                }

                public function model(string $contract): string
                {
                    return $this->models[$contract] ?? $contract;
                }

                public function getConvention(): object
                {
                    return new class {
                        public function contractForModel(string $model): string
                        {
                            return str_replace('\\Models\\', '\\Contracts\\', $model);
                        }

                        public function modelForProxy(string $proxy): string
                        {
                            return substr($proxy, 0, -5);
                        }

                        public function proxyForModel(string $model): string
                        {
                            return $model . 'Proxy';
                        }
                    };
                }
            };
        });

        // Standard auth configuration for standalone package testing
        $app['config']->set('auth.guards.user', [
            'driver'   => 'session',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.guards.admin', [
            'driver'   => 'session',
            'provider' => 'admins',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model'  => \Webkul\User\Models\User::class,
        ]);
        $app['config']->set('auth.providers.admins', [
            'driver' => 'eloquent',
            'model'  => \Webkul\User\Models\User::class,
        ]);

        // Register default AuthenticationRedirectResolver singleton if class exists
        if (interface_exists(\Webkul\Core\Contracts\AuthenticationRedirectResolver::class)) {
            $app->singleton(\Webkul\Core\Contracts\AuthenticationRedirectResolver::class, function () {
                $resolver = new class implements \Webkul\Core\Contracts\AuthenticationRedirectResolver {
                    protected array $rules = [];

                    public function register(string $name, \Closure $matcher, \Closure $target, int $priority = 0): void
                    {
                        $this->rules[$name] = [
                            'matcher'  => $matcher,
                            'target'   => $target,
                            'priority' => $priority,
                        ];
                    }

                    public function resolve(\Illuminate\Http\Request $request): ?string
                    {
                        $sorted = $this->rules;
                        uasort($sorted, fn ($a, $b) => $b['priority'] <=> $a['priority']);

                        foreach ($sorted as $rule) {
                            if (($rule['matcher'])($request)) {
                                return ($rule['target'])();
                            }
                        }

                        return null;
                    }
                };

                // Default fallback: admin login
                $resolver->register('admin_fallback', fn () => true, fn () => route('admin.session.create'), -100);

                return $resolver;
            });
        }
    }
}
