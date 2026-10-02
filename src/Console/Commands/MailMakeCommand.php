<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\MailGenerator;

class MailMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laraseed:make-mail
                            {package : The vendor and package name in Vendor/PackageName format (e.g. Acme/Blog)}
                            {name : The Mailable class name (e.g. WelcomeMail)}
                            {--view= : Custom Blade view name (e.g. emails.welcome)}
                            {--markdown= : Generate a Markdown mail template with optional custom view name}
                            {--queued : Whether the Mailable should implement ShouldQueue}
                            {--dry-run : Simulate generation without creating or modifying any files}
                            {--force : Force overwrite of existing mail and view files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new Mailable class and companion Blade template within the specified Laraseed package';

    /**
     * Execute the console command.
     */
    public function handle(MailGenerator $generator): int
    {
        $packageInput = (string) $this->argument('package');
        $mailName = (string) $this->argument('name');
        $view = $this->option('view') !== null ? (string) $this->option('view') : null;
        $markdown = $this->option('markdown') !== null ? (string) $this->option('markdown') : null;
        $queued = (bool) $this->option('queued');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $result = $generator->generate($packageInput, $mailName, $view, $markdown, $queued, $dryRun, $force);
            $pkg = $result['package'];

            if ($dryRun) {
                $this->components->info('[DRY-RUN MODE] Mail generation simulated successfully. Zero files were created on disk.');
            }

            $tableData = array_map(
                fn (array $file): array => [$file['path'], $file['action'], "{$file['bytes']} bytes"],
                $result['files']
            );

            $this->table(['File Path', 'Action', 'Size'], $tableData);

            if (! $dryRun) {
                $this->components->info("Mailable [{$pkg->namespace}\\Mail\\{$mailName}] generated successfully!");
                $this->line("<comment>View Reference:</comment> {$pkg->identity->packageSnake}::{$result['view']}");
            }

            return self::SUCCESS;
        } catch (PackageGenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
