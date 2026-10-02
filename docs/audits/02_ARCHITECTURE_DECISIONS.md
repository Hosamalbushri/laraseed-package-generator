# LARASEED PACKAGE GENERATOR V4 AUDIT
## 02 — Architectural Decisions and Design Framework

**Document ID:** `02_ARCHITECTURE_DECISIONS.md`  
**Audit Stage:** Phase 00 — Architectural Decisions  
**Author:** Principal Laravel Architect & Security Auditor  
**Date:** 2026-10-02  
**Status:** ARCHITECTURE DECISIONS ADOPTED  

---

## 1. Core Architectural Question

> *Does the current generator architecture already support independent capability registration, or is a brand-new architectural registry / abstract layer genuinely necessary?*

### 1.1 Finding & Decision
**Decision: Extend Existing Abstractions. No Redundant Meta-Layers.**

The current PackageGenerator architecture is cleanly layered:
- **`PackageResolver`:** Resolves package namespaces, paths, and identities with strict boundary checking.
- **`GenerationPlan`:** Prepares target-file-to-content maps and performs preflight collision checks.
- **`FilesystemTransaction` & `FilesystemWriter`:** Coordinates atomic filesystem journaling, rollback, and `PathGuard` canonical containment validation.
- **`StubRenderer`:** Standardizes placeholder token interpolation (`{{ NAMESPACE }}`, `{{ CLASS_NAME }}`, `{{ PACKAGE_KEY }}`, etc.).

Introducing an over-abstracted "Generator Plugin Manager" or "Universal Generator Pipeline" would add unnecessary cognitive and runtime overhead without providing tangible developer benefits.

Each generator is a cohesive single-responsibility class that composes `PackageResolver`, `StubRenderer`, and `FilesystemWriter`. This pattern has proven resilient, highly testable, and completely isolated from Foundation.

---

## 2. Standard Sub-Generator Design Pattern

All new sub-generators (Middleware, Mail, Notification, Proxy) must adhere strictly to the established generator contract:

```php
namespace Laraseed\PackageGenerator\Generators;

class ExampleGenerator
{
    protected string $basePath;

    public function __construct(
        protected PackageResolver $resolver = new PackageResolver,
        protected StubRenderer $renderer = new StubRenderer,
        protected FilesystemWriter $writer = new FilesystemWriter,
        ?string $basePath = null
    ) {
        $this->basePath = $basePath ?? base_path();
        $this->resolver = new PackageResolver(basePath: $this->basePath);
        $this->writer = new FilesystemWriter(basePath: $this->basePath);
    }

    /**
     * @return array{
     *     package: ResolvedPackage,
     *     artifact: string,
     *     dry_run: bool,
     *     force: bool,
     *     files: array<int, array{path: string, full_path: string, action: string, bytes: int}>
     * }
     */
    public function generate(
        string $packageInput,
        string $name,
        bool $dryRun = false,
        bool $force = false
    ): array {
        $resolved = $this->resolver->resolve($packageInput);
        $this->validateIdentifier($name);

        $content = $this->renderer->render('example.php.stub', $resolved->identity, [
            '{{ CLASS_NAME }}' => $name,
        ]);

        $files = ["src/Path/{$name}.php" => $content];
        $plan = new GenerationPlan($resolved->identity->relativePackagePath, $this->basePath, $files);
        $plan->preflight($force);

        $results = $this->writer->execute($plan, $dryRun, $force);

        return [
            'package' => $resolved,
            'artifact' => $name,
            'dry_run' => $dryRun,
            'force'   => $force,
            'files'   => $results,
        ];
    }
}
```

---

## 3. Dynamic Template Catalog Registry Architecture

To support the Extensible Template System without breaking backwards compatibility with `WebTemplateCatalog`:

1. **Keep `WebTemplateCatalog` as the central catalog facade.**
2. Convert static definitions from hardcoded arrays into an extensible runtime map.
3. Provide:
   - `WebTemplateCatalog::register(string $id, array $definition): void`
   - `WebTemplateCatalog::registerFromConfig(): void` (reads `config('laraseed.web_templates', [])`)
   - `WebTemplateCatalog::reset(): void` (for test isolation)
4. Validate that any dynamically registered template definition specifies a valid metadata schema (`id`, `name`, `description`, `files`) with stubs residing in readable locations.

---

## 4. Preservation of Public CLI & Package Contracts

1. **CLI Signature Parity:** All commands MUST support `{package}` (or `{name}` for `make-package`), `{name}` (for sub-generators), `{--dry-run}`, and `{--force}`.
2. **Zero Modification to Foundation/Contacts:** Foundation contracts and Contacts package source code remain strictly unmodified.
3. **Strict CSP & Security Boundaries:** Dynamic branding via stylesheet route, zero inline handlers, `SameSite=Lax` and `Secure` cookie attributes, and `PathGuard` containment remain permanent, non-negotiable invariants.
