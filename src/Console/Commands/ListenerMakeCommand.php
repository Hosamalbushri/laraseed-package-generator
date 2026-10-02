<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\ListenerGenerator;

class ListenerMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laraseed:make-listener
                            {package : The vendor and package name in Vendor/PackageName format (e.g. Acme/Blog)}
                            {name : The Listener class name (e.g. SendPostNotification)}
                            {--event= : Optional existing Event class name within the package (e.g. PostCreated)}
                            {--dry-run : Simulate generation without creating or modifying any files}
                            {--force : Force overwrite of existing listener file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate an Event Listener within the specified Laraseed package';

    /**
     * Execute the console command.
     */
    public function handle(ListenerGenerator $generator): int
    {
        $packageInput = (string) $this->argument('package');
        $listenerName = (string) $this->argument('name');
        $eventName = $this->option('event') !== null ? (string) $this->option('event') : null;
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $result = $generator->generate($packageInput, $listenerName, $eventName, $dryRun, $force);
            $pkg = $result['package'];

            if ($dryRun) {
                $this->components->info('[DRY-RUN MODE] Listener generation simulated successfully. Zero files were created on disk.');
            }

            $tableData = array_map(
                fn (array $file): array => [$file['path'], $file['action'], "{$file['bytes']} bytes"],
                $result['files']
            );

            $this->table(['File Path', 'Action', 'Size'], $tableData);

            if (! $dryRun) {
                $this->components->info("Listener [{$pkg->namespace}\\Listeners\\{$listenerName}] generated successfully!");
            }

            return self::SUCCESS;
        } catch (PackageGenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
