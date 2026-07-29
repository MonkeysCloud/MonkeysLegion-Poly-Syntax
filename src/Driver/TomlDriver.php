<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Driver;

use Monkeyslegion\PolySyntax\Contract\DriverInterface;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;

/**
 * Driver for TOML format transformation.
 *
 * Uses a lightweight native parser and encoder — zero external dependencies.
 *
 * ## Supported TOML v1.0 Features
 *
 * - **Keys:** bare, quoted (basic + literal), dotted
 * - **Strings:** basic, literal, multi-line basic, multi-line literal
 * - **Numerics:** decimal int, hex (0x), octal (0o), binary (0b), float,
 *   special floats (inf, nan)
 * - **Booleans:** `true`, `false`
 * - **Datetimes:** RFC 3339 offset, local, date-only, time-only
 * - **Arrays:** inline, multi-line with trailing comma
 * - **Tables:** `[table]`, `[a.b.c]`, mixed dotted keys
 * - **Array of Tables:** `[[array]]`
 * - **Inline Tables:** `{key = "val"}`
 * - **Comments:** `# full line` and `# inline`
 *
 * ## Conversion Rules
 *
 * ### Decoding (TOML → Array)
 * - Top-level keys become top-level array entries.
 * - Tables become nested sub-arrays.
 * - Array of tables produces a list of sub-arrays.
 * - Inline tables are decoded as nested arrays.
 *
 * ### Encoding (Array → TOML)
 * - Flat arrays are written as key = value pairs.
 * - Nested arrays become `[table]` sections.
 * - Lists of nested arrays with the same key become `[[array-of-tables]]`.
 * - Datetime values are formatted as RFC 3339 strings.
 * - Strings are encoded as basic strings with proper escaping.
 */
final class TomlDriver implements DriverInterface
{
    public function __construct()
    {
    }

    #[\Override]
    public function supportedSyntax(): Syntax
    {
        return Syntax::TOML;
    }

    // ─── Decode ──────────────────────────────────────────────────────

    #[\Override]
    public function decode(string $input): array
    {
        $trimmed = \trim($input);

        if ($trimmed === '') {
            return [];
        }

        $result = [];
        $currentTable = &$result;
        $lines = \explode("\n", $trimmed);
        $lineCount = \count($lines);

        // Phase 1: Normalise multi-line strings into single-line equivalents
        $normalised = $this->normaliseMultilineStrings($lines);

        // Phase 2: Parse normalised lines
        foreach ($normalised as $lineIndex => $line) {
            $trimmedLine = \trim($line);

            if ($trimmedLine === '') {
                continue;
            }

            // Table header: [table] or [[array]]
            if ($trimmedLine[0] === '[') {
                $currentTable = &$this->resolveTableHeader($result, $trimmedLine, $lineIndex);
                continue;
            }

            // Key = value pair
            $eqPos = $this->findFirstEqualsOutsideBrackets($trimmedLine);

            if ($eqPos === null) {
                throw new DecodeException(
                    \sprintf(
                        'Invalid key-value syntax at line %d: %s',
                        $lineIndex + 1,
                        $trimmedLine,
                    ),
                );
            }

            $keyPart = \trim(\substr($trimmedLine, 0, $eqPos));
            $valuePart = \trim(\substr($trimmedLine, $eqPos + 1));

            if ($keyPart === '') {
                throw new DecodeException(
                    \sprintf('Empty key at line %d', $lineIndex + 1),
                );
            }

            $keys = $this->parseKeyPath($keyPart);
            $value = $this->parseValue($valuePart, $lineIndex + 1);

            // Apply dotted keys through the current table
            $this->setNestedValue($currentTable, $keys, $value, $lineIndex + 1);
        }

        return $result;
    }

    // ─── Encode ──────────────────────────────────────────────────────

    #[\Override]
    public function encode(array $data): string
    {
        if ($data === []) {
            return '';
        }

        $parts = [];
        $this->encodeTable($data, $parts, '');

        return \implode("\n", $parts) . "\n";
    }

    // ─── Private: Multi-line String Normalisation ────────────────────

    /**
     * Normalise multi-line strings into single-line equivalents.
     *
     * Multi-line basic strings ("""...""") and multi-line literal strings
     * ('''...''') are collapsed into a single quoted basic/literal string
     * that the Phase 2 parser can handle.
     *
     * @param  list<string> $lines
     * @return list<string>
     */
    private function normaliseMultilineStrings(array $lines): array
    {
        /** @var list<string> $normalised */
        $normalised = [];
        $count = \count($lines);
        $i = 0;

        while ($i < $count) {
            $raw = $lines[$i];
            $line = $this->stripComment($raw);

            // Handle multi-line basic strings (""")
            $multiline = $this->tryNormaliseMultilineBasic($lines, $count, $i, $line);

            if ($multiline !== null) {
                [$normalisedLine, $newIndex] = $multiline;

                if (\trim($normalisedLine) !== '') {
                    $normalised[] = $normalisedLine;
                }

                $i = $newIndex;
                continue;
            }

            // Handle multi-line literal strings (''')
            $multiline = $this->tryNormaliseMultilineLiteral($lines, $count, $i, $line);

            if ($multiline !== null) {
                [$normalisedLine, $newIndex] = $multiline;

                if (\trim($normalisedLine) !== '') {
                    $normalised[] = $normalisedLine;
                }

                $i = $newIndex;
                continue;
            }

            if (\trim($line) !== '') {
                $normalised[] = $line;
            }

            $i++;
        }

        return $normalised;
    }

    /**
     * Try to normalise a multi-line basic string.
     *
     * @param  list<string> $lines
     * @return array{0: string, 1: int}|null
     */
    private function tryNormaliseMultilineBasic(
        array $lines,
        int $count,
        int $i,
        string $line,
    ): ?array {
        // Detect multi-line string start: line has =""" and the value portion
        // starts a multi-line string (not already closed on the same line)
        $eqPos = \strpos($line, '=');

        if ($eqPos === false) {
            return null;
        }

        $valuePart = \trim(\substr($line, $eqPos + 1));

        if (!\str_starts_with($valuePart, '"""')) {
            return null;
        }

        // If the value has a closing """ as well, it's a complete single-line value
        if (\substr_count($line, '"""') > 1) {
            return null;
        }

        // Get the key part (everything before and including =)
        $openerBase = \trim(\substr($line, 0, $eqPos + 1)) . ' ';
        $content = '';
        $i++;

        while ($i < $count) {
            $chunk = $lines[$i];

            if (\str_contains($chunk, '"""')) {
                $pos = \strpos($chunk, '"""');
                /** @var int<0, max> $pos */
                $content .= \substr($chunk, 0, $pos);
                $afterPos = $pos + 3;
                $after = \trim(\substr($chunk, $afterPos));

                // Handle line-ending backslash: strip \<newline> pairs
                $content = $this->processMultilineBasicContent($content);

                // Build the reconstructed line as key = "decoded value"
                $result = $openerBase . '"'
                    . $this->encodeBasicString($content) . '"';

                if ($after !== '') {
                    $result .= ' ' . $after;
                }

                return [$result, $i + 1];
            }

            $content .= $chunk . "\n";
            $i++;
        }

        // Unclosed multi-line string — return original line
        return [$line, $i];
    }

    /**
     * Try to normalise a multi-line literal string.
     *
     * @param  list<string> $lines
     * @return array{0: string, 1: int}|null
     */
    private function tryNormaliseMultilineLiteral(
        array $lines,
        int $count,
        int $i,
        string $line,
    ): ?array {
        // Detect multi-line literal string start
        $eqPos = \strpos($line, '=');

        if ($eqPos === false) {
            return null;
        }

        $valuePart = \trim(\substr($line, $eqPos + 1));

        if (!\str_starts_with($valuePart, "'''")) {
            return null;
        }

        if (\substr_count($line, "'''") > 1) {
            return null;
        }

        $openerBase = \trim(\substr($line, 0, $eqPos + 1)) . ' ';
        $content = '';
        $i++;

        while ($i < $count) {
            $chunk = $lines[$i];

            if (\str_contains($chunk, "'''")) {
                $pos = \strpos($chunk, "'''");
                /** @var int<0, max> $pos */
                $content .= \substr($chunk, 0, $pos);
                $afterPos = $pos + 3;
                $after = \trim(\substr($chunk, $afterPos));

                // Strip leading newline after opening ''' (TOML spec)
                if (\strlen($content) > 0 && $content[0] === "\n") {
                    $content = \substr($content, 1);
                }

                $content = \rtrim($content, "\n");

                // Build the reconstructed line
                $result = $openerBase . "'" . $content . "'";

                if ($after !== '') {
                    $result .= ' ' . $after;
                }

                return [$result, $i + 1];
            }

            $content .= $chunk . "\n";
            $i++;
        }

        return [$line, $i];
    }

    /**
     * Process multi-line basic string content: strip leading newline,
     * handle line-ending backslash continuations.
     */
    private function processMultilineBasicContent(string $content): string
    {
        // Strip leading newline after opening """ (TOML spec §2.4)
        if (\strlen($content) > 0 && $content[0] === "\n") {
            $content = \substr($content, 1);
        }

        // Handle line-ending backslash: remove backslash followed by newline
        $content = \str_replace("\\\n", '', $content);

        return \rtrim($content, "\n");
    }

    // ─── Private: Table Header Resolution ────────────────────────────

    /**
     * Resolve a table header and return a reference to the target array.
     *
     * @param  array<mixed> &$root
     * @return array<mixed>
     */
    private function &resolveTableHeader(
        array &$root,
        string $trimmedLine,
        int $lineIndex,
    ): array {
        if (\str_starts_with($trimmedLine, '[[') && \str_ends_with($trimmedLine, ']]')) {
            $keyPath = \substr($trimmedLine, 2, -2);

            return $this->ensureArrayOfTablesPath($root, $this->parseKeyPath($keyPath));
        }

        if (\str_starts_with($trimmedLine, '[') && \str_ends_with($trimmedLine, ']')) {
            $keyPath = \substr($trimmedLine, 1, -1);

            return $this->ensureTablePath($root, $this->parseKeyPath($keyPath));
        }

        throw new DecodeException(
            \sprintf(
                'Invalid table header syntax at line %d: %s',
                $lineIndex + 1,
                $trimmedLine,
            ),
        );
    }

    // ─── Private: Parse Helpers ──────────────────────────────────────

    /**
     * Strip inline comments from a line, respecting string boundaries.
     */
    private function stripComment(string $line): string
    {
        $inBasic = false;
        $inLiteral = false;
        $escape = false;
        $len = \strlen($line);

        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($ch === '\\' && $inBasic) {
                $escape = true;
                continue;
            }

            if ($ch === '"' && !$inLiteral) {
                $inBasic = !$inBasic;
                continue;
            }

            if ($ch === "'" && !$inBasic) {
                $inLiteral = !$inLiteral;
                continue;
            }

            if ($ch === '#' && !$inBasic && !$inLiteral) {
                return \trim(\substr($line, 0, $i));
            }
        }

        return \trim($line);
    }

    /**
     * Find the first '=' that is not inside brackets, braces, or strings.
     */
    private function findFirstEqualsOutsideBrackets(string $line): ?int
    {
        $depth = 0;
        $inBasic = false;
        $inLiteral = false;
        $escape = false;
        $len = \strlen($line);

        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($ch === '\\' && $inBasic) {
                $escape = true;
                continue;
            }

            if ($ch === '"' && !$inLiteral) {
                $inBasic = !$inBasic;
                continue;
            }

            if ($ch === "'" && !$inBasic) {
                $inLiteral = !$inLiteral;
                continue;
            }

            if ($inBasic || $inLiteral) {
                continue;
            }

            if ($ch === '[' || $ch === '{') {
                $depth++;
                continue;
            }

            if ($ch === ']' || $ch === '}') {
                $depth--;
                continue;
            }

            if ($ch === '=' && $depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Parse a key path (dotted keys) into an array of key strings.
     *
     * @return list<string>
     */
    private function parseKeyPath(string $path): array
    {
        $keys = [];
        $len = \strlen($path);
        $i = 0;

        while ($i < $len) {
            if ($path[$i] === '.' || $path[$i] === ' ' || $path[$i] === "\t") {
                $i++;
                continue;
            }

            // Quoted key
            if ($path[$i] === '"' || $path[$i] === "'") {
                $quote = $path[$i];
                $i++;
                $key = '';

                while ($i < $len && $path[$i] !== $quote) {
                    if ($quote === '"' && $path[$i] === '\\' && $i + 1 < $len) {
                        $key .= $this->decodeEscape($path[$i + 1]);
                        $i += 2;
                        continue;
                    }

                    $key .= $path[$i];
                    $i++;
                }

                if ($i < $len) {
                    $i++;
                }

                $keys[] = $key;
                continue;
            }

            // Bare key
            $key = '';

            while ($i < $len && $path[$i] !== '.' && $path[$i] !== ' ' && $path[$i] !== "\t") {
                $key .= $path[$i];
                $i++;
            }

            if ($key !== '') {
                $keys[] = $key;
            }
        }

        if ($keys === []) {
            throw new DecodeException('Empty key path');
        }

        return $keys;
    }

    /**
     * Set a value at a dotted path within a target array,
     * throwing on scalar overwrite.
     *
     * @param  array<mixed> &$target
     * @param  list<string>  $keys
     * @param  mixed         $value
     */
    private function setNestedValue(
        array &$target,
        array $keys,
        mixed $value,
        int $lineNumber,
    ): void {
        $cursor = &$target;

        for ($k = 0; $k < \count($keys) - 1; $k++) {
            $key = $keys[$k];

            if (isset($cursor[$key]) && !\is_array($cursor[$key])) {
                throw new DecodeException(
                    \sprintf(
                        'Cannot extend key "%s" as table: already exists as a value at line %d',
                        $key,
                        $lineNumber,
                    ),
                );
            }

            if (!isset($cursor[$key])) {
                $cursor[$key] = [];
            }

            $cursor = &$cursor[$key];
        }

        $finalKey = $keys[\count($keys) - 1];

        if (\array_key_exists($finalKey, $cursor)) {
            throw new DecodeException(
                \sprintf('Duplicate key "%s" at line %d', $finalKey, $lineNumber),
            );
        }

        $cursor[$finalKey] = $value;
    }

    /**
     * Parse a TOML value into its PHP equivalent.
     */
    private function parseValue(string $value, int $lineNumber): mixed
    {
        $trimmed = \trim($value);

        if ($trimmed === '') {
            throw new DecodeException(
                \sprintf('Empty value at line %d', $lineNumber),
            );
        }

        // Boolean
        if ($trimmed === 'true') {
            return true;
        }

        if ($trimmed === 'false') {
            return false;
        }

        // Inline table
        if ($trimmed[0] === '{') {
            return $this->parseInlineTable(
                \substr($trimmed, 1, -1),
                $lineNumber,
            );
        }

        // Array
        if ($trimmed[0] === '[') {
            return $this->parseArray(
                \substr($trimmed, 1, -1),
                $lineNumber,
            );
        }

        // String
        $stringValue = $this->tryParseString($trimmed);

        if ($stringValue !== null) {
            return $stringValue;
        }

        // Datetime / date / time
        $dt = $this->tryParseDateTime($trimmed);

        if ($dt !== null) {
            return $dt;
        }

        // Special floats
        return $this->parseNumeric($trimmed, $lineNumber);
    }

    /**
     * Try to parse a string value.
     */
    private function tryParseString(string $trimmed): null|string
    {
        if ($trimmed[0] === '"') {
            if (
                \strlen($trimmed) >= 6
                && \substr($trimmed, 0, 3) === '"""'
                && \substr($trimmed, -3) === '"""'
            ) {
                return $this->decodeBasicString(\substr($trimmed, 3, -3));
            }

            return $this->decodeBasicString(\substr($trimmed, 1, -1));
        }

        if ($trimmed[0] === "'") {
            if (
                \strlen($trimmed) >= 6
                && \substr($trimmed, 0, 3) === "'''"
                && \substr($trimmed, -3) === "'''"
            ) {
                return \substr($trimmed, 3, -3);
            }

            return \substr($trimmed, 1, -1);
        }

        return null;
    }

    /**
     * Parse a numeric value (int, float, or special).
     */
    private function parseNumeric(string $trimmed, int $lineNumber): int|float
    {
        if ($trimmed === 'inf' || $trimmed === '+inf') {
            return \INF;
        }

        if ($trimmed === '-inf') {
            return -\INF;
        }

        if ($trimmed === 'nan' || $trimmed === '+nan' || $trimmed === '-nan') {
            return \NAN;
        }

        $clean = \str_replace('_', '', $trimmed);

        // Hex integer
        if (\str_starts_with($clean, '0x') || \str_starts_with($clean, '0X')) {
            $hexVal = \substr($clean, 2);

            if (\preg_match('/^[0-9a-fA-F]+$/', $hexVal)) {
                return \intval($hexVal, 16);
            }

            throw new DecodeException(
                \sprintf('Invalid hex integer at line %d: %s', $lineNumber, $trimmed),
            );
        }

        // Octal integer
        if (\str_starts_with($clean, '0o') || \str_starts_with($clean, '0O')) {
            $octVal = \substr($clean, 2);

            if (\preg_match('/^[0-7]+$/', $octVal)) {
                return \intval($octVal, 8);
            }

            throw new DecodeException(
                \sprintf('Invalid octal integer at line %d: %s', $lineNumber, $trimmed),
            );
        }

        // Binary integer
        if (\str_starts_with($clean, '0b') || \str_starts_with($clean, '0B')) {
            $binVal = \substr($clean, 2);

            if (\preg_match('/^[01]+$/', $binVal)) {
                return \bindec($binVal);
            }

            throw new DecodeException(
                \sprintf('Invalid binary integer at line %d: %s', $lineNumber, $trimmed),
            );
        }

        // Float or decimal int
        if (
            \is_numeric($clean)
            || \preg_match('/^[+-]?\d+\.\d/', $clean)
            || \preg_match('/^[+-]?\d+[eE][+-]?\d+/', $clean)
        ) {
            if (
                \str_contains($clean, '.')
                || \str_contains($clean, 'e')
                || \str_contains($clean, 'E')
            ) {
                return (float) $clean;
            }

            return (int) $clean;
        }

        throw new DecodeException(
            \sprintf('Unrecognised value at line %d: %s', $lineNumber, $trimmed),
        );
    }

    /**
     * Parse an inline table.
     *
     * @return array<string, mixed>
     */
    private function parseInlineTable(string $inner, int $lineNumber): array
    {
        $result = [];

        if (\trim($inner) === '') {
            return $result;
        }

        $pairs = $this->splitByComma($inner);

        foreach ($pairs as $pair) {
            $trimmed = \trim($pair);

            if ($trimmed === '') {
                continue;
            }

            $eqPos = $this->findFirstEqualsOutsideBrackets($trimmed);

            if ($eqPos === null) {
                throw new DecodeException(
                    \sprintf(
                        'Invalid inline table syntax at line %d: %s',
                        $lineNumber,
                        $trimmed,
                    ),
                );
            }

            $key = \trim(\substr($trimmed, 0, $eqPos));
            $val = \trim(\substr($trimmed, $eqPos + 1));
            $parsedKeys = $this->parseKeyPath($key);
            $parsedVal = $this->parseValue($val, $lineNumber);

            if (\count($parsedKeys) === 1) {
                $result[$parsedKeys[0]] = $parsedVal;
            } else {
                $this->setNestedValue(
                    $result,
                    $parsedKeys,
                    $parsedVal,
                    $lineNumber,
                );
            }
        }

        return $result;
    }

    /**
     * Parse a TOML array.
     *
     * @return list<mixed>
     */
    private function parseArray(string $inner, int $lineNumber): array
    {
        $result = [];
        $trimmed = \trim($inner);

        if ($trimmed === '') {
            return $result;
        }

        $elements = $this->splitByComma($trimmed);

        foreach ($elements as $element) {
            $trimmedEl = \trim($element);

            if ($trimmedEl === '') {
                continue;
            }

            $result[] = $this->parseValue($trimmedEl, $lineNumber);
        }

        return $result;
    }

    /**
     * Split a comma-separated list respecting nesting and string boundaries.
     *
     * @return list<string>
     */
    private function splitByComma(string $input): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $inBasic = false;
        $inLiteral = false;
        $escape = false;
        $len = \strlen($input);

        for ($i = 0; $i < $len; $i++) {
            $ch = $input[$i];

            if ($escape) {
                $current .= $ch;
                $escape = false;
                continue;
            }

            if ($ch === '\\' && $inBasic) {
                $current .= $ch;
                $escape = true;
                continue;
            }

            if ($ch === '"' && !$inLiteral) {
                $inBasic = !$inBasic;
                $current .= $ch;
                continue;
            }

            if ($ch === "'" && !$inBasic) {
                $inLiteral = !$inLiteral;
                $current .= $ch;
                continue;
            }

            if ($inBasic || $inLiteral) {
                $current .= $ch;
                continue;
            }

            if ($ch === '[' || $ch === '{') {
                $depth++;
                $current .= $ch;
                continue;
            }

            if ($ch === ']' || $ch === '}') {
                $depth--;
                $current .= $ch;
                continue;
            }

            if ($ch === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        $remaining = \trim($current);

        if ($remaining !== '') {
            $parts[] = $remaining;
        }

        return $parts;
    }

    /**
     * Try to parse a datetime string.
     *
     * @return \DateTimeImmutable|string|null
     */
    private function tryParseDateTime(string $value): \DateTimeImmutable|string|null
    {
        $value = \str_replace(' ', 'T', $value);

        // Full offset datetime: 1979-05-27T07:32:00Z or with offset
        if (\preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value)) {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        }

        // Local datetime: 1979-05-27T07:32:00
        if (\preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?$/', $value)) {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        }

        // Local date: 1979-05-27
        if (\preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        // Local time: 07:32:00 or 07:32:00.999999
        if (\preg_match('/^\d{2}:\d{2}:\d{2}(\.\d+)?$/', $value)) {
            return $value;
        }

        return null;
    }

    /**
     * Decode a basic string, processing escape sequences.
     */
    private function decodeBasicString(string $value): string
    {
        $result = '';
        $len = \strlen($value);
        $i = 0;

        while ($i < $len) {
            if ($value[$i] === '\\' && $i + 1 < $len) {
                $result .= $this->decodeEscape($value[$i + 1]);
                $i += 2;
                continue;
            }

            $result .= $value[$i];
            $i++;
        }

        return $result;
    }

    /**
     * Decode a TOML escape sequence character.
     */
    private function decodeEscape(string $ch): string
    {
        return match ($ch) {
            'b' => "\x08",
            't' => "\t",
            'n' => "\n",
            'f' => "\x0C",
            'r' => "\r",
            '"' => '"',
            '\\' => '\\',
            default => $ch,
        };
    }

    // ─── Private: Table Path Resolution ──────────────────────────────

    /**
     * Navigate (or create) a table path in the result array.
     *
     * @param  array<mixed> &$root
     * @param  list<string>  $keys
     * @return array<mixed>
     */
    private function &ensureTablePath(array &$root, array $keys): array
    {
        $current = &$root;

        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                $current[$key] = [];
            } elseif (!\is_array($current[$key])) {
                throw new DecodeException(
                    \sprintf(
                        'Cannot define table "%s": key already exists as a value',
                        \implode('.', $keys),
                    ),
                );
            }

            $current = &$current[$key];
        }

        return $current;
    }

    /**
     * Navigate (or create) an array-of-tables path.
     *
     * @param  array<mixed> &$root
     * @param  list<string>  $keys
     * @return array<mixed>
     */
    private function &ensureArrayOfTablesPath(array &$root, array $keys): array
    {
        $current = &$root;

        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                $current[$key] = [];
            }

            if (!\is_array($current[$key])) {
                throw new DecodeException(
                    \sprintf(
                        'Cannot define array of tables "%s": key already exists as a value',
                        \implode('.', $keys),
                    ),
                );
            }

            $current = &$current[$key];
        }

        $current[] = [];

        /** @var array<mixed> $last */
        $last = &$current[\count($current) - 1];

        return $last;
    }

    // ─── Private: Encode Helpers ─────────────────────────────────────

    /**
     * Recursively encode a data array into TOML lines.
     *
     * @param  array<mixed> $data
     * @param  list<string> &$parts
     * @param  string       $prefix
     * @param  bool         $isArrayOfTables
     */
    private function encodeTable(
        array $data,
        array &$parts,
        string $prefix,
        bool $isArrayOfTables = false,
    ): void {
        $simple = [];
        /** @var array<string, list<array<mixed>>> $arrayTables */
        $arrayTables = [];
        /** @var array<string, array<mixed>> $subTables */
        $subTables = [];

        foreach ($data as $key => $value) {
            if (!\is_array($value)) {
                $simple[$key] = $value;
                continue;
            }

            // Detect array of tables: list of associative arrays
            if (\array_is_list($value) && $value !== []) {
                $allAssoc = true;

                foreach ($value as $item) {
                    if (!\is_array($item) || \array_is_list($item)) {
                        $allAssoc = false;
                        break;
                    }
                }

                if ($allAssoc) {
                    $arrayTables[$key] = $value;
                    continue;
                }

                // List array (like [1, 2, 3]) — encode as inline value
                $simple[$key] = $value;
                continue;
            }

            // Empty array — encode as inline []
            if ($value === []) {
                $simple[$key] = $value;
                continue;
            }

            // Flat associative array (no nested arrays) → inline table
            // Nested associative array (has sub-arrays) → table header
            $hasNested = false;

            foreach ($value as $v) {
                if (\is_array($v)) {
                    $hasNested = true;
                    break;
                }
            }

            if ($hasNested) {
                $subTables[$key] = $value;
            } else {
                // Flat associative array → encode as inline value
                $simple[$key] = $value;
            }
        }

        foreach ($simple as $key => $value) {
            $parts[] = $this->encodeKeyValue($key, $value);
        }

        if ($simple !== [] && ($subTables !== [] || $arrayTables !== [])) {
            $parts[] = '';
        }

        foreach ($subTables as $key => $value) {
            $tablePath = $prefix !== '' ? $prefix . '.' . $key : $key;
            $parts[] = '[' . $tablePath . ']';

            if ($value === []) {
                $parts[] = '';
                continue;
            }

            $this->encodeTable($value, $parts, $tablePath);
            $parts[] = '';
        }

        foreach ($arrayTables as $key => $items) {
            $tablePath = $prefix !== '' ? $prefix . '.' . $key : $key;

            foreach ($items as $item) {
                $parts[] = '[[' . $tablePath . ']]';

                if ($item === []) {
                    $parts[] = '';
                    continue;
                }

                $this->encodeTable($item, $parts, $tablePath, true);
                $parts[] = '';
            }
        }

        // Trim trailing blank lines from sub-tables
        if ($prefix !== '' && !$isArrayOfTables && $parts !== []) {
            $lastIdx = \count($parts) - 1;

            while ($lastIdx >= 0 && $parts[$lastIdx] === '') {
                \array_pop($parts);
                $lastIdx--;
            }
        }
    }

    /**
     * Encode a single key-value pair as a TOML line.
     */
    private function encodeKeyValue(string $key, mixed $value): string
    {
        return $this->encodeKey($key) . ' = ' . $this->encodeValue($value);
    }

    /**
     * Encode a key, quoting if necessary.
     */
    private function encodeKey(string $key): string
    {
        if (\preg_match('/^[A-Za-z0-9_-]+$/', $key)) {
            return $key;
        }

        return '"' . $this->encodeBasicString($key) . '"';
    }

    /**
     * Encode a value for TOML output.
     */
    private function encodeValue(mixed $value): string
    {
        if ($value === null) {
            return '""';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        if (\is_float($value)) {
            return $this->encodeFloat($value);
        }

        if (\is_string($value)) {
            return '"' . $this->encodeBasicString($value) . '"';
        }

        if ($value instanceof \DateTimeInterface) {
            return '"' . $value->format(\DateTimeInterface::RFC3339) . '"';
        }

        if (\is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            // Non-empty array
            if (\array_is_list($value)) {
                return $this->encodeListArray($value);
            }

            /** @var array<string, mixed> $assoc */
            $assoc = $value;

            return $this->encodeInlineTable($assoc);
        }

        // Non-array, non-scalar (resources, closures, etc.)

        return '""';
    }

    /**
     * Encode a float value.
     */
    private function encodeFloat(float $value): string
    {
        if (\is_infinite($value)) {
            return $value > 0 ? 'inf' : '-inf';
        }

        if (\is_nan($value)) {
            return 'nan';
        }

        $str = (string) $value;

        if (!\str_contains($str, '.')) {
            return $str . '.0';
        }

        return $str;
    }

    /**
     * Encode a list array.
     *
     * @param list<mixed> $values
     */
    private function encodeListArray(array $values): string
    {
        $items = [];

        foreach ($values as $val) {
            $items[] = $this->encodeValue($val);
        }

        return '[' . \implode(', ', $items) . ']';
    }

    /**
     * Encode an associative array as an inline table.
     *
     * @param array<string, mixed> $data
     */
    private function encodeInlineTable(array $data): string
    {
        $pairs = [];

        if ($data === []) {
            return '{}';
        }

        foreach ($data as $key => $value) {
            $pairs[] = $this->encodeKey($key) . ' = ' . $this->encodeValue($value);
        }

        return '{ ' . \implode(', ', $pairs) . ' }';
    }

    /**
     * Encode a string as a TOML basic string with proper escaping.
     */
    private function encodeBasicString(string $value): string
    {
        $result = '';
        $len = \strlen($value);

        for ($i = 0; $i < $len; $i++) {
            $ch = $value[$i];
            $code = \ord($ch);

            $result .= match (true) {
                $ch === '"' => '\\"',
                $ch === '\\' => '\\\\',
                $ch === "\x08" => '\\b',
                $ch === "\t" => '\\t',
                $ch === "\n" => '\\n',
                $ch === "\x0C" => '\\f',
                $ch === "\r" => '\\r',
                $code < 0x20 => \sprintf('\\u%04X', $code),
                default => $ch,
            };
        }

        return $result;
    }
}
