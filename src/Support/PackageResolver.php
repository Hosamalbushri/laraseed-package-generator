<?php

namespace Laraseed\PackageGenerator\Support;

use Illuminate\Filesystem\Filesystem;
use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;

class ResolvedPackage
{
    public function __construct(
        public readonly PackageIdentity $identity,
        public readonly string $packagePath,
        public readonly array $manifest,
        public readonly string $namespace,
    ) {}
}

class PackageResolver
{
    protected string $basePath;

    public function __construct(
        protected Filesystem $filesystem = new Filesystem,
        ?string $basePath = null,
    ) {
        $this->basePath = $basePath ?? base_path();
    }

    /**
     * Resolve and validate an existing optional package by input string.
     *
     * @throws PackageGenerationException
     */
    public function resolve(string $input): ResolvedPackage
    {
        $identity = PackageIdentity::fromInput($input);
        $relativePackagePath = $identity->relativePackagePath;
        $packagePath = rtrim($this->basePath, '/') . '/' . $relativePackagePath;

        PathGuard::assertWithinAuthorizedPackages($packagePath, $this->basePath, 'Package');

        if (! $this->filesystem->isDirectory($packagePath)) {
            throw PackageGenerationException::invalidInput("Package [{$input}] not found at [{$relativePackagePath}]. Generate package first using laraseed:make-package.");
        }

        $manifestPath = "{$packagePath}/composer.json";
        PathGuard::assertWithinAuthorizedPackages($manifestPath, $this->basePath, 'Package manifest');

        if (! $this->filesystem->exists($manifestPath)) {
            throw PackageGenerationException::invalidInput("Package manifest composer.json missing at [{$relativePackagePath}/composer.json].");
        }

        try {
            $content = $this->filesystem->get($manifestPath);
            $manifest = json_decode((string) $content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw PackageGenerationException::invalidInput("Package manifest [{$relativePackagePath}/composer.json] contains malformed JSON.");
        }

        if (! is_array($manifest)) {
            throw PackageGenerationException::invalidInput("Package manifest [{$relativePackagePath}/composer.json] must contain a JSON object.");
        }

        $metadata = $manifest['extra']['laraseed'] ?? null;

        if (! is_array($metadata) || ! isset($metadata['id']) || ($metadata['type'] ?? null) !== 'optional') {
            throw PackageGenerationException::invalidInput("Package manifest at [{$relativePackagePath}/composer.json] is missing valid extra.laraseed optional composition metadata.");
        }

        $psr4 = $manifest['autoload']['psr-4'] ?? [];
        $namespace = null;

        if (is_array($psr4)) {
            foreach ($psr4 as $ns => $path) {
                $trimmedPath = trim(is_string($path) ? $path : '', '/');
                if ($trimmedPath === 'src') {
                    $namespace = rtrim($ns, '\\');
                    break;
                }
            }
        }

        if ($namespace === null || $namespace === '') {
            $namespace = $identity->namespace;
        }

        return new ResolvedPackage(
            identity: $identity,
            packagePath: $packagePath,
            manifest: $manifest,
            namespace: $namespace,
        );
    }
}
