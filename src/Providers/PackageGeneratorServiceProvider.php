<?php

namespace Laraseed\PackageGenerator\Providers;

use Illuminate\Support\ServiceProvider;
use Laraseed\PackageGenerator\Console\Commands\AdminMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\CommandMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\ContractMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\ControllerMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\DataGridMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\EventMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\ListenerMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\MailMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\MiddlewareMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\MigrationMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\ModelMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\ModuleProviderMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\NotificationMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\PackageMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\ProviderMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\ProxyMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\RepositoryMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\RequestMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\RouteMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\SeederMakeCommand;
use Laraseed\PackageGenerator\Console\Commands\WebDefaultCommand;
use Laraseed\PackageGenerator\Console\Commands\WebMakeCommand;
use Laraseed\PackageGenerator\Generators\AdminGenerator;
use Laraseed\PackageGenerator\Generators\FilesystemWriter;
use Laraseed\PackageGenerator\Generators\PackageGenerator;
use Laraseed\PackageGenerator\Generators\StubRenderer;
use Laraseed\PackageGenerator\Generators\WebGenerator;
use Laraseed\PackageGenerator\Support\DefaultWebPackageManager;
use Laraseed\PackageGenerator\Support\PackageLock;
use Laraseed\PackageGenerator\Support\PackageResolver;

class PackageGeneratorServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(PackageLock::class, function ($app) {
            return new PackageLock(basePath: $app->basePath());
        });

        $this->app->singleton(DefaultWebPackageManager::class, function ($app) {
            return new DefaultWebPackageManager(basePath: $app->basePath());
        });

        $this->app->singleton(FilesystemWriter::class, function ($app) {
            return new FilesystemWriter(
                new \Illuminate\Filesystem\Filesystem,
                $app->make(PackageLock::class),
                $app->basePath()
            );
        });

        $this->app->singleton(PackageGenerator::class, function ($app) {
            return new PackageGenerator(
                new StubRenderer,
                $app->make(FilesystemWriter::class),
                $app->basePath()
            );
        });

        $this->app->singleton(AdminGenerator::class, function ($app) {
            return new AdminGenerator(
                $app->make(PackageResolver::class),
                new StubRenderer,
                $app->make(FilesystemWriter::class),
                $app->make(PackageLock::class),
                $app->basePath()
            );
        });

        $this->app->singleton(WebGenerator::class, function ($app) {
            return new WebGenerator(
                $app->make(PackageResolver::class),
                new StubRenderer,
                $app->make(FilesystemWriter::class),
                $app->make(PackageLock::class),
                $app->basePath()
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PackageMakeCommand::class,
                ModelMakeCommand::class,
                ContractMakeCommand::class,
                ProxyMakeCommand::class,
                MigrationMakeCommand::class,
                RepositoryMakeCommand::class,
                RequestMakeCommand::class,
                ControllerMakeCommand::class,
                RouteMakeCommand::class,
                ProviderMakeCommand::class,
                ModuleProviderMakeCommand::class,
                EventMakeCommand::class,
                ListenerMakeCommand::class,
                MiddlewareMakeCommand::class,
                MailMakeCommand::class,
                NotificationMakeCommand::class,
                CommandMakeCommand::class,
                SeederMakeCommand::class,
                DataGridMakeCommand::class,
                AdminMakeCommand::class,
                WebMakeCommand::class,
                WebDefaultCommand::class,
            ]);
        }
    }
}
