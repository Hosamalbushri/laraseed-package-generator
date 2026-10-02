# Contributing to Laraseed Package Generator

Thank you for your interest in contributing to `laraseed/package-generator`! We welcome bug reports, feature requests, documentation improvements, and pull requests.

---

## Code of Conduct

Please be respectful, collaborative, and constructive when participating in issues and discussions.

---

## Development Setup

1. **Fork and clone the repository:**
   ```bash
   git clone https://github.com/Hosamalbushri/laraseed-package-generator.git
   cd laraseed-package-generator
   ```

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Run the test suite:**
   ```bash
   vendor/bin/phpunit
   ```

4. **Validate composer configuration:**
   ```bash
   composer validate --strict
   ```

---

## Pull Request Guidelines

- Ensure all existing and new tests pass with `vendor/bin/phpunit`.
- Follow PSR-12 coding standards.
- Add comprehensive test cases for any new generator commands, options, or bug fixes.
- Keep commits focused and provide clear commit messages.
- Update documentation in `docs/` and `CHANGELOG.md` where appropriate.

---

## Reporting Issues & Security Concerns

- **Bug Reports & Feature Requests:** Open an issue on [GitHub Issues](https://github.com/Hosamalbushri/laraseed-package-generator/issues).
- **Security Vulnerabilities:** Follow the instructions in [SECURITY.md](SECURITY.md).
