<?php

namespace Laraseed\PackageGenerator\Support;

use Illuminate\Support\Str;

class PackageIdentity
{
    public readonly string $vendor;

    public readonly string $package;

    public readonly string $namespace;

    public readonly string $escapedNamespace;

    public readonly string $vendorLower;

    public readonly string $vendorKebab;

    public readonly string $packageKebab;

    public readonly string $packageSnake;

    public readonly string $composerName;

    public readonly string $providerClass;

    public readonly string $moduleClass;

    public readonly string $providerFqn;

    public readonly string $escapedProviderFqn;

    public readonly string $moduleFqn;

    public readonly string $escapedModuleFqn;

    public readonly string $relativePackagePath;

    public function __construct(string $input)
    {
        [$vendor, $package] = explode('/', trim($input));

        $this->vendor = $vendor;
        $this->package = $package;
        $this->namespace = "{$this->vendor}\\{$this->package}";
        $this->escapedNamespace = "{$this->vendor}\\\\{$this->package}";

        $this->vendorLower = strtolower($this->vendor);
        $this->vendorKebab = Str::kebab($this->vendor);
        $this->packageKebab = Str::kebab($this->package);
        $this->packageSnake = Str::snake($this->package);

        $this->composerName = "{$this->vendorKebab}/{$this->packageKebab}";

        $this->providerClass = "{$this->package}ServiceProvider";
        $this->moduleClass = 'ModuleServiceProvider';

        $this->providerFqn = "{$this->namespace}\\Providers\\{$this->providerClass}";
        $this->escapedProviderFqn = "{$this->escapedNamespace}\\\\Providers\\\\{$this->providerClass}";

        $this->moduleFqn = "{$this->namespace}\\Providers\\{$this->moduleClass}";
        $this->escapedModuleFqn = "{$this->escapedNamespace}\\\\Providers\\\\{$this->moduleClass}";

        $this->relativePackagePath = "packages/{$this->vendor}/{$this->package}";
    }

    public static function fromInput(string $input): self
    {
        (new PackageNameValidator)->validate($input);

        return new self($input);
    }
}
