<?php

namespace Laraseed\PackageGenerator\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\FilesystemTransaction;
use Laraseed\PackageGenerator\Generators\FilesystemWriter;
use Laraseed\PackageGenerator\Generators\GenerationPlan;
use Laraseed\PackageGenerator\Generators\PackageGenerator;
use Laraseed\PackageGenerator\Support\PackageResolver;
use Laraseed\PackageGenerator\Support\PathGuard;
use Laraseed\PackageGenerator\Tests\TestCase;

class PackageGeneratorContainmentAndTransactionTest extends TestCase
{
    protected Filesystem $filesystem;

    /**
     * @var list<string>
     */
    protected array $createdDirectories = [];

    /**
     * @var list<string>
     */
    protected array $createdSymlinks = [];

    /**
     * @var list<string>
     */
    protected array $externalTempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem;
        $this->createdDirectories = [];
        $this->createdSymlinks = [];
        $this->externalTempDirs = [];
    }

    protected function tearDown(): void
    {
        // 1. Remove symlinks first (never follow or delete target dirs through symlinks)
        foreach ($this->createdSymlinks as $symlink) {
            if (is_link($symlink)) {
                @unlink($symlink);
            }
        }

        // 2. Remove created package directories inside the repository
        foreach ($this->createdDirectories as $dir) {
            if ($this->filesystem->isDirectory($dir) && ! is_link($dir)) {
                $this->filesystem->deleteDirectory($dir);
            }
        }

        // 3. Remove disposable external temp directories
        foreach ($this->externalTempDirs as $tempDir) {
            if ($this->filesystem->isDirectory($tempDir) && ! is_link($tempDir)) {
                $this->filesystem->deleteDirectory($tempDir);
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

    protected function createExternalTempDir(string $prefix = 'laraseed_ext_'): string
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . uniqid();
        $this->filesystem->makeDirectory($tempDir, 0755, true);
        $this->externalTempDirs[] = $tempDir;

        return $tempDir;
    }

    protected function createTrackedSymlink(string $target, string $linkPath): void
    {
        $parent = dirname($linkPath);
        if (! $this->filesystem->isDirectory($parent)) {
            $this->filesystem->makeDirectory($parent, 0755, true);
            $this->trackDirectory($parent);
        }

        if (file_exists($linkPath) || is_link($linkPath)) {
            @unlink($linkPath);
        }

        symlink($target, $linkPath);
        $this->createdSymlinks[] = $linkPath;
    }

    // =========================================================================
    // STEP 2 / SEC-PG-04: FILESYSTEM CONTAINMENT TESTS
    // =========================================================================

    public function test_package_resolver_rejects_external_symlink_package_root(): void
    {
        $externalDir = $this->createExternalTempDir('ext_pkg_');
        $this->filesystem->put($externalDir . '/composer.json', json_encode([
            'name' => 'acme-test/symlink-pkg',
            'extra' => [
                'laraseed' => [
                    'id' => 'symlink_pkg',
                    'type' => 'optional',
                ],
            ],
        ]));

        $linkPath = base_path('packages/AcmeTest/ExternalSymlinkPkg');
        $this->createTrackedSymlink($externalDir, $linkPath);

        $resolver = new PackageResolver($this->filesystem);

        $this->expectException(PackageGenerationException::class);
        $this->expectExceptionMessage('outside the authorized packages directory');

        $resolver->resolve('AcmeTest/ExternalSymlinkPkg');
    }

    public function test_subgenerators_reject_external_symlink_package_via_cli(): void
    {
        $externalDir = $this->createExternalTempDir('ext_sub_');
        $this->filesystem->put($externalDir . '/composer.json', json_encode([
            'name' => 'acme-test/symlink-sub-pkg',
            'extra' => [
                'laraseed' => [
                    'id' => 'symlink_sub_pkg',
                    'type' => 'optional',
                ],
            ],
        ]));

        $linkPath = base_path('packages/AcmeTest/SymlinkSubPkg');
        $this->createTrackedSymlink($externalDir, $linkPath);

        // All CLI commands must reject the external symlink package
        $this->artisan('laraseed:make-model AcmeTest/SymlinkSubPkg Post')->assertExitCode(1);
        $this->artisan('laraseed:make-admin AcmeTest/SymlinkSubPkg')->assertExitCode(1);
        $this->artisan('laraseed:make-web AcmeTest/SymlinkSubPkg')->assertExitCode(1);
        $this->artisan('laraseed:make-repository AcmeTest/SymlinkSubPkg PostRepository')->assertExitCode(1);

        // Verify zero files were written into the external target directory
        $this->assertFalse(file_exists($externalDir . '/src/Models/Post.php'));
        $this->assertFalse(file_exists($externalDir . '/src/Admin'));
        $this->assertFalse(file_exists($externalDir . '/src/Web'));
    }

    public function test_nested_symlink_inside_package_is_rejected(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/NestedSymPkg');
        $this->artisan('laraseed:make-package AcmeTest/NestedSymPkg')->assertExitCode(0);

        $externalDir = $this->createExternalTempDir('ext_nested_');
        $nestedLink = "{$pkgDir}/src/Models";
        $this->createTrackedSymlink($externalDir, $nestedLink);

        $this->artisan('laraseed:make-model AcmeTest/NestedSymPkg User')->assertExitCode(1);

        // Verify target inside external directory was NOT created
        $this->assertFalse(file_exists($externalDir . '/User.php'));
    }

    public function test_broken_or_dangling_symlink_is_rejected_with_actionable_error(): void
    {
        $linkPath = base_path('packages/AcmeTest/DanglingLinkPkg');
        $this->createTrackedSymlink('/nonexistent/directory/' . uniqid(), $linkPath);

        $resolver = new PackageResolver($this->filesystem);

        $this->expectException(PackageGenerationException::class);
        $this->expectExceptionMessage('broken or dangling symbolic link');

        $resolver->resolve('AcmeTest/DanglingLinkPkg');
    }

    public function test_directory_boundary_aware_comparison_rejects_prefix_collisions(): void
    {
        $base = base_path();
        $evilPath = rtrim($base, '/\\') . '/packages-evil/Acme/Blog';

        $this->expectException(PackageGenerationException::class);
        $this->expectExceptionMessage('outside the authorized packages directory');

        PathGuard::assertWithinAuthorizedPackages($evilPath, $base);
    }

    public function test_nonexistent_destinations_inside_packages_are_permitted(): void
    {
        $base = base_path();
        $legitPath = base_path('packages/AcmeTest/BrandNewPkg/src/Providers/BrandNewPkgServiceProvider.php');

        $canonical = PathGuard::assertWithinAuthorizedPackages($legitPath, $base);

        $this->assertStringStartsWith(realpath(base_path('packages')), $canonical);
    }

    public function test_revalidation_immediately_before_writing_protects_destination_paths(): void
    {
        $tx = new FilesystemTransaction($this->filesystem, base_path());

        $this->expectException(PackageGenerationException::class);
        $this->expectExceptionMessage('outside the authorized packages directory');

        $tx->writeFile('/tmp/evil_file.txt', 'evil_content');
    }

    // =========================================================================
    // STEP 3 & 4 / SEC-PG-02: TRANSACTIONAL GENERATION & FAILURE INJECTION
    // =========================================================================

    public function test_failure_before_first_write_leaves_zero_filesystem_changes(): void
    {
        $pkgDir = base_path('packages/AcmeTest/FailBeforeFirst');
        $this->trackDirectory($pkgDir);

        $files = [
            'src/Test.php' => '<?php // test',
        ];

        // Intentionally create an invalid destination plan with a path that will fail containment
        $plan = new GenerationPlan('packages/AcmeTest/FailBeforeFirst', base_path(), $files);

        $tx = new FilesystemTransaction($this->filesystem, base_path());

        try {
            $tx->run(function (FilesystemTransaction $t) use ($plan) {
                // Simulate an exception before any write
                throw new \RuntimeException('Failure before first write');
            });
            $this->fail('Expected exception was not thrown');
        } catch (PackageGenerationException $e) {
            $this->assertStringContainsString('Failure before first write', $e->getMessage());
        }

        $this->assertFalse($this->filesystem->isDirectory($pkgDir));
    }

    public function test_failure_after_one_successful_write_rolls_back_first_file_and_directories(): void
    {
        $pkgDir = base_path('packages/AcmeTest/RollbackAfterOne');
        $this->trackDirectory($pkgDir);

        $file1 = "{$pkgDir}/file1.txt";
        $file2 = "{$pkgDir}/sub/file2.txt";

        $tx = new FilesystemTransaction($this->filesystem, base_path());

        try {
            $tx->run(function (FilesystemTransaction $t) use ($file1, $file2) {
                $t->writeFile($file1, 'content 1');
                // At this point file1 exists
                throw new \RuntimeException('Failure on step 2');
            });
            $this->fail('Expected exception was not thrown');
        } catch (PackageGenerationException $e) {
            $this->assertStringContainsString('Failure on step 2', $e->getMessage());
        }

        // Assert file1 was rolled back and deleted
        $this->assertFalse(file_exists($file1));
        $this->assertFalse($this->filesystem->isDirectory($pkgDir));
    }

    public function test_failure_midway_through_base_package_generation_rolls_back_all_created_files(): void
    {
        $pkgDir = base_path('packages/AcmeTest/MidFlightFailPkg');
        $this->trackDirectory($pkgDir);

        // Pre-create the directory for provider and make it read-only to force write failure midway
        $this->filesystem->makeDirectory("{$pkgDir}/src/Providers", 0755, true);
        chmod("{$pkgDir}/src/Providers", 0555);

        try {
            $this->artisan('laraseed:make-package AcmeTest/MidFlightFailPkg')->assertExitCode(1);
        } finally {
            chmod("{$pkgDir}/src/Providers", 0755);
        }

        // Verify composer.json and other files were rolled back and zero orphan files remain
        $this->assertFalse(file_exists("{$pkgDir}/composer.json"));
        $this->assertFalse(file_exists("{$pkgDir}/src/Config/mid_flight_fail_pkg.php"));
        $this->assertFalse($this->filesystem->isDirectory("{$pkgDir}/src/Config"));
    }

    public function test_failure_while_overwriting_existing_file_restores_original_content(): void
    {
        $pkgDir = base_path('packages/AcmeTest/OverwriteRestorePkg');
        $this->trackDirectory($pkgDir);

        $this->filesystem->makeDirectory($pkgDir, 0755, true);
        $testFile = "{$pkgDir}/config.json";
        $originalContent = '{"version": 1, "custom": "pre_existing_data"}';
        $this->filesystem->put($testFile, $originalContent);

        $tx = new FilesystemTransaction($this->filesystem, base_path());

        try {
            $tx->run(function (FilesystemTransaction $t) use ($testFile) {
                // Overwrite the existing file
                $t->writeFile($testFile, '{"version": 2, "modified": true}', force: true);
                // Then fail
                throw new \RuntimeException('Midway crash after overwrite');
            });
            $this->fail('Expected exception was not thrown');
        } catch (PackageGenerationException $e) {
            $this->assertStringContainsString('Midway crash after overwrite', $e->getMessage());
        }

        // Verify original content was faithfully restored
        $this->assertTrue(file_exists($testFile));
        $this->assertSame($originalContent, file_get_contents($testFile));
    }

    public function test_rollback_failure_reporting_includes_both_errors(): void
    {
        // Create custom FailingFilesystem to simulate rollback error
        $failingFs = new class extends Filesystem {
            public bool $shouldFailDelete = false;

            public function delete($paths): bool
            {
                if ($this->shouldFailDelete) {
                    return false;
                }

                return parent::delete($paths);
            }
        };

        $pkgDir = base_path('packages/AcmeTest/RollbackFailPkg');
        $this->trackDirectory($pkgDir);

        $tx = new FilesystemTransaction($failingFs, base_path());

        try {
            $tx->run(function (FilesystemTransaction $t) use ($pkgDir, $failingFs) {
                $t->writeFile("{$pkgDir}/test.txt", 'data');
                $failingFs->shouldFailDelete = true;
                throw new \RuntimeException('Original write failure');
            });
            $this->fail('Expected exception was not thrown');
        } catch (PackageGenerationException $e) {
            $this->assertStringContainsString('Original write failure', $e->getMessage());
            $this->assertStringContainsString('Rollback failure', $e->getMessage());
        }
    }

    public function test_safe_retry_after_rollback_succeeds_without_collision(): void
    {
        $pkgDir = base_path('packages/AcmeTest/SafeRetryPkg');
        $this->trackDirectory($pkgDir);

        // 1. Simulate failure during first attempt
        $this->filesystem->makeDirectory("{$pkgDir}/src/Providers", 0755, true);
        chmod("{$pkgDir}/src/Providers", 0555);

        try {
            $this->artisan('laraseed:make-package AcmeTest/SafeRetryPkg')->assertExitCode(1);
        } finally {
            chmod("{$pkgDir}/src/Providers", 0755);
        }

        // 2. Safe retry: Re-running without --force must succeed cleanly
        $this->artisan('laraseed:make-package AcmeTest/SafeRetryPkg')->assertExitCode(0);

        $this->assertTrue($this->filesystem->exists("{$pkgDir}/composer.json"));
        $this->assertTrue($this->filesystem->exists("{$pkgDir}/src/Providers/SafeRetryPkgServiceProvider.php"));
    }

    public function test_dry_run_creates_zero_files_and_zero_directories(): void
    {
        $pkgDir = base_path('packages/AcmeTest/DryRunFullCheckPkg');
        $this->trackDirectory($pkgDir);

        $this->artisan('laraseed:make-package AcmeTest/DryRunFullCheckPkg --dry-run')
            ->assertExitCode(0);

        $this->assertFalse($this->filesystem->isDirectory($pkgDir));
    }

    public function test_pre_existing_user_data_is_never_deleted_during_rollback(): void
    {
        $pkgDir = base_path('packages/AcmeTest/PreExistingUserPkg');
        $this->trackDirectory($pkgDir);

        $this->filesystem->makeDirectory("{$pkgDir}/custom_user_dir", 0755, true);
        $userFile = "{$pkgDir}/custom_user_dir/user_notes.txt";
        $this->filesystem->put($userFile, 'Do not delete this critical user data');

        $tx = new FilesystemTransaction($this->filesystem, base_path());

        try {
            $tx->run(function (FilesystemTransaction $t) use ($pkgDir) {
                $t->writeFile("{$pkgDir}/src/Generated.php", '<?php // code');
                throw new \RuntimeException('Generation failed');
            });
        } catch (PackageGenerationException $e) {
            $this->assertStringContainsString('Generation failed', $e->getMessage());
        }

        // Verify user file and user directory are intact
        $this->assertTrue(file_exists($userFile));
        $this->assertSame('Do not delete this critical user data', file_get_contents($userFile));
        $this->assertTrue($this->filesystem->isDirectory("{$pkgDir}/custom_user_dir"));
        // Generated file was rolled back
        $this->assertFalse(file_exists("{$pkgDir}/src/Generated.php"));
    }

    public function test_concurrent_generation_of_different_packages_operates_without_cross_interference(): void
    {
        $pkg1Dir = $this->trackDirectory('packages/AcmeTest/ConcurPkgOne');
        $pkg2Dir = $this->trackDirectory('packages/AcmeTest/ConcurPkgTwo');

        $gen1 = new PackageGenerator(basePath: base_path());
        $gen2 = new PackageGenerator(basePath: base_path());

        $res1 = $gen1->generate('AcmeTest/ConcurPkgOne');
        $res2 = $gen2->generate('AcmeTest/ConcurPkgTwo');

        $this->assertCount(10, $res1['files']);
        $this->assertCount(10, $res2['files']);

        $this->assertTrue($this->filesystem->exists("{$pkg1Dir}/composer.json"));
        $this->assertTrue($this->filesystem->exists("{$pkg2Dir}/composer.json"));
    }

    public function test_same_package_concurrency_without_force_fails_cleanly_preserving_first_process_output(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/SamePkgConcur');

        $tx1 = new FilesystemTransaction($this->filesystem, base_path());
        $tx2 = new FilesystemTransaction($this->filesystem, base_path());

        // Process 1 writes initial files
        $tx1->run(function (FilesystemTransaction $t) use ($pkgDir) {
            $t->writeFile("{$pkgDir}/composer.json", '{"name": "process-1"}');
            $t->writeFile("{$pkgDir}/src/Providers/PackageServiceProvider.php", '<?php // process-1');
        });

        $this->assertSame('{"name": "process-1"}', file_get_contents("{$pkgDir}/composer.json"));

        // Process 2 concurrently attempts to generate same package without force
        try {
            $tx2->run(function (FilesystemTransaction $t) use ($pkgDir) {
                // T2 creates a new file first
                $t->writeFile("{$pkgDir}/src/Config/config.php", '<?php // process-2 config');
                // T2 collides on composer.json without force
                $t->writeFile("{$pkgDir}/composer.json", '{"name": "process-2"}', force: false);
            });
            $this->fail('Process 2 should have thrown collision exception');
        } catch (PackageGenerationException $e) {
            $this->assertStringContainsString('Existing files detected', $e->getMessage());
        }

        // Assert Process 1 output was preserved, Process 2 newly created file was rolled back
        $this->assertSame('{"name": "process-1"}', file_get_contents("{$pkgDir}/composer.json"));
        $this->assertSame('<?php // process-1', file_get_contents("{$pkgDir}/src/Providers/PackageServiceProvider.php"));
        $this->assertFalse(file_exists("{$pkgDir}/src/Config/config.php"));
    }

    public function test_same_package_concurrency_with_force_restores_prior_content_on_failure(): void
    {
        $pkgDir = $this->trackDirectory('packages/AcmeTest/SamePkgForceConcur');

        $tx1 = new FilesystemTransaction($this->filesystem, base_path());
        $tx2 = new FilesystemTransaction($this->filesystem, base_path());

        // Process 1 commits baseline files
        $tx1->run(function (FilesystemTransaction $t) use ($pkgDir) {
            $t->writeFile("{$pkgDir}/composer.json", '{"name": "process-1-baseline"}');
        });

        // Process 2 overwrites composer.json with force, but crashes midway
        try {
            $tx2->run(function (FilesystemTransaction $t) use ($pkgDir) {
                $t->writeFile("{$pkgDir}/composer.json", '{"name": "process-2-mutated"}', force: true);
                $t->writeFile("{$pkgDir}/src/Extra.php", '<?php // extra');
                throw new \RuntimeException('Process 2 crashed midway');
            });
            $this->fail('Process 2 should have thrown exception');
        } catch (PackageGenerationException $e) {
            $this->assertStringContainsString('Process 2 crashed midway', $e->getMessage());
        }

        // Assert composer.json was restored to Process 1 baseline and extra file removed
        $this->assertSame('{"name": "process-1-baseline"}', file_get_contents("{$pkgDir}/composer.json"));
        $this->assertFalse(file_exists("{$pkgDir}/src/Extra.php"));
    }
}
