<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\RequestGenerator;

class RequestMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laraseed:make-request
                            {package : The vendor and package name in Vendor/PackageName format (e.g. Acme/Blog)}
                            {name : The FormRequest class name (e.g. StorePostRequest)}
                            {--dry-run : Simulate generation without creating or modifying any files}
                            {--force : Force overwrite of existing request file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new FormRequest within the specified Laraseed package';

    /**
     * Execute the console command.
     */
    public function handle(RequestGenerator $generator): int
    {
        $packageInput = (string) $this->argument('package');
        $requestName = (string) $this->argument('name');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $result = $generator->generate($packageInput, $requestName, $dryRun, $force);
            $pkg = $result['package'];

            if ($dryRun) {
                $this->components->info('[DRY-RUN MODE] Request generation simulated successfully. Zero files were created on disk.');
            }

            $tableData = array_map(
                fn (array $file): array => [$file['path'], $file['action'], "{$file['bytes']} bytes"],
                $result['files']
            );

            $this->table(['File Path', 'Action', 'Size'], $tableData);

            if (! $dryRun) {
                $this->components->info("FormRequest [{$pkg->namespace}\\Http\\Requests\\{$requestName}] generated successfully!");
            }

            return self::SUCCESS;
        } catch (PackageGenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
