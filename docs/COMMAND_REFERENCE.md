# Laraseed Package Generator V4 — Command Reference

Complete reference guide for all Artisan CLI commands provided by `laraseed/package-generator`.

---

## Command Summary Table

| Command | Description | Key Options |
| :--- | :--- | :--- |
| [`laraseed:make-package`](#laraseedmake-package) | Scaffold a new package (Concord modular or plain library) | `--plain`, `--dry-run`, `--force` |
| [`laraseed:make-model`](#laraseedmake-model) | Generate Eloquent model with optional Contract & Proxy | `--contract`, `--proxy`, `--dry-run`, `--force` |
| [`laraseed:make-contract`](#laraseedmake-contract) | Generate a standalone PHP interface / Contract | `--dry-run`, `--force` |
| [`laraseed:make-proxy`](#laraseedmake-proxy) | Generate a Concord ModelProxy class | `--dry-run`, `--force` |
| [`laraseed:make-controller`](#laraseedmake-controller) | Generate an HTTP Controller (API or standard) | `--api`, `--model=`, `--dry-run`, `--force` |
| [`laraseed:make-repository`](#laraseedmake-repository) | Generate a Prettus-compatible Repository | `--model=`, `--dry-run`, `--force` |
| [`laraseed:make-request`](#laraseedmake-request) | Generate a FormRequest validation class | `--dry-run`, `--force` |
| [`laraseed:make-event`](#laraseedmake-event) | Generate a Dispatchable Event class | `--dry-run`, `--force` |
| [`laraseed:make-listener`](#laraseedmake-listener) | Generate an Event Listener class | `--event=`, `--dry-run`, `--force` |
| [`laraseed:make-migration`](#laraseedmake-migration) | Generate a database migration file | `--dry-run`, `--force` |
| [`laraseed:make-seeder`](#laraseedmake-seeder) | Generate a Database Seeder class | `--dry-run`, `--force` |
| [`laraseed:make-middleware`](#laraseedmake-middleware) | Generate an HTTP Middleware class | `--dry-run`, `--force` |
| [`laraseed:make-mail`](#laraseedmake-mail) | Generate a Mailable and Blade email template | `--view=`, `--markdown=`, `--queued`, `--dry-run`, `--force` |
| [`laraseed:make-notification`](#laraseedmake-notification) | Generate a multi-channel Notification class | `--channels=`, `--database`, `--broadcast`, `--queued`, `--dry-run`, `--force` |
| [`laraseed:make-admin`](#laraseedmake-admin) | Scaffold the Admin capability module | `--dry-run`, `--force` |
| [`laraseed:make-web`](#laraseedmake-web) | Scaffold the Web capability module | `--template=`, `--dry-run`, `--force` |
| [`laraseed:make-command`](#laraseedmake-command) | Generate a custom Artisan Console Command | `--command=`, `--dry-run`, `--force` |
| [`laraseed:make-datagrid`](#laraseedmake-datagrid) | Generate a Webkul DataGrid class | `--model=`, `--dry-run`, `--force` |
| [`laraseed:make-provider`](#laraseedmake-provider) | Generate a secondary ServiceProvider | `--dry-run`, `--force` |
| [`laraseed:make-module-provider`](#laraseedmake-module-provider) | Generate or regenerate Concord Module Provider | `--dry-run`, `--force` |
| [`laraseed:make-route`](#laraseedmake-route) | Generate package-owned route file | `--type=`, `--dry-run`, `--force` |
| [`laraseed:web-default`](#laraseedweb-default) | Manage, inspect, select, or clear the default public Web package | `--list`, `--status`, `--clear`, `--dry-run` |
| [`laraseed:packages`](#laraseedpackages) | Display installed and enabled optional packages | *(Read-only)* |
| [`laraseed:version`](#laraseedversion) | Display currently installed Laraseed version | *(Read-only)* |

---

## Detailed Command Specifications

### `laraseed:make-package`
Scaffolds a new package inside `packages/{Vendor}/{Package}` adhering to isolation boundaries.

```bash
php artisan laraseed:make-package <name> [--plain] [--dry-run] [--force]
```
- **Arguments:**
  - `name`: Package identifier in `Vendor/Package` format (e.g. `Acme/Billing`).
- **Options:**
  - `--plain`: Generates a minimal PSR-4 library without Concord module metadata or presentation directories.
  - `--dry-run`: Simulates the file operations without creating any directories or files.
  - `--force`: Overwrites existing files if the target directory already exists.

---

### `laraseed:make-model`
Generates an Eloquent model within `src/Models/`, with optional atomic creation of Contracts and Concord ModelProxies.

```bash
php artisan laraseed:make-model <package> <name> [--contract] [--proxy] [--dry-run] [--force]
```
- **Arguments:**
  - `package`: Target package identifier (e.g. `Acme/Billing`).
  - `name`: Model class name (e.g. `Invoice`).
- **Options:**
  - `--contract`: Generates companion Contract interface in `src/Contracts/{Name}.php` and binds it in the Model class.
  - `--proxy`: Atomically generates Contract in `src/Contracts/{Name}.php` and Concord ModelProxy in `src/Models/{Name}Proxy.php`.

---

### `laraseed:make-contract`
Generates a standalone PHP Interface within `src/Contracts/`.

```bash
php artisan laraseed:make-contract <package> <name> [--dry-run] [--force]
```
- **Arguments:**
  - `package`: Target package identifier.
  - `name`: Interface name (e.g. `CustomerContract` or `Customer`).

---

### `laraseed:make-proxy`
Generates a Concord ModelProxy class in `src/Models/{Name}Proxy.php`.

```bash
php artisan laraseed:make-proxy <package> <name> [--dry-run] [--force]
```
- **Arguments:**
  - `package`: Target package identifier.
  - `name`: Target model name (e.g. `Invoice` or `InvoiceProxy`).

---

### `laraseed:make-controller`
Generates an HTTP Controller within `src/Http/Controllers/`.

```bash
php artisan laraseed:make-controller <package> <name> [--api] [--model=] [--dry-run] [--force]
```
- **Arguments:**
  - `package`: Target package identifier.
  - `name`: Controller name (e.g. `InvoiceController`).
- **Options:**
  - `--api`: Generates an API controller located in `src/Http/Controllers/Api/{Name}.php` with standard JSON responses.
  - `--model=`: Optional associated model name for docstrings and typehints.

---

### `laraseed:make-repository`
Generates a Prettus-compatible Repository within `src/Repositories/`.

```bash
php artisan laraseed:make-repository <package> <name> [--model=] [--dry-run] [--force]
```
- **Arguments:**
  - `package`: Target package identifier.
  - `name`: Repository class name (e.g. `InvoiceRepository`).
- **Options:**
  - `--model=`: Associated Model name for `model()` binding.

---

### `laraseed:make-request`
Generates a FormRequest validation class within `src/Http/Requests/`.

```bash
php artisan laraseed:make-request <package> <name> [--dry-run] [--force]
```

---

### `laraseed:make-event` & `laraseed:make-listener`
Generates Dispatchable Events and Event Listeners.

```bash
# Event
php artisan laraseed:make-event <package> <name> [--dry-run] [--force]

# Listener
php artisan laraseed:make-listener <package> <name> [--event=] [--dry-run] [--force]
```
- **Options (`make-listener`):**
  - `--event=`: Target event class name to import and typehint in `handle(Event $event)`.

---

### `laraseed:make-migration` & `laraseed:make-seeder`
Generates timestamped database migrations and seeders.

```bash
# Migration
php artisan laraseed:make-migration <package> <name> [--dry-run] [--force]

# Seeder
php artisan laraseed:make-seeder <package> <name> [--dry-run] [--force]
```

---

### `laraseed:make-middleware`
Generates an HTTP Middleware class within `src/Http/Middleware/`.

```bash
php artisan laraseed:make-middleware <package> <name> [--dry-run] [--force]
```
- **Example:**
  ```bash
  php artisan laraseed:make-middleware Acme/Billing VerifyTenantSignature
  ```

---

### `laraseed:make-mail`
Generates a Mailable class in `src/Mail/` and companion Blade views in `src/Resources/views/`.

```bash
php artisan laraseed:make-mail <package> <name> [--view=] [--markdown=] [--queued] [--dry-run] [--force]
```
- **Options:**
  - `--view=`: Relative view path for HTML email (e.g. `emails.invoice`).
  - `--markdown=`: Relative view path for Markdown email (e.g. `emails.order_markdown`).
  - `--queued`: Implements `ShouldQueue` on the Mailable class.

---

### `laraseed:make-notification`
Generates a Notification class in `src/Notifications/` supporting Mail, Database, and Broadcast delivery channels.

```bash
php artisan laraseed:make-notification <package> <name> [--channels=] [--database] [--broadcast] [--queued] [--dry-run] [--force]
```
- **Options:**
  - `--channels=`: Comma-separated list of channels (e.g. `mail,database,broadcast`).
  - `--database`: Convenience shortcut to include database channel.
  - `--broadcast`: Convenience shortcut to include broadcast channel.
  - `--queued`: Implements `ShouldQueue` on the Notification class.
- **Example:**
  ```bash
  php artisan laraseed:make-notification Acme/Billing InvoiceOverdue --broadcast --database --queued
  ```

---

### `laraseed:make-admin`
Generates the Admin capability module in `src/Admin/`, registers menu items, ACL permissions, DataGrid, views, and routes, and updates `composer.json` capability registry.

```bash
php artisan laraseed:make-admin <package> [--dry-run] [--force]
```

---

### `laraseed:make-web`
Generates the Web capability module in `src/Web/`, registers public views, layout, Tailwind configurations, and routes, and updates `composer.json` capability registry.

```bash
php artisan laraseed:make-web <package> [--template=] [--dry-run] [--force]
```
- **Options:**
  - `--template=`: Select template from registry (`starter` is default; custom templates registered in `config/laraseed.php` are supported).

---

### `laraseed:web-default`
Manages, inspects, selects, or clears the default public Web package providing the root `/` entry point.

```bash
php artisan laraseed:web-default [package] [--list] [--status] [--clear] [--dry-run] [--force]
```
- **Arguments:**
  - `package`: (Optional) The package identifier to select as default (must be enabled in `LARASEED_OPTIONAL_PACKAGES` and declare Web capability).
- **Options:**
  - `--list`: Displays a formatted table of all discovered Web packages, their activation status, registered entry route, and whether they are currently default.
  - `--status`: Inspects the currently configured default package, route resolution, target URL, and operational health.
  - `--clear`: Clears the default Web package selection so `/` safely serves the built-in fallback view.
  - `--dry-run`: Simulates validation and environment persistence without modifying disk.
  - `--force`: Bypasses non-fatal diagnostic warnings during selection.
- **Examples:**
  ```bash
  # List all discovered Web-capable packages
  php artisan laraseed:web-default --list

  # Check status of the current default selection
  php artisan laraseed:web-default --status

  # Set "store" package as default
  php artisan laraseed:web-default store

  # Clear default selection
  php artisan laraseed:web-default --clear
  ```

---

### Utility Commands

```bash
# Inspect all optional packages and activation status
php artisan laraseed:packages

# Display Laraseed version
php artisan laraseed:version
```
