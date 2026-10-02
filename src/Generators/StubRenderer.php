<?php

namespace Laraseed\PackageGenerator\Generators;

use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;
use Laraseed\PackageGenerator\Support\PackageIdentity;

class StubRenderer
{
    protected string $stubsPath;

    public function __construct(?string $stubsPath = null)
    {
        $this->stubsPath = $stubsPath ?? __DIR__ . '/../../stubs';
    }

    /**
     * Render a stub file replacing placeholders with identity values.
     */
    public function render(string $stubName, PackageIdentity $identity): string
    {
        $file = str_starts_with($stubName, DIRECTORY_SEPARATOR) ? $stubName : "{$this->stubsPath}/{$stubName}";

        if (! file_exists($file)) {
            throw PackageGenerationException::invalidInput("Stub file [{$stubName}] not found at [{$file}].");
        }

        $content = (string) file_get_contents($file);

        $replacements = [
            '{{ VENDOR }}' => $identity->vendor,
            '{{ PACKAGE }}' => $identity->package,
            '{{ NAMESPACE }}' => $identity->namespace,
            '{{ ESCAPED_NAMESPACE }}' => $identity->escapedNamespace,
            '{{ VENDOR_LOWER }}' => $identity->vendorLower,
            '{{ PACKAGE_KEBAB }}' => $identity->packageKebab,
            '{{ PACKAGE_SNAKE }}' => $identity->packageSnake,
            '{{ PACKAGE_ID }}' => $identity->packageSnake,
            '{{ COMPOSER_NAME }}' => $identity->composerName,
            '{{ PROVIDER_CLASS }}' => $identity->providerClass,
            '{{ MODULE_CLASS }}' => $identity->moduleClass,
            '{{ PROVIDER_FQN }}' => $identity->providerFqn,
            '{{ ESCAPED_PROVIDER_FQN }}' => $identity->escapedProviderFqn,
            '{{ MODULE_FQN }}' => $identity->moduleFqn,
            '{{ ESCAPED_MODULE_FQN }}' => $identity->escapedModuleFqn,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }
}
