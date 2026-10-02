<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\MigrationGenerator;

class MigrationMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laraseed:make-migration
                            {package : The vendor and package name in Vendor/PackageName format (e.g. Acme/Blog)}
                            {name : The migration name in snake_case (e.g. create_posts_table)}
                            {--dry-run : Simulate generation without creating or modifying any files}
                            {--force : Force overwrite of existing migration file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new database migration within the specified Laraseed package';

    /**
     * Execute the console command.
     */
    public function handle(MigrationGenerator $generator): int
    {
        $packageInput = (string) $this->argument('package');
        $migrationName = (string) $this->argument('name');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $result = $generator->generate($packageInput, $migrationName, $dryRun, $force);
            $pkg = $result['package'];

            if ($dryRun) {
                $this->components->info('[DRY-RUN MODE] Migration generation simulated successfully. Zero files were created on disk.');
            }

            $tableData = array_map(
                fn (array $file): array => [$file['path'], $file['action'], "{$file['bytes']} bytes"],
                $result['files']
            );

            $this->table(['File Path', 'Action', 'Size'], $tableData);

            if (! $dryRun) {
                $this->components->info("Migration [{$result['filename']}] generated successfully in package [{$pkg->identity->relativePackagePath}]!");
            }

            return self::SUCCESS;
        } catch (PackageGenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
