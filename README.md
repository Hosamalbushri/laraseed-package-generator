# Laraseed Package Generator (`laraseed/package-generator`)

[![Latest Version](https://img.shields.io/badge/version-4.0.0-blue.svg)](CHANGELOG.md)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Tests: 225 Passed](https://img.shields.io/badge/tests-225%20passed-brightgreen.svg)](tests/)

An enterprise-grade modular scaffolding and lifecycle management engine for Laravel 12. Generates fully isolated, self-contained packages with domain models, contracts, Concord proxies, HTTP middleware, mail, notifications, admin panels, web capabilities, and transactional filesystem operations.

---

## Features

- **Dual Architecture Scaffolding:** Create Concord modular packages (default) or plain PSR-4 libraries (`--plain`).
- **Atomic Model & Proxy Composition:** Scaffold Eloquent Model, Contract interface, and Concord ModelProxy atomically via `laraseed:make-model <pkg> <model> --contract --proxy`.
- **Presentation & Messaging Generators:** Scaffold standard HTTP Controllers (`--api`), Middleware, Mailables (HTML/Markdown views), and Multi-Channel Notifications (`--broadcast`, `--database`, `--queued`).
- **Extensible Web Capability:** Scaffolds Vite and Tailwind-driven frontend templates with strict Content Security Policy (CSP) compliance and pluggable template registration.
- **Enterprise Concurrency & Safety:** Advisory file locking ([`PackageLock`](src/Support/PackageLock.php)), atomic filesystem transactions ([`FilesystemTransaction`](src/Generators/FilesystemTransaction.php)), and path containment ([`PathGuard`](src/Support/PathGuard.php)).
- **Seamless Bootstrap:** Dynamic PSR-4 ClassLoader bridge eliminates Catch-22 bootstrap deadlocks before `composer dump-autoload` is executed.

---

## Installation

Add the package to your `composer.json` repositories (for local path packages) or install via Composer:

```bash
composer require --dev laraseed/package-generator
```

Laravel's package auto-discovery will automatically register [`Laraseed\PackageGenerator\Providers\PackageGeneratorServiceProvider`](src/Providers/PackageGeneratorServiceProvider.php).

---

## Quick Usage

### 1. Generate a New Package
```bash
# Concord modular package
php artisan laraseed:make-package Acme/Billing

# Plain PSR-4 library
php artisan laraseed:make-package Acme/Calculator --plain
```

### 2. Scaffold Domain Models, Contracts & Proxies
```bash
php artisan laraseed:make-model Acme/Billing Invoice --contract --proxy
```

### 3. Scaffold Presentation & Messaging Components
```bash
# API Controller
php artisan laraseed:make-controller Acme/Billing InvoiceController --api

# HTTP Middleware
php artisan laraseed:make-middleware Acme/Billing VerifyBillingSignature

# Markdown Mailable
php artisan laraseed:make-mail Acme/Billing InvoiceReceiptMail --markdown=emails.receipt

# Multi-channel Queued Notification
php artisan laraseed:make-notification Acme/Billing InvoiceDueNotification --database --broadcast --queued
```

### 4. Scaffold Admin & Web Capabilities
```bash
# Admin Panel Module (DataGrid, ACL, Menu, Routes)
php artisan laraseed:make-admin Acme/Billing

# Public Web Portal (Vite, Tailwind, Blade Views)
php artisan laraseed:make-web Acme/Billing --template=starter
```

---

## Documentation

Detailed documentation is available in the [`docs/`](docs/) directory:

- [Getting Started](docs/GETTING_STARTED.md) — Step-by-step setup and package creation workflow.
- [Command Reference](docs/COMMAND_REFERENCE.md) — Comprehensive guide to all 16 Artisan commands and options.
- [Architecture & Design](docs/ARCHITECTURE.md) — Deep dive into generator internals, transactions, concurrency locks, and discovery.
- [Configuration Guide](docs/CONFIGURATION.md) — Configuration options and environment settings.
- [Web Templates Guide](docs/TEMPLATES.md) — Creating and registering custom frontend templates.
- [Testing Guide](docs/TESTING.md) — Running standalone and host-application test suites.
- [Security & Containment](docs/SECURITY.md) — Path traversal defense, symlink boundaries, and CSP compliance.
- [Deployment Guide](docs/DEPLOYMENT.md) — Production deployment, Composer optimization, and caching.
- [Release Checklist](docs/RELEASE_CHECKLIST.md) — Pre-flight verification checklist.
- [Release Notes](docs/RELEASE_NOTES.md) — Detailed changelog and compatibility notes.
- [Audit History](docs/audits/) — Historical architecture, security, and integration audit reports.

---

## Running Tests

Execute package tests using the package's PHPUnit configuration:

```bash
./vendor/bin/phpunit -c packages/Laraseed/PackageGenerator/phpunit.xml
```

Or via the host application's test runner:

```bash
php artisan test packages/Laraseed/PackageGenerator/tests/
```

---

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
