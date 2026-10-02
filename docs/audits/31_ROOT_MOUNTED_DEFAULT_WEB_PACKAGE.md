# Laraseed V4 — Audit Report #31: Default Web Package Root Mounting Verification

**Phase:** Phase 08 — Step 05  
**Auditor:** Principal Laravel Architect, Modular Routing Engineer, Package Architect and Integration Test Engineer  
**Date:** October 2, 2026  
**Status:** PASSED  
**Baseline Test Count:** 559 passing tests (4,011 assertions, zero failures)

---

## 1. Executive Summary

In Phase 08 Step 05, the Default Web Package management system was evolved from HTTP 302 redirection to **native root-mounted routing**. 

When a package is configured as the application's default web package (via `LARASEED_DEFAULT_WEB_PACKAGE` or `config('laraseed.default_web_package')`), its public web capability is dynamically mounted directly under the root domain (`/`), serving its homepage and internal pages (`/pages/about`, `/pages/contact`, `/branding.css`, `/account/dashboard`) without exposing package prefixes or performing HTTP redirects.

Non-default packages seamlessly retain their isolated URL prefixes (e.g. `/store-pkg`), preserving multi-tenant and modular package coexistence. When no default web package is configured or active, the core application continues to serve the fallback landing view (`web.fallback`) at `/` with HTTP 200 OK.

---

## 2. Architecture & Design Verification

### 2.1 Dynamic Root Prefix Resolution
In `src/Web/Providers/WebServiceProvider.php`:
```php
$defaultPkg = (string) (config('laraseed.default_web_package') ?? config('laraseed.web.default_package') ?? '');
$isDefault = $defaultPkg !== '' && (
    strtolower(str_replace('-', '_', $defaultPkg)) === '{{ PACKAGE_KEY }}'
    || strtolower(str_replace('-', '_', $defaultPkg)) === '{{ PACKAGE_ID }}'
);

$rawPrefix = config('{{ PACKAGE_KEY }}_web.prefix');

if ($isDefault && ($rawPrefix === null || $rawPrefix === '' || $rawPrefix === '{{ PACKAGE_SLUG }}')) {
    $prefix = '';
} else {
    $prefix = (string) ($rawPrefix ?? '{{ PACKAGE_SLUG }}');
}
```

### 2.2 Root Route Ownership Coordination
When a package mounts at `''`:
1. It claims root ownership: `config(['laraseed.web.root_owner' => '{{ PACKAGE_KEY }}'])`.
2. If another package attempts to claim root, a `RuntimeException` is thrown with an actionable diagnostic.
3. In `routes/web.php`, `App\Http\Controllers\WebEntryPointController` is registered only when `config('laraseed.web.root_owner')` is `null`.

### 2.3 Collision & System Route Protection
Packages attempting to use reserved core system prefixes (`admin`, `install`, `api`, `up`, `sanctum`, or configured `app.admin_path`) are intercepted and rejected at boot time with a `RuntimeException`.

### 2.4 Production Cache Invariants
Because route prefixes are resolved during route registration, modifying `LARASEED_DEFAULT_WEB_PACKAGE` in production requires compiling both configuration and route caches:
```bash
php artisan config:cache && php artisan route:cache
```

---

## 3. Test Suite Execution & Evidence Matrix

### 3.1 Suite Inventory & Results
| Suite / Command | Tests | Assertions | Status |
| :--- | :--- | :--- | :--- |
| `packages/Laraseed/PackageGenerator/phpunit.xml` | 252 | 1,448 | **PASSED** |
| `RootMountedDefaultWebPackageTest` (Focused) | 12 | 70 | **PASSED** |
| Complete Application Suite (`php artisan test`) | 559 | 4,011 | **PASSED** |

### 3.2 Verified Scenarios (12 Matrix Items)
1. **Empty App Root:** Verified `/` renders `web.fallback` with HTTP 200 when zero web packages are loaded.
2. **Direct Homepage Response:** Verified `/` renders default package `HomeController::index` with HTTP 200 and zero redirects.
3. **Root-Mounted Internal Pages:** Verified `/pages/about` and `/branding.css` render directly without package slugs.
4. **Root Named Routes:** Verified `route('...web.home')` -> `http://localhost/` and `route('...web.pages.show', ['page' => 'contact'])` -> `http://localhost/pages/contact`.
5. **Non-Default Isolation:** Verified non-default packages retain isolated prefixes (`/acme-mount-store-front`).
6. **Dynamic Switching:** Verified switching default packages transfers root mounting to the new default and assigns the isolated slug prefix to the previous default.
7. **Clear Default Command:** Verified `laraseed:web-default --clear` restores fallback rendering at `/`.
8. **Disabled Package Protection:** Verified disabled packages cannot claim root mounting or serve `/`.
9. **Protected Core Routes:** Verified `/admin`, `/admin/login`, `/up`, and admin redirection resolver rules remain unaffected.
10. **Multiple Root Claim Detection:** Verified `RuntimeException` is raised when two packages attempt to mount at root.
11. **Reserved System Prefix Collision:** Verified `RuntimeException` is raised when a package specifies `prefix => 'admin'`.
12. **Subprocess Cache Transitions:** Verified `optimize:clear`, `config:cache`, and `route:cache` execute cleanly in sub-processes with zero serialization errors.

---

## 4. Boundary & Integrity Statement

- **Webkul Foundation (`packages/Webkul/*`):** 0 files modified.
- **Laraseed Contacts (`packages/Laraseed/Contacts/*`):** 0 files modified.
- **Self-Containment:** All generator stubs, commands, services, and tests are self-contained in `packages/Laraseed/PackageGenerator`.
