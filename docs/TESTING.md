# Testing Guide

Laraseed Package Generator includes a comprehensive automated test suite consisting of **270 feature tests and 1,680 assertions**.

---

## Test Organization

Package tests are organized within `tests/Feature/`:

```text
tests/
├── Feature/
│   ├── CleanInstallationVerificationTest.php
│   ├── ConcurrentGenerationTest.php
│   ├── DefaultWebPackageManagementTest.php
│   ├── MailGeneratorTest.php
│   ├── MiddlewareGeneratorTest.php
│   ├── NotificationGeneratorTest.php
│   ├── PackageDiscoveryAndBootstrapTest.php
│   ├── PackageGeneratorContainmentAndTransactionTest.php
│   ├── PackageGeneratorTest.php
│   ├── PlainPackageGeneratorTest.php
│   ├── ProxyGeneratorTest.php
│   ├── ReleaseReadinessAuditTest.php
│   ├── RootMountedDefaultWebPackageTest.php
│   ├── RootRoutingDeterminismAndProductionVerificationTest.php
│   ├── WebPackageGeneratorTest.php
│   ├── WebPackageStrictCspAndSecurityTest.php
│   └── WebTemplateRegistryTest.php
├── TestCase.php
└── helpers.php
```

---

## Running Package Tests

### 1. Standalone Package PHPUnit Execution
From the root of this package repository:

```bash
composer install
vendor/bin/phpunit
```

### 2. Running Specific Test Suites
```bash
# Run concurrency exclusion tests
vendor/bin/phpunit tests/Feature/ConcurrentGenerationTest.php

# Run default web package management tests
vendor/bin/phpunit tests/Feature/DefaultWebPackageManagementTest.php

# Run root-mounted routing verification tests
vendor/bin/phpunit tests/Feature/RootMountedDefaultWebPackageTest.php
```

### 3. Running Inside a Laravel Host Application
When developing within a Laravel host application:

```bash
php artisan test
```
