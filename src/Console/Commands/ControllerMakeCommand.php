<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\ControllerGenerator;

class ControllerMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laraseed:make-controller
                            {package : The vendor and package name in Vendor/PackageName format (e.g. Acme/Blog)}
                            {name : The Controller class name (e.g. PostController)}
                            {--api : Generate an API controller with JSON response methods}
                            {--dry-run : Simulate generation without creating or modifying any files}
                            {--force : Force overwrite of existing controller file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a presentation-neutral Controller within the specified Laraseed package';

    /**
     * Execute the console command.
     */
    public function handle(ControllerGenerator $generator): int
    {
        $packageInput = (string) $this->argument('package');
        $controllerName = (string) $this->argument('name');
        $isApi = (bool) $this->option('api');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $result = $generator->generate($packageInput, $controllerName, $isApi, $dryRun, $force);
            $pkg = $result['package'];

            if ($dryRun) {
                $this->components->info('[DRY-RUN MODE] Controller generation simulated successfully. Zero files were created on disk.');
            }

            $tableData = array_map(
                fn (array $file): array => [$file['path'], $file['action'], "{$file['bytes']} bytes"],
                $result['files']
            );

            $this->table(['File Path', 'Action', 'Size'], $tableData);

            if (! $dryRun) {
                $typeStr = $isApi ? 'API Controller' : 'Controller';
                $this->components->info("{$typeStr} [{$pkg->namespace}\\Http\\Controllers\\{$controllerName}] generated successfully!");
            }

            return self::SUCCESS;
        } catch (PackageGenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
