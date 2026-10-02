<?php

namespace Laraseed\PackageGenerator\Console\Commands;

use Illuminate\Console\Command;
use Laraseed\PackageGenerator\Support\DefaultWebPackageManager;

class WebDefaultCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laraseed:web-default
                            {package? : The package identifier to select as default Web entry point}
                            {--list : List all Web-capable packages and their current eligibility}
                            {--status : Inspect the current default Web package selection and status}
                            {--clear : Clear the default Web package selection}
                            {--dry-run : Simulate configuration changes without modifying disk}
                            {--force : Force selection bypassing non-fatal warnings}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage, inspect, select, or clear the default public Web package for the root entry point';

    /**
     * Execute the console command.
     */
    public function handle(DefaultWebPackageManager $manager): int
    {
        $package = $this->argument('package');
        $isList = $this->option('list');
        $isStatus = $this->option('status');
        $isClear = $this->option('clear');
        $isDryRun = $this->option('dry-run');

        if ($isStatus) {
            return $this->handleStatus($manager);
        }

        if ($isClear) {
            return $this->handleClear($manager, $isDryRun);
        }

        if ($package !== null && $package !== '') {
            return $this->handleSelect($manager, $package, $isDryRun);
        }

        return $this->handleList($manager);
    }

    /**
     * Handle status inspection.
     */
    protected function handleStatus(DefaultWebPackageManager $manager): int
    {
        $status = $manager->getStatus();

        $this->components->info('Laraseed Default Web Package Status');

        $rows = [
            ['Configured Key', $status['configured_value'] ?? '<none>'],
            ['Status', $status['status']],
            ['Valid', $status['is_valid'] ? 'YES' : 'NO'],
        ];

        if ($status['validation'] !== null) {
            $val = $status['validation'];
            $rows[] = ['Entry Route', $val['entry_route'] ?? '<none>'];
            $rows[] = ['Target URL', $val['target_url'] ?? '<none>'];
            $rows[] = ['Active in Optional Packages', $val['is_enabled'] ? 'YES' : 'NO'];
            $rows[] = ['Web Capability Declared', $val['has_web'] ? 'YES' : 'NO'];
        }

        $this->table(['Property', 'Value'], $rows);

        if (! $status['is_valid']) {
            $this->components->error($status['message']);

            return self::FAILURE;
        }

        $this->components->info($status['message']);

        return self::SUCCESS;
    }

    /**
     * Handle clearing the default package.
     */
    protected function handleClear(DefaultWebPackageManager $manager, bool $isDryRun): int
    {
        if ($isDryRun) {
            $this->components->info('[DRY RUN] Would clear LARASEED_DEFAULT_WEB_PACKAGE in .env.');

            return self::SUCCESS;
        }

        $envUpdated = $manager->persistEnv('LARASEED_DEFAULT_WEB_PACKAGE', '');

        if ($envUpdated) {
            $this->components->info('Default Web package cleared successfully in .env.');
        } else {
            $this->components->warn('No .env file found or unable to write. Please ensure LARASEED_DEFAULT_WEB_PACKAGE="" in your environment.');
        }

        $this->line('Root URL [/] will now serve the fallback landing view.');
        $this->line('In production, remember to run: <comment>php artisan config:cache && php artisan route:cache</comment>');

        return self::SUCCESS;
    }

    /**
     * Handle selecting a package as default.
     */
    protected function handleSelect(DefaultWebPackageManager $manager, string $packageId, bool $isDryRun): int
    {
        $val = $manager->validatePackage($packageId);

        if (! $val['valid']) {
            $this->components->error("Cannot select [{$packageId}] as default Web package:");
            foreach ($val['errors'] as $error) {
                $this->line("  • <fg=red>{$error}</>");
            }

            return self::FAILURE;
        }

        if ($isDryRun) {
            $this->components->info("[DRY RUN] Would set default Web package to [{$packageId}].");
            $this->line("  • Entry Route: <comment>{$val['entry_route']}</comment>");
            $this->line("  • Target URL:  <comment>{$val['target_url']}</comment>");

            return self::SUCCESS;
        }

        $envUpdated = $manager->persistEnv('LARASEED_DEFAULT_WEB_PACKAGE', $packageId);

        if ($envUpdated) {
            $this->components->info("Default Web package set to [{$packageId}] in .env.");
        } else {
            $this->components->warn("No .env file found or unable to write. Please ensure LARASEED_DEFAULT_WEB_PACKAGE=\"{$packageId}\" in your environment.");
        }

        $this->line("  • Entry Route: <comment>{$val['entry_route']}</comment>");
        $this->line("  • Target URL:  <comment>{$val['target_url']}</comment>");
        $this->line('In production, remember to run: <comment>php artisan config:cache && php artisan route:cache</comment>');


        return self::SUCCESS;
    }

    /**
     * Handle listing eligible Web packages.
     */
    protected function handleList(DefaultWebPackageManager $manager): int
    {
        $packages = $manager->listEligiblePackages();

        if ($packages === []) {
            $this->components->info('No packages with Web capability were discovered on disk.');
            $this->line('Generate a Web capability using: <comment>php artisan laraseed:make-web Vendor/Package</comment>');

            return self::SUCCESS;
        }

        $this->components->info('Discovered Web-Capable Packages');

        $rows = [];
        foreach ($packages as $pkg) {
            $rows[] = [
                $pkg['id'],
                $pkg['name'],
                $pkg['enabled'] ? '<fg=green>YES</>' : '<fg=red>NO</>',
                $pkg['entry_route'],
                $pkg['target_url'],
                $pkg['is_default'] ? '<fg=green;options=bold>ACTIVE DEFAULT</>' : '-',
                $pkg['status'],
            ];
        }

        $this->table(
            ['ID', 'Package Name', 'Enabled', 'Entry Route', 'Target URL', 'Default', 'Status'],
            $rows
        );

        $current = $manager->resolveConfiguredDefault();
        if ($current === null) {
            $this->line('Active default: <comment>None (serving fallback view)</comment>');
        } else {
            $this->line("Active default: <comment>{$current}</comment>");
        }

        return self::SUCCESS;
    }
}
