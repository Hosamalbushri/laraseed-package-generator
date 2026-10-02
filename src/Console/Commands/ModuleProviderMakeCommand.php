<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\ModuleProviderGenerator;

class ModuleProviderMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laraseed:make-module-provider
                            {package : The vendor and package name in Vendor/PackageName format (e.g. Acme/Blog)}
                            {--dry-run : Simulate generation without creating or modifying any files}
                            {--force : Force overwrite of existing ModuleServiceProvider file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate or regenerate the Concord ModuleServiceProvider for the specified Laraseed package';

    /**
     * Execute the console command.
     */
    public function handle(ModuleProviderGenerator $generator): int
    {
        $packageInput = (string) $this->argument('package');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $result = $generator->generate($packageInput, $dryRun, $force);
            $pkg = $result['package'];

            if ($dryRun) {
                $this->components->info('[DRY-RUN MODE] ModuleServiceProvider generation simulated successfully. Zero files were created on disk.');
            }

            $tableData = array_map(
                fn (array $file): array => [$file['path'], $file['action'], "{$file['bytes']} bytes"],
                $result['files']
            );

            $this->table(['File Path', 'Action', 'Size'], $tableData);

            if (! $dryRun) {
                $this->components->info("ModuleServiceProvider [{$pkg->namespace}\\Providers\\ModuleServiceProvider] generated successfully!");
            }

            return self::SUCCESS;
        } catch (PackageGenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
