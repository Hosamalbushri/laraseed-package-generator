# Laraseed Package Generator (`laraseed/package-generator`)

[![Latest Version](https://img.shields.io/badge/version-4.0.0-blue.svg)](CHANGELOG.md)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Tests: 270 Passed](https://img.shields.io/badge/tests-270%20passed-brightgreen.svg)](tests/)
[![GitHub Actions CI](https://github.com/Hosamalbushri/laraseed-package-generator/actions/workflows/ci.yml/badge.svg)](https://github.com/Hosamalbushri/laraseed-package-generator/actions)

An enterprise-grade modular scaffolding and lifecycle management engine for Laravel 11 and Laravel 12. Generates fully isolated, self-contained packages with domain models, contracts, Concord proxies, HTTP middleware, mail, notifications, admin panels, web capabilities, and transactional filesystem operations.

---

## Features

- **Dual Architecture Scaffolding:** Create Concord modular packages (default) or plain PSR-4 libraries (`--plain`).
- **Atomic Model & Proxy Composition:** Scaffold Eloquent Model, Contract interface, and Concord ModelProxy atomically via `laraseed:make-model <pkg> <model> --contract --proxy`.
- **Presentation & Messaging Generators:** Scaffold standard HTTP Controllers (`--api`), Middleware, Mailables (HTML/Markdown views), and Multi-Channel Notifications (`--broadcast`, `--database`, `--queued`).
- **Extensible Web Capability:** Scaffolds Vite and Tailwind-driven frontend templates with strict Content Security Policy (CSP) compliance and pluggable template registration.
- **Default Web Package Manager:** Select and mount any enabled Web package directly at root `/` (`laraseed:web-default`) with zero HTTP redirects.
- **Enterprise Concurrency & Safety:** Advisory file locking ([`PackageLock`](src/Support/PackageLock.php)), atomic filesystem transactions ([`FilesystemTransaction`](src/Generators/FilesystemTransaction.php)), and path containment ([`PathGuard`](src/Support/PathGuard.php)).
- **Seamless Bootstrap:** Dynamic PSR-4 ClassLoader bridge eliminates Catch-22 bootstrap deadlocks before `composer dump-autoload` is executed.

---

## Installation

### A. Before Packagist Publication (VCS Repository)

Add the Git repository to your Laravel application's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/Hosamalbushri/laraseed-package-generator.git"
    }
]
```

Then install development dependency:

```bash
composer require --dev laraseed/package-generator:dev-main
```

### B. After Packagist Publication

Once published on Packagist, install directly via Composer:

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

### 5. Manage Default Web Entry Point
```bash
# List discovered Web packages
php artisan laraseed:web-default --list

# Set billing package as root web entry point (serves directly at / with zero redirects)
php artisan laraseed:web-default billing

# Revert to core fallback landing page
php artisan laraseed:web-default --clear
```

---

## Documentation

Detailed documentation is available in the [`docs/`](docs/) directory:

- [Getting Started](docs/GETTING_STARTED.md) — Step-by-step setup and package creation workflow.
- [Command Reference](docs/COMMAND_REFERENCE.md) — Comprehensive guide to all 22 Artisan commands and options.
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

In a standalone checkout of this repository:

```bash
composer install
vendor/bin/phpunit
```

Or within a Laravel host application:

```bash
php artisan test
```

---

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details on code contributions, standards, and test execution.

---

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
