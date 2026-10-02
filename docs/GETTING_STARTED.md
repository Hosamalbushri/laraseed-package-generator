# Getting Started with Laraseed Package Generator

This guide walks you through installing and using the Laraseed Package Generator in any Laravel application.

---

## 1. Prerequisites

- **PHP:** `^8.2` (tested on PHP 8.2, 8.3, and 8.4)
- **Laravel Framework:** `^11.0` or `^12.0`
- **Composer:** 2.x

---

## 2. Installation

### A. Before Packagist Publication (VCS Repository)

Add the Git repository to your project's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/Hosamalbushri/laraseed-package-generator.git"
    }
]
```

Then install the package as a development dependency:

```bash
composer require --dev laraseed/package-generator:dev-main
```

### B. After Packagist Publication

Once published to Packagist:

```bash
composer require --dev laraseed/package-generator
```

Laravel's package auto-discovery will automatically register the service provider. Verify that commands are registered:

```bash
php artisan list laraseed
```

---

## 3. Creating Your First Package

### Concord Modular Package
```bash
php artisan laraseed:make-package Acme/Billing
```
This scaffolds:
- `packages/Acme/Billing/composer.json`
- `packages/Acme/Billing/src/Providers/BillingServiceProvider.php`
- Standard directory structure for Contracts, Database, Events, Http, Models, and Repositories.

### Plain PSR-4 Library
```bash
php artisan laraseed:make-package Acme/Helper --plain
```
This generates a minimalist library structure with clean Composer autoloading and standard service provider.

---

## 4. Scaffolding Domain Components

### Domain Model with Contract and Proxy
```bash
php artisan laraseed:make-model Acme/Billing Invoice --contract --proxy
```

### Database Migration and Seeder
```bash
php artisan laraseed:make-migration Acme/Billing create_invoices_table
php artisan laraseed:make-seeder Acme/Billing InvoiceDatabaseSeeder
```

### Presentation & Messaging
```bash
php artisan laraseed:make-controller Acme/Billing InvoiceController --api
php artisan laraseed:make-middleware Acme/Billing EnsureTenantValid
php artisan laraseed:make-mail Acme/Billing InvoiceReceipt --markdown=emails.receipt
php artisan laraseed:make-notification Acme/Billing InvoiceDue --database --broadcast --queued
```

### Admin & Web Capabilities
```bash
# Admin Panel Module (DataGrid, ACL, Menu, Routes)
php artisan laraseed:make-admin Acme/Billing

# Public Web Capability (Vite, Tailwind, Blade Views)
php artisan laraseed:make-web Acme/Billing --template=starter
```

---

## 5. Activating the Package & Default Web Selection

### Activate the Package in .env
```dotenv
LARASEED_OPTIONAL_PACKAGES="billing"
```

### Inspect Discovered and Enabled Packages
```bash
php artisan laraseed:packages
```

### Mount as Default Web Package (Root `/` Entry Point)
```bash
php artisan laraseed:web-default billing
```
This configures the package to serve directly at `/` with HTTP 200 OK and zero redirects.
