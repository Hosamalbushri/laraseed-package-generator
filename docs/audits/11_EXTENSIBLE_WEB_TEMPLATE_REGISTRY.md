# LARASEED PACKAGE GENERATOR V4
## 11 — Extensible Web Template Registry Report

**Document ID:** `11_EXTENSIBLE_WEB_TEMPLATE_REGISTRY.md`  
**Audit Stage:** Phase 04 — Extensible Web Template Registry  
**Author:** Principal Laravel Architect, Extensible Template System Designer, & Security Auditor  
**Date:** 2026-10-02  
**Status:** PHASE 04 COMPLETE & VERIFIED  

---

## 1. Executive Summary

In Phase 04 of the Laraseed Package Generator V4 roadmap, we implemented the **Extensible Web Template Registry** (`WebTemplateCatalog`).

This architectural capability enables developers and third-party packages to dynamically register, configure, and generate custom Web Starter templates (`laraseed:make-web {package} --template={id}`) without modifying the core generator source code.

The design strictly maintains:
1. **100% Backward Compatibility:** Default `starter` Web template behavior, file layout, and strict CSP compatibility remain identical.
2. **Security & Path Containment:** All registered templates are validated before execution and enforced within package boundaries using `PathGuard` and atomic `FilesystemTransaction` rollbacks.
3. **Runtime & Test Isolation:** The catalog provides explicit `register()`, `unregister()`, `reset()`, and `registerFromConfig()` methods, preventing test pollution and ensuring clean state management.

---

## 2. Architectural Design & Registry API

### 2.1 WebTemplateCatalog Architecture
The `WebTemplateCatalog` acts as the single source of truth for all available Web capability templates.

```text
                                 ┌─────────────────────────────┐
                                 │     WebTemplateCatalog      │
                                 └──────────────┬──────────────┘
                                                │
                 ┌──────────────────────────────┼──────────────────────────────┐
                 ▼                              ▼                              ▼
    ┌─────────────────────────┐    ┌─────────────────────────┐    ┌─────────────────────────┐
    │  Built-in 'starter'     │    │  Host App Config        │    │  Runtime Provider       │
    │  Web Starter Template   │    │  laraseed.web_templates │    │  Custom Registration    │
    └─────────────────────────┘    └─────────────────────────┘    └─────────────────────────┘
                 │                              │                              │
                 └──────────────────────────────┼──────────────────────────────┘
                                                ▼
                                 ┌─────────────────────────────┐
                                 │   WebGenerator Execution    │
                                 │   • PathGuard Containment   │
                                 │   • Transactional Rollback  │
                                 │   • Preflight Collision     │
                                 └─────────────────────────────┘
```

### 2.2 Registration API Methods

| Method | Signature | Purpose & Behavior |
| :--- | :--- | :--- |
| `register` | `register(string $id, array $definition, bool $overwrite = false): void` | Registers a template definition. Validates ID format (`/^[a-z0-9_-]+$/`), schema constraints, and prevents accidental duplicate registration unless `$overwrite` is true. |
| `unregister` | `unregister(string $id): void` | Unregisters a specific template by ID. |
| `reset` | `reset(): void` | Restores catalog state back to built-in default templates (isolated for testing). |
| `registerFromConfig` | `registerFromConfig(): void` | Automatically imports templates defined in `config('laraseed.web_templates', [])`. |
| `all` | `all(): array` | Returns all registered template definitions. |
| `get` | `get(string $id): array` | Retrieves a template definition by ID or throws actionable `PackageGenerationException`. |
| `has` | `has(string $id): bool` | Returns boolean indicating if a template ID is registered. |
| `getAvailableTemplateIds`| `getAvailableTemplateIds(): array` | Returns list of all registered template IDs for CLI error messaging. |
| `defaultTemplateId` | `defaultTemplateId(): string` | Returns default template ID (`starter`). |

---

## 3. Template Definition Schema & Validation

Every registered template must adhere to a strict schema:

```php
WebTemplateCatalog::register('custom_portal', [
    'name'        => 'Customer Portal Starter',
    'description' => 'A lightweight customer dashboard with custom navigation and auth',
    'files'       => [
        'src/Web/Providers/WebServiceProvider.php' => 'templates/starter/provider.php.stub',
        'src/Web/Config/web.php'                   => 'templates/starter/config.php.stub',
        'src/Web/Resources/views/portal.blade.php' => '/path/to/custom_portal.blade.php.stub',
    ],
]);
```

### 3.1 Strict Validation Rules
1. **Identifier Syntax:** Template IDs must match `/^[a-z0-9_-]+$/` (lowercase alphanumeric characters, underscores, and dashes only).
2. **Duplicate Rejection:** Registering an existing template ID throws `PackageGenerationException::invalidInput("Web template [{$id}] is already registered.")` unless explicitly flagged with `overwrite: true`.
3. **Non-Empty Schema Attributes:**
   - `name`: non-empty string.
   - `description`: string.
   - `files`: non-empty associative array mapping package-relative destination paths to stub paths.
4. **Stub Path Flexibility:** Stubs can be specified as relative paths (resolved within `PackageGenerator/stubs/`) or absolute filesystem paths (resolved directly by `StubRenderer`).

---

## 4. Generator & StubRenderer Integration

### 4.1 Absolute & Relative Stub Resolution
`StubRenderer::render()` was enhanced to transparently support both generator-bundled relative stubs and external absolute paths:
```php
$file = str_starts_with($stubName, DIRECTORY_SEPARATOR) ? $stubName : "{$this->stubsPath}/{$stubName}";
```

### 4.2 Dynamic Template Identity Placeholders
`WebGenerator` injects `{{ TEMPLATE_ID }}` alongside standard placeholders (`{{ PACKAGE_KEY }}`, `{{ PACKAGE_SLUG }}`, `{{ PACKAGE_TITLE }}`, `{{ UPPER_PACKAGE_KEY }}`), allowing generated configuration and templates to dynamically reference their template identifier.

### 4.3 Security & Path Containment Guarantees
- **Containment:** Every destination file path is validated through `PathGuard::normalizeRelativePath()` and verified to reside strictly within the target package root. Any attempt to write outside the package (e.g. `../../evil.php`) is rejected before disk mutation.
- **Preflight Collision Detection:** Existing files are scanned before writing; collision requires `--force`.
- **Atomic Rollback:** If any file write fails, `FilesystemTransaction` reverts all modified and newly created files to their exact pre-generation state.

---

## 5. Duplicate Service Provider Registration Investigation

### 5.1 Architectural Finding
In Plain Packages (introduced in Phase 03), `composer.json` declares:
1. `extra.laraseed.provider` (consumed by Laraseed's `OptionalPackageComposition` in host applications).
2. `extra.laravel.providers` (consumed by Laravel's `PackageManifest` for Composer package auto-discovery).

### 5.2 Container Idempotency Verification
We verified Laravel's service provider registration semantics in `Illuminate\Foundation\Application::register()`:
```php
if ($registered = $this->getProvider($provider)) {
    return $registered;
}
```
- Laravel's service container natively enforces registration idempotency.
- If a package is discovered via Composer auto-discovery and subsequently loaded via `OptionalPackageComposition`, Laravel's container detects that the provider class is already registered and returns the existing instance without re-executing `register()` or double-booting.
- Automated tests (`test_duplicate_service_provider_registration_behavior`) explicitly verify that repeated registration returns identical instances with zero side-effects.

---

## 6. Automated Test Suite & Verification Results

### 6.1 Dedicated Test Suite: `WebTemplateRegistryTest.php`
A comprehensive 14-test suite (`tests/Feature/Laraseed/WebTemplateRegistryTest.php`) was created:
- `test_catalog_contains_default_starter_template`: Verifies built-in starter metadata.
- `test_catalog_get_nonexistent_template_throws_exception_with_available_list`: Verifies error reporting.
- `test_catalog_registers_custom_template_successfully`: Verifies dynamic registration.
- `test_catalog_rejects_invalid_template_identifiers`: Rejects whitespace, uppercase, and special characters.
- `test_catalog_rejects_duplicate_registration_unless_overwritten`: Verifies duplicate safety.
- `test_catalog_allows_overwrite_when_explicitly_flagged`: Verifies explicit override support.
- `test_catalog_validates_definition_schema`: Validates name, description, and files structure.
- `test_catalog_unregister_removes_template`: Verifies unregister functionality.
- `test_catalog_reset_restores_default_starter_only`: Verifies test state isolation.
- `test_catalog_registers_from_config`: Verifies host application config integration.
- `test_make_web_generates_package_using_dynamically_registered_custom_template`: Full generation flow.
- `test_custom_template_enforces_path_containment_and_rejects_path_traversal`: Security verification.
- `test_custom_template_dry_run_simulates_without_writing_files`: Dry run verification.
- `test_duplicate_service_provider_registration_behavior`: Idempotency verification.

### 6.2 Full Application Test Baseline

```text
Test Summary:
Total Tests:       475 passed
Total Assertions:  3,563 assertions
Failures / Errors: 0
Duration:          17.44s
```

All previous test suites across Admin, Web, Contacts, and generator commands passed with zero regressions.

---

## 7. Modified Files Summary

| File | Type | Changes |
| :--- | :--- | :--- |
| `packages/Laraseed/PackageGenerator/src/Templates/WebTemplateCatalog.php` | Production | Added `register()`, `unregister()`, `reset()`, `registerFromConfig()`, `ensureInitialized()`, schema validation, and identifier syntax rules. |
| `packages/Laraseed/PackageGenerator/src/Generators/StubRenderer.php` | Production | Added support for absolute stub file paths. |
| `packages/Laraseed/PackageGenerator/src/Generators/WebGenerator.php` | Production | Injected `{{ TEMPLATE_ID }}` into template replacements. |
| `packages/Laraseed/PackageGenerator/stubs/templates/starter/config.php.stub` | Stub | Replaced hardcoded template name with `{{ TEMPLATE_ID }}` placeholder. |
| `tests/Feature/Laraseed/WebTemplateRegistryTest.php` | Test | 14 automated tests covering all registry workflows, security, and edge cases. |

---

## 8. Conclusion

Phase 04 is fully implemented, strictly tested, and verified against all security, architecture, and test criteria. The Laraseed Package Generator now possesses an extensible Web template registry ready for custom package ecosystems.
