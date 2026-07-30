# MonkeysLegion-Poly-Syntax — Agent Instructions

## Project

Lightweight, zero-dependency PHP 8.4+ library for bidirectional data format transformations (JSON, XML, CSV, YAML, TOML). Namespace: `Monkeyslegion\PolySyntax\`.

## Must Follow

### Code Style

- **PSR-12** enforced by PHP_CodeSniffer
- **Strict types**: every file starts with `declare(strict_types=1);`
- **Native function calls** prefixed with `\` (e.g. `\strlen`, `\fopen`)
- **Final classes** for driver implementations
- **`#[Override]`** attribute on all interface/abstract method implementations
- **Named arguments** in calls with 3+ parameters
- **`match` expressions** preferred over `switch`
- No `echo` or `var_dump` in library code

### PHP 8.4

- `readonly` classes for immutable value objects
- Property hooks (`get`, `set`) where appropriate
- `array_find()`, `array_any()`, `array_all()` for clean array operations
- `mb_trim()`, `mb_ucfirst()` for robust string handling
- `new` in initializer expressions

### Architecture

- **Zero runtime dependencies** — only PHP 8.4+ and bundled extensions
- **`Transformer` is the single entry point** for all format transformations
- **Every driver implements `DriverInterface`** with `decode()` + `encode()` + `supportedSyntax()`
- **Custom drivers** can be registered at runtime without modifying core
- **External libraries** must be optional (`suggest` in composer.json), checked at runtime with `class_exists()`

### Testing

- **PHPUnit 11.x** with `#[Test]` and `#[DataProvider]` attributes
- **Round-trip tests** (decode → encode → compare) for every driver
- **Edge cases**: empty inputs, malformed data, deeply nested structures, Unicode, large payloads
- Test files in `tests/Driver/` for drivers, `tests/` for core
- Namespace: `Monkeyslegion\PolySyntax\Tests\*`, filename: `*Test.php`

### Quality Gates

Run `composer quality-report` for the full suite:

- `cs-check` — PSR-12, zero violations
- `analyse` — PHPStan Level 9, zero errors
- `test` — PHPUnit 11.x, 217+ tests, all pass
- `infection` — MSI ≥ 90%, Covered MSI ≥ 95% (config: `infection.json.dist`)

### Commit Style

- No emojis in commit messages
- Present tense imperative: "Add X", "Fix Y", "Refactor Z"
- One logical change per commit

## Key Files

- `src/Transformer.php` — main facade for format transformation
- `src/Contract/DriverInterface.php` — driver contract
- `src/Enum/Syntax.php` — strongly-typed format identifiers
- `src/Driver/*.php` — format drivers (JSON, XML, CSV, YAML, TOML)
- `src/Exception/*.php` — domain exception hierarchy
- `infection.json.dist` — mutation testing config (MSI ≥ 90%)
- `phpstan.neon.dist` — static analysis Level 9

## Quick Reference

- Run all fast checks: `composer check` (cs + phpstan + test)
- Full report (with mutation): `composer quality-report`
- Mutation testing only: `composer infection`
- Coverage report: `composer test:coverage`
- Auto-fix code style: `composer cs-fix`
