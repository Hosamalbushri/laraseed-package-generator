# Laraseed Package Generator V4 — Pre-Flight Release Checklist

This checklist must be executed and verified by the Release Manager before deploying or tagging a release of Laraseed Package Generator V4.

---

## 1. Environment & Baseline Integrity

- [ ] **PHP Version Compatibility:** Verify PHP runtime is `>= 8.3` (tested on PHP 8.4.1).
- [ ] **Laravel Version Compatibility:** Verify Laravel framework is `>= 12.0` (tested on Laravel 12.61.1).
- [ ] **Core Protected Packages Untouched:**
  - Verify `git diff --stat packages/Webkul` reports 0 changed files.
  - Verify `git diff --stat packages/Laraseed/Contacts` reports 0 changed files.
- [ ] **Full Test Suite Status:**
  - Execute `php artisan test` and verify **501 tests passed (3,696 assertions) with 0 failures**.

---

## 2. Package Discovery & Autoloading

- [ ] **Composer Autoload Optimization:**
  - Run `composer dump-autoload --optimize` without warnings or syntax errors.
  - If deploying in authoritative mode, run `composer dump-autoload -a` and confirm exit code 0.
- [ ] **Pre-Autoload Bootstrap Resilience:**
  - Verify that running `php artisan --version` and `php artisan list` executes cleanly without requiring manual autoloader rebuilds.
- [ ] **Active vs Inactive Isolation:**
  - Verify that inactive packages on disk with uninstalled dependencies do not halt core application bootstrap.
  - Verify that active packages declared in `LARASEED_OPTIONAL_PACKAGES` load all declared service providers.

---

## 3. Filesystem Safety & Permissions

- [ ] **Advisory Lock Directory:**
  - Verify `storage/framework/locks/` exists and is writable by the PHP process.
- [ ] **No Disposable Artifacts:**
  - Verify that `packages/` contains only permanent packages (`Laraseed/`, `Webkul/`) and no lingering `Acme*` disposable probe directories.
- [ ] **Transactional Rollback Verification:**
  - Verify that failed generation plans cleanly roll back created files without orphaned state.

---

## 4. Security & Content Security Policy (CSP)

- [ ] **Path Traversal Containment:**
  - Verify that relative paths (`../`, `..\`) in package or template identifiers are strictly rejected by [`PathGuard`](file:///home/hosam/Documents/CampusHub-main/packages/Laraseed/PackageGenerator/src/Support/PathGuard.php).
- [ ] **Symlink Boundary Enforcement:**
  - Verify that symlinks pointing outside `packages/` are safely ignored by boundary checks in [`config/laraseed.php`](file:///home/hosam/Documents/CampusHub-main/config/laraseed.php).
- [ ] **Blade Template Security:**
  - Verify that generated Web capability views contain no inline event handlers (`onclick`) or unescaped variables.

---

## 5. Generator Functionality Verification

- [ ] **Concord Modular Packages:** `laraseed:make-package Acme/Example` creates valid module provider, composer.json, and directories.
- [ ] **Plain PSR-4 Packages:** `laraseed:make-package Acme/Lib --plain` creates minimal library skeleton.
- [ ] **Atomic Models:** `laraseed:make-model Acme/Example Item --contract --proxy` generates Model, Contract, and Proxy atomicaally.
- [ ] **Presentation Generators:** `make-controller`, `make-middleware`, `make-mail`, and `make-notification` generate syntactically valid PHP files.
- [ ] **Capability Modules:** `make-admin` and `make-web` update `extra.laraseed.capabilities` without race conditions or manifest corruption.

---

## 6. Final Sign-Off

| Checkpoint | Responsible Role | Status | Date |
| :--- | :--- | :---: | :---: |
| Test Suite & Regression Baseline | QA Lead / Integration Engineer | **PASSED** | 2026-10-02 |
| Security & Boundary Audit | Application Security Auditor | **PASSED** | 2026-10-02 |
| Architectural Compliance | Principal Laravel Architect | **PASSED** | 2026-10-02 |
| Documentation Completeness | Release Manager | **PASSED** | 2026-10-02 |
