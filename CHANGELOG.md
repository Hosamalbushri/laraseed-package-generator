# Changelog

All notable changes to `laraseed/package-generator` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [4.0.0] - 2026-10-03

### Added
- **Default Web Package Manager (`laraseed:web-default`):** Added command to inspect, list, select, validate, and clear the active default Web package.
- **Direct Root-Mounted Web Routing:** Enabled zero-redirect HTTP 200 homepage mounting at root `GET /` with automatic sub-prefix routing and reserved system prefix collision guards (`admin`, `install`, `api`, `up`, `sanctum`).
- **Plain Package Generation:** Added `--plain` flag to `laraseed:make-package` for creating standalone, minimalist PSR-4 library packages without Concord or presentation dependencies.
- **Atomic Model & Proxy Composition:** Added `--contract` and `--proxy` flags to `laraseed:make-model` to scaffold Eloquent Model, Contract interface, and Concord ModelProxy in a single atomic transaction.
- **Standalone Proxy Generator:** Added `laraseed:make-proxy` command.
- **Standalone Contract Generator:** Added `laraseed:make-contract` command.
- **HTTP Middleware Generator:** Added `laraseed:make-middleware` command with standard PSR-15/Laravel middleware scaffolding.
- **Mail Generator:** Added `laraseed:make-mail` with support for HTML views (`--view=`), Markdown views (`--markdown=`), and queuing (`--queued`).
- **Notification Generator:** Added `laraseed:make-notification` with support for Mail, Database, and Broadcast channels (`--database`, `--broadcast`, `--queued`).
- **Dynamic Web Template Registry:** Added [`WebTemplateCatalog`](src/Templates/WebTemplateCatalog.php) with config-based custom template registration and schema validation.
- **Enterprise Concurrency Locking:** Added [`PackageLock`](src/Support/PackageLock.php) providing advisory `flock` mutual exclusion with PID tracking and reentrant lock acquisition.
- **Filesystem Transactions:** Added [`FilesystemTransaction`](src/Generators/FilesystemTransaction.php) for atomic generation plans with complete rollback upon failure.
- **Path Containment:** Added [`PathGuard`](src/Support/PathGuard.php) to enforce strict boundary checks and prevent path traversal or symlink escapes.
- **Dynamic ClassLoader Mapping:** Integrated dynamic PSR-4 namespace registration with Composer's live `ClassLoader`.

### Changed
- Refactored `NotificationMakeCommand` to provide dynamic import hygiene (injecting `MailMessage` only when Mail channel is active).
- Modernized Web capability starter templates for strict Content Security Policy (CSP) compliance (zero inline scripts or event handlers).
- Standardized test suite for standalone Composer package execution with Orchestra Testbench.

---

## [3.0.0] - 2026-10-01

### Added
- Web capability generator (`laraseed:make-web`) with Vite and Tailwind support.
- Authentication mode scaffolding and permission guard isolation.
- Multi-package web routing and sub-prefix collision prevention.

---

## [2.0.0] - 2026-09-30

### Added
- Admin capability generator (`laraseed:make-admin`) with DataGrid and ACL menu bindings.
- Model, Repository, Request, Controller, Event, Listener, Migration, and Seeder generators.
