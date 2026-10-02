# Security Policy & Invariants

Laraseed Package Generator implements defense-in-depth controls across filesystem access, concurrency, template trust, and runtime execution.

---

## Security Invariants

### 1. Filesystem Containment (`PathGuard`)
- All target generation paths must reside strictly within authorized package boundaries.
- Input strings are validated to reject path traversal sequences (`../`, `..\`, null bytes `\0`).
- Symlinks pointing outside `packages/` fail realpath boundary containment checks and are ignored.

### 2. Content Security Policy (CSP) Compliance
- Generated frontend templates contain zero inline JavaScript handlers (`onclick`, `onchange`, etc.).
- Modal dialogs, theme switches, and navigation interactions utilize standard DOM event listeners registered through a compiled JavaScript kernel.
- Stylesheets enforce valid CSS MIME types and reject malicious injection payloads.

### 3. Concurrency Protection
- Concurrent generation processes acquire advisory file locks (`PackageLock`) to prevent race conditions and manifest corruption.
- Process locks record PID and timestamp metadata.

### 4. Transactional Rollback
- Mid-operation exceptions trigger immediate transactional rollback via [`FilesystemTransaction`](../src/Generators/FilesystemTransaction.php), restoring modified manifests and removing orphan files.
