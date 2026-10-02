<?php

namespace {
    if (! function_exists('vite')) {
        /**
         * Standalone Vite helper implementation for rendering compiled package assets.
         */
        function vite(): object
        {
            return new class {
                public function set(array|string $entrypoints, ?string $package = null): string
                {
                    $entries = is_array($entrypoints) ? $entrypoints : [$entrypoints];
                    $output = [];

                    if ($package) {
                        $config = config("krayin-vite.viters.{$package}", []);
                        $buildDir = $config['build_directory'] ?? null;
                        if ($buildDir) {
                            $manifestPath = public_path("{$buildDir}/manifest.json");
                            if (file_exists($manifestPath)) {
                                $manifest = json_decode(file_get_contents($manifestPath), true) ?: [];
                                foreach ($entries as $entry) {
                                    if (isset($manifest[$entry]['file'])) {
                                        $file = $manifest[$entry]['file'];
                                        $url = asset("{$buildDir}/{$file}");
                                        if (str_ends_with($file, '.css')) {
                                            $output[] = '<link rel="stylesheet" href="' . e($url) . '" />';
                                        } elseif (str_ends_with($file, '.js')) {
                                            $output[] = '<script type="module" src="' . e($url) . '"></script>';
                                        }
                                    }
                                }
                            }
                        }
                    }

                    return implode("\n", $output);
                }

                public function asset(string $path, ?string $package = null): string
                {
                    return asset($path);
                }
            };
        }
    }
}

namespace Konekt\Concord {
    if (! class_exists(BaseModuleServiceProvider::class)) {
        if (class_exists(\Illuminate\Support\ServiceProvider::class)) {
            abstract class BaseModuleServiceProvider extends \Illuminate\Support\ServiceProvider
            {
            }
        } else {
            abstract class BaseModuleServiceProvider
            {
            }
        }
    }
}

namespace Konekt\Concord\Proxies {
    if (! class_exists(ModelProxy::class)) {
        abstract class ModelProxy
        {
            public static function modelClass(): string
            {
                $proxyClass = static::class;
                return substr($proxyClass, 0, -5);
            }
        }
    }
}

namespace Webkul\User\Models {
    if (! class_exists(User::class)) {
        if (class_exists(\Illuminate\Foundation\Auth\User::class)) {
            class User extends \Illuminate\Foundation\Auth\User
            {
                protected $guarded = [];
            }
        } else {
            class User
            {
                protected $guarded = [];
            }
        }
    }
}

namespace Webkul\Core\Contracts {
    if (! interface_exists(AuthenticationRedirectResolver::class)) {
        interface AuthenticationRedirectResolver
        {
            public function register(string $name, \Closure $matcher, \Closure $target, int $priority = 0): void;
            public function resolve(\Illuminate\Http\Request $request): ?string;
        }
    }
}

namespace Webkul\Core\Exceptions {
    if (! class_exists(InvalidPackageComposition::class)) {
        class InvalidPackageComposition extends \RuntimeException
        {
        }
    }
}

namespace Webkul\Core\Packages {
    use Webkul\Core\Exceptions\InvalidPackageComposition;

    if (! class_exists(OptionalPackageManifestLoader::class)) {
        class OptionalPackageManifestLoader
        {
            public function load(array $manifestPaths): array
            {
                $packages = [];

                foreach ($manifestPaths as $path) {
                    if (! file_exists($path)) {
                        continue;
                    }
                    $manifest = json_decode(file_get_contents($path), true) ?: [];
                    $metadata = $manifest['extra']['laraseed'] ?? null;

                    if (! is_array($metadata)) {
                        continue;
                    }

                    $id = $metadata['id'] ?? null;
                    $composerName = $manifest['name'] ?? null;
                    $provider = $metadata['provider'] ?? null;
                    $module = $metadata['concord_module'] ?? null;

                    if (! is_string($id) || ! is_string($composerName) || ! is_string($provider)) {
                        continue;
                    }

                    $capabilities = [];
                    foreach (($metadata['capabilities'] ?? []) as $capName => $capMeta) {
                        if (is_array($capMeta)) {
                            $capabilities[$capName] = [
                                'provider' => $capMeta['provider'] ?? '',
                                'enabled'  => (bool) ($capMeta['enabled'] ?? true),
                            ];
                        }
                    }

                    $packages[$id] = [
                        'id'             => $id,
                        'composer_name'  => $composerName,
                        'provider'       => $provider,
                        'concord_module' => $module,
                        'capabilities'   => $capabilities,
                        'requires'       => (array) ($metadata['requires'] ?? []),
                    ];
                }

                return $packages;
            }
        }
    }

    if (! class_exists(OptionalPackageComposition::class)) {
        class OptionalPackageComposition
        {
            protected array $enabled;

            public function __construct(
                protected array $packages,
                array $enabled,
            ) {
                $this->normalizeCapabilities();
                $this->assertKnownPackages($enabled);
                $this->assertAcyclic();
                $this->assertEnabledDependencies($enabled);

                $this->enabled = $this->sortByDependencies(array_values(array_unique($enabled)));
            }

            protected function normalizeCapabilities(): void
            {
                foreach ($this->packages as $id => $data) {
                    if (! isset($this->packages[$id]['capabilities']) || ! is_array($this->packages[$id]['capabilities'])) {
                        $this->packages[$id]['capabilities'] = [];
                    }
                }
            }

            public static function parseEnabledPackageIds(string $value): array
            {
                if (trim($value) === '') {
                    return [];
                }

                return array_values(array_unique(array_filter(
                    array_map('trim', explode(',', $value)),
                    fn (string $id): bool => $id !== '',
                )));
            }

            public function isEnabled(string $packageId): bool
            {
                $this->assertKnownPackages([$packageId]);

                return in_array($packageId, $this->enabled, true);
            }

            public function enabledPackages(): array
            {
                return $this->enabled;
            }

            public function providers(): array
            {
                return array_map(
                    fn (string $id): string => $this->packages[$id]['provider'],
                    $this->enabled,
                );
            }

            public function concordModules(): array
            {
                return array_values(array_filter(array_map(
                    fn (string $id): ?string => $this->packages[$id]['concord_module'],
                    $this->enabled,
                )));
            }

            public function hasCapability(string $packageId, string $capability): bool
            {
                $this->assertKnownPackages([$packageId]);

                return isset($this->packages[$packageId]['capabilities'][$capability]);
            }

            public function capability(string $packageId, string $capability): ?array
            {
                $this->assertKnownPackages([$packageId]);

                return $this->packages[$packageId]['capabilities'][$capability] ?? null;
            }

            public function capabilities(string $packageId): array
            {
                $this->assertKnownPackages([$packageId]);

                return $this->packages[$packageId]['capabilities'] ?? [];
            }

            public function capabilityProviders(string $capability): array
            {
                $providers = [];

                foreach ($this->enabled as $packageId) {
                    $cap = $this->packages[$packageId]['capabilities'][$capability] ?? null;

                    if ($cap !== null && ($cap['enabled'] ?? true) === true) {
                        $providers[] = $cap['provider'];
                    }
                }

                return array_values(array_unique($providers));
            }

            public function dependencyGraph(): array
            {
                return array_map(
                    fn (array $package): array => $package['requires'],
                    $this->packages,
                );
            }

            public function enabledDependents(string $packageId): array
            {
                $this->assertKnownPackages([$packageId]);

                return array_values(array_filter(
                    $this->enabled,
                    fn (string $candidate): bool => in_array($packageId, $this->packages[$candidate]['requires'], true),
                ));
            }

            public function canDisable(string $packageId): bool
            {
                return $this->enabledDependents($packageId) === [];
            }

            public function packages(): array
            {
                return $this->packages;
            }

            protected function assertKnownPackages(array $packageIds): void
            {
                foreach ($packageIds as $id) {
                    if (! isset($this->packages[$id])) {
                        throw new InvalidPackageComposition("Unknown Optional package ID [{$id}].");
                    }
                }
            }

            protected function assertEnabledDependencies(array $enabled): void
            {
                foreach ($enabled as $id) {
                    foreach ($this->packages[$id]['requires'] as $dependency) {
                        if (! in_array($dependency, $enabled, true)) {
                            throw new InvalidPackageComposition("Optional package \"{$id}\" requires enabled package \"{$dependency}\".");
                        }
                    }
                }
            }

            protected function assertAcyclic(): void
            {
                $visiting = [];
                $visited = [];

                $visit = function (string $id) use (&$visit, &$visiting, &$visited): void {
                    if (isset($visiting[$id])) {
                        throw new InvalidPackageComposition("Optional package dependency cycle detected at [{$id}].");
                    }

                    if (isset($visited[$id])) {
                        return;
                    }

                    $visiting[$id] = true;

                    foreach ($this->packages[$id]['requires'] as $dependency) {
                        if (! isset($this->packages[$dependency])) {
                            throw new InvalidPackageComposition("Optional package [{$id}] requires unknown Optional package [{$dependency}].");
                        }

                        $visit($dependency);
                    }

                    unset($visiting[$id]);
                    $visited[$id] = true;
                };

                foreach (array_keys($this->packages) as $id) {
                    $visit($id);
                }
            }

            protected function sortByDependencies(array $enabled): array
            {
                $result = [];
                $visited = [];

                $visit = function (string $id) use (&$visit, &$result, &$visited, $enabled): void {
                    if (isset($visited[$id])) {
                        return;
                    }

                    foreach ($this->packages[$id]['requires'] as $dependency) {
                        if (in_array($dependency, $enabled, true)) {
                            $visit($dependency);
                        }
                    }

                    $visited[$id] = true;
                    $result[] = $id;
                };

                sort($enabled);

                foreach ($enabled as $id) {
                    $visit($id);
                }

                return $result;
            }
        }
    }
}

namespace Webkul\Core\Console\Commands {
    if (! class_exists(PackageDiagnosticsCommand::class)) {
        if (class_exists(\Illuminate\Console\Command::class)) {
            class PackageDiagnosticsCommand extends \Illuminate\Console\Command
            {
                protected $signature = 'laraseed:packages';
                protected $description = 'Display the installed and enabled state of Laraseed optional packages (read-only)';

                public function handle(\Webkul\Core\Packages\OptionalPackageComposition $composition): int
                {
                    $this->info('Laraseed Optional Packages:');
                    foreach ($composition->packages() as $id => $pkg) {
                        $this->line("- {$id}");
                    }
                    return 0;
                }
            }
        } else {
            class PackageDiagnosticsCommand
            {
            }
        }
    }
}

namespace App\Http\Controllers {
    if (! class_exists(WebEntryPointController::class, false)) {
        if (class_exists(\Illuminate\Routing\Controller::class)) {
            class WebEntryPointController extends \Illuminate\Routing\Controller
            {
                public function index(\Illuminate\Http\Request $request): \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
                {
                    $defaultPackage = config('laraseed.default_web_package') ?? config('laraseed.web.default_package');
                    if (! $defaultPackage) {
                        return view('web.fallback');
                    }

                    $enabledPackages = (array) config('laraseed.optional_packages.enabled', []);
                    if (! in_array($defaultPackage, $enabledPackages, true)) {
                        return view('web.fallback');
                    }

                    $packageKey = str_replace('-', '_', strtolower($defaultPackage));
                    $candidates = [
                        "{$packageKey}.web.home",
                        "{$defaultPackage}.web.home",
                    ];

                    foreach ($candidates as $candidate) {
                        if (\Illuminate\Support\Facades\Route::has($candidate)) {
                            return redirect()->route($candidate);
                        }
                    }

                    return view('web.fallback');
                }
            }
        } else {
            class WebEntryPointController
            {
            }
        }
    }
}
