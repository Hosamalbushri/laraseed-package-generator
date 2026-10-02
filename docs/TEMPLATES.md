# Web Templates Guide

Laraseed Package Generator V4 includes an extensible template catalog system via [`WebTemplateCatalog`](../src/Templates/WebTemplateCatalog.php).

---

## Default Starter Template

The built-in `starter` template is located in `stubs/templates/starter/` and provides:
- Clean Vanilla JavaScript frontend kernel.
- Tailwind CSS styling and configuration.
- Responsive Blade navigation and accessible modal components.
- Strict Content Security Policy (CSP) compliance (zero inline scripts or event handlers).
- Production Vite build configuration.

---

## Registering Custom Templates

You can register custom templates programmatically or via configuration:

### 1. Configuration Registration (`config/package-generator.php`)
```php
'web_templates' => [
    'portal' => [
        'label' => 'Customer Portal',
        'description' => 'Customer self-service portal template',
        'path' => base_path('stubs/templates/portal'),
    ],
],
```

### 2. Programmatic Registration
```php
use Laraseed\PackageGenerator\Templates\WebTemplateCatalog;

$catalog = app(WebTemplateCatalog::class);
$catalog->register('portal', [
    'label' => 'Customer Portal',
    'description' => 'Customer self-service portal template',
    'path' => '/absolute/path/to/portal/template',
]);
```

### 3. Usage
```bash
php artisan laraseed:make-web Acme/Billing --template=portal
```

---

## Template Security Requirements

- All template source paths must reside within authorized application or package directories.
- Stubs must not contain inline JavaScript event handlers (`onclick`, etc.) or unsanitized DOM sinks.
