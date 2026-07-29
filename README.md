# MonkeysLegion-Poly-Syntax

**Lightweight, zero-dependency PHP 8.4+ library** for high-performance, bidirectional transformations between modern data representation formats. Built for AI pipelines where **every token counts**.

[![PHP Version](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/releases/8.4/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![CS: PSR-12](https://img.shields.io/badge/Code%20Style-PSR--12-ff69b4)](https://www.php-fig.org/psr/psr-12/)
[![PHPStan Level 9](https://img.shields.io/badge/PHPStan-Level%209-brightgreen)](https://phpstan.org/)
[![Coverage](https://img.shields.io/badge/Coverage-93.7%25-success)](https://github.com/monkeyscloud/monkeyslegion-poly-syntax)
[![CI](https://github.com/monkeyscloud/monkeyslegion-poly-syntax/actions/workflows/ci.yml/badge.svg)](https://github.com/monkeyscloud/monkeyslegion-poly-syntax/actions/workflows/ci.yml)

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
- **🧪 PHPStan Level 9** — maximum static analysis rigor
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
use Monkeyslegion\PolySyntax\Driver\CsvDriver;
use Monkeyslegion\PolySyntax\Driver\XmlDriver;

// Create a transformer and register the built-in drivers
$transformer = new Transformer();
$transformer->registerDriver(new JsonDriver());
$transformer->registerDriver(new CsvDriver());
$transformer->registerDriver(new XmlDriver());

// JSON → Array
$data = $transformer->decode('{"hello":"world","count":42}', Syntax::JSON);
// $data = ['hello' => 'world', 'count' => 42]

// Array → CSV (single row from associative array)
$csv = $transformer->encode($data, Syntax::CSV);
// "hello,count\nworld,42"
// Note: multi-row data requires an array of associative arrays.

// Or transform directly between formats — decode then encode in one call
$xml = $transformer->transform(
    '{"hello":"world","count":42}',
    Syntax::JSON,
    Syntax::XML,
);
// <?xml version="1.0"?>\n<root><hello>world</hello><count>42</count></root>
```

---

## 📋 Supported Formats

| Format | Status | Driver | Backend |
|--------|--------|--------|---------|
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
|-----------|-------------|
| `Transformer` | Facade for format routing and transformation orchestration |
| `DriverInterface` | Contract every driver must implement (`decode()` + `encode()`) |
| `Syntax` enum | Strongly-typed format identifiers |
| `Exception\*` | Domain-specific exception hierarchy |

---

## 🧩 Driver Reference

### JsonDriver

A strict JSON driver using native `json_decode` / `json_encode` with sensible defaults.

#### Constructor Options

```php
use Monkeyslegion\PolySyntax\Driver\JsonDriver;

// Default flags (recommended)
$driver = new JsonDriver();

// Custom encode flags (e.g., pretty-print for readability)
$driver = new JsonDriver(
    encodeFlags: JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
);

// Custom decode flags (e.g., strict UTF-8 validation)
$driver = new JsonDriver(
    decodeFlags: JSON_THROW_ON_ERROR,
);

// Custom nesting depth
$driver = new JsonDriver(depth: 64);
```

**Default flags:**

| Operation | Flags |
|-----------|-------|
| Decode | `JSON_THROW_ON_ERROR`, `JSON_INVALID_UTF8_IGNORE` |
| Encode | `JSON_THROW_ON_ERROR`, `JSON_UNESCAPED_UNICODE`, `JSON_UNESCAPED_SLASHES`, `JSON_INVALID_UTF8_IGNORE` |

#### Decoding

```php
// Simple decode
$data = $driver->decode('{"name":"Alice","age":30}');
// ['name' => 'Alice', 'age' => 30]

// Deeply nested data
$data = $driver->decode('{"a":{"b":{"c":[1,2,3]}}}');
// ['a' => ['b' => ['c' => [1, 2, 3]]]]

// Array at root level
$data = $driver->decode('[{"id":1},{"id":2}]');
// [['id' => 1], ['id' => 2]]

// Malformed JSON → throws DecodeException
$driver->decode('{invalid}');
// Monkeyslegion\PolySyntax\Exception\DecodeException: Failed to decode JSON: Syntax error
```

#### Encoding

```php
// Simple encode
$output = $driver->encode(['name' => 'Alice', 'age' => 30]);
// {"name":"Alice","age":30}

// Multibyte string
$output = $driver->encode(['message' => 'Hello 世界']);
// {"message":"Hello 世界"}

// Nested data
$output = $driver->encode(['items' => [['id' => 1], ['id' => 2]]]);
// {"items":[{"id":1},{"id":2}]}

// Empty array
$output = $driver->encode([]);
// []
```

#### Pretty Print Example

```php
$pretty = new JsonDriver(
    encodeFlags: JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
);

echo $pretty->encode([
    'name' => 'Alice',
    'roles' => ['admin', 'editor'],
    'metadata' => ['theme' => 'dark'],
]);
// {
//     "name": "Alice",
//     "roles": [
//         "admin",
//         "editor"
//     ],
//     "metadata": {
//         "theme": "dark"
//     }
// }
```

#### Advanced: Serialization of Non-Object Data

```php
// Root-level arrays → JSON array output
$output = $driver->encode([10, 20, 30]);
// [10,20,30]

// Null values → JSON null
$output = $driver->encode(['key' => null]);
// {"key":null}

// Boolean values → JSON true/false
$output = $driver->encode(['active' => true, 'verified' => false]);
// {"active":true,"verified":false}
```

---

### XmlDriver

An XXE-safe XML driver using SimpleXML for parsing and DOMDocument for writing.

#### Constructor Options

```php
use Monkeyslegion\PolySyntax\Driver\XmlDriver;

// Default: root element "root", XXE protection enabled
$driver = new XmlDriver();

// Custom root element name
$driver = new XmlDriver(rootElement: 'document');

// Custom root element with default namespace
$driver = new XmlDriver(
    rootElement: 'feed',
    defaultNamespace: 'https://example.com/ns',
);

// Custom libxml parser options
$driver = new XmlDriver(
    libxmlOptions: LIBXML_NONET | LIBXML_PARSEHUGE,
);
```

**Default libxml options:**
- `LIBXML_NONET` — Disables network access (XXE protection)
- `LIBXML_NSCLEAN` — Strips redundant namespace declarations
- `LIBXML_PARSEHUGE` — Allows deep nesting and large text nodes

#### Decoding

```php
// Simple elements → associative array
$data = $driver->decode('<root><name>Alice</name><age>30</age></root>');
// ['name' => 'Alice', 'age' => '30']

// Nested elements → nested arrays
$data = $driver->decode('
    <root>
        <user>
            <name>Alice</name>
            <role>admin</role>
        </user>
    </root>
');
// ['user' => ['name' => 'Alice', 'role' => 'admin']]

// Repeated elements → list
$data = $driver->decode('
    <root>
        <item>apple</item>
        <item>banana</item>
        <item>cherry</item>
    </root>
');
// ['item' => ['apple', 'banana', 'cherry']]

// Attributes → @attributes key
$data = $driver->decode('<root><item id="1" currency="USD">10.99</item></root>');
// ['item' => ['@attributes' => ['id' => '1', 'currency' => 'USD'], '@text' => '10.99']]

// Empty elements → empty string
$data = $driver->decode('<root><empty/><void></void></root>');
// ['empty' => '', 'void' => '']

// Element with both attributes and children (value becomes nested)
$data = $driver->decode('
    <root>
        <product category="electronics">
            <name>Phone</name>
            <price>599</price>
        </product>
    </root>
');
// [
//     'product' => [
//         '@attributes' => ['category' => 'electronics'],
//         'name' => 'Phone',
//         'price' => '599',
//     ],
// ]
```

#### Encoding

```php
// Simple associative array
$output = $driver->encode(['name' => 'Alice', 'age' => 30]);
// <?xml version="1.0"?>\n<root><name>Alice</name><age>30</age></root>

// Attributes via @attributes key
$output = $driver->encode([
    'product' => [
        '@attributes' => ['id' => '42', 'currency' => 'USD'],
        '@text' => '10.99',
    ],
]);
// <?xml version="1.0"?>\n<root>
//   <product id="42" currency="USD">10.99</product>
// </root>

// Nested data with repeated keys
$output = $driver->encode([
    'users' => [
        ['name' => 'Alice', 'role' => 'admin'],
        ['name' => 'Bob', 'role' => 'editor'],
    ],
]);
// <?xml version="1.0"?>\n<root>
//   <users><name>Alice</name><role>admin</role></users>
//   <users><name>Bob</name><role>editor</role></users>
// </root>

// Integer keys → prefixed with "item"
$output = $driver->encode(['apple', 'banana', 'cherry']);
// <?xml version="1.0"?>\n<root>
//   <item>apple</item><item>banana</item><item>cherry</item>
// </root>

// Empty array → self-closing root element
$output = $driver->encode([]);
// <?xml version="1.0"?>\n<root/>
```

#### Namespace Support

```php
$driver = new XmlDriver(
    rootElement: 'feed',
    defaultNamespace: 'https://example.com/ns',
);

$output = $driver->encode(['title' => 'Hello', 'id' => 42]);
// <?xml version="1.0"?>\n<feed xmlns="https://example.com/ns"><title>Hello</title><id>42</id></feed>

// Round-trip: decode preserves namespace declaration
$data = $driver->decode($output);
// ['title' => 'Hello', 'id' => '42']
```

#### Custom Root Element Name

```php
$driver = new XmlDriver(rootElement: 'document');

$output = $driver->encode(['key' => 'value']);
// <?xml version="1.0"?>\n<document><key>value</key></document>
```

#### Text Content on Parent Elements

When you need an element to have both attributes and text content, use the `@text` key:

```php
$output = $driver->encode([
    'item' => [
        '@attributes' => ['id' => '1', 'type' => 'gadget'],
        '@text' => 'Super Widget',
    ],
]);
// <?xml version="1.0"?>\n<root>
//   <item id="1" type="gadget">Super Widget</item>
// </root>
```

#### Error Handling

```php
// Malformed XML → DecodeException
$driver->decode('<root><unclosed>');
// Monkeyslegion\PolySyntax\Exception\DecodeException:
//   Failed to parse XML: [FATAL] Line 1, Col 18: Opening and ending tag mismatch

// Empty input → DecodeException
$driver->decode('');
// Monkeyslegion\PolySyntax\Exception\DecodeException: Cannot decode empty XML input

// Encoding failure → EncodeException (e.g., resources, closures in data)
$driver->encode(['callback' => fn() => 'hi']);
// Monkeyslegion\PolySyntax\Exception\EncodeException:
//   Failed to encode XML: Object of class Closure could not be converted to string
```

#### XXE Protection

External entity injection is blocked by default — `LIBXML_NOENT` is intentionally omitted:

```php
// Attempted XXE payload — safely handled
$malicious = '<?xml version="1.0"?>
<!DOCTYPE foo [
  <!ENTITY xxe SYSTEM "file:///etc/passwd">
]>
<root>&xxe;</root>';

$data = $driver->decode($malicious);
// Monkeyslegion\PolySyntax\Exception\DecodeException:
//   Failed to parse XML: [WARNING] Line 5, Col 10: ...
```

---

### CsvDriver

A configurable CSV driver using native `fgetcsv` / `fputcsv`.

#### Constructor Options

```php
use Monkeyslegion\PolySyntax\Driver\CsvDriver;

// Default: comma-delimited, double-quoted, first row is header
$driver = new CsvDriver();

// Tab-separated values (TSV)
$driver = new CsvDriver(
    delimiter: "\t",
    enclosure: '"',
    escape: '\\',
);

// Semicolon-delimited (European CSV)
$driver = new CsvDriver(delimiter: ';');

// No header row — returns indexed rows
$driver = new CsvDriver(hasHeaders: false);

// Manual header override — first row treated as data
$driver = new CsvDriver(
    headers: ['name', 'email', 'role'],
);

// Limit to first 10 data rows
$driver = new CsvDriver(maxRows: 10);
```

#### Decoding

```php
// Default: first row is header → associative arrays
$data = $driver->decode("name,age,role\nAlice,30,admin\nBob,25,editor");
// [
//     ['name' => 'Alice', 'age' => '30', 'role' => 'admin'],
//     ['name' => 'Bob',   'age' => '25', 'role' => 'editor'],
// ]

// Without headers → indexed arrays
$naked = new CsvDriver(hasHeaders: false);
$data = $naked->decode("Alice,30\nBob,25");
// [
//     ['Alice', '30'],
//     ['Bob', '25'],
// ]

// With manual headers — first row becomes data
$custom = new CsvDriver(headers: ['name', 'age']);
$data = $custom->decode("Alice,30\nBob,25");
// [
//     ['name' => 'Alice', 'age' => '30'],
//     ['name' => 'Bob',   'age' => '25'],
// ]

// Quoted fields with embedded commas
$data = $driver->decode('"name","bio"\nAlice,"Hello, world!"');
// [['name' => 'Alice', 'bio' => 'Hello, world!']]

// Escaped quotes in fields
$data = $driver->decode('"name","note"\nAlice,"She said ""hello"""');
// [['name' => 'Alice', 'note' => 'She said "hello"']]

// Empty input → empty array
$data = $driver->decode('');
// []
```

#### Encoding

```php
// Header row auto-generated from array keys
$output = $driver->encode([
    ['name' => 'Alice', 'age' => 30],
    ['name' => 'Bob', 'age' => 25],
]);
// "name,age\nAlice,30\nBob,25"

// Boolean values → "true" / "false"
$output = $driver->encode([
    ['name' => 'Alice', 'active' => true],
    ['name' => 'Bob', 'active' => false],
]);
// "name,active\nAlice,true\nBob,false"

// Null values → empty string
$output = $driver->encode([
    ['name' => 'Alice', 'email' => null],
]);
// "name,email\nAlice,"

// Float values → preserved as strings
$output = $driver->encode([
    ['product' => 'Widget', 'price' => 19.99],
]);
// "product,price\nWidget,19.99"

// Empty data → empty string
$output = $driver->encode([]);
// ''

// Custom delimiter (tab-separated)
$tsv = new CsvDriver(delimiter: "\t", hasHeaders: false);
$output = $tsv->encode([
    ['Alice', 30, 'admin'],
    ['Bob', 25, 'editor'],
]);
// "Alice\t30\tadmin\nBob\t25\teditor"
```

#### Error Handling

```php
// Invalid delimiter (multi-byte)
new CsvDriver(delimiter: '||');
// InvalidArgumentException: CSV delimiter must be a single character, got "||"

// Invalid enclosure (multi-byte)
new CsvDriver(enclosure: '""');
// InvalidArgumentException: CSV enclosure must be a single character, got ""

// Non-array row in data — silently skipped
$output = $driver->encode([
    ['name' => 'Alice'],
    'not_an_array',
]);
// "name\nAlice" (non-array row ignored)

// Not an array of arrays → EncodeException
$driver->encode([1, 2, 3]);
// Monkeyslegion\PolySyntax\Exception\EncodeException:
//   CSV encoding requires an array of associative arrays
```

---

## 🔄 Transformer Integration

### Registering Drivers

```php
use Monkeyslegion\PolySyntax\Transformer;
use Monkeyslegion\PolySyntax\Driver\JsonDriver;
use Monkeyslegion\PolySyntax\Driver\XmlDriver;
use Monkeyslegion\PolySyntax\Driver\CsvDriver;

$transformer = new Transformer();

// Register individually — supports method chaining
$transformer
    ->registerDriver(new JsonDriver())
    ->registerDriver(new XmlDriver())
    ->registerDriver(new CsvDriver());
```

### Direct Transformation Between Formats

```php
use Monkeyslegion\PolySyntax\Syntax;

// JSON → XML
$xml = $transformer->transform(
    '{"items":[{"id":1,"name":"Widget"},{"id":2,"name":"Gadget"}]}',
    Syntax::JSON,
    Syntax::XML,
);

// XML → CSV
$csv = $transformer->transform(
    '<root><item><name>Widget</name><price>9.99</price></item></root>',
    Syntax::XML,
    Syntax::CSV,
);

// CSV → JSON (with custom driver options)
$jsonDriver = new JsonDriver(
    encodeFlags: JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
);
$transformer->registerDriver($jsonDriver);

$json = $transformer->transform(
    "name,price\nWidget,9.99\nGadget,24.99",
    Syntax::CSV,
    Syntax::JSON,
);
// [
//     {
//         "name": "Widget",
//         "price": "9.99"
//     },
//     {
//         "name": "Gadget",
//         "price": "24.99"
//     }
// ]
```

### Checking Supported Formats

```php
// Check if a format is registered
if ($transformer->supports(Syntax::YAML)) {
    $yaml = $transformer->encode($data, Syntax::YAML);
}

// List all registered formats
foreach ($transformer->supportedSyntaxes() as $syntax) {
    echo $syntax->label();   // "JSON", "XML", "CSV"
    echo $syntax->value;     // "json", "xml", "csv"
}
```

### Error Handling Patterns

```php
use Monkeyslegion\PolySyntax\Exception\TransformerException;
use Monkeyslegion\PolySyntax\Exception\DecodeException;
use Monkeyslegion\PolySyntax\Exception\EncodeException;
use Monkeyslegion\PolySyntax\Exception\UnsupportedSyntaxException;

// Fine-grained catching
try {
    return $transformer->decode($input, Syntax::JSON);
} catch (DecodeException $e) {
    // Input was malformed
    error_log("Parse error: {$e->getMessage()}");
    return null;
}

// Catch-all for any transformer error
try {
    return $transformer->transform($input, Syntax::JSON, Syntax::XML);
} catch (TransformerException $e) {
    error_log("Transformation failed: {$e->getMessage()}");
    throw $e;
}

// Unsupported format
try {
    $transformer->encode($data, Syntax::TOML);
} catch (UnsupportedSyntaxException $e) {
    echo "Driver for {$e->getMessage()} is not registered.";
}
```

### Real-World AI Pipeline Example

```php
// 1. Receive JSON from API
$apiResponse = '{"results":[{"name":"Alice","analysis":"...long text..."}]}';

// 2. Decode to array
$data = $transformer->decode($apiResponse, Syntax::JSON);

// 3. Extract text to analyze
foreach ($data['results'] as &$result) {
    // 4. Encode individual item as CSV (token-efficient) for LLM prompt
    $promptFragment = $transformer->encode([$result], Syntax::CSV);

    // ... send $promptFragment to LLM, get response ...

    // 5. Decode LLM response back to array
    $llmResult = $transformer->decode($llmResponse, Syntax::CSV);
    $result['sentiment'] = $llmResult[0]['sentiment'] ?? 'unknown';
}

// 6. Encode final result back to JSON
echo $transformer->encode($data, Syntax::JSON);
```

---

## 📊 Token Optimization

```php
use Monkeyslegion\PolySyntax\Util\TokenOptimizer;

$optimizer = new TokenOptimizer();
$savings = $optimizer->estimateSavings($jsonPayload, Syntax::JSON, Syntax::YAML);

printf(
    "Switch from %s to %s saves ~%d tokens (%.1f%%)",
    $savings->from()->name,
    $savings->to()->name,
    $savings->tokensSaved(),
    $savings->percentageSaved(),
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
composer test         # PHPUnit (112+ tests)
composer analyse      # PHPStan Level 9
composer cs-check     # PSR-12 code style
composer infection    # Mutation testing (MSI ≥ 50%)
```

### Quality Gates

| Gate | Requirement |
|------|-------------|
| **PHPStan** | Level 9, zero errors |
| **PHPCS** | PSR-12, zero errors |
| **Test coverage** | ≥ 90% lines |
| **Mutation score** | ≥ 50% MSI |

---

## 🤝 Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for detailed guidelines.

### Quick summary

- **PHP 8.4+ only** — embrace modern features
- **Zero runtime dependencies** — native PHP only for core
- **PSR-12** coding style + **PHPStan Level 9** static analysis
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
