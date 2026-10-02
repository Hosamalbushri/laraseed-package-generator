<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\PackageGenerator;

class PackageMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laraseed:make-package
                            {name : The vendor and package name in Vendor/PackageName format (e.g. Acme/Blog)}
                            {--plain : Generate a minimal library package without Concord or presentation boilerplate}
                            {--dry-run : Simulate generation without creating or modifying any files}
                            {--force : Force overwrite of existing package recipe files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new Laraseed optional package adhering to Foundation isolation rules';

    /**
     * Execute the console command.
     */
    public function handle(PackageGenerator $generator): int
    {
        $input = (string) $this->argument('name');
        $plain = (bool) $this->option('plain');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $result = $generator->generate(
                input: $input,
                plain: $plain,
                dryRun: $dryRun,
                force: $force
            );
            $identity = $result['identity'];

            if ($dryRun) {
                $this->components->info('[DRY-RUN MODE] Generation simulated successfully. Zero files were created on disk.');
            }

            $tableData = array_map(
                fn (array $file): array => [$file['path'], $file['action'], "{$file['bytes']} bytes"],
                $result['files']
            );

            $this->table(['File Path', 'Action', 'Size'], $tableData);

            if (! $dryRun) {
                if ($plain) {
                    $this->components->info("Plain package [{$identity->composerName}] generated successfully!");
                    $this->line("<comment>Package ID:</comment> {$identity->packageSnake}");
                    $this->line("<comment>Provider:</comment>   {$identity->providerFqn}");
                    $this->line('');
                    $this->line("To register this package in Laraseed, enable package ID [{$identity->packageSnake}] in LARASEED_OPTIONAL_PACKAGES.");
                } else {
                    $this->components->info("Package [{$identity->composerName}] generated successfully!");
                    $this->line("<comment>Package ID:</comment> {$identity->packageSnake}");
                    $this->line("<comment>Provider:</comment>   {$identity->providerFqn}");
                    $this->line("<comment>Concord:</comment>    {$identity->moduleFqn}");
                    $this->line('');
                    $this->line("To register this package in Laraseed, enable package ID [{$identity->packageSnake}] in LARASEED_OPTIONAL_PACKAGES.");
                }
            }

            return self::SUCCESS;
        } catch (PackageGenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
