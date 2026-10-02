<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Generators\WebGenerator;
use Laraseed\PackageGenerator\Templates\WebTemplateCatalog;

class WebMakeCommand extends Command
{
    protected $signature = 'laraseed:make-web
                            {package : The vendor and package name in Vendor/PackageName format (e.g. Acme/Blog)}
                            {--template=starter : The Web template to scaffold (default: starter)}
                            {--dry-run : Simulate generation without creating or modifying any files}
                            {--force : Force overwrite of existing web capability files}';

    protected $description = 'Generate optional Web capability skeleton within the specified Laraseed package';

    public function handle(WebGenerator $generator): int
    {
        $packageInput = (string) $this->argument('package');
        $template = (string) ($this->option('template') ?: WebTemplateCatalog::defaultTemplateId());
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        try {
            $result = $generator->generate($packageInput, $template, $dryRun, $force);
            $pkg = $result['package'];

            if ($dryRun) {
                $this->components->info("[DRY-RUN MODE] Web capability [template: {$template}] generation simulated successfully. Zero files were created on disk.");
            }

            $tableData = array_map(
                fn (array $file): array => [$file['path'], $file['action'], "{$file['bytes']} bytes"],
                $result['files']
            );

            $this->table(['File Path', 'Action', 'Size'], $tableData);

            if (! $dryRun) {
                $this->components->info("Web capability [template: {$template}] for package [{$pkg->identity->composerName}] generated successfully!");
            }

            return self::SUCCESS;
        } catch (PackageGenerationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
