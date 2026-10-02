<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\SeederGenerator;

class SeederMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laraseed:make-seeder
                            {package : The vendor and package name in Vendor/PackageName format (e.g. Acme/Blog)}
                            {name : The Seeder class name (e.g. PostSeeder)}
                            {--dry-run : Simulate generation without creating or modifying any files}
                            {--force : Force overwrite of existing seeder file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new Database Seeder within the specified Laraseed package';

    /**
     * Execute the console command.
     */
    public function handle(SeederGenerator $generator): int
    {
        $packageInput = (string) $this->argument('package');
        $seederName = (string) $this->argument('name');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $result = $generator->generate($packageInput, $seederName, $dryRun, $force);
            $pkg = $result['package'];

            if ($dryRun) {
                $this->components->info('[DRY-RUN MODE] Seeder generation simulated successfully. Zero files were created on disk.');
            }

            $tableData = array_map(
                fn (array $file): array => [$file['path'], $file['action'], "{$file['bytes']} bytes"],
                $result['files']
            );

            $this->table(['File Path', 'Action', 'Size'], $tableData);

            if (! $dryRun) {
                $this->components->info("Seeder [{$pkg->namespace}\\Database\\Seeders\\{$seederName}] generated successfully!");
            }

            return self::SUCCESS;
        } catch (PackageGenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
