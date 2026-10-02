# Laraseed Package Generator V4 — Pre-Flight Release Checklist

This checklist must be executed and verified by the Release Maintainer before tagging `v4.0.0` or publishing `laraseed/package-generator`.

---

## 1. Environment & Baseline Integrity

- [ ] **PHP Version Compatibility:** Verify PHP runtime matches `^8.2` (tested on PHP 8.2, 8.3, and 8.4).
- [ ] **Laravel Version Compatibility:** Verify Laravel framework matches `^11.0` or `^12.0` (tested on Laravel 11.x and 12.x).
- [ ] **Composer Package Validation:**
  - Execute `composer validate --strict` and confirm zero warnings or errors.
- [ ] **Standalone Package Test Suite:**
  - Execute `vendor/bin/phpunit` in clean standalone clone.
  - Confirm **270 tests passed (1,680 assertions)** with 0 failures.
- [ ] **Host Monorepo Baseline (Historical Context):**
  - CampusHub host integration baseline: 501 tests passed (3,696 assertions).

---

## 2. Package Discovery & Autoloading

- [ ] **Composer Autoload Optimization:**
  - Run `composer dump-autoload --optimize` without warnings or syntax errors.
  - If deploying in authoritative mode, run `composer dump-autoload -a` and confirm exit code 0.
- [ ] **Pre-Autoload Bootstrap Resilience:**
  - Verify that running `php artisan list` executes cleanly in a fresh installation before running `composer dump-autoload`.
- [ ] **Active vs Inactive Isolation:**
  - Verify that inactive packages on disk with uninstalled dependencies do not halt core application bootstrap.
  - Verify that active packages declared in `LARASEED_OPTIONAL_PACKAGES` load all declared service providers.

---

## 3. Filesystem Safety & Permissions

- [ ] **Advisory Lock Directory:**
  - Verify `storage/framework/locks/` exists or is created with write permissions.
- [ ] **No Disposable Artifacts:**
  - Verify that git working tree is clean with zero lingering probe packages or cache files.
- [ ] **Transactional Rollback Verification:**
  - Verify that failed generation plans cleanly roll back created files without orphaned state.

---

## 4. Security & Content Security Policy (CSP)

- [ ] **Path Traversal Containment:**
  - Verify that relative paths (`../`, `..\`, null bytes) in package or template identifiers are strictly rejected by [`PathGuard`](../src/Support/PathGuard.php).
- [ ] **Symlink Boundary Enforcement:**
  - Verify that symlinks pointing outside target directories fail canonical realpath boundary checks.
- [ ] **Blade Template Security:**
  - Verify that generated Web capability views contain no inline event handlers (`onclick`) or unescaped variables.

---

## 5. Generator Functionality Verification

- [ ] **Concord Modular Packages:** `laraseed:make-package Acme/Example` creates valid module provider, composer.json, and directories.
- [ ] **Plain PSR-4 Packages:** `laraseed:make-package Acme/Lib --plain` creates minimal library skeleton.
- [ ] **Atomic Models:** `laraseed:make-model Acme/Example Item --contract --proxy` generates Model, Contract, and Proxy atomically.
- [ ] **Presentation Generators:** `make-controller`, `make-middleware`, `make-mail`, and `make-notification` generate syntactically valid PHP files.
- [ ] **Capability Modules:** `make-admin` and `make-web` update `extra.laraseed.capabilities` without race conditions or manifest corruption.
- [ ] **Default Web Management:** `laraseed:web-default` lists, inspects, selects, and clears default root Web package.

---

## 6. Final Sign-Off

| Checkpoint | Responsible Role | Status | Date |
| :--- | :--- | :---: | :---: |
| Standalone Test Suite & Quality Assurance | Package Maintainer | **PASSED** | 2026-10-03 |
| Security & Boundary Audit | Security Auditor | **PASSED** | 2026-10-03 |
| Architectural Compliance | Principal Laravel Architect | **PASSED** | 2026-10-03 |
| Documentation Completeness | Release Engineer | **PASSED** | 2026-10-03 |
