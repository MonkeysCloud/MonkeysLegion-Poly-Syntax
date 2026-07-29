# Contributing to MonkeysLegion-Poly-Syntax

First off, thank you for considering contributing! 🎉

This is a community-driven project and we welcome all forms of contributions — whether it's a new driver, a bug fix, documentation improvement, or a feature request.

## 📋 Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Environment](#development-environment)
- [PHP Version & Standards](#php-version--standards)
- [Architecture Overview](#architecture-overview)
- [Adding a New Driver](#adding-a-new-driver)
- [Testing](#testing)
- [Static Analysis](#static-analysis)
- [Code Style](#code-style)
- [Pull Request Process](#pull-request-process)
- [Driver Design Principles](#driver-design-principles)
- [Questions?](#questions)

---

## Code of Conduct

This project and everyone participating in it is governed by the [Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold this code. Please report unacceptable behavior by opening a [GitHub Issue](https://github.com/monkeyscloud/monkeyslegion-poly-syntax/issues).

---

## Getting Started

1. **Fork** the repository on GitHub.
2. **Clone** your fork locally:

   ```bash
   git clone https://github.com/your-username/monkeyslegion-poly-syntax.git
   cd monkeyslegion-poly-syntax
   ```

3. **Install dependencies**:

   ```bash
   composer install
   ```

4. **Create a branch** for your changes:

   ```bash
   git checkout -b feature/my-feature
   ```

---

## Development Environment

- **PHP 8.4+** is required — the package uses PHP 8.4 features extensively (property hooks, asymmetric visibility, `#[Override]`, `array_find`, etc.)
- **Composer 2.x** is required
- Optionally install `ext-yaml` for YAML driver testing, though we provide a lightweight fallback parser

### Quick validation

Run all quality checks with a single command:

```bash
composer check
```

This runs:

1. `composer cs-check` — PSR-12 code style
2. `composer analyse` — PHPStan Level 8 static analysis
3. `composer test` — PHPUnit test suite

---

## PHP Version & Standards

| Requirement | Standard |
| ------------- | ---------- |
| **PHP Version** | 8.4+ only |
| **Code Style** | [PSR-12](https://www.php-fig.org/psr/psr-12/) |
| **Autoloading** | [PSR-4](https://www.php-fig.org/psr/psr-4/) |
| **Static Analysis** | PHPStan Level 8 |
| **Testing** | PHPUnit 11.x |
| **Type System** | Strict types everywhere, native PHP 8.4 features preferred |

### PHP 8.4 Features We Embrace

- `enum` (backed) for strong-typed format references
- `readonly` classes for immutable value objects
- Property hooks (`get`, `set`) for computed properties
- Asymmetric visibility for controlled API surfaces
- `#[Override]` attribute for interface implementations
- `array_find()`, `array_any()`, `array_all()` for clean array operations
- `mb_trim()`, `mb_ucfirst()` for robust string handling
- `new` in initializer expressions
- `<?=` with arbitrary expressions

---

## Architecture Overview

```
[ Input Payload ]  ──▶ ( Input Driver: decode() )  ──▶ [ Native PHP Array ]
                                                              │
[ Output Payload ] ◀── ( Output Driver: encode() ) ◀── [ Native PHP Array ]
```

The core transformer is **dependency-free** — it only requires PHP 8.4+ and its bundled extensions.

- All drivers implement `Monkeyslegion\PolySyntax\Contract\DriverInterface`
- The `Transformer` class manages driver registration and transformation orchestration
- Custom drivers can be registered at runtime without modifying the core

---

## Adding a New Driver

### 1. Implement the interface

Create a new class in `src/Driver/` that implements `DriverInterface`:

```php
<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Driver;

use Monkeyslegion\PolySyntax\Contract\DriverInterface;
use Monkeyslegion\PolySyntax\Enum\Syntax;

final class CustomDriver implements DriverInterface
{
    public function supportedSyntax(): Syntax
    {
        return Syntax::CUSTOM;
    }

    public function decode(string $input): array
    {
        // Parse $input into a PHP array
    }

    public function encode(array $data): string
    {
        // Serialize array into the target format
    }
}
```

### 2. Write tests

Create `tests/Driver/CustomDriverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Tests\Driver;

use Monkeyslegion\PolySyntax\Driver\CustomDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CustomDriverTest extends TestCase
{
    #[Test]
    public function itCanRoundTripData(): void
    {
        $driver = new CustomDriver();
        $original = '...';
        $decoded = $driver->decode($original);
        $encoded = $driver->encode($decoded);

        self::assertSame($original, $encoded);
    }
}
```

### 3. Zero-Dependency Policy

New drivers **must not introduce runtime dependencies**. Use only:

- PHP 8.4+ built-in functions and classes
- Bundled extensions (`json`, `simplexml`, `libxml`, `mbstring`)
- Native PHP array operations

If an external library is unavoidable, it must be:

- Added to `composer.json` under `suggest` (not `require`)
- Checked at runtime with `class_exists()` before use
- Documented as optional with a graceful fallback

---

## Testing

- All tests must pass before a PR is merged
- Run tests with: `composer test`
- New drivers must include round-trip tests (decode → encode → compare)
- Edge cases are highly valued: empty inputs, malformed data, deeply nested structures, Unicode content, large payloads
- Coverage reports: `composer test:coverage` (output in `.build/coverage/`)

### Test conventions

- Test classes use PHPUnit 11.x attributes (`#[Test]`, `#[DataProvider]`)
- Test files go in `tests/Driver/` for drivers, `tests/` for core
- Namespace: `Monkeyslegion\PolySyntax\Tests\*`
- Filename: `*Test.php`

---

## Static Analysis

We enforce **PHPStan Level 8** — no exceptions. Run before submitting:

```bash
composer analyse
```

This ensures:

- Strict return type declarations
- Proper handling of nullable values
- No unused or uninitialized properties
- Template/generic array shape verification

---

## Code Style

We follow **PSR-12** with the following additional conventions:

- **Strict types**: Every file must start with `declare(strict_types=1);`
- **Final classes**: Driver implementations should be `final`
- **No `echo` or `var_dump`** in library code
- **Named arguments** preferred for clarity in method calls with 3+ parameters
- **`match` expressions** preferred over `switch` statements

Auto-fix code style with:

```bash
composer cs-fix
```

---

## Pull Request Process

1. **Before submitting**, run `composer check` and ensure everything passes.
2. **Keep PRs focused** — one feature or fix per PR. Large PRs are harder to review.
3. **Update documentation** — if you change behavior, update the README and relevant docblocks.
4. **Add tests** — new features require tests. Bug fixes require a regression test.
5. **Update ROADMAP.md** — if your PR completes a roadmap item, mark it as `[x]`.
6. **Describe your changes** — provide a clear summary and motivation in the PR description.

### PR checklist

- [ ] I have run `composer check` and all checks pass
- [ ] I have added/updated tests to cover my changes
- [ ] I have updated documentation (README, docblocks) as needed
- [ ] My code follows PSR-12 and project conventions
- [ ] I have not introduced new runtime dependencies

---

## Driver Design Principles

### ✅ Do

- Keep drivers **immutable** and **stateless** where possible
- Validate input early and throw domain-specific exceptions
- Handle edge cases (empty input, null values, Unicode)
- Document format-specific behaviors and limitations
- Use `#[Override]` attribute on interface methods

### ❌ Don't

- Add runtime dependencies — use `suggest` if absolutely needed
- Swallow exceptions — let `DecodeException` / `EncodeException` propagate
- Use deprecated PHP features
- Assume input is well-formed — always validate
- Use dynamic properties or loose typing

### Error handling

- Input failures → `Monkeyslegion\PolySyntax\Exception\DecodeException`
- Output failures → `Monkeyslegion\PolySyntax\Exception\EncodeException`
- Configuration/routing errors → `Monkeyslegion\PolySyntax\Exception\TransformerException`
- Unsupported format → `Monkeyslegion\PolySyntax\Exception\UnsupportedSyntaxException`

---

## Questions?

- Open a [Discussion](https://github.com/monkeyscloud/monkeyslegion-poly-syntax/discussions) for questions
- Open an [Issue](https://github.com/monkeyscloud/monkeyslegion-poly-syntax/issues) for bug reports or feature requests
- Check the [ROADMAP](ROADMAP.md) for planned features

Thank you for contributing! 🚀
