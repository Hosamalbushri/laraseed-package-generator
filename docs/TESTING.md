# Testing Guide

Laraseed Package Generator includes a comprehensive automated test suite consisting of 225 feature tests and 1,314 assertions.

---

## Test Organization

Package tests are located in `packages/Laraseed/PackageGenerator/tests/`:

```text
tests/
├── Feature/
│   ├── ConcurrentGenerationTest.php
│   ├── MailGeneratorTest.php
│   ├── MiddlewareGeneratorTest.php
│   ├── NotificationGeneratorTest.php
│   ├── PackageDiscoveryAndBootstrapTest.php
│   ├── PackageGeneratorContainmentAndTransactionTest.php
│   ├── PackageGeneratorTest.php
│   ├── PlainPackageGeneratorTest.php
│   ├── ProxyGeneratorTest.php
│   ├── WebPackageGeneratorTest.php
│   ├── WebPackageStrictCspAndSecurityTest.php
│   └── WebTemplateRegistryTest.php
└── TestCase.php
```

---

## Running Package Tests

### Standalone PHPUnit Execution
Run tests directly using the package-level `phpunit.xml`:

```bash
./vendor/bin/phpunit -c packages/Laraseed/PackageGenerator/phpunit.xml
```

### Running Specific Test Suites
```bash
# Run concurrency tests
./vendor/bin/phpunit -c packages/Laraseed/PackageGenerator/phpunit.xml packages/Laraseed/PackageGenerator/tests/Feature/ConcurrentGenerationTest.php

# Run notification tests
./vendor/bin/phpunit -c packages/Laraseed/PackageGenerator/phpunit.xml packages/Laraseed/PackageGenerator/tests/Feature/NotificationGeneratorTest.php
```

### Full Application Test Runner
The host application automatically discovers all package tests via the `Packages` test suite:

```bash
php artisan test
```
