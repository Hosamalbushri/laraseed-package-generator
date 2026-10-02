<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\ModelGenerator;

class ModelMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laraseed:make-model
                            {package : The vendor and package name in Vendor/PackageName format (e.g. Acme/Blog)}
                            {name : The Model class name (e.g. Post)}
                            {--contract : Generate companion Contract interface}
                            {--proxy : Generate companion Contract interface and Concord ModelProxy}
                            {--dry-run : Simulate generation without creating or modifying any files}
                            {--force : Force overwrite of existing model, contract, or proxy files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new Eloquent model (with optional Contract and Concord Proxy) within the specified Laraseed package';

    /**
     * Execute the console command.
     */
    public function handle(ModelGenerator $generator): int
    {
        $packageInput = (string) $this->argument('package');
        $modelName = (string) $this->argument('name');
        $withContract = (bool) $this->option('contract');
        $withProxy = (bool) $this->option('proxy');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $result = $generator->generate(
                packageInput: $packageInput,
                modelName: $modelName,
                withProxy: $withProxy,
                withContract: $withContract,
                dryRun: $dryRun,
                force: $force
            );

            $pkg = $result['package'];

            if ($dryRun) {
                $this->components->info('[DRY-RUN MODE] Model generation simulated successfully. Zero files were created on disk.');
            }

            $tableData = array_map(
                fn (array $file): array => [$file['path'], $file['action'], "{$file['bytes']} bytes"],
                $result['files']
            );

            $this->table(['File Path', 'Action', 'Size'], $tableData);

            if (! $dryRun) {
                if ($result['has_proxy']) {
                    $this->components->info("Model [{$pkg->namespace}\\Models\\{$modelName}], Contract [{$pkg->namespace}\\Contracts\\{$modelName}], and Proxy [{$pkg->namespace}\\Models\\{$modelName}Proxy] generated successfully!");
                    $this->components->bulletList([
                        "Register model in [src/Providers/ModuleServiceProvider.php]: protected \$models = [ {$modelName}::class ];",
                    ]);
                } elseif ($result['has_contract']) {
                    $this->components->info("Model [{$pkg->namespace}\\Models\\{$modelName}] and Contract [{$pkg->namespace}\\Contracts\\{$modelName}] generated successfully!");
                } else {
                    $this->components->info("Model [{$pkg->namespace}\\Models\\{$modelName}] generated successfully!");
                }
            }

            return self::SUCCESS;
        } catch (PackageGenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
