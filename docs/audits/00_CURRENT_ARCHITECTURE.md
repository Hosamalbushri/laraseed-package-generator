# LARASEED PACKAGE GENERATOR V4 AUDIT
## 00 — Current Architecture and Technical State

**Document ID:** `00_CURRENT_ARCHITECTURE.md`  
**Audit Stage:** Phase 00 — Baseline Architecture Audit  
**Author:** Principal Laravel Architect & Security Auditor  
**Date:** 2026-10-02  
**Status:** VERIFIED BASELINE  

---

## 1. Architectural Overview

The Laraseed Package Generator (`packages/Laraseed/PackageGenerator`) provides a suite of Artisan commands and transactional scaffolding generators designed to build modular, package-first extensions for the Laraseed CRM platform while enforcing strict isolation boundaries with the Foundation layer (`packages/Webkul`).

The architecture operates on four core layers:
1. **Console Interface Layer (`src/Console/Commands/`):** 17 Artisan commands with uniform option parity (`--dry-run`, `--force`).
2. **Generation Orchestration Layer (`src/Generators/`):** Primary capability orchestrators (`PackageGenerator`, `AdminGenerator`, `WebGenerator`) and 14 granular sub-generators.
3. **Execution & Integrity Layer (`src/Support/PathGuard.php`, `src/Generators/FilesystemTransaction.php`, `src/Generators/GenerationPlan.php`, `src/Generators/FilesystemWriter.php`):** Canonical path containment, preflight collision detection, atomic journaling, and deterministic rollback.
4. **Template & Stub Catalog Layer (`src/Templates/`, `stubs/`):** Blade, CSS, JS, PHP, and JSON templates rendered via `StubRenderer`.

---

## 2. Command Suite & Generator Inventory

### 2.1 Primary Capability Generators

| Command | Generator Class | Generated Artifacts / Actions | Manifest Mutation |
| :--- | :--- | :--- | :--- |
| `laraseed:make-package` | `PackageGenerator` | Base library skeleton, `PackageServiceProvider`, `ModuleServiceProvider` (Concord), `config.php`, lang files (`en`, `ar`), `TestCase.php`, `PackageTest.php` | Creates `composer.json` with `extra.laraseed` metadata |
| `laraseed:make-admin` | `AdminGenerator` | `AdminServiceProvider`, `menu.php`, `acl.php`, `AdminController.php`, `Routes/web.php`, `views/index.blade.php`, lang files | Registers `extra.laraseed.capabilities.admin` |
| `laraseed:make-web` | `WebGenerator` | `WebServiceProvider`, `web.php`, `AuthenticateWeb.php`, controllers (`Home`, `Page`, `Account`), Blade UI components (`layout`, `header`, `navbar`, `footer`, `modal`, `card`, `button`, `section`, `form-control-group`), vanilla JS kernel, CSS, Vite config, test | Registers `extra.laraseed.capabilities.web` |

### 2.2 Granular Sub-Generators

| Command | Generator Class | Target Path | Base Class / Contract |
| :--- | :--- | :--- | :--- |
| `laraseed:make-model` | `ModelGenerator` | `src/Models/{Name}.php` | `Illuminate\Database\Eloquent\Model` |
| `laraseed:make-contract` | `ContractGenerator` | `src/Contracts/{Name}.php` | PHP Interface |
| `laraseed:make-migration` | `MigrationGenerator` | `src/Database/Migrations/{timestamp}_{name}.php` | `Illuminate\Database\Migrations\Migration` |
| `laraseed:make-repository` | `RepositoryGenerator` | `src/Repositories/{Name}Repository.php` | `Webkul\Core\Eloquent\Repository` |
| `laraseed:make-request` | `RequestGenerator` | `src/Http/Requests/{Name}.php` | `Illuminate\Foundation\Http\FormRequest` |
| `laraseed:make-controller` | `ControllerGenerator` | `src/Http/Controllers/{Name}Controller.php` | `App\Http\Controllers\Controller` |
| `laraseed:make-route` | `RouteGenerator` | `src/Routes/{name}.php` | Route definitions (`web` or `api`) |
| `laraseed:make-provider` | `ProviderGenerator` | `src/Providers/{Name}ServiceProvider.php` | `Illuminate\Support\ServiceProvider` |
| `laraseed:make-module-provider`| `ModuleProviderGenerator` | `src/Providers/ModuleServiceProvider.php` | `Konekt\Concord\BaseModuleServiceProvider` |
| `laraseed:make-event` | `EventGenerator` | `src/Events/{Name}.php` | Event class with `Dispatchable`, `SerializesModels` |
| `laraseed:make-listener` | `ListenerGenerator` | `src/Listeners/{Name}.php` | Event Listener |
| `laraseed:make-command` | `CommandGenerator` | `src/Console/Commands/{Name}.php` | `Illuminate\Console\Command` |
| `laraseed:make-seeder` | `SeederGenerator` | `src/Database/Seeders/{Name}Seeder.php` | `Illuminate\Database\Seeder` |
| `laraseed:make-datagrid` | `DataGridGenerator` | `src/DataGrids/{Name}DataGrid.php` | `Webkul\DataGrid\DataGrid` |

---

## 3. Verified Security and Integrity Subsystems

### 3.1 Filesystem Containment (`PathGuard`)
- **Enforcement:** Enforces directory boundary isolation via canonical realpath resolution, inspecting both physical paths and symbolic link targets.
- **Protection:** Traversal attempts (`../`, absolute paths, symlinks pointing outside `packages/`) throw actionable `PackageGenerationException::pathTraversalAttempted()`.
- **Pre-write Validation:** Validated at planning time (`GenerationPlan::preflight()`) and re-validated immediately before writing in `FilesystemWriter::writeFile()`.

### 3.2 Transactional Generation (`FilesystemTransaction`)
- **Journaling:** Tracks every filesystem mutation (`create_file`, `update_file`, `create_dir`).
- **Rollback:** In the event of an unhandled exception mid-generation, created files and directories are cleanly purged in reverse order; overwritten files are restored to their exact pre-transaction byte snapshots.
- **Pre-existing Data Safety:** Pre-existing files and user-created directories are never deleted during rollback.

### 3.3 Strict Content Security Policy (CSP) & Cookie Security
- **Dynamic Branding:** Served via dedicated route (`route('*.web.branding.css')`) and CSS custom properties; zero inline style blocks or `style="..."` attributes.
- **JavaScript Execution:** 100% native DOM event delegation via `WebStarterKernel`. Zero inline `onclick`/`onload` handlers. Strict CSP compatibility under `script-src 'self'` and `style-src 'self'`.
- **Cookie Security:** Dark mode preference cookie enforces `SameSite=Lax` and dynamically appends `; Secure` when accessed over HTTPS.

---

## 4. Architectural Summary

The generator has evolved through Phases 01–04 into an atomic, hardened, and high-performance subsystem. It provides a rock-solid foundation for introducing new modular capabilities without modifying existing public contracts or risking regressions in Foundation or Contacts.
