# Security Policy

## Supported Versions

We release patches for security vulnerabilities in the following versions:

| Version | Supported          |
| ------- | ------------------ |
| 0.x     | ✅ Active development |

## Reporting a Vulnerability

We take the security of **MonkeysLegion-Poly-Syntax** seriously. If you believe you have found a security vulnerability, please **do not** open a public issue.

Instead, report it privately via one of the following methods:

- **GitHub Security Advisory**: Navigate to the repository's **Security > Advisories** tab and submit a private advisory.
- **Email**: Send your report to **<security@monkeyscloud.com>** (or use the GitHub Security Advisories tab).

### What to include

When reporting a vulnerability, please include as much of the following as possible:

- Type of vulnerability (e.g., XXE injection, buffer overflow, RCE)
- Affected component(s) and driver(s)
- Steps to reproduce the issue
- Proof of concept or exploit code (if available)
- Potential impact and attack surface

We will acknowledge receipt within **48 hours** and provide an initial assessment within **5 business days**. We will keep you informed throughout the fix and release process.

## Scope

The following are in scope for security reports:

- The core `PolySyntax` engine and its drivers
- Input parsing and encoding logic (especially XML, YAML)
- Any custom parser implementations

The following are **out of scope**:

- Third-party packages listed under `suggest` (these have their own security policies)
- The underlying PHP runtime or its extensions
- Applications that consume this library

## Security Best Practices When Using This Package

### XML External Entity (XXE) Protection

When processing XML data from untrusted sources, ensure XXE loading is disabled. Our `XmlDriver` disables external entities by default, but you should verify your PHP environment does not have `LIBXML_NOENT` enabled globally.

### Input Validation

Always validate input data before passing it to the transformer. Malformed or malicious payloads could cause excessive memory consumption during parsing. Consider setting `max_depth` or input size limits in your application layer.

### Trust Boundaries

Be aware of the trust boundaries when transforming between formats:

- Data decoded from an untrusted source should be sanitized before encoding to another format
- CSV injection (formula injection) can occur when CSV output is opened in spreadsheet applications — prefix cells starting with `=`, `+`, `-`, or `@` with a tab character if needed

## Disclosure Policy

We follow a coordinated disclosure process:

1. **Report received** — acknowledged within 48 hours
2. **Investigation** — initial assessment within 5 business days
3. **Fix preparation** — patch developed and reviewed
4. **Release** — new version published with fix
5. **Public disclosure** — advisory published after release

We aim to complete this process within **14 days** for critical vulnerabilities.

## Recognition

We believe in crediting security researchers who help us improve our security. With your permission, we will acknowledge your contribution in our release notes and security advisories.

---

Thank you for helping keep **MonkeysLegion-Poly-Syntax** and its community safe.
