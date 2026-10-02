<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\AdminGenerator;

class AdminMakeCommand extends Command
{
    protected $signature = 'laraseed:make-admin
                            {package : The vendor and package name in Vendor/PackageName format (e.g. Acme/Blog)}
                            {--dry-run : Simulate generation without creating or modifying any files}
                            {--force : Force overwrite of existing admin integration files}';

    protected $description = 'Generate optional Admin integration layer skeleton within the specified Laraseed package';

    public function handle(AdminGenerator $generator): int
    {
        $packageInput = (string) $this->argument('package');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $result = $generator->generate($packageInput, $dryRun, $force);
            $pkg = $result['package'];

            if ($dryRun) {
                $this->components->info('[DRY-RUN MODE] Admin integration layer generation simulated successfully. Zero files were created on disk.');
            }

            $tableData = array_map(
                fn (array $file): array => [$file['path'], $file['action'], "{$file['bytes']} bytes"],
                $result['files']
            );

            $this->table(['File Path', 'Action', 'Size'], $tableData);

            if (! $dryRun) {
                $this->components->info("Admin integration layer for package [{$pkg->identity->composerName}] generated successfully!");
            }

            return self::SUCCESS;
        } catch (PackageGenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
