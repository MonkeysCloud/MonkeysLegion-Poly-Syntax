<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Stream;

use Monkeyslegion\PolySyntax\Contract\StreamingDecoderInterface;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;

/**
 * Streaming TOML decoder with section-based streaming.
 *
 * Processes large TOML files incrementally by yielding completed
 * table sections as they're parsed:
 *
 * - Root-level keys are yielded first as a single section.
 * - Each `[table]` section is yielded when the next table header or EOF
 *   is reached.
 * - Each `[[array-of-tables]]` entry is yielded individually.
 * - Multi-line strings (""" and ''') are handled across chunk boundaries.
 *
 * ## Usage
 *
 * ```php
 * $decoder = new TomlStreamingDecoder();
 *
 * foreach ($chunks as $chunk) {
 *     $decoder->feed($chunk);
 *
 *     while (($section = $decoder->next()) !== null) {
 *         processSection($section);
 *     }
 * }
 *
 * $decoder->end();
 *
 * while (($section = $decoder->next()) !== null) {
 *     processSection($section);
 * }
 * ```
 *
 * ## Limitations
 *
 * - Only supports the TOML v1.0 features that the full `TomlDriver` supports.
 * - Each yielded item represents a complete table section, not individual keys.
 * - Does not validate cross-section key uniqueness (the full driver does).
 */
final class TomlStreamingDecoder implements StreamingDecoderInterface
{
    /** @var int Maximum nesting depth for inline structures. */
    private const MAX_DEPTH = 64;

    private string $buffer = '';
    private bool $ended = false;
    private int $pos = 0;

    /** @var list<array<string, mixed>> */
    private array $queue = [];

    /** @var array<string, mixed> Current section data being accumulated. */
    private array $currentSection = [];

    /**
     * The path of the current table header (empty for root).
     *
     * Examples: '', 'server', 'database.connection'
     */
    private string $currentTablePath = '';

    /**
     * Whether the current section is an array-of-tables ([[...]]).
     * Root and regular [table] sections become full tables, while
     * [[array-of-tables]] entries become individual items with the
     * table name as their key.
     */
    private bool $isArrayOfTables = false;

    /** @var string|null The key under which array-of-tables entries are collected. */
    private ?string $arrayOfTablesKey = null;

    /** @var list<string> Accumulated entries for the current [[array]] group. */
    private array $arrayOfTablesAccumulator = [];

    /** @var bool Whether we're currently inside a multi-line string. */
    private bool $inMultiline = false;

    /** @var string The type of multi-line string: 'basic' or 'literal'. */
    private string $multilineType = '';

    /** @var string Accumulated content of the current multi-line string. */
    private string $multilineContent = '';

    /** @var string The opener part before the multi-line string value (e.g., "key = "). */
    private string $multilineOpener = '';

    public function supportedSyntax(): Syntax
    {
        return Syntax::TOML;
    }

    public function feed(string $chunk): void
    {
        if ($this->ended) {
            throw new DecodeException('Cannot feed data after end()');
        }

        $this->buffer .= $chunk;
        $this->processBuffer();
    }

    public function end(): void
    {
        $this->ended = true;

        // Flush any remaining data in the buffer
        if ($this->buffer !== '') {
            $this->processLines([$this->buffer]);
            $this->buffer = '';
        }

        // If we're inside a multi-line string, handle incomplete state
        if ($this->inMultiline) {
            // Try to reconstruct the line with what we have
            $result = $this->multilineOpener . '"' . $this->multilineContent . '"';
            $this->processSingleLine($result);
            $this->inMultiline = false;
            $this->multilineType = '';
            $this->multilineContent = '';
            $this->multilineOpener = '';
        }

        // Flush the final section
        $this->flushSection();
    }

    public function next(): mixed
    {
        if ($this->queue !== []) {
            $this->pos++;
            return \array_shift($this->queue);
        }

        return null;
    }

    public function reset(): void
    {
        $this->buffer = '';
        $this->ended = false;
        $this->pos = 0;
        $this->queue = [];
        $this->currentSection = [];
        $this->currentTablePath = '';
        $this->isArrayOfTables = false;
        $this->arrayOfTablesKey = null;
        $this->arrayOfTablesAccumulator = [];
        $this->inMultiline = false;
        $this->multilineType = '';
        $this->multilineContent = '';
        $this->multilineOpener = '';
    }

    public function position(): int
    {
        return $this->pos;
    }

    /**
     * Process the current buffer, extracting complete lines.
     */
    private function processBuffer(): void
    {
        if ($this->buffer === '') {
            return;
        }

        // When inside a multiline string, we need to check if the buffer
        // contains the closing token even without a newline separator.
        if (!\str_contains($this->buffer, "\n")) {
            if ($this->inMultiline) {
                $closeToken = $this->multilineType === 'basic' ? '"""' : "'''";

                if (\str_contains($this->buffer, $closeToken)) {
                    // Buffer contains the closing token — process it now
                    $this->processLines([$this->buffer]);
                    $this->buffer = '';
                }
            }

            return;
        }

        $lines = \explode("\n", $this->buffer);
        $this->buffer = (string) \array_pop($lines);

        $this->processLines($lines);
    }

    /**
     * Process an array of complete lines.
     *
     * @param list<string> $lines
     */
    private function processLines(array $lines): void
    {
        foreach ($lines as $line) {
            $trimmed = \rtrim($line, "\r");

            if ($this->inMultiline) {
                $this->continueMultiline($trimmed);
                continue;
            }

            $this->processSingleLine($trimmed);
        }
    }

    /**
     * Process a single complete line of TOML.
     */
    private function processSingleLine(string $line): void
    {
        $trimmed = \trim($line);

        if ($trimmed === '' || $trimmed[0] === '#') {
            return; // Blank or comment line
        }

        // Table header
        if ($trimmed[0] === '[') {
            // Flush the current section before starting a new one
            $this->flushSection();

            if (\str_starts_with($trimmed, '[[') && \str_ends_with($trimmed, ']]')) {
                // Array of tables: [[path]]
                $path = \trim(\substr($trimmed, 2, -2));
                $this->currentTablePath = $path;
                $this->isArrayOfTables = true;
                $this->arrayOfTablesKey = $path;
                $this->currentSection = [];
            } elseif (\str_starts_with($trimmed, '[') && \str_ends_with($trimmed, ']')) {
                // Regular table: [path]
                $path = \trim(\substr($trimmed, 1, -1));
                $this->currentTablePath = $path;
                $this->isArrayOfTables = false;
                $this->arrayOfTablesKey = null;
                $this->currentSection = [];
            } else {
                throw new DecodeException(
                    \sprintf('Invalid table header syntax: %s', $trimmed),
                );
            }

            return;
        }

        // Check for multi-line string start
        if ($this->tryStartMultiline($trimmed)) {
            return;
        }

        // Regular key = value
        $this->parseAndSetKeyValue($trimmed);
    }

    /**
     * Try to start a multi-line string. Returns true if started.
     */
    private function tryStartMultiline(string $line): bool
    {
        $eqPos = \strpos($line, '=');

        if ($eqPos === false) {
            return false;
        }

        $valuePart = \trim(\substr($line, $eqPos + 1));

        // Multi-line basic string
        if (\str_starts_with($valuePart, '"""')) {
            // Check if it's closed on the same line
            if (\substr_count($line, '"""') > 1) {
                return false; // Fully on one line, handle normally
            }

            $this->inMultiline = true;
            $this->multilineType = 'basic';
            $this->multilineOpener = \trim(\substr($line, 0, $eqPos + 1)) . ' ';
            $this->multilineContent = '';

            // Content after the opening """
            $content = \substr($valuePart, 3);

            if ($content !== '') {
                // First line of content after the opener is appended (may be \n already)
                if (\strlen($content) > 0 && $content[0] === "\n") {
                    $content = \substr($content, 1);
                }

                $this->multilineContent .= $content;
            }

            return true;
        }

        // Multi-line literal string
        if (\str_starts_with($valuePart, "'''")) {
            if (\substr_count($line, "'''") > 1) {
                return false;
            }

            $this->inMultiline = true;
            $this->multilineType = 'literal';
            $this->multilineOpener = \trim(\substr($line, 0, $eqPos + 1)) . ' ';
            $this->multilineContent = '';

            $content = \substr($valuePart, 3);

            if ($content !== '' && \strlen($content) > 0 && $content[0] === "\n") {
                $content = \substr($content, 1);
            }

            $this->multilineContent .= $content;

            return true;
        }

        return false;
    }

    /**
     * Continue accumulating a multi-line string.
     */
    private function continueMultiline(string $line): void
    {
        $closeToken = $this->multilineType === 'basic' ? '"""' : "'''";

        $closePos = \strpos($line, $closeToken);

        if ($closePos !== false) {
            $this->multilineContent .= \substr($line, 0, $closePos);
            $after = \trim(\substr($line, $closePos + 3));

            // Process line-ending backslash for basic strings
            if ($this->multilineType === 'basic') {
                $this->multilineContent = \str_replace("\\\n", '', $this->multilineContent);
                $this->multilineContent = \rtrim($this->multilineContent, "\n");
            } else {
                // Literal: strip leading \n after opening
                if (\strlen($this->multilineContent) > 0 && $this->multilineContent[0] === "\n") {
                    $this->multilineContent = \substr($this->multilineContent, 1);
                }
                $this->multilineContent = \rtrim($this->multilineContent, "\n");
            }

            // Reconstruct as a single-line value
            $escaped = $this->multilineType === 'basic'
                ? '"' . $this->encodeBasicString($this->multilineContent) . '"'
                : "'" . $this->multilineContent . "'";

            $reconstructed = $this->multilineOpener . $escaped;

            if ($after !== '') {
                $reconstructed .= ' ' . $after;
            }

            $this->inMultiline = false;
            $this->multilineType = '';
            $this->multilineContent = '';
            $this->multilineOpener = '';

            $this->parseAndSetKeyValue($reconstructed);
        } else {
            $this->multilineContent .= $line . "\n";
        }
    }

    /**
     * Flush the current section into the queue as a single item.
     */
    private function flushSection(): void
    {
        if ($this->currentSection === []) {
            return;
        }

        if ($this->isArrayOfTables && $this->arrayOfTablesKey !== null) {
            // Array-of-tables: each section is one entry in the array
            $this->queue[] = [$this->arrayOfTablesKey => $this->currentSection];
        } elseif ($this->currentTablePath !== '') {
            // Regular table: yield the table as a nested object
            $keys = \explode('.', $this->currentTablePath);
            $item = $this->currentSection;

            // Build nested structure for dotted paths
            // (most table paths are simple, but support dotted notation)
            if (\count($keys) > 1) {
                $nested = [];
                $cursor = &$nested;

                foreach ($keys as $k) {
                    $cursor[$k] = [];
                    $cursor = &$cursor[$k];
                }

                $cursor = $item;

                // Extract the top-level key
                $topKey = $keys[0];
                $this->queue[] = [$topKey => $nested[$topKey]];
            } else {
                $this->queue[] = [$this->currentTablePath => $item];
            }
        } else {
            // Root keys
            $this->queue[] = $this->currentSection;
        }

        $this->currentSection = [];
    }

    /**
     * Parse a key = value line and set it in the current section.
     */
    private function parseAndSetKeyValue(string $line): void
    {
        $eqPos = $this->findFirstEquals($line);

        if ($eqPos === null) {
            throw new DecodeException(
                \sprintf('Invalid key-value syntax: %s', $line),
            );
        }

        $key = \trim(\substr($line, 0, $eqPos));
        $valueStr = \trim(\substr($line, $eqPos + 1));

        if ($key === '') {
            throw new DecodeException(
                \sprintf('Empty key in line: %s', $line),
            );
        }

        // Parse the value
        $value = $this->parseValue($valueStr);

        // Handle dotted keys: a.b.c = val
        $keys = $this->parseDottedKeys($key);

        if (\count($keys) === 1) {
            $this->currentSection[$keys[0]] = $value;
        } else {
            $this->setNested($this->currentSection, $keys, $value);
        }
    }

    /**
     * Find the first '=' that is not inside brackets, braces, or strings.
     */
    private function findFirstEquals(string $line): ?int
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
     * Parse a TOML value string into a PHP equivalent.
     */
    private function parseValue(string $value): mixed
    {
        $trimmed = \trim($value);

        if ($trimmed === '') {
            return '';
        }

        // Boolean
        if ($trimmed === 'true') {
            return true;
        }

        if ($trimmed === 'false') {
            return false;
        }

        // String
        if ($trimmed[0] === '"') {
            return $this->decodeBasicString(\substr($trimmed, 1, -1));
        }

        if ($trimmed[0] === "'") {
            return \substr($trimmed, 1, -1);
        }

        // Inline table
        if ($trimmed[0] === '{') {
            return $this->parseInlineTable(\substr($trimmed, 1, -1));
        }

        // Array
        if ($trimmed[0] === '[') {
            return $this->parseArray(\substr($trimmed, 1, -1));
        }

        // Numeric
        return $this->parseNumeric($trimmed);
    }

    /**
     * Decode a basic string with escape sequences.
     */
    private function decodeBasicString(string $value): string
    {
        $result = '';
        $len = \strlen($value);
        $i = 0;

        while ($i < $len) {
            if ($value[$i] === '\\' && $i + 1 < $len) {
                $next = $value[$i + 1];
                $result .= match ($next) {
                    'b' => "\x08",
                    't' => "\t",
                    'n' => "\n",
                    'f' => "\x0C",
                    'r' => "\r",
                    '"' => '"',
                    '\\' => '\\',
                    default => $next,
                };
                $i += 2;
                continue;
            }

            $result .= $value[$i];
            $i++;
        }

        return $result;
    }

    /**
     * Encode a string for TOML basic string output (escape special chars).
     */
    private function encodeBasicString(string $value): string
    {
        if (\str_contains($value, "\x00")) {
            $value = \str_replace("\x00", '', $value);
        }

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

    /**
     * Parse a TOML numeric value.
     */
    private function parseNumeric(string $trimmed): int|float
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

        // Hex
        if (\str_starts_with($clean, '0x') || \str_starts_with($clean, '0X')) {
            return \intval(\substr($clean, 2), 16);
        }

        // Octal
        if (\str_starts_with($clean, '0o') || \str_starts_with($clean, '0O')) {
            return \intval(\substr($clean, 2), 8);
        }

        // Binary
        if (\str_starts_with($clean, '0b') || \str_starts_with($clean, '0B')) {
            return \bindec(\substr($clean, 2));
        }

        // Float or int
        if (\str_contains($clean, '.') || \str_contains($clean, 'e') || \str_contains($clean, 'E')) {
            return (float) $clean;
        }

        return (int) $clean;
    }

    /**
     * Parse a TOML inline table.
     *
     * @return array<string, mixed>
     */
    private function parseInlineTable(string $inner): array
    {
        $result = [];
        $trimmed = \trim($inner);

        if ($trimmed === '') {
            return $result;
        }

        $pairs = $this->splitByComma($trimmed);

        foreach ($pairs as $pair) {
            $trimmedPair = \trim($pair);

            if ($trimmedPair === '') {
                continue;
            }

            $eqPos = $this->findFirstEquals($trimmedPair);

            if ($eqPos === null) {
                continue;
            }

            $key = \trim(\substr($trimmedPair, 0, $eqPos));
            $val = \trim(\substr($trimmedPair, $eqPos + 1));
            $result[$key] = $this->parseValue($val);
        }

        return $result;
    }

    /**
     * Parse a TOML inline array.
     *
     * @return list<mixed>
     */
    private function parseArray(string $inner): array
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

            $result[] = $this->parseValue($trimmedEl);
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
     * Parse a key path (dotted keys) into an array of key strings.
     *
     * @return list<string>
     */
    private function parseDottedKeys(string $path): array
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
                        $key .= match ($path[$i + 1]) {
                            'b' => "\x08",
                            't' => "\t",
                            'n' => "\n",
                            'f' => "\x0C",
                            'r' => "\r",
                            '"' => '"',
                            '\\' => '\\',
                            default => $path[$i + 1],
                        };
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

        return $keys;
    }

    /**
     * Set a value at a dotted path within a target array.
     *
     * @param array<mixed>  &$target
     * @param list<string>   $keys
     * @param mixed          $value
     */
    private function setNested(array &$target, array $keys, mixed $value): void
    {
        $cursor = &$target;

        for ($k = 0; $k < \count($keys) - 1; $k++) {
            $key = $keys[$k];

            if (!isset($cursor[$key])) {
                $cursor[$key] = [];
            }

            $cursor = &$cursor[$key];
        }

        $cursor[$keys[\count($keys) - 1]] = $value;
    }
}
