# Laraseed Package Generator V4 — Release Notes

**Release Version:** 4.0.0  
**Release Date:** 2026-10-03  
**Target Platform:** Laravel 11.x / 12.x & PHP 8.2+ (Tested on PHP 8.2, 8.3, 8.4)

---

## Overview

Laraseed Package Generator V4 introduces a modernized, secure, and highly extensible scaffolding framework for building modular Laravel applications and standalone domain libraries. V4 adds support for lightweight PSR-4 packages, atomic Concord contract and model proxy generation, comprehensive presentation scaffolding (middleware, mail, notifications), extensible web templates, cross-process concurrency locking, default web package root mounting, and seamless pre-autoload runtime bootstrap.

---

## What's New in V4

### 1. Dual Package Architecture
- **Plain PSR-4 Libraries:** Added `--plain` flag to `laraseed:make-package` for generating minimal standalone libraries without Concord presentation dependencies.
- **Concord Modular Packages:** Enhanced default modular package generation with automated module service providers and capability registration.

### 2. Atomic Model & Concord Proxy Generation
- Added `--contract` and `--proxy` options to `laraseed:make-model`.
- Scaffolds Eloquent Model, Contract interface, and Concord ModelProxy in a single atomic filesystem transaction.
- Standalone commands added: `laraseed:make-contract` and `laraseed:make-proxy`.

### 3. Presentation & Messaging Component Generators
- **HTTP Middleware Generator:** `laraseed:make-middleware` generates standardized PSR-15/Laravel request middleware.
- **Mail Generator:** `laraseed:make-mail` scaffolds Mailables and companion Blade views with support for HTML (`--view=`), Markdown (`--markdown=`), and queuing (`--queued`).
- **Notification Generator:** `laraseed:make-notification` supports Mail, Database, and Broadcast channels with dynamic import hygiene, `--broadcast` convenience flag, and multi-channel dispatch.

### 4. Pluggable Web Capability & Templates
- `laraseed:make-web` scaffolds frontend web capabilities with Vite, Tailwind CSS, and Blade layouts.
- Dynamic template registry via [`WebTemplateCatalog`](../src/Templates/WebTemplateCatalog.php), supporting custom template registration via `config/package-generator.php`.
- Full compliance with strict Content Security Policy (CSP) headers (zero inline event handlers or unsafe scripts).

### 5. Root-Mounted Default Web Package Management
- `laraseed:web-default` command allows inspecting, validating, selecting, and clearing the active default Web package.
- Root-mounted default Web package serves directly at `GET /` with HTTP 200 OK and zero redirects.
- Automatic routing collision avoidance against reserved system prefixes (`admin`, `install`, `api`, `up`, `sanctum`).

### 6. Enterprise Filesystem Transactions & Concurrency Safety
- **Filesystem Locking:** [`PackageLock`](../src/Support/PackageLock.php) enforces cross-process mutual exclusion via advisory file locks and PID tracking, eliminating race conditions during parallel capability scaffolding.
- **Transactional Rollback:** [`FilesystemTransaction`](../src/Generators/FilesystemTransaction.php) ensures atomic plan execution, rolling back all created files and restoring manifests if any step fails.
- **Path Traversal Containment:** [`PathGuard`](../src/Support/PathGuard.php) strictly confines all generator file operations to authorized package directories.

### 7. Dynamic ClassLoader Mapping & Bootstrap Resilience
- Registers local package namespaces directly into Composer's live `ClassLoader`.
- Resolves the pre-autoload Catch-22 bootstrap issue, allowing immediate execution of Artisan commands without requiring manual `composer dump-autoload`.
- Active and inactive package partitioning ensures dormant packages on disk never halt core application bootstrap.

---

## Upgrading & Compatibility

### Requirements
- **PHP:** `^8.2`
- **Laravel Framework:** `^11.0` or `^12.0`
- **Composer:** `^2.2`

### Backward Compatibility
- 100% backward compatible with packages created in Laraseed V2 and V3.
- Core Foundation packages require zero modifications.

---

## Known Operational Considerations

1. **Composer Authoritative Classmap Mode (`--classmap-authoritative` / `-a`):**
   When running Composer in authoritative mode in production environments, Composer deliberately skips disk scans for classes not indexed in `autoload_classmap.php`. `composer dump-autoload -a` must be run whenever packages or classes are added or modified on disk.
2. **Advisory Lock Storage:**
   Ensure `storage/framework/locks/` is writable by PHP worker processes when running generator commands in automated CI/CD pipelines.
