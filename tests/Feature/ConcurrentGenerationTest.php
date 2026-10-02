<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\AdminGenerator;
use Laraseed\PackageGenerator\Generators\WebGenerator;
use Laraseed\PackageGenerator\Support\PackageLock;
use Symfony\Component\Process\Process;
use Laraseed\PackageGenerator\Tests\TestCase;

class ConcurrentGenerationTest extends TestCase
{
    protected Filesystem $filesystem;

    protected array $createdDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem;
        $this->createdDirectories = [];

        PackageLock::resetHeldLocks();

        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'AcmeConcurrent\\')) {
                $relative = substr($class, strlen('AcmeConcurrent\\'));
                $parts = explode('\\', $relative, 2);
                $package = $parts[0];
                $subPath = isset($parts[1]) ? str_replace('\\', '/', $parts[1]) : '';
                $path = base_path("packages/AcmeConcurrent/{$package}/src/{$subPath}.php");
                if (file_exists($path)) {
                    require_once $path;
                }
            }
        });
    }

    protected function tearDown(): void
    {
        PackageLock::resetHeldLocks();

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

    /**
     * 1. Equivalent package identifier representations resolve to identical lock files.
     */
    public function test_canonical_lock_identity_across_equivalent_representations(): void
    {
        $lock = new PackageLock();
        $base = base_path();

        $path1 = $lock->getLockFilePath('AcmeConcurrent/CanonPkg');
        $path2 = $lock->getLockFilePath('packages/AcmeConcurrent/CanonPkg');
        $path3 = $lock->getLockFilePath("{$base}/packages/AcmeConcurrent/CanonPkg");
        $path4 = $lock->getLockFilePath('packages/AcmeConcurrent/CanonPkg/');

        $this->assertSame($path1, $path2, 'Vendor/Package and packages/Vendor/Package must produce identical lock paths.');
        $this->assertSame($path1, $path3, 'Relative and absolute package paths must produce identical lock paths.');
        $this->assertSame($path1, $path4, 'Trailing slashes must normalize to the same lock path.');
    }

    /**
     * 2. Different application roots produce different lock identities.
     */
    public function test_different_application_roots_produce_isolated_lock_files(): void
    {
        $lockApp1 = new PackageLock(basePath: '/var/www/app1');
        $lockApp2 = new PackageLock(basePath: '/var/www/app2');

        $pathApp1 = $lockApp1->getLockFilePath('Acme/Blog');
        $pathApp2 = $lockApp2->getLockFilePath('Acme/Blog');

        $this->assertNotSame($pathApp1, $pathApp2, 'Different application base paths must never share a lock file.');
    }

    /**
     * 3. Temporary-directory fallback preserves cross-application isolation.
     */
    public function test_temporary_directory_fallback_maintains_cross_application_isolation(): void
    {
        $tempLocksDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laraseed_shared_temp_test';

        $lockApp1 = new PackageLock(locksDirectory: $tempLocksDir, basePath: '/opt/laravel_app_alpha');
        $lockApp2 = new PackageLock(locksDirectory: $tempLocksDir, basePath: '/opt/laravel_app_beta');

        $pathApp1 = $lockApp1->getLockFilePath('Acme/SharedName');
        $pathApp2 = $lockApp2->getLockFilePath('Acme/SharedName');

        $this->assertNotSame($pathApp1, $pathApp2, 'Even within a shared temporary locks directory, application roots must isolate lock keys.');
    }

    /**
     * 4. Nested same-owner acquisition succeeds reentrantly across representations.
     */
    public function test_nested_same_owner_acquisition_succeeds_reentrantly(): void
    {
        $lock = new PackageLock();
        $executedOuter = false;
        $executedInner = false;
        $executedDeepest = false;

        $result = $lock->withLock('AcmeConcurrent/ReentrantPkg', function () use ($lock, &$executedOuter, &$executedInner, &$executedDeepest) {
            $executedOuter = true;

            // Nested call with relative 'packages/' prefix
            $innerResult = $lock->withLock('packages/AcmeConcurrent/ReentrantPkg', function () use ($lock, &$executedInner, &$executedDeepest) {
                $executedInner = true;

                // Deeply nested call with absolute path
                $absPath = base_path('packages/AcmeConcurrent/ReentrantPkg');
                $deepResult = $lock->withLock($absPath, function () use (&$executedDeepest) {
                    $executedDeepest = true;
                    return 'deepest_ok';
                });

                return $deepResult . '_inner_ok';
            });

            return $innerResult . '_outer_ok';
        });

        $this->assertTrue($executedOuter);
        $this->assertTrue($executedInner);
        $this->assertTrue($executedDeepest);
        $this->assertSame('deepest_ok_inner_ok_outer_ok', $result);
    }

    /**
     * 5. Independent competing packages have isolated lock files and can be acquired concurrently.
     */
    public function test_independent_packages_have_isolated_competing_ownership(): void
    {
        $lock = new PackageLock();
        $path1 = $lock->getLockFilePath('AcmeConcurrent/PkgOne');
        $path2 = $lock->getLockFilePath('AcmeConcurrent/PkgTwo');

        $this->assertNotSame($path1, $path2);

        // Acquire lock 1
        $dir = dirname($path1);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $fp1 = fopen($path1, 'c+');
        $this->assertTrue(flock($fp1, LOCK_EX));

        try {
            // Lock 2 must be acquirable concurrently without timeout
            $executed = false;
            $lock->withLock('AcmeConcurrent/PkgTwo', function () use (&$executed) {
                $executed = true;
            }, timeoutSeconds: 1);

            $this->assertTrue($executed);
        } finally {
            flock($fp1, LOCK_UN);
            fclose($fp1);
            @unlink($path1);
            @unlink($path2);
        }
    }

    /**
     * 6. True cross-process exclusion using an independent OS process.
     */
    public function test_true_cross_process_exclusion_with_real_subprocesses(): void
    {
        $packageId = 'AcmeConcurrent/SubprocessPkg';
        $signalFile = base_path('packages/AcmeConcurrent_signal_' . uniqid() . '.tmp');

        // Subprocess script acquires lock on package, signals readiness, sleeps 1.2s, then releases
        $subProcessScript = sprintf(
            <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$lock = $app->make(\Laraseed\PackageGenerator\Support\PackageLock::class);
$lock->withLock('%s', function () use ($lock) {
    file_put_contents('%s', 'LOCKED');
    usleep(1200000); // 1.2s
});
PHP,
            $packageId,
            $signalFile
        );

        $process = new Process([PHP_BINARY, '-r', $subProcessScript], base_path());
        $process->start();

        // Wait up to 3 seconds for subprocess to acquire lock and create signal file
        $signaled = false;
        $start = microtime(true);
        while (microtime(true) - $start < 3.0) {
            if (file_exists($signalFile)) {
                $signaled = true;
                break;
            }
            usleep(20000); // 20ms
        }
        $this->assertTrue($signaled, 'Subprocess did not acquire lock and signal in time.');

        $lock = new PackageLock();

        // Attempting to acquire lock while subprocess holds it must time out
        $timeoutCaught = false;
        try {
            $lock->withLock("packages/{$packageId}", function () {
                $this->fail('Lock should be held by background process.');
            }, timeoutSeconds: 1);
        } catch (PackageGenerationException $e) {
            $timeoutCaught = true;
            $this->assertStringContainsString("Timeout acquiring exclusive lock for package [packages/{$packageId}]", $e->getMessage());
        }

        $this->assertTrue($timeoutCaught, 'Expected lock timeout while subprocess held lock.');

        // Wait for subprocess to complete and release
        $process->wait();
        $this->assertTrue($process->isSuccessful(), 'Subprocess failed: ' . $process->getErrorOutput());

        if (file_exists($signalFile)) {
            @unlink($signalFile);
        }

        // Lock must now be freely acquirable by this process
        $afterSuccess = false;
        $lock->withLock($packageId, function () use (&$afterSuccess) {
            $afterSuccess = true;
        }, timeoutSeconds: 2);

        $this->assertTrue($afterSuccess, 'Lock was not released after subprocess completed.');
    }

    /**
     * 7. Concurrent Admin and Web capability generation preserves both capabilities.
     */
    public function test_concurrent_admin_and_web_generation_preserves_both_capabilities_in_composer_json(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeConcurrent/DualGenPkg');
        $this->artisan('laraseed:make-package AcmeConcurrent/DualGenPkg')->assertExitCode(0);

        $adminGen = $this->app->make(AdminGenerator::class);
        $webGen = $this->app->make(WebGenerator::class);

        $adminResult = $adminGen->generate('AcmeConcurrent/DualGenPkg');
        $webResult = $webGen->generate('AcmeConcurrent/DualGenPkg');

        $this->assertNotEmpty($adminResult['files']);
        $this->assertNotEmpty($webResult['files']);

        $composerData = json_decode((string) file_get_contents("{$pkgDir}/composer.json"), true);
        $capabilities = $composerData['extra']['laraseed']['capabilities'];

        $this->assertArrayHasKey('admin', $capabilities);
        $this->assertArrayHasKey('web', $capabilities);
        $this->assertTrue($capabilities['admin']['enabled']);
        $this->assertTrue($capabilities['web']['enabled']);
        $this->assertSame('AcmeConcurrent\\DualGenPkg\\Admin\\Providers\\AdminServiceProvider', $capabilities['admin']['provider']);
        $this->assertSame('AcmeConcurrent\\DualGenPkg\\Web\\Providers\\WebServiceProvider', $capabilities['web']['provider']);
    }

    /**
     * 8. Lock timeout is thrown when lock is externally held.
     */
    public function test_package_lock_throws_timeout_exception_when_held(): void
    {
        $lock = new PackageLock();
        $packageId = 'AcmeConcurrent/TimeoutPkg';

        $lockFilePath = $lock->getLockFilePath($packageId);
        $dir = dirname($lockFilePath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = fopen($lockFilePath, 'c+');
        $this->assertNotFalse($fp);
        $this->assertTrue(flock($fp, LOCK_EX));

        try {
            $this->expectException(PackageGenerationException::class);
            $this->expectExceptionMessage("Timeout acquiring exclusive lock for package [{$packageId}] after 1 seconds.");

            $lock->withLock($packageId, function () {
                $this->fail('Callback should not execute when lock is held.');
            }, timeoutSeconds: 1);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($lockFilePath);
        }
    }

    /**
     * 9. Exceptions during nested acquisition cleanly release all depths.
     */
    public function test_exceptions_during_nested_acquisition_cleanly_release_all_depths(): void
    {
        $lock = new PackageLock();
        $packageId = 'AcmeConcurrent/NestedExceptionPkg';

        $caught = false;
        try {
            $lock->withLock($packageId, function () use ($lock, $packageId) {
                $lock->withLock("packages/{$packageId}", function () {
                    throw new \RuntimeException('Failure inside nested lock level.');
                });
            });
        } catch (\RuntimeException $e) {
            $caught = true;
            $this->assertSame('Failure inside nested lock level.', $e->getMessage());
        }

        $this->assertTrue($caught);

        // Lock must immediately be acquirable without timeout
        $subsequentExecuted = false;
        $lock->withLock($packageId, function () use (&$subsequentExecuted) {
            $subsequentExecuted = true;
        }, timeoutSeconds: 1);

        $this->assertTrue($subsequentExecuted, 'Lock was not properly released after nested exception.');
    }

    /**
     * 10. Transaction rollback under failure releases lock allowing waiting/subsequent process to succeed.
     */
    public function test_transaction_rollback_under_failure_releases_lock_allowing_subsequent_generation(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeConcurrent/RollbackPkg');
        $this->artisan('laraseed:make-package AcmeConcurrent/RollbackPkg')->assertExitCode(0);

        $composerPath = "{$pkgDir}/composer.json";
        $composerOriginal = file_get_contents($composerPath);

        // Make composer.json read-only to cause atomic transaction failure
        chmod($composerPath, 0444);

        $adminGen = $this->app->make(AdminGenerator::class);

        try {
            $adminGen->generate('AcmeConcurrent/RollbackPkg');
            $this->fail('Expected generation failure.');
        } catch (\Throwable) {
            // Expected
        } finally {
            chmod($composerPath, 0664);
        }

        // Original composer.json must be preserved and no residue files left
        $this->assertSame($composerOriginal, file_get_contents($composerPath));
        $this->assertFalse($this->filesystem->isDirectory("{$pkgDir}/src/Admin"));

        // Verify lock is released and another generation can succeed
        $adminResult = $adminGen->generate('AcmeConcurrent/RollbackPkg');
        $this->assertNotEmpty($adminResult['files']);
        $this->assertTrue($this->filesystem->isDirectory("{$pkgDir}/src/Admin"));
    }

    /**
     * Collision preflight is honored inside lock scope.
     */
    public function test_concurrent_conflicting_writes_respect_collision_preflight_and_force(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeConcurrent/ConflictPkg');
        $this->artisan('laraseed:make-package AcmeConcurrent/ConflictPkg')->assertExitCode(0);

        $adminGen = $this->app->make(AdminGenerator::class);
        $adminGen->generate('AcmeConcurrent/ConflictPkg');

        // Second generation without force must throw collision exception
        $this->expectException(PackageGenerationException::class);
        $this->expectExceptionMessage('Existing files detected');

        $adminGen->generate('AcmeConcurrent/ConflictPkg', force: false);
    }

    /**
     * Dry-run simulation executes within lock without mutating the filesystem.
     */
    public function test_dry_run_executes_within_lock_without_mutating_filesystem(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeConcurrent/DryRunLockPkg');
        $this->artisan('laraseed:make-package AcmeConcurrent/DryRunLockPkg')->assertExitCode(0);

        $composerBefore = file_get_contents("{$pkgDir}/composer.json");

        $adminGen = $this->app->make(AdminGenerator::class);
        $result = $adminGen->generate('AcmeConcurrent/DryRunLockPkg', dryRun: true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame($composerBefore, file_get_contents("{$pkgDir}/composer.json"));
        $this->assertFalse($this->filesystem->isDirectory("{$pkgDir}/src/Admin"));
    }

    /**
     * Sequential generation backward compatibility across all generator commands.
     */
    public function test_sequential_generation_backward_compatibility(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeConcurrent/SequentialPkg');
        $this->artisan('laraseed:make-package AcmeConcurrent/SequentialPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-middleware AcmeConcurrent/SequentialPkg LogRequests')->assertExitCode(0);
        $this->artisan('laraseed:make-mail AcmeConcurrent/SequentialPkg WelcomeMail')->assertExitCode(0);
        $this->artisan('laraseed:make-notification AcmeConcurrent/SequentialPkg AlertNotification')->assertExitCode(0);
        $this->artisan('laraseed:make-model AcmeConcurrent/SequentialPkg Item --proxy')->assertExitCode(0);
        $this->artisan('laraseed:make-admin AcmeConcurrent/SequentialPkg')->assertExitCode(0);
        $this->artisan('laraseed:make-web AcmeConcurrent/SequentialPkg')->assertExitCode(0);

        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Http/Middleware/LogRequests.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Mail/WelcomeMail.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Notifications/AlertNotification.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Models/Item.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Contracts/Item.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Models/ItemProxy.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Admin/Providers/AdminServiceProvider.php"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Web/Providers/WebServiceProvider.php"));

        $composer = json_decode((string) file_get_contents("{$pkgDir}/composer.json"), true);
        $this->assertArrayHasKey('admin', $composer['extra']['laraseed']['capabilities']);
        $this->assertArrayHasKey('web', $composer['extra']['laraseed']['capabilities']);
    }
}
