<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\ProxyGenerator;

class ProxyMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laraseed:make-proxy
                            {package : The vendor and package name in Vendor/PackageName format (e.g. Acme/Blog)}
                            {name : The Proxy class name or Model name (e.g. Post or PostProxy)}
                            {--dry-run : Simulate generation without creating or modifying any files}
                            {--force : Force overwrite of existing proxy file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new Concord ModelProxy within the specified Laraseed package';

    /**
     * Execute the console command.
     */
    public function handle(ProxyGenerator $generator): int
    {
        $packageInput = (string) $this->argument('package');
        $proxyName = (string) $this->argument('name');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $result = $generator->generate(
                packageInput: $packageInput,
                proxyName: $proxyName,
                dryRun: $dryRun,
                force: $force
            );

            $pkg = $result['package'];
            $proxyClass = $result['proxy'];

            if ($dryRun) {
                $this->components->info('[DRY-RUN MODE] ModelProxy generation simulated successfully. Zero files were created on disk.');
            }

            $tableData = array_map(
                fn (array $file): array => [$file['path'], $file['action'], "{$file['bytes']} bytes"],
                $result['files']
            );

            $this->table(['File Path', 'Action', 'Size'], $tableData);

            if (! $dryRun) {
                $this->components->info("ModelProxy [{$pkg->namespace}\\Models\\{$proxyClass}] generated successfully!");
            }

            return self::SUCCESS;
        } catch (PackageGenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
