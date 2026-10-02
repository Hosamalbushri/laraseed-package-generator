<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\CommandGenerator;

class CommandMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laraseed:make-command
                            {package : The vendor and package name in Vendor/PackageName format (e.g. Acme/Blog)}
                            {name : The Command class name (e.g. RebuildIndex)}
                            {--signature= : Optional custom Artisan signature (e.g. blog:rebuild-index)}
                            {--dry-run : Simulate generation without creating or modifying any files}
                            {--force : Force overwrite of existing command file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new Console Command within the specified Laraseed package';

    /**
     * Execute the console command.
     */
    public function handle(CommandGenerator $generator): int
    {
        $packageInput = (string) $this->argument('package');
        $commandName = (string) $this->argument('name');
        $sigOption = $this->option('signature') !== null ? (string) $this->option('signature') : null;
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $result = $generator->generate($packageInput, $commandName, $sigOption, $dryRun, $force);
            $pkg = $result['package'];

            if ($dryRun) {
                $this->components->info('[DRY-RUN MODE] Command generation simulated successfully. Zero files were created on disk.');
            }

            $tableData = array_map(
                fn (array $file): array => [$file['path'], $file['action'], "{$file['bytes']} bytes"],
                $result['files']
            );

            $this->table(['File Path', 'Action', 'Size'], $tableData);

            if (! $dryRun) {
                $this->components->info("Console Command [{$pkg->namespace}\\Console\\Commands\\{$commandName}] (signature: '{$result['signature']}') generated successfully!");
            }

            return self::SUCCESS;
        } catch (PackageGenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
