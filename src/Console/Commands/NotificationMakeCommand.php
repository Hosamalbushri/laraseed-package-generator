<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\NotificationGenerator;

class NotificationMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laraseed:make-notification
                            {package : The vendor and package name in Vendor/PackageName format (e.g. Acme/Blog)}
                            {name : The Notification class name (e.g. OrderCreated)}
                            {--channels=mail : Comma-separated list of delivery channels (e.g. mail, database, broadcast)}
                            {--database : Include database notification channel}
                            {--broadcast : Include broadcast notification channel}
                            {--queued : Whether the Notification should implement ShouldQueue}
                            {--dry-run : Simulate generation without creating or modifying any files}
                            {--force : Force overwrite of existing notification file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new Notification class within the specified Laraseed package';

    /**
     * Execute the console command.
     */
    public function handle(NotificationGenerator $generator): int
    {
        $packageInput = (string) $this->argument('package');
        $notificationName = (string) $this->argument('name');
        $channelsInput = $this->option('channels') !== null ? (string) $this->option('channels') : 'mail';
        $database = (bool) $this->option('database');
        $broadcast = (bool) $this->option('broadcast');
        $queued = (bool) $this->option('queued');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $channels = array_filter(array_map('trim', explode(',', $channelsInput)));
        if ($database && ! in_array('database', $channels, true)) {
            $channels[] = 'database';
        }
        if ($broadcast && ! in_array('broadcast', $channels, true)) {
            $channels[] = 'broadcast';
        }

        try {
            $result = $generator->generate($packageInput, $notificationName, $channels, $queued, $dryRun, $force);
            $pkg = $result['package'];

            if ($dryRun) {
                $this->components->info('[DRY-RUN MODE] Notification generation simulated successfully. Zero files were created on disk.');
            }

            $tableData = array_map(
                fn (array $file): array => [$file['path'], $file['action'], "{$file['bytes']} bytes"],
                $result['files']
            );

            $this->table(['File Path', 'Action', 'Size'], $tableData);

            if (! $dryRun) {
                $this->components->info("Notification [{$pkg->namespace}\\Notifications\\{$notificationName}] generated successfully!");
                $this->line("<comment>Channels:</comment> " . implode(', ', $result['channels']));
            }

            return self::SUCCESS;
        } catch (PackageGenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
