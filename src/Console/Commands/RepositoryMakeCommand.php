<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\RepositoryGenerator;

class RepositoryMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laraseed:make-repository
                            {package : The vendor and package name in Vendor/PackageName format (e.g. Acme/Blog)}
                            {name : The Repository class name (e.g. PostRepository)}
                            {--model= : Optional existing Model class name within the package (e.g. Post)}
                            {--dry-run : Simulate generation without creating or modifying any files}
                            {--force : Force overwrite of existing repository file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new Repository within the specified Laraseed package';

    /**
     * Execute the console command.
     */
    public function handle(RepositoryGenerator $generator): int
    {
        $packageInput = (string) $this->argument('package');
        $repositoryName = (string) $this->argument('name');
        $modelName = $this->option('model') !== null ? (string) $this->option('model') : null;
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $result = $generator->generate($packageInput, $repositoryName, $modelName, $dryRun, $force);
            $pkg = $result['package'];

            if ($dryRun) {
                $this->components->info('[DRY-RUN MODE] Repository generation simulated successfully. Zero files were created on disk.');
            }

            $tableData = array_map(
                fn (array $file): array => [$file['path'], $file['action'], "{$file['bytes']} bytes"],
                $result['files']
            );

            $this->table(['File Path', 'Action', 'Size'], $tableData);

            if (! $dryRun) {
                $this->components->info("Repository [{$pkg->namespace}\\Repositories\\{$repositoryName}] generated successfully!");
            }

            return self::SUCCESS;
        } catch (PackageGenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
