<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\DataGridGenerator;

class DataGridMakeCommand extends Command
{
    protected $signature = 'laraseed:make-datagrid
                            {package : The vendor and package name in Vendor/PackageName format (e.g. Acme/Blog)}
                            {name : The DataGrid class name (e.g. PostDataGrid)}
                            {--model= : Optional model name to associate with the DataGrid}
                            {--dry-run : Simulate generation without creating or modifying any files}
                            {--force : Force overwrite of existing DataGrid file}';

    protected $description = 'Generate a new DataGrid class within the specified Laraseed package';

    public function handle(DataGridGenerator $generator): int
    {
        $packageInput = (string) $this->argument('package');
        $dataGridName = (string) $this->argument('name');
        $model = $this->option('model') ? (string) $this->option('model') : null;
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $result = $generator->generate($packageInput, $dataGridName, $model, $dryRun, $force);
            $pkg = $result['package'];

            if ($dryRun) {
                $this->components->info('[DRY-RUN MODE] DataGrid generation simulated successfully. Zero files were created on disk.');
            }

            $tableData = array_map(
                fn (array $file): array => [$file['path'], $file['action'], "{$file['bytes']} bytes"],
                $result['files']
            );

            $this->table(['File Path', 'Action', 'Size'], $tableData);

            if (! $dryRun) {
                $this->components->info("DataGrid [{$dataGridName}] generated successfully!");
            }

            return self::SUCCESS;
        } catch (PackageGenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
