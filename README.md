# MonkeysLegion-Poly-Syntax

**Lightweight, zero-dependency PHP 8.4+ library** for high-performance, bidirectional transformations between modern data representation formats. Built for AI pipelines where **every token counts**.

[![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/releases/8.4/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![CS: PSR-12](https://img.shields.io/badge/Code%20Style-PSR--12-ff69b4)](https://www.php-fig.org/psr/psr-12/)
[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-Level%208-brightgreen)](https://phpstan.org/)

---

## 🌟 Why Poly-Syntax?

In modern AI workflows, the format you choose for structured data directly affects **cost and performance**:

| Format | ~Tokens per 1KB | Best For |
|--------|----------------:|----------|
| JSON   | ~290 tokens     | API compatibility |
| YAML   | ~180 tokens     | ⚡ **Lowest token cost** for LLM prompts |
| TOML   | ~200 tokens     | Leaner alternative to JSON |
| XML    | ~310 tokens     | Legacy/document interoperability |
| CSV    | ~160 tokens     | ⚡ **Minimal overhead** for tabular data |

**Poly-Syntax** lets you pick the optimal format for each stage of your AI pipeline — encode your source data in the most token-efficient format before sending it to an LLM, then decode the response back to your preferred working format.

---

## ✨ Features

- **🚀 Zero runtime dependencies** — only requires PHP 8.4+ and its bundled extensions
- **🔄 Bidirectional conversion** — transform between any supported format pair
- **📦 Driver architecture** — pluggable, extensible, easy to add custom formats
- **⚡ Token optimization** — compute token/character savings when switching formats
- **🧩 PSR-4 autoloading** with strict PSR-12 coding standards
- **🔒 XXE-safe XML** parsing by default
- **🧪 PHPStan Level 8** — maximum static analysis rigor
- **🆕 PHP 8.4 native** — enums, property hooks, `#[Override]`, `array_find`, and more

---

## 📦 Installation

```bash
composer require monkeyscloud/monkeyslegion-poly-syntax
```

> **Requires PHP 8.4+.** No external extensions needed — JSON, XML, and CSV drivers work out of the box.
>
> For **YAML** and **TOML** support, see [Optional Drivers](#optional-drivers) below.

---

## 🚀 Quick Start

```php
use Monkeyslegion\PolySyntax\Transformer;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Driver\JsonDriver;
use Monkeyslegion\PolySyntax\Driver\YamlDriver;

// Create a transformer and register drivers
$transformer = new Transformer();
$transformer->registerDriver(new JsonDriver());

// JSON → Array
$data = $transformer->decode('{"hello":"world","count":42}', Syntax::JSON);

// Array → YAML (if YamlDriver is available)
$transformer->registerDriver(new YamlDriver());
$yaml = $transformer->encode($data, Syntax::YAML);

// Or transform directly between formats
$yaml = $transformer->transform(
    '{"hello":"world","count":42}',
    Syntax::JSON,
    Syntax::YAML
);
```

---

## 📋 Supported Formats

| Format | Status | Driver | Backend |
| -------- | -------- | -------- | --------- |
| **JSON** | ✅ Built-in | `JsonDriver` | `json_decode` / `json_encode` (native) |
| **XML** | ✅ Built-in | `XmlDriver` | `SimpleXML` + `DOMDocument` (native) |
| **CSV** | ✅ Built-in | `CsvDriver` | `fgetcsv` / `fputcsv` (native) |
| **YAML** | ⚡ Optional | `YamlDriver` | Internal lightweight parser or `ext-yaml` |
| **TOML** | ⚡ Optional | `TomlDriver` | Internal lightweight parser |

### Optional Drivers

YAML and TOML drivers are **included in the package** with lightweight native parsers. For advanced parsing needs (edge cases, large files), you can optionally install:

```bash
composer require symfony/yaml    # For YamlDriver advanced mode
composer require yosymfony/toml   # For TomlDriver advanced mode
```

The drivers auto-detect the best available backend at runtime.

---

## 🏗️ Architecture

```
┌─────────────┐     ┌──────────────────┐     ┌──────────────┐
│ Input       │ ──▶ │ Input Driver     │ ──▶ │ Native PHP   │
│ Payload     │     │ decode()         │     │ Array        │
└─────────────┘     └──────────────────┘     └──────┬───────┘
                                                     │
┌──────────────┐     ┌──────────────────┐            │
│ Output       │ ◀── │ Output Driver    │ ◀──────────┘
│ Payload      │     │ encode()         │
└──────────────┘     └──────────────────┘
```

### Core Components

| Component | Description |
| ----------- | ------------- |
| `Transformer` | Facade for format routing and transformation orchestration |
| `DriverInterface` | Contract every driver must implement (`decode()` + `encode()`) |
| `Syntax` enum | Strongly-typed format identifiers |
| `Exception\*` | Domain-specific exception hierarchy |

---

## 🧪 Token Optimization

```php
use Monkeyslegion\PolySyntax\Util\TokenOptimizer;

$optimizer = new TokenOptimizer();
$savings = $optimizer->estimateSavings($jsonPayload, Syntax::JSON, Syntax::YAML);

printf(
    "Switch from %s to %s saves ~%d tokens (%.1f%%)",
    $savings->from()->name,
    $savings->to()->name,
    $savings->tokensSaved(),
    $savings->percentageSaved()
);
```

---

## 🧑‍💻 Development

```bash
git clone https://github.com/monkeyscloud/monkeyslegion-poly-syntax.git
cd monkeyslegion-poly-syntax
composer install

# Run all quality checks
composer check

# Or individual checks
composer test        # PHPUnit tests
composer analyse     # PHPStan Level 8
composer cs-check    # PSR-12 code style
```

---

## 🤝 Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for detailed guidelines.

### Quick summary

- **PHP 8.4+ only** — embrace modern features
- **Zero runtime dependencies** — native PHP only for core
- **PSR-12** coding style + **PHPStan Level 8** static analysis
- **Tests required** — PHPUnit round-trip tests for every driver
- **One feature per PR** — keep changes focused

---

## 📄 License

This project is licensed under the MIT License — see [LICENSE](LICENSE) for details.

---

## 🔒 Security

Found a vulnerability? Please see [SECURITY.md](SECURITY.md) for our disclosure process. **Do not** open public issues for security reports.

---

## 🛣️ Roadmap

See [ROADMAP.md](ROADMAP.md) for planned features and milestones.

---

*Built for high-efficiency AI engineering and modern PHP workflows.*
