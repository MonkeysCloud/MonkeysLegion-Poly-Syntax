# Package Architecture & Development Roadmap: `PolySyntax`

## 📌 Executive Summary & Vision

**Poly-Syntax** is a lightweight, **zero-dependency** PHP 8.4+ package designed to perform high-performance, bidirectional transformations between modern data representation formats (JSON, XML, CSV, YAML, TOML, and custom structures).

### 🎯 Primary Catalyst: LLM Token Optimization

In modern AI workflows, converting structured payloads from verbose formats like JSON (≈290 tokens/KB) to token-dense formats like YAML (≈180 tokens/KB) or CSV (≈160 tokens/KB) can drastically reduce token overhead — yielding **lower API costs** and **faster generation times**.

### 🎯 Secondary Goal: Agnostic Serialization Bridge

While optimized for AI pipelines, Poly-Syntax is architected as a general-purpose serialization bridge for any PHP 8.4+ application.

---

## 🏗️ Core Architectural Design

The package leverages an **Adapter / Driver Pattern** with an intermediate internal format:

```
[ Input Payload ]  ──▶ ( Input Driver: decode() )  ──▶ [ Native PHP Array ]
                                                                │
[ Output Payload ] ◀── ( Output Driver: encode() ) ◀── [ Native PHP Array ]
```

### Architectural Principles

1. **🔋 Zero Runtime Dependencies**
   - The core engine requires **only PHP 8.4+** and its bundled extensions (`json`, `simplexml`, `libxml`, `mbstring`)
   - No `symfony/yaml`, no `yosymfony/toml`, no third-party parsing libraries in `require`
   - Optional parsers may be added via `suggest` for advanced edge cases

2. **🔌 Pluggable Driver Architecture**
   - Drivers are registered at runtime via `DriverInterface`
   - The core handles registry and routing without hard dependencies on any parser
   - Custom user-land drivers can be registered without modifying the package

3. **📜 Explicit Contracts**
   - Strict typing with `declare(strict_types=1)`
   - Native PHP 8.4 features: backed enums, `readonly` classes, property hooks, `#[Override]`
   - Custom exception hierarchy for predictable error handling

4. **🛡️ Security by Default**
   - XXE protection enabled on all XML parsing
   - Input validation before processing
   - Memory-safe parsing limits

5. **🎯 Data Loss Minimization**
   - Drivers maintain data fidelity during array hydration and serialization
   - Documented format-specific trade-offs

---

## 🗺️ Implementation Roadmap & Milestones

### Phase 1: Core Foundation & Contracts (Target: v0.1.0)

> Focus: Establish strict interfaces, driver registry, and first-party native drivers.

- [ ] **Core Engine**
  - [ ] `Monkeyslegion\PolySyntax\Contract\DriverInterface` — `decode(string): array` + `encode(array): string`
  - [ ] `Monkeyslegion\PolySyntax\Transformer` — facade with driver registry and `transform()` orchestration
  - [ ] `Monkeyslegion\PolySyntax\Enum\Syntax` — backed enum (`json`, `yaml`, `toml`, `xml`, `csv`)
  - [ ] Exception hierarchy:
    - `TransformerException` (base)
    - `DecodeException` (input failures)
    - `EncodeException` (output failures)
    - `UnsupportedSyntaxException` (unregistered format)

- [ ] **Native Drivers (zero external deps)**
  - [ ] `JsonDriver` — `json_decode` / `json_encode` with `JSON_THROW_ON_ERROR` + `JSON_INVALID_UTF8_IGNORE`
  - [ ] `XmlDriver` — SimpleXML + DOMDocument with default XXE protection (`LIBXML_NOENT` disabled)
  - [ ] `CsvDriver` — `fgetcsv` / `fputcsv` with configurable delimiter, enclosure, and escape

- [ ] **Quality Assurance**
  - [ ] PHPUnit test suite with round-trip validation (JSON ↔ XML, JSON ↔ CSV)
  - [ ] PHPStan at Level 8 — zero errors
  - [ ] PSR-12 enforced via PHP_CodeSniffer
  - [ ] GitHub Actions CI pipeline (test, analyse, cs-check on push/PR)

---

### Phase 2: Driver Expansion & Token Utilities (Target: v0.2.0)

> Focus: YAML/TOML support and AI-specific tooling.

- [ ] **Additional Format Drivers**
  - [ ] `YamlDriver` — lightweight native YAML parser (subset) with optional `symfony/yaml` fallback
  - [ ] `TomlDriver` — lightweight native TOML parser (subset) with optional `yosymfony/toml` fallback

- [ ] **Token Optimization Utilities**
  - [ ] `TokenOptimizer` — estimate token/character savings between formats
  - [ ] Format-aware token counting (different delimiters have different byte/token ratios)

- [ ] **Custom Driver Extensions**
  - [ ] Support for runtime registration of custom user-land drivers
  - [ ] Support for chained transformations (A → B → C)

- [ ] **Documentation**
  - [ ] Full PHPStan-clean docblocks on all public APIs
  - [ ] Usage examples for common AI pipeline patterns
  - [ ] Migration guide from other serialization libraries

---

### Phase 3: Performance & Ecosystem (Target: v0.3.0+)

> Focus: Benchmarking, edge cases, and framework integration.

- [ ] **Performance**
  - [ ] Benchmark suite comparing drivers against common payloads (1KB–10MB)
  - [ ] Optimize hot paths in frequently used drivers (JSON, CSV)
  - [ ] Memory profiling for large payload scenarios

- [ ] **Streaming & Large File Support**
  - [ ] `StreamingDecoder` interface for line-by-line / chunk-by-chunk processing
  - [ ] CSV streaming for large datasets
  - [ ] JSON streaming via `json_decode($chunk, true)` with state machine

- [ ] **Edge Case Hardening**
  - [ ] Deeply nested structures (configurable depth limits)
  - [ ] Unicode and multi-byte character handling
  - [ ] Binary-safe string processing
  - [ ] Empty and null input handling

- [ ] **Framework Integration**
  - [ ] Laravel `Str` macro / facade
  - [ ] Symfony serializer tag
  - [ ] Standalone CLI tool (`poly-syntax convert file.json file.yaml`)

---

### Phase 4: Advanced Features (Target: v0.4.0+)

> Focus: Beyond token optimization — intelligent format features.

- [ ] **Schema-Guided Transformation**
  - [ ] Define mapping rules between formats (e.g., XML attributes → JSON keys)
  - [ ] Support for format-specific features (XML namespaces, YAML anchors/aliases)

- [ ] **Auto-Detection**
  - [ ] Heuristic-based source format detection from content
  - [ ] Confidence scoring for ambiguous payloads

- [ ] **Validation & Linting**
  - [ ] Validate data fidelity across transformation
  - [ ] Detect and warn about data loss (e.g., CSV flattening nested JSON)
  - [ ] Syntax linting for each supported format

---

## 🛠️ Contribution Guidelines

We welcome community contributions! See [CONTRIBUTING.md](CONTRIBUTING.md) for full details.

### Quick Start

1. **Pick an open task** — check the checklists above or browse [GitHub Issues](https://github.com/monkeyscloud/monkeyslegion-poly-syntax/issues)
2. **Adhere to standards:**
   - **PHP Version:** 8.4+ only
   - **Dependencies:** Zero new runtime deps (use `suggest` if absolutely required)
   - **Code Style:** PSR-12 (enforced via PHP_CodeSniffer)
   - **Static Analysis:** PHPStan Level 8 — no exceptions
   - **Testing:** PHPUnit round-trip tests for every driver or feature
3. **Submit a PR** — one feature per PR, keep it focused

### Submitting a New Driver

1. Implement `Monkeyslegion\PolySyntax\Contract\DriverInterface`
2. Add no runtime dependencies — use only PHP 8.4+ native features
3. Write unit tests validating `decode()` and `encode()` accuracy under `tests/Driver/`
4. Update README.md format support table
5. Run `composer check` before submitting

---

## ❌ Out of Scope

The following are **not** planned for this package:

- Binary format support (Protobuf, MessagePack, BSON, CBOR)
- Template engine or code generation
- Data validation/schema enforcement (use dedicated validators)
- HTTP transport or network I/O
- Persistent storage or caching

---

## 📄 License & Maintainer

**MIT License** — see [LICENSE](LICENSE).

Built for high-efficiency AI engineering and modern PHP software workflows by [MonkeysCloud](https://github.com/monkeyscloud).
