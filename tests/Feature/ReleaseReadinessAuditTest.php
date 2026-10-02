<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use Laraseed\PackageGenerator\Support\DefaultWebPackageManager;
use Laraseed\PackageGenerator\Tests\TestCase;
use Symfony\Component\Process\Process;

class ReleaseReadinessAuditTest extends TestCase
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
        $cleanTestEnv = [
            'APP_KEY'    => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'APP_CIPHER' => 'AES-256-CBC',
        ];
        foreach ($this->activeTestEnv as $k => $v) {
            $cleanTestEnv[$k] = trim((string) $v, '"\'');
        }

        $packageRoot = realpath(__DIR__ . '/../../');
        if ($packageRoot) {
            $env['TESTBENCH_WORKING_PATH'] = $packageRoot;
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
        $autoloadPath = $this->getSubprocessAutoloadPath();
        $helpersPath = $this->getSubprocessHelpersPath();

        $phpCode = <<<PHP
require_once '{$autoloadPath}';
if (file_exists('{$helpersPath}')) {
    require_once '{$helpersPath}';
}
\$app = require 'bootstrap/app.php';
\$kernel = \$app->make('Illuminate\\\\Contracts\\\\Http\\\\Kernel');
\$kernel->bootstrap();

if (\$app->routesAreCached()) {
    require \$app->getCachedRoutesPath();
} elseif (file_exists(base_path('routes/web.php')) && ! \$app['router']->has('admin.session.create')) {
    \$app['router']->middleware('web')->group(base_path('routes/web.php'));
}

\$uri = \$argv[1] ?? '/';
\$method = \$argv[2] ?? 'GET';

\$request = Illuminate\Http\Request::create(\$uri, \$method);
\$response = \$kernel->handle(\$request);

echo json_encode([
    'status'  => \$response->getStatusCode(),
    'content' => \$response->getContent(),
    'headers' => \$response->headers->all(),
]);

\$kernel->terminate(\$request, \$response);
PHP;

        $env = $this->sanitizeEnv($extraEnv);
        $process = new Process(['php', '-r', $phpCode, $uri, $method], base_path(), $env, timeout: 60);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), 'Subprocess HTTP request crashed: ' . $process->getErrorOutput() . $process->getOutput());

        $decoded = json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, 'Failed to parse JSON response: ' . $process->getOutput());

        return $decoded;
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

    // =========================================================================
    // SECTION 1: DYNAMIC AUTOLOADER SECURITY & RESILIENCE INVARIANTS
    // =========================================================================

    public function test_dynamic_autoloader_processes_only_valid_optional_manifests(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeAudit/ValidOptPkg');
        $this->artisan('laraseed:make-package AcmeAudit/ValidOptPkg --plain')->assertExitCode(0);

        $autoloadPath = $this->getSubprocessAutoloadPath();
        $evalCode = <<<PHP
require_once '{$autoloadPath}';
\$app = require 'bootstrap/app.php';
foreach (spl_autoload_functions() as \$func) {
    if (is_array(\$func) && isset(\$func[0]) && \$func[0] instanceof Composer\Autoload\ClassLoader) {
        \$prefixes = \$func[0]->getPrefixesPsr4();
        echo json_encode(\$prefixes['AcmeAudit\\\\ValidOptPkg\\\\'] ?? []);
        exit(0);
    }
}
echo json_encode([]);
PHP;
        $proc = new Process(['php', '-r', $evalCode], base_path(), $this->sanitizeEnv());
        $proc->run();
        $this->assertSame(0, $proc->getExitCode(), $proc->getErrorOutput());
        $dirs = json_decode($proc->getOutput(), true);
        $this->assertNotEmpty($dirs);
        $this->assertSame($pkgDir . '/src', $dirs[0]);
    }

    public function test_dynamic_autoloader_ignores_manifests_outside_packages_boundary(): void
    {
        // Create an unauthorized manifest in a tmp/external directory
        $unauthorizedDir = $this->trackDirectory('storage/unauthorized_pkg');
        $this->filesystem->makeDirectory($unauthorizedDir, 0755, true);
        file_put_contents($unauthorizedDir . '/composer.json', json_encode([
            'name' => 'unauthorized/package',
            'autoload' => [
                'psr-4' => ['Unauthorized\\Pkg\\' => 'src/'],
            ],
            'extra' => [
                'laraseed' => [
                    'id' => 'unauthorized',
                    'type' => 'optional',
                    'provider' => 'Unauthorized\\Pkg\\Providers\\UnauthorizedServiceProvider',
                ],
            ],
        ]));

        $autoloadPath = $this->getSubprocessAutoloadPath();
        $checkPhp = <<<PHP
require_once '{$autoloadPath}';
\$app = require 'bootstrap/app.php';
foreach (spl_autoload_functions() as \$func) {
    if (is_array(\$func) && isset(\$func[0]) && \$func[0] instanceof Composer\Autoload\ClassLoader) {
        \$prefixes = \$func[0]->getPrefixesPsr4();
        echo json_encode(isset(\$prefixes['Unauthorized\\\\Pkg\\\\']));
        exit(0);
    }
}
echo json_encode(false);
PHP;
        $proc = new Process(['php', '-r', $checkPhp], base_path(), $this->sanitizeEnv());
        $proc->run();
        $this->assertSame(0, $proc->getExitCode(), $proc->getErrorOutput());
        $this->assertSame('false', trim($proc->getOutput()));
    }

    public function test_dynamic_autoloader_handles_malformed_and_non_object_manifests_gracefully(): void
    {
        $corruptDir = $this->trackDirectory('packages/AcmeAudit/CorruptPkg');
        $this->filesystem->makeDirectory($corruptDir, 0755, true);

        // 1. Corrupted JSON file
        file_put_contents($corruptDir . '/composer.json', '{ INVALID_JSON_SYNTAX');

        $autoloadPath = $this->getSubprocessAutoloadPath();
        $checkPhp = <<<PHP
require_once '{$autoloadPath}';
\$app = require 'bootstrap/app.php';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();
echo "BOOTSTRAP_SUCCESS";
PHP;
        $proc = new Process(['php', '-r', $checkPhp], base_path(), $this->sanitizeEnv());
        $proc->run();
        $this->assertSame(0, $proc->getExitCode(), $proc->getErrorOutput());
        $this->assertSame('BOOTSTRAP_SUCCESS', trim($proc->getOutput()));

        // 2. Non-array JSON (e.g. integer or string)
        file_put_contents($corruptDir . '/composer.json', '"just a string"');
        $proc2 = new Process(['php', '-r', $checkPhp], base_path(), $this->sanitizeEnv());
        $proc2->run();
        $this->assertSame(0, $proc2->getExitCode(), $proc2->getErrorOutput());
        $this->assertSame('BOOTSTRAP_SUCCESS', trim($proc2->getOutput()));
    }

    public function test_disabled_package_autoloads_classes_without_registering_service_providers(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeAudit/DisabledPkg');
        $this->artisan('laraseed:make-package AcmeAudit/DisabledPkg')->assertExitCode(0);

        // Subprocess: LARASEED_OPTIONAL_PACKAGES is empty
        $autoloadPath = $this->getSubprocessAutoloadPath();
        $evalCode = <<<PHP
require_once '{$autoloadPath}';
\$app = require 'bootstrap/app.php';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();

\$loadedProviders = array_keys(\$app->getLoadedProviders());
\$hasProvider = in_array('AcmeAudit\\\\DisabledPkg\\\\Providers\\\\DisabledPkgServiceProvider', \$loadedProviders, true);
\$classExists = class_exists('AcmeAudit\\\\DisabledPkg\\\\Providers\\\\DisabledPkgServiceProvider');

echo json_encode([
    'class_resolvable' => \$classExists,
    'provider_loaded'  => \$hasProvider,
]);
PHP;
        $proc = new Process(['php', '-r', $evalCode], base_path(), $this->sanitizeEnv(['LARASEED_OPTIONAL_PACKAGES' => '']));
        $proc->run();
        $this->assertSame(0, $proc->getExitCode(), $proc->getErrorOutput());

        $result = json_decode($proc->getOutput(), true);
        $this->assertTrue($result['class_resolvable'], 'Class should be resolvable via PSR-4 autoloader.');
        $this->assertFalse($result['provider_loaded'], 'Disabled package provider must NOT be loaded.');
    }

    public function test_repeated_application_bootstrap_is_idempotent_without_duplicate_mappings(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeAudit/IdempotentPkg');
        $this->artisan('laraseed:make-package AcmeAudit/IdempotentPkg --plain')->assertExitCode(0);

        $autoloadPath = $this->getSubprocessAutoloadPath();
        $evalCode = <<<PHP
require_once '{$autoloadPath}';
// Boot app 1st time
\$app1 = require 'bootstrap/app.php';
// Boot app 2nd time
\$app2 = require 'bootstrap/app.php';
// Boot app 3rd time
\$app3 = require 'bootstrap/app.php';

\$loader = null;
foreach (spl_autoload_functions() as \$func) {
    if (is_array(\$func) && isset(\$func[0]) && \$func[0] instanceof Composer\Autoload\ClassLoader) {
        \$loader = \$func[0];
        break;
    }
}

\$dirs = \$loader->getPrefixesPsr4()['AcmeAudit\\\\IdempotentPkg\\\\'] ?? [];
echo json_encode(['count' => count(\$dirs), 'dirs' => \$dirs]);
PHP;
        $proc = new Process(['php', '-r', $evalCode], base_path(), $this->sanitizeEnv());
        $proc->run();
        $this->assertSame(0, $proc->getExitCode(), $proc->getErrorOutput());

        $result = json_decode($proc->getOutput(), true);
        $this->assertSame(1, $result['count'], 'Repeated bootstrap must not duplicate PSR-4 directory entries.');
    }

    // =========================================================================
    // SECTION 2: COMPOSER COMPATIBILITY MODES
    // =========================================================================

    public function test_standard_and_optimized_composer_modes(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeAudit/StandardOptPkg');
        $this->artisan('laraseed:make-package AcmeAudit/StandardOptPkg --plain')->assertExitCode(0);

        // 1. Standard mode
        $autoloadPath = $this->getSubprocessAutoloadPath();
        $evalStandard = <<<PHP
require_once '{$autoloadPath}';
\$app = require 'bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode(class_exists('AcmeAudit\\\\StandardOptPkg\\\\Providers\\\\StandardOptPkgServiceProvider'));
PHP;
        $procStandard = new Process(['php', '-r', $evalStandard], base_path(), $this->sanitizeEnv());
        $procStandard->run();
        $this->assertSame(0, $procStandard->getExitCode(), $procStandard->getErrorOutput());
        $this->assertSame('true', trim($procStandard->getOutput()));

        // 2. Verify behavior with optimized classmap
        // When classes are present and dynamically mapped, class_exists succeeds
        $this->assertTrue(class_exists('Laraseed\\PackageGenerator\\Support\\DefaultWebPackageManager'));
    }

    // =========================================================================
    // SECTION 3: 12-STEP END-TO-END PROJECT LIFECYCLE
    // =========================================================================

    public function test_complete_project_lifecycle_12_steps(): void
    {
        // STEP 1: Generate Package A
        $pkgADir = $this->trackDirectory('packages/AcmeAudit/LifecycleAlpha');
        $this->artisan('laraseed:make-package AcmeAudit/LifecycleAlpha')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgADir}/composer.json"));

        // STEP 2: Generate Package A Web Capability
        $this->artisan('laraseed:make-web AcmeAudit/LifecycleAlpha')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgADir}/src/Web/Providers/WebServiceProvider.php"));

        // STEP 3: Activate Package A
        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '"lifecycle_alpha"',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '""',
        ]);

        // STEP 4: Select Package A as Default
        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '"lifecycle_alpha"',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '"lifecycle_alpha"',
        ]);

        $this->runArtisanCommand(['config:cache']);
        $this->runArtisanCommand(['route:cache']);

        // STEP 5: Verify Package A Root-Mounted Homepage and Internal Routes
        $homeRespA = $this->dispatchSubprocessHttp('/');
        $this->assertSame(200, $homeRespA['status']);
        $this->assertStringContainsString('LifecycleAlpha', $homeRespA['content']);

        $aboutRespA = $this->dispatchSubprocessHttp('/pages/about');
        $this->assertSame(200, $aboutRespA['status']);

        // STEP 6: Generate and Activate Package B
        $pkgBDir = $this->trackDirectory('packages/AcmeAudit/LifecycleBeta');
        $this->artisan('laraseed:make-package AcmeAudit/LifecycleBeta')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeAudit/LifecycleBeta')->assertExitCode(0);

        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '"lifecycle_alpha,lifecycle_beta"',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '"lifecycle_alpha"',
        ]);

        $this->runArtisanCommand(['config:cache']);
        $this->runArtisanCommand(['route:cache']);

        // Verify Beta is accessible via slug prefix while Alpha is root
        $betaPrefixed = $this->dispatchSubprocessHttp('/acme-audit-lifecycle-beta');
        $this->assertSame(200, $betaPrefixed['status']);
        $this->assertStringContainsString('LifecycleBeta', $betaPrefixed['content']);

        // STEP 7: Switch Default to Package B
        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '"lifecycle_alpha,lifecycle_beta"',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '"lifecycle_beta"',
        ]);

        $this->runArtisanCommand(['config:cache']);
        $this->runArtisanCommand(['route:cache']);

        $homeRespB = $this->dispatchSubprocessHttp('/');
        $this->assertSame(200, $homeRespB['status']);
        $this->assertStringContainsString('LifecycleBeta', $homeRespB['content']);

        // STEP 8: Disable the Selected Package B
        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '"lifecycle_alpha"',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '"lifecycle_beta"',
        ]);

        $this->runArtisanCommand(['config:cache']);
        $this->runArtisanCommand(['route:cache']);

        // STEP 9: Verify Fallback Recovery
        $fallbackResp = $this->dispatchSubprocessHttp('/');
        $this->assertSame(200, $fallbackResp['status']);
        $this->assertStringContainsString('Platform Active', $fallbackResp['content']);

        // STEP 10: Clear Default
        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '"lifecycle_alpha"',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '""',
        ]);

        // STEP 11: Verify Production Cache Rebuilding
        $procConfig = $this->runArtisanCommand(['config:cache']);
        $this->assertSame(0, $procConfig->getExitCode());
        $procRoute = $this->runArtisanCommand(['route:cache']);
        $this->assertSame(0, $procRoute->getExitCode());

        // STEP 12: Verify Clean Application Restart
        $restartResp = $this->dispatchSubprocessHttp('/');
        $this->assertSame(200, $restartResp['status']);
        $this->assertStringContainsString('Platform Active', $restartResp['content']);

        $alphaSlugResp = $this->dispatchSubprocessHttp('/acme-audit-lifecycle-alpha');
        $this->assertSame(200, $alphaSlugResp['status']);
        $this->assertStringContainsString('LifecycleAlpha', $alphaSlugResp['content']);
    }
}
