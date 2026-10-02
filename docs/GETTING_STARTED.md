# Getting Started with Laraseed Package Generator

This guide walks you through setting up and using the Laraseed Package Generator in a Laravel project.

---

## 1. Prerequisites

- PHP `>= 8.3` (tested with PHP 8.4)
- Laravel `>= 12.0`
- Composer 2.x

---

## 2. Installation

Add `laraseed/package-generator` as a development requirement:

```bash
composer require --dev laraseed/package-generator
```

Verify that the commands are registered:

```bash
php artisan list laraseed
```

---

## 3. Creating Your First Package

### Concord Modular Package
```bash
php artisan laraseed:make-package Acme/Billing
```
This generates:
- `packages/Acme/Billing/composer.json`
- `packages/Acme/Billing/src/Providers/BillingServiceProvider.php`
- Directory structure for Contracts, Database, Events, Http, Models, and Repositories.

### Plain PSR-4 Library
```bash
php artisan laraseed:make-package Acme/Helper --plain
```
This generates a minimalist library structure with clean Composer autoloading.

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

---

## 5. Activating the Package

Enable the package in your `.env` configuration:

```dotenv
LARASEED_OPTIONAL_PACKAGES="billing"
```

Verify loaded packages:

```bash
php artisan laraseed:packages
```
