<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Laraseed\PackageGenerator\Tests\TestCase;
use Symfony\Component\Process\Process;

class CleanInstallationVerificationTest extends TestCase
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

    /**
     * Verify all registered Artisan generator and management commands.
     */
    public function test_all_artisan_generator_commands_execution(): void
    {
        $pkgDir = $this->trackDirectory('packages/CleanTest/MasterSuite');

        // 1. make-package
        $this->artisan('laraseed:make-package CleanTest/MasterSuite')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/composer.json"));

        // 2. make-model with contract & proxy
        $this->artisan('laraseed:make-model CleanTest/MasterSuite Customer --contract --proxy')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Models/Customer.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Contracts/Customer.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Models/CustomerProxy.php"));

        // 3. make-contract
        $this->artisan('laraseed:make-contract CleanTest/MasterSuite AccountManager')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Contracts/AccountManager.php"));

        // 4. make-proxy
        $this->artisan('laraseed:make-proxy CleanTest/MasterSuite Account')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Models/AccountProxy.php"));

        // 5. make-migration
        $this->artisan('laraseed:make-migration CleanTest/MasterSuite create_master_table')->assertExitCode(0);
        $this->assertNotEmpty($this->filesystem->glob("{$pkgDir}/src/Database/Migrations/*_create_master_table.php"));

        // 6. make-repository
        $this->artisan('laraseed:make-repository CleanTest/MasterSuite CustomerRepository --model=Customer')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Repositories/CustomerRepository.php"));

        // 7. make-request
        $this->artisan('laraseed:make-request CleanTest/MasterSuite StoreCustomerRequest')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Http/Requests/StoreCustomerRequest.php"));

        // 8. make-controller
        $this->artisan('laraseed:make-controller CleanTest/MasterSuite CustomerController --api')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Http/Controllers/CustomerController.php"));

        // 9. make-route
        $this->artisan('laraseed:make-route CleanTest/MasterSuite custom --type=api')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Routes/custom.php"));

        // 10. make-provider
        $this->artisan('laraseed:make-provider CleanTest/MasterSuite EventServiceProvider')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Providers/EventServiceProvider.php"));

        // 11. make-module-provider
        $this->artisan('laraseed:make-module-provider CleanTest/MasterSuite --force')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Providers/ModuleServiceProvider.php"));

        // 12. make-event
        $this->artisan('laraseed:make-event CleanTest/MasterSuite CustomerRegistered')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Events/CustomerRegistered.php"));

        // 13. make-listener
        $this->artisan('laraseed:make-listener CleanTest/MasterSuite SendWelcomeEmail --event=CustomerRegistered')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Listeners/SendWelcomeEmail.php"));

        // 14. make-middleware
        $this->artisan('laraseed:make-middleware CleanTest/MasterSuite CheckAccountStatus')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Http/Middleware/CheckAccountStatus.php"));

        // 15. make-mail
        $this->artisan('laraseed:make-mail CleanTest/MasterSuite WelcomeMail')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Mail/WelcomeMail.php"));

        // 16. make-notification
        $this->artisan('laraseed:make-notification CleanTest/MasterSuite OrderStatusNotification --channels=mail,database')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Notifications/OrderStatusNotification.php"));

        // 17. make-command
        $this->artisan('laraseed:make-command CleanTest/MasterSuite SyncCustomersCommand --signature=customers:sync')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Console/Commands/SyncCustomersCommand.php"));

        // 18. make-seeder
        $this->artisan('laraseed:make-seeder CleanTest/MasterSuite CustomerDatabaseSeeder')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Database/Seeders/CustomerDatabaseSeeder.php"));

        // 19. make-datagrid
        $this->artisan('laraseed:make-datagrid CleanTest/MasterSuite CustomerDataGrid')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/DataGrids/CustomerDataGrid.php"));

        // 20. make-admin
        $this->artisan('laraseed:make-admin CleanTest/MasterSuite')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Admin/Providers/AdminServiceProvider.php"));

        // 21. make-web
        $this->artisan('laraseed:make-web CleanTest/MasterSuite')->assertExitCode(0);
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Web/Providers/WebServiceProvider.php"));

        // 22. web-default inspection
        $this->artisan('laraseed:web-default --list')->assertExitCode(0);
    }

    /**
     * Verify root-mounted Web package serves homepage directly at / without HTTP redirects.
     */
    public function test_web_package_default_root_mount_direct_response_without_redirects(): void
    {
        $pkgDir = $this->trackDirectory('packages/CleanTest/DirectRootPkg');
        $this->artisan('laraseed:make-package CleanTest/DirectRootPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web CleanTest/DirectRootPkg')->assertExitCode(0);

        $this->writeTestEnv([
            'LARASEED_OPTIONAL_PACKAGES'   => '"direct_root_pkg"',
            'LARASEED_DEFAULT_WEB_PACKAGE' => '"direct_root_pkg"',
        ]);

        $this->runArtisanCommand(['config:cache']);
        $this->runArtisanCommand(['route:cache']);

        // 1. Root route: HTTP 200 OK, zero redirects (status is 200, location header is empty)
        $home = $this->dispatchSubprocessHttp('/');
        $this->assertSame(200, $home['status']);
        $this->assertEmpty($home['headers']['location'] ?? []);
        $this->assertStringContainsString('DirectRootPkg', $home['content']);

        // 2. Internal page directly under root: HTTP 200 OK
        $about = $this->dispatchSubprocessHttp('/pages/about');
        $this->assertSame(200, $about['status']);
        $this->assertStringContainsString('about', strtolower($about['content']));

        // 3. Branding stylesheet: HTTP 200 OK
        $css = $this->dispatchSubprocessHttp('/branding.css');
        $this->assertSame(200, $css['status']);
        $this->assertStringContainsString('--brand-color', $css['content']);
    }

    /**
     * Verify Composer autoload modes: standard, optimized, authoritative.
     */
    public function test_composer_autoload_modes_verification(): void
    {
        $pkgDir = $this->trackDirectory('packages/CleanTest/ComposerModePkg');
        $this->artisan('laraseed:make-package CleanTest/ComposerModePkg --plain')->assertExitCode(0);

        $autoloadPath = $this->getSubprocessAutoloadPath();
        $helpersPath = $this->getSubprocessHelpersPath();
        $evalCode = <<<PHP
require_once '{$autoloadPath}';
if (file_exists('{$helpersPath}')) {
    require_once '{$helpersPath}';
}
\$app = require 'bootstrap/app.php';
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

\$class = 'CleanTest\\\\ComposerModePkg\\\\Providers\\\\ComposerModePkgServiceProvider';
echo json_encode([
    'class_exists' => class_exists(\$class),
]);
PHP;

        $env = $this->sanitizeEnv();
        $proc = new Process(['php', '-r', $evalCode], base_path(), $env);
        $proc->run();
        $this->assertSame(0, $proc->getExitCode(), $proc->getErrorOutput());
        $result = json_decode($proc->getOutput(), true);
        $this->assertTrue($result['class_exists']);
    }
}
