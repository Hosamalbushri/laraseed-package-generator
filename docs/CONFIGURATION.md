# Configuration Guide

Laraseed Package Generator supports both environment variable configuration and published configuration options.

---

## Configuration File

The default package configuration file is located at `config/package-generator.php`:

```php
return [
    /*
    |--------------------------------------------------------------------------
    | Packages Storage Path
    |--------------------------------------------------------------------------
    | Root directory for generated optional packages relative to base_path().
    */
    'packages_path' => env('LARASEED_PACKAGES_PATH', 'packages'),

    /*
    |--------------------------------------------------------------------------
    | Advisory Concurrency Locking
    |--------------------------------------------------------------------------
    | Cross-process concurrency locking settings for generation plans.
    */
    'locking' => [
        'enabled' => true,
        'timeout' => 5.0,
        'locks_directory' => storage_path('framework/locks'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Web Template Registrations
    |--------------------------------------------------------------------------
    | Pluggable custom templates for make-web.
    */
    'web_templates' => [
        // 'custom-template' => [
        //     'label' => 'Custom Starter',
        //     'description' => 'Custom Blade template',
        //     'path' => base_path('stubs/templates/custom'),
        // ],
    ],
];
```

---

## Environment Variables

| Variable | Default | Description |
| :--- | :--- | :--- |
| `LARASEED_OPTIONAL_PACKAGES` | `""` | Comma-separated list of enabled optional package identifiers. |
| `LARASEED_PACKAGES_PATH` | `"packages"` | Base directory for generated packages. |
| `LARASEED_DEFAULT_WEB_PACKAGE` | `null` | Key of the enabled optional package to serve as the default web entry point (`/`). |

