<?php

namespace Laraseed\PackageGenerator\Support;

use Laraseed\PackageGenerator\Exceptions\PackageGenerationException;

class PackageNameValidator
{
    /**
     * Reserved vendor namespaces that cannot be used for business packages.
     *
     * @var list<string>
     */
    protected array $reservedVendors = [
        'webkul',
    ];

    /**
     * Foundation and system reserved package names across all vendors.
     *
     * @var list<string>
     */
    protected array $reservedPackages = [
        'core',
        'admin',
        'user',
        'datagrid',
        'installer',
        'debugbar',
        'laraseed',
        'packagegenerator',
        'package_generator',
    ];

    /**
     * Specific fully qualified vendor/package pairs that are reserved.
     *
     * @var list<string>
     */
    protected array $reservedQualifiedPackages = [
        'laraseed/packagegenerator',
        'laraseed/package_generator',
        'laraseed/core',
        'laraseed/admin',
        'laraseed/user',
        'laraseed/datagrid',
        'laraseed/installer',
        'laraseed/debugbar',
        'laraseed/laraseed',
    ];

    /**
     * Validate the given vendor/package input string.
     *
     * @throws PackageGenerationException
     */
    public function validate(string $input): void
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            throw PackageGenerationException::invalidInput('Package name cannot be empty.');
        }

        if (str_contains($trimmed, "\0")) {
            throw PackageGenerationException::invalidInput('Package name contains null bytes.');
        }

        if (str_contains($trimmed, '..') || str_contains($trimmed, '\\')) {
            throw PackageGenerationException::invalidInput('Path traversal sequences ("..", "\\") are strictly forbidden.');
        }

        if (str_starts_with($trimmed, '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $trimmed) === 1) {
            throw PackageGenerationException::invalidInput('Absolute paths are strictly forbidden.');
        }

        $parts = explode('/', $trimmed);

        if (count($parts) !== 2) {
            throw PackageGenerationException::invalidInput('Package name must be in Vendor/PackageName format (e.g., Acme/Blog).');
        }

        [$vendor, $package] = $parts;

        if (trim($vendor) === '' || trim($package) === '') {
            throw PackageGenerationException::invalidInput('Both Vendor and PackageName parts must be non-empty.');
        }

        $normVendor = strtolower(trim($vendor));
        $normPackage = strtolower(trim($package));
        $strippedPackage = str_replace(['-', '_'], '', $normPackage);
        $normQualified = "{$normVendor}/{$normPackage}";
        $strippedQualified = "{$normVendor}/{$strippedPackage}";

        if (in_array($normVendor, $this->reservedVendors, true)) {
            throw PackageGenerationException::reservedName($vendor);
        }

        if (in_array($normPackage, $this->reservedPackages, true) || in_array($strippedPackage, $this->reservedPackages, true)) {
            throw PackageGenerationException::reservedName($package);
        }

        if (in_array($normQualified, $this->reservedQualifiedPackages, true) || in_array($strippedQualified, $this->reservedQualifiedPackages, true)) {
            throw PackageGenerationException::reservedName("{$vendor}/{$package}");
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $vendor) !== 1) {
            throw PackageGenerationException::invalidInput("Vendor name [{$vendor}] must be a valid PHP namespace identifier.");
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $package) !== 1) {
            throw PackageGenerationException::invalidInput("Package name [{$package}] must be a valid PHP namespace identifier.");
        }
    }
}
