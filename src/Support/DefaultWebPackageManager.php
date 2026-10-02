<?php

namespace Laraseed\PackageGenerator\Support;

use Illuminate\Support\Facades\Route;
use JsonException;

class DefaultWebPackageManager
{
    public function __construct(
        protected ?string $basePath = null
    ) {
        $this->basePath = $this->basePath ?? base_path();
    }

    /**
     * Resolve the currently configured default package identifier using strict precedence.
     */
    public function resolveConfiguredDefault(): ?string
    {
        $primary = config('laraseed.default_web_package');
        if (is_string($primary) && trim($primary) !== '') {
            return trim($primary);
        }

        $legacy = config('laraseed.web.default_package');
        if (is_string($legacy) && trim($legacy) !== '') {
            return trim($legacy);
        }

        return null;
    }

    /**
     * Normalize a package identifier for loose comparisons (kebab vs snake).
     */
    public function normalizeId(string $packageId): string
    {
        return strtolower(str_replace('-', '_', trim($packageId)));
    }

    /**
     * Check if a package identifier is currently enabled.
     */
    public function isPackageEnabled(string $packageId): bool
    {
        $enabledPackages = (array) config('laraseed.optional_packages.enabled', []);

        if (in_array($packageId, $enabledPackages, true)) {
            return true;
        }

        $normalizedTarget = $this->normalizeId($packageId);
        $normalizedEnabled = array_map([$this, 'normalizeId'], $enabledPackages);

        return in_array($normalizedTarget, $normalizedEnabled, true);
    }

    /**
     * Discover all packages residing on disk that declare or contain a Web capability.
     */
    public function getDiscoveredWebPackages(): array
    {
        $packages = [];
        $packagesDir = $this->basePath . DIRECTORY_SEPARATOR . 'packages';

        if (! is_dir($packagesDir)) {
            return [];
        }

        $manifests = glob($packagesDir . '/*/*/composer.json') ?: [];

        foreach ($manifests as $manifestPath) {
            $raw = @file_get_contents($manifestPath);
            if ($raw === false) {
                continue;
            }

            try {
                $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (! is_array($data) || ($data['extra']['laraseed']['type'] ?? null) !== 'optional') {
                continue;
            }

            $laraseedMeta = $data['extra']['laraseed'] ?? [];
            $id = $laraseedMeta['id'] ?? basename(dirname($manifestPath));
            $capabilities = $laraseedMeta['capabilities'] ?? [];

            $hasWebCapability = isset($capabilities['web']) && ($capabilities['web']['enabled'] ?? true);

            // Also check for physical Web directory presence
            $webDir = dirname($manifestPath) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Web';
            if (! $hasWebCapability && is_dir($webDir)) {
                $hasWebCapability = true;
            }

            if ($hasWebCapability) {
                $vendor = basename(dirname($manifestPath, 2));
                $packageName = basename(dirname($manifestPath));
                $packageKey = strtolower($vendor) . '_' . \Illuminate\Support\Str::snake($packageName);

                $packages[$id] = [
                    'id'           => $id,
                    'name'         => $data['name'] ?? $id,
                    'vendor'       => $vendor,
                    'package'      => $packageName,
                    'package_key'  => $packageKey,
                    'path'         => dirname($manifestPath),
                    'manifest'     => $manifestPath,
                    'provider'     => $laraseedMeta['provider'] ?? null,
                    'web_provider' => $capabilities['web']['provider'] ?? null,
                    'enabled'      => $this->isPackageEnabled($id),
                ];
            }
        }

        return $packages;
    }

    /**
     * Resolve the canonical named public entry route for a given package identifier.
     */
    public function resolveEntryRoute(string $packageId): ?string
    {
        $packageKey = $this->normalizeId($packageId);

        // Check if there is a discovered package with vendor-scoped key
        $discovered = $this->getDiscoveredWebPackages();
        $fullKeys = [];
        foreach ($discovered as $id => $meta) {
            if ($this->normalizeId($id) === $packageKey || $this->normalizeId($meta['package_key'] ?? '') === $packageKey) {
                if (! empty($meta['package_key'])) {
                    $fullKeys[] = $meta['package_key'];
                }
            }
        }

        $candidates = [
            // 1. Explicit application config overrides
            config("laraseed.web.entry_routes.{$packageId}"),
            config("laraseed.web.entry_routes.{$packageKey}"),
        ];

        foreach ($fullKeys as $fullKey) {
            $candidates[] = config("laraseed.web.entry_routes.{$fullKey}");
            $candidates[] = config("{$fullKey}_web.entry_route");
            $candidates[] = config("{$fullKey}_web.navigation.home.route");
            $candidates[] = config("{$fullKey}_web.routes.home");
        }

        $candidates = array_merge($candidates, [
            // 2. Package configuration overrides
            config("{$packageKey}_web.entry_route"),
            config("{$packageId}_web.entry_route"),
            config("{$packageKey}_web.navigation.home.route"),
            config("{$packageId}_web.navigation.home.route"),
            config("{$packageKey}_web.routes.home"),
            config("{$packageId}_web.routes.home"),
            // 3. Capability manifest catalog metadata
            config("laraseed.optional_packages.catalog.{$packageId}.capabilities.web.entry_route"),
            config("laraseed.optional_packages.catalog.{$packageKey}.capabilities.web.entry_route"),
        ]);

        foreach ($fullKeys as $fullKey) {
            $candidates[] = "{$fullKey}.web.home";
        }

        $candidates[] = "{$packageKey}.web.home";
        $candidates[] = "{$packageId}.web.home";

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && Route::has($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Validate the eligibility of a package identifier to serve as the default web package.
     */
    public function validatePackage(string $packageId, ?string $requestHost = null): array
    {
        $currentDefault = $this->resolveConfiguredDefault();
        $isCurrentDefault = ($currentDefault !== null && $this->normalizeId($currentDefault) === $this->normalizeId($packageId));

        if (preg_match('/^[a-zA-Z0-9_\-]+$/', $packageId) !== 1) {
            return [
                'valid'             => false,
                'status'            => 'INVALID_IDENTIFIER',
                'is_current_default'=> false,
                'errors'            => ["Package identifier [{$packageId}] contains invalid characters."],
                'entry_route'       => null,
                'target_url'        => null,
                'is_enabled'        => false,
                'has_web'           => false,
            ];
        }

        $discovered = $this->getDiscoveredWebPackages();
        $matchedPkg = null;
        foreach ($discovered as $id => $data) {
            if ($this->normalizeId($id) === $this->normalizeId($packageId)) {
                $matchedPkg = $data;
                break;
            }
        }

        if ($matchedPkg === null) {
            return [
                'valid'             => false,
                'status'            => 'NOT_FOUND',
                'is_current_default'=> $isCurrentDefault,
                'errors'            => ["Package [{$packageId}] was not found among discovered packages with Web capability."],
                'entry_route'       => null,
                'target_url'        => null,
                'is_enabled'        => false,
                'has_web'           => false,
            ];
        }

        $isEnabled = $this->isPackageEnabled($packageId);
        if (! $isEnabled) {
            return [
                'valid'             => false,
                'status'            => 'DISABLED',
                'is_current_default'=> $isCurrentDefault,
                'errors'            => ["Package [{$packageId}] is not active in LARASEED_OPTIONAL_PACKAGES."],
                'entry_route'       => null,
                'target_url'        => null,
                'is_enabled'        => false,
                'has_web'           => true,
            ];
        }

        $entryRoute = $this->resolveEntryRoute($packageId);
        if ($entryRoute === null || ! Route::has($entryRoute)) {
            return [
                'valid'             => false,
                'status'            => 'ROUTE_MISSING',
                'is_current_default'=> $isCurrentDefault,
                'errors'            => ["Package [{$packageId}] does not have a registered public entry route."],
                'entry_route'       => $entryRoute,
                'target_url'        => null,
                'is_enabled'        => true,
                'has_web'           => true,
            ];
        }

        try {
            $targetUrl = route($entryRoute);
        } catch (\Throwable $e) {
            return [
                'valid'             => false,
                'status'            => 'ROUTE_ERROR',
                'is_current_default'=> $isCurrentDefault,
                'errors'            => ["Error evaluating route [{$entryRoute}]: " . $e->getMessage()],
                'entry_route'       => $entryRoute,
                'target_url'        => null,
                'is_enabled'        => true,
                'has_web'           => true,
            ];
        }

        // External Host Check
        $targetHost = parse_url($targetUrl, PHP_URL_HOST);
        if ($requestHost !== null && $targetHost !== null && strcasecmp($targetHost, $requestHost) !== 0) {
            return [
                'valid'             => false,
                'status'            => 'EXTERNAL_HOST',
                'is_current_default'=> $isCurrentDefault,
                'errors'            => ["Route [{$entryRoute}] targets disallowed external host [{$targetHost}]."],
                'entry_route'       => $entryRoute,
                'target_url'        => $targetUrl,
                'is_enabled'        => true,
                'has_web'           => true,
            ];
        }

        // Redirect Loop Check
        $targetPath = trim((string) (parse_url($targetUrl, PHP_URL_PATH) ?? ''), '/');
        if ($entryRoute === 'laraseed.web.entry' || (! $isCurrentDefault && $targetPath === '')) {
            return [
                'valid'             => false,
                'status'            => 'REDIRECT_LOOP',
                'is_current_default'=> $isCurrentDefault,
                'errors'            => ["Route [{$entryRoute}] resolves directly to root path [/], creating a redirect loop."],
                'entry_route'       => $entryRoute,
                'target_url'        => $targetUrl,
                'is_enabled'        => true,
                'has_web'           => true,
            ];
        }


        return [
            'valid'             => true,
            'status'            => $isCurrentDefault ? 'ACTIVE_DEFAULT' : 'ELIGIBLE',
            'is_current_default'=> $isCurrentDefault,
            'errors'            => [],
            'entry_route'       => $entryRoute,
            'target_url'        => $targetUrl,
            'is_enabled'        => true,
            'has_web'           => true,
        ];
    }

    /**
     * Inspect current default web package selection and diagnostics.
     */
    public function getStatus(?string $requestHost = null): array
    {
        $current = $this->resolveConfiguredDefault();

        if ($current === null) {
            return [
                'has_selection'    => false,
                'configured_value' => null,
                'is_valid'         => true,
                'status'           => 'NONE_SELECTED',
                'message'          => 'No default Web package is currently selected. Root URL serves the fallback landing view.',
                'validation'       => null,
            ];
        }

        $validation = $this->validatePackage($current, $requestHost);

        return [
            'has_selection'    => true,
            'configured_value' => $current,
            'is_valid'         => $validation['valid'],
            'status'           => $validation['status'],
            'message'          => $validation['valid']
                ? "Active default web package is [{$current}] (Route: {$validation['entry_route']}, URL: {$validation['target_url']})."
                : "Configured default [{$current}] has issues: " . implode(' ', $validation['errors']),
            'validation'       => $validation,
        ];
    }

    /**
     * List all discovered Web-capable packages and their current eligibility.
     */
    public function listEligiblePackages(?string $requestHost = null): array
    {
        $discovered = $this->getDiscoveredWebPackages();
        $results = [];

        foreach ($discovered as $id => $data) {
            $validation = $this->validatePackage($id, $requestHost);
            $results[] = [
                'id'          => $id,
                'name'        => $data['name'],
                'enabled'     => $data['enabled'],
                'entry_route' => $validation['entry_route'] ?? '-',
                'target_url'  => $validation['target_url'] ?? '-',
                'is_default'  => $validation['is_current_default'],
                'status'      => $validation['status'],
                'valid'       => $validation['valid'],
                'errors'      => $validation['errors'],
            ];
        }

        return $results;
    }

    /**
     * Atomically persist a configuration key in the application's .env file.
     */
    public function persistEnv(string $key, ?string $value, ?string $envPath = null): bool
    {
        $targetFile = $envPath ?? ($this->basePath . DIRECTORY_SEPARATOR . '.env');

        if (! file_exists($targetFile) || ! is_readable($targetFile) || ! is_writable($targetFile)) {
            return false;
        }

        $lockPath = storage_path('framework/locks/laraseed_env.lock');
        if (! is_dir(dirname($lockPath))) {
            @mkdir(dirname($lockPath), 0755, true);
        }

        $lockHandle = fopen($lockPath, 'c+');
        if ($lockHandle === false) {
            return false;
        }

        $tmpFile = null;

        try {
            if (! flock($lockHandle, LOCK_EX)) {
                return false;
            }

            $content = file_get_contents($targetFile);
            if ($content === false) {
                return false;
            }

            $perms = fileperms($targetFile) & 0777;
            $escapedKey = preg_quote($key, '/');
            $formattedValue = ($value === null || $value === '') ? '' : '"' . addcslashes($value, '"\\') . '"';
            $newLine = "{$key}={$formattedValue}";

            // Handle single or multiple duplicate keys deterministically
            if (preg_match("/^{$escapedKey}=.*/m", $content)) {
                $matched = false;
                $lines = explode("\n", $content);
                $newLines = [];
                foreach ($lines as $line) {
                    if (preg_match("/^{$escapedKey}=.*/", $line)) {
                        if (! $matched) {
                            $newLines[] = $newLine;
                            $matched = true;
                        }
                        // Omit duplicate lines
                    } else {
                        $newLines[] = $line;
                    }
                }
                $newContent = implode("\n", $newLines);
            } else {
                $newContent = rtrim($content) . "\n" . $newLine . "\n";
            }

            if (! str_ends_with($newContent, "\n")) {
                $newContent .= "\n";
            }

            // Atomic temp file write and rename
            $tmpFile = $targetFile . '.tmp.' . getmypid() . '_' . hrtime(true);
            if (file_put_contents($tmpFile, $newContent) === false) {
                return false;
            }

            @chmod($tmpFile, $perms);

            if (! rename($tmpFile, $targetFile)) {
                return false;
            }

            $tmpFile = null; // Successfully renamed

            return true;
        } finally {
            if ($tmpFile !== null && file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}
