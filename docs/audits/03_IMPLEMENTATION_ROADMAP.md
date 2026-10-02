# LARASEED PACKAGE GENERATOR V4 AUDIT
## 03 — Prioritized Implementation Roadmap

**Document ID:** `03_IMPLEMENTATION_ROADMAP.md`  
**Audit Stage:** Phase 00 — Implementation Planning  
**Author:** Principal Laravel Architect & Security Auditor  
**Date:** 2026-10-02  
**Status:** ROADMAP APPROVED  

---

## 1. Roadmap Overview & Sequencing

The V4 enhancements are divided into cohesive, dependency-ordered milestones. Each milestone addresses one focused problem without mixing unrelated architectural changes.

```
[Phase 01: Core Sub-Generators]
       │ (Middleware, Mail, Notification)
       ▼
[Phase 02: Concord Model Proxies]
       │ (make-proxy, make-model --proxy)
       ▼
[Phase 03: Plain Package Recipe]
       │ (make-package --plain)
       ▼
[Phase 04: Extensible Template Registry]
         (Dynamic WebTemplateCatalog)
```

---

## 2. Milestone Breakdown

### Milestone 1: Core Sub-Generators Suite (Middleware, Mail, Notification)

#### Objective
Add standard Laravel component generators for HTTP Middleware, Mailables, and Notifications to support modular CRM packages.

#### Affected Files
- `packages/Laraseed/PackageGenerator/src/Generators/MiddlewareGenerator.php` (New)
- `packages/Laraseed/PackageGenerator/src/Generators/MailGenerator.php` (New)
- `packages/Laraseed/PackageGenerator/src/Generators/NotificationGenerator.php` (New)
- `packages/Laraseed/PackageGenerator/src/Console/Commands/MiddlewareMakeCommand.php` (New)
- `packages/Laraseed/PackageGenerator/src/Console/Commands/MailMakeCommand.php` (New)
- `packages/Laraseed/PackageGenerator/src/Console/Commands/NotificationMakeCommand.php` (New)
- `packages/Laraseed/PackageGenerator/stubs/middleware.php.stub` (New)
- `packages/Laraseed/PackageGenerator/stubs/mail.php.stub` (New)
- `packages/Laraseed/PackageGenerator/stubs/notification.php.stub` (New)
- `packages/Laraseed/PackageGenerator/src/Providers/PackageGeneratorServiceProvider.php` (Register commands)
- `tests/Feature/Laraseed/PackageGeneratorSubGeneratorsV4Test.php` (New)

#### Acceptance Criteria & Invariants
- Enforces strict class name validation (no path traversal, valid PHP identifier).
- Generates artifacts within `src/Http/Middleware/`, `src/Mail/`, and `src/Notifications/`.
- Full `--dry-run` and `--force` support.
- Fully protected by `PathGuard` and transactional rollback.

---

### Milestone 2: Concord Model Proxies & Enhanced Model Generation

#### Objective
Add `laraseed:make-proxy` and `--proxy` flag to `laraseed:make-model` to automate Concord Model Proxy creation and ModuleServiceProvider registration.

#### Affected Files
- `packages/Laraseed/PackageGenerator/src/Generators/ProxyGenerator.php` (New)
- `packages/Laraseed/PackageGenerator/src/Console/Commands/ProxyMakeCommand.php` (New)
- `packages/Laraseed/PackageGenerator/stubs/proxy.php.stub` (New)
- `packages/Laraseed/PackageGenerator/src/Generators/ModelGenerator.php` (Add `--proxy` and `--contract` support)
- `packages/Laraseed/PackageGenerator/src/Console/Commands/ModelMakeCommand.php` (Add `{--proxy}` option)
- `tests/Feature/Laraseed/ModelAndProxyGeneratorTest.php` (New)

#### Acceptance Criteria & Invariants
- Standalone `make-proxy` creates `src/Models/{Name}Proxy.php` extending `Konekt\Concord\Proxies\ModelProxy`.
- `make-model {package} {name} --proxy` creates Contract, Model, and Proxy atomically within a single transaction.
- Zero breaking changes to standard `make-model` behavior.

---

### Milestone 3: Plain Package Recipe (`laraseed:make-package --plain`)

#### Objective
Support generating lightweight library packages without Concord or presentation boilerplate.

#### Affected Files
- `packages/Laraseed/PackageGenerator/src/Generators/PackageGenerator.php`
- `packages/Laraseed/PackageGenerator/src/Console/Commands/PackageMakeCommand.php`
- `tests/Feature/Laraseed/PlainPackageGeneratorTest.php` (New)

#### Acceptance Criteria & Invariants
- `laraseed:make-package Acme/Tools --plain` generates pure `composer.json`, `src/ToolsServiceProvider.php`, `config.php`, and `TestCase.php`.
- Excludes Concord `ModuleServiceProvider` and presentation directories.
- Default `make-package` without `--plain` remains completely unchanged.

---

### Milestone 4: Extensible Web Template Catalog Registry

#### Objective
Enable dynamic registration of Web capability templates via service providers and configuration.

#### Affected Files
- `packages/Laraseed/PackageGenerator/src/Templates/WebTemplateCatalog.php`
- `packages/Laraseed/PackageGenerator/src/Generators/WebGenerator.php`
- `tests/Feature/Laraseed/WebTemplateCatalogExtensibilityTest.php` (New)

#### Acceptance Criteria & Invariants
- `WebTemplateCatalog::register($id, $definition)` permits runtime template additions.
- Config-driven template registration via `config('laraseed.web_templates')`.
- Full path validation ensuring custom template stubs reside in authorized readable directories.
- Default `starter` template remains 100% functional and unmodified.

---

## 3. Recommended First Implementation Step

The recommended first implementation step upon approval is:
**Milestone 1: Core Sub-Generators Suite (`laraseed:make-middleware`, `laraseed:make-mail`, `laraseed:make-notification`).**
