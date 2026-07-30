<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Driver;

use Monkeyslegion\PolySyntax\Contract\DriverInterface;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;

/**
 * Driver for YAML format transformation.
 *
 * Uses a lightweight native parser and encoder — zero external dependencies.
 *
 * ## Supported YAML v1.2 Features
 *
 * - **Scalars:** strings (quoted + unquoted), integers, floats, booleans,
 *   null (`null`, `~`, empty)
 * - **Mappings:** `key: value`, nested indentation-based mappings
 * - **Sequences:** `- item`, nested indentation-based sequences
 * - **Nested Structures:** mapping-in-sequence, sequence-in-mapping,
 *   deeply nested combinations
 * - **Comments:** `# full line` and `# inline`
 * - **Quoted Strings:** double-quoted (`"..."` with escapes), single-quoted
 *   (`'...'` literal)
 * - **Block Scalars:** literal (`|`), folded (`>`)
 * - **Inline Mapping:** `{key: val, ...}`
 * - **Inline Sequence:** `[a, b, c]`
 * - **Multi-document:** `---` separator
 *
 * ## Conversion Rules
 *
 * ### Decoding (YAML → Array)
 * - Top-level keys become top-level array entries.
 * - Mappings become associative arrays.
 * - Sequences become indexed arrays.
 * - Boolean-like strings (`true`, `false`, `yes`, `no`, `on`, `off`)
 *   are converted to PHP booleans.
 * - Null-like strings (`null`, `~`, empty) become `null`.
 * - Numeric strings are parsed to int/float where applicable.
 *
 * ### Encoding (Array → YAML)
 * - Associative arrays become `key: value` mappings.
 * - Indexed arrays become `- item` sequences.
 * - Strings are quoted only when necessary (contain special chars).
 * - Booleans are encoded as `true` / `false`.
 * - Null values are encoded as `~`.
 * - Nested structures use 2-space indentation.
 */
final class YamlDriver implements DriverInterface
{
    /**
     * Default indentation in spaces.
     */
    private const INDENT = 2;

    /**
     * Maximum nesting depth for decode/encode.
     */
    private readonly int $maxDepth;

    /**
     * @param int $maxDepth Maximum nesting depth (default 128). Throws
     *                       DecodeException when exceeded during parsing.
     */
    public function __construct(
        int $maxDepth = 128,
    ) {
        $this->maxDepth = \max(1, $maxDepth);
    }

    #[\Override]
    public function supportedSyntax(): Syntax
    {
        return Syntax::YAML;
    }

    // ─── Decode ──────────────────────────────────────────────────────

    /**
     * Decode a YAML string into a PHP array.
     *
     * @param  string $input The YAML string to decode.
     * @return array<mixed>   The decoded PHP array.
     *
     * @throws DecodeException When the input cannot be parsed.
     */
    #[\Override]
    public function decode(string $input): array
    {
        $trimmed = \trim($input);

        if ($trimmed === '') {
            return [];
        }

        $lines = \explode("\n", $trimmed);
        $normalised = $this->normaliseLines($lines);

        if ($normalised === []) {
            return [];
        }

        return $this->parseYamlLines($normalised);
    }

    // ─── Encode ──────────────────────────────────────────────────────

    /**
     * Encode a PHP array into a YAML string.
     *
     * @param  array<mixed> $data The PHP array to encode.
     * @return string              The formatted YAML string.
     */
    #[\Override]
    public function encode(array $data): string
    {
        if ($data === []) {
            return "{}\n";
        }

        $lines = [];
        $this->encodeNode($data, $lines, 0);
        $output = \implode("\n", $lines) . "\n";

        return $output;
    }

    // ─── Private: Line Normalisation ─────────────────────────────────

    /**
     * Normalise raw YAML lines: strip comments, blank lines, handle
     * multi-document separators.
     *
     * @param  list<string> $lines
     * @return list<string>
     */
    private function normaliseLines(array $lines): array
    {
        $result = [];

        foreach ($lines as $raw) {
            $stripped = $this->stripComment($raw);

            if ($stripped === '' || $stripped === '---') {
                continue;
            }

            $result[] = $stripped;
        }

        return $result;
    }

    /**
     * Strip YAML comments (#), respecting string boundaries.
     */
    private function stripComment(string $line): string
    {
        $inDouble = false;
        $inSingle = false;
        $escape = false;
        $len = \strlen($line);

        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($ch === '\\' && $inDouble) {
                $escape = true;
                continue;
            }

            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                continue;
            }

            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                continue;
            }

            if ($ch === '#' && !$inDouble && !$inSingle) {
                return \rtrim(\substr($line, 0, $i));
            }
        }

        return \rtrim($line);
    }

    // ─── Private: Core Parser ────────────────────────────────────────

    /**
     * Parse a list of normalised YAML lines into a PHP array.
     *
     * Uses an indentation stack to track nesting levels. Each stack entry
     * holds a reference to the current container, whether it's a sequence,
     * its indentation level, and for mappings, the pending key.
     *
     * @param  list<string> $lines
     * @return array<mixed>
     */
    private function parseYamlLines(array $lines): array
    {
        $result = [];
        $stack = [
            $this->makeFrame($result, false, -1, null),
        ];
        $blockScalar = null;

        // Track nesting depth to enforce maxDepth limit
        $currentDepth = 0;

        $i = 0;
        $lineCount = \count($lines);

        while ($i < $lineCount) {
            $rawLine = $lines[$i];
            $indent = \strlen($rawLine) - \strlen(\ltrim($rawLine));
            $content = \ltrim($rawLine);

            // Block scalar continuation
            if ($blockScalar !== null) {
                if ($indent >= $blockScalar['indent'] || \trim($rawLine) === '') {
                    $blockScalar['lines'][] = \substr($rawLine, $blockScalar['indent']);
                    $i++;
                    continue;
                }

                $this->flushBlockScalar($stack, $blockScalar, \count($stack) - 1);
                $blockScalar = null;
                continue;
            }

            if ($content === '') {
                $i++;
                continue;
            }

            // Pop stack to current indentation
            while (\count($stack) > 1 && $indent < $stack[\count($stack) - 1]['indent']) {
                $idx = \count($stack) - 1;

                if ($stack[$idx - 1]['key'] !== null && !$stack[$idx - 1]['is_seq']) {
                    $stack[$idx - 1]['container'][$stack[$idx - 1]['key']] = $stack[$idx]['container'];
                    $stack[$idx - 1]['key'] = null;
                }

                \array_pop($stack);
                $currentDepth--;
            }

            $top = &$stack[\count($stack) - 1];

            // Sequence item: "- value" or "- "
            // Enforce depth limit before pushing new frames
            if ($currentDepth >= $this->maxDepth) {
                throw new DecodeException(
                    \sprintf(
                        'Maximum nesting depth of %d exceeded near line %d',
                        $this->maxDepth,
                        $i + 1,
                    ),
                );
            }

            if (\str_starts_with($content, '- ')) {
                $valuePart = \substr($content, 2);
                $i = $this->parseSequenceItem(
                    $top,
                    $stack,
                    $valuePart,
                    $indent,
                    $i,
                    $lines,
                    $blockScalar,
                );
                $i++;
                continue;
            }

            // Bare dash with no space
            if (\ltrim($content) === '-') {
                $newIdx = \count($top['container']);
                $top['container'][] = [];
                $stack[] = $this->makeFrame($top['container'][$newIdx], true, $indent + 1, null);
                $currentDepth++;
                $i++;
                continue;
            }

            // Mapping: key: value
            if (\str_contains($content, ': ')) {
                $colonPos = $this->findColonOutsideQuotes($content);

                if ($colonPos !== null) {
                    $i = $this->parseMappingLine(
                        $top,
                        $stack,
                        $content,
                        $colonPos,
                        $indent,
                        $i,
                        $lines,
                        $blockScalar,
                    );
                    $i++;
                    continue;
                }
            }

            // Bare colon at end of line
            if (\str_ends_with($content, ':') && !\str_ends_with($content, '::')) {
                $colonPos = $this->findColonOutsideQuotes($content);

                if ($colonPos !== null && $colonPos === \strlen($content) - 1) {
                    $key = \trim(\substr($content, 0, $colonPos));
                    $key = $this->unquoteString($key);
                    $top['container'][$key] = [];
                    $stack[] = $this->makeFrame($top['container'][$key], false, $indent + 1, null);
                    $currentDepth++;
                    $i++;
                    continue;
                }
            }

            // Fallback: try to find any colon
            $colonPos = $this->findColonOutsideQuotes($content);

            if ($colonPos !== null) {
                $i = $this->parseMappingLine(
                    $top,
                    $stack,
                    $content,
                    $colonPos,
                    $indent,
                    $i,
                    $lines,
                    $blockScalar,
                );
                $i++;
                continue;
            }

            // Bare scalar
            if ($top['is_seq']) {
                $top['container'][] = $this->parseScalar($content);
            }

            $i++;
        }

        // Flush pending block scalar
        if ($blockScalar !== null) {
            $this->flushBlockScalar($stack, $blockScalar, \count($stack) - 1);
        }

        // Close remaining containers
        while (\count($stack) > 1) {
            $idx = \count($stack) - 1;

            if ($stack[$idx - 1]['key'] !== null && !$stack[$idx - 1]['is_seq']) {
                $stack[$idx - 1]['container'][$stack[$idx - 1]['key']] = $stack[$idx]['container'];
                $stack[$idx - 1]['key'] = null;
            }

            \array_pop($stack);
        }

        return $result;
    }

    /**
     * Parse a sequence item line.
     *
     * @param  array{container: array, is_seq: bool, indent: int, key: string|null} &$top
     * @param  array<int, array{container: array, is_seq: bool, indent: int, key: string|null}> &$stack
     * @param  list<string> $lines
     * @param  array{key: null|string, type: string, indent: int,
     *   lines: list<string>, target?: mixed}|null &$blockScalar
     * @return int The line index to continue from.
     */
    private function parseSequenceItem(
        array &$top,
        array &$stack,
        string $valuePart,
        int $indent,
        int $lineIndex,
        array $lines,
        array|null &$blockScalar,
    ): int {
        $valuePart = \trim($valuePart);

        if ($valuePart === '') {
            $newIdx = \count($top['container']);
            $top['container'][] = [];
            $stack[] = $this->makeFrame($top['container'][$newIdx], true, $indent + 1, null);

            return $lineIndex;
        }

        // Depth check before any stack push
        $frameDepth = \count($stack);

        if ($frameDepth >= $this->maxDepth) {
            throw new DecodeException(
                \sprintf(
                    'Maximum nesting depth of %d exceeded near line %d',
                    $this->maxDepth,
                    $lineIndex + 1,
                ),
            );
        }

        // Block scalar as sequence item
        if ($valuePart === '|' || $valuePart === '>') {
            $top['container'][] = '';
            $lastIdx = \count($top['container']) - 1;
            $blockScalar = [
                'key' => null,
                'type' => $valuePart,
                'indent' => $indent + self::INDENT,
                'lines' => [],
                'target' => &$top['container'][$lastIdx],
            ];

            return $lineIndex;
        }

        if (\str_starts_with($valuePart, '{')) {
            $top['container'][] = $this->parseInlineMapping(\substr($valuePart, 1, -1));

            return $lineIndex;
        }

        if (\str_starts_with($valuePart, '[')) {
            $top['container'][] = $this->parseInlineSequence(\substr($valuePart, 1, -1));

            return $lineIndex;
        }

        // Check for inline key: value after dash
        $inlineColon = $this->findColonOutsideQuotes($valuePart);

        if ($inlineColon !== null && !\str_starts_with($valuePart, '"') && !\str_starts_with($valuePart, "'")) {
            // This is a mapping inline with dash: - key: value
            $key = \trim(\substr($valuePart, 0, $inlineColon));
            // Validate the key looks like a bare YAML key (not a URL like http://)
            if (!\preg_match('/^[A-Za-z0-9_\-\.]+$/', $key)) {
                $top['container'][] = $this->parseScalar($valuePart);

                return $lineIndex;
            }

            $val = \trim(\substr($valuePart, $inlineColon + 1));
            $key = $this->unquoteString($key);

            if ($val === '') {
                $top['container'][] = [$key => []];
                $lastIdx = \count($top['container']) - 1;
                $stack[] = $this->makeFrame($top['container'][$lastIdx][$key], false, $indent + 1, null);
            } else {
                $top['container'][] = [$key => $this->parseScalar($val)];
                $lastIdx = \count($top['container']) - 1;
                $stack[] = $this->makeFrame(
                    $top['container'][$lastIdx],
                    false,
                    $indent + self::INDENT,
                    null,
                );
            }

            $frameDepth = \count($stack);

            if ($frameDepth >= $this->maxDepth) {
                throw new DecodeException(
                    \sprintf(
                        'Maximum nesting depth of %d exceeded near line %d',
                        $this->maxDepth,
                        $lineIndex + 1,
                    ),
                );
            }

            return $lineIndex;
        }

        $top['container'][] = $this->parseScalar($valuePart);

        return $lineIndex;
    }

    /**
     * Parse a mapping line (key: value).
     *
     * @param  array{container: array, is_seq: bool, indent: int, key: string|null} &$top
     * @param  array<int, array{container: array, is_seq: bool, indent: int, key: string|null}> &$stack
     * @param  list<string> $lines
     * @param  array{key: null|string, type: string, indent: int,
     *   lines: list<string>, target?: mixed}|null &$blockScalar
     */
    private function parseMappingLine(
        array &$top,
        array &$stack,
        string $content,
        int $colonPos,
        int $indent,
        int $lineIndex,
        array $lines,
        array|null &$blockScalar,
    ): int {
        $key = \trim(\substr($content, 0, $colonPos));
        $valuePart = \trim(\substr($content, $colonPos + 1));
        $key = $this->unquoteString($key);

        // Block scalar indicator
        if ($valuePart === '|' || $valuePart === '>') {
            $top['container'][$key] = '';
            $blockScalar = [
                'key' => $key,
                'type' => $valuePart,
                'indent' => $indent + self::INDENT,
                'lines' => [],
                'target' => &$top['container'][$key],
            ];

            return $lineIndex;
        }

        // Nested structure (no value after colon)
        if ($valuePart === '') {
            $top['container'][$key] = [];
            $stack[] = $this->makeFrame($top['container'][$key], false, $indent + 1, null);

            return $lineIndex;
        }

        // Inline mapping
        if (\str_starts_with($valuePart, '{')) {
            $top['container'][$key] = $this->parseInlineMapping(\substr($valuePart, 1, -1));

            return $lineIndex;
        }

        // Inline sequence
        if (\str_starts_with($valuePart, '[')) {
            $top['container'][$key] = $this->parseInlineSequence(\substr($valuePart, 1, -1));

            return $lineIndex;
        }

        // Scalar value
        $top['container'][$key] = $this->parseScalar($valuePart);

        return $lineIndex;
    }

    /**
     * Create a stack frame.
     *
     * @param  array       &$container
     * @param  bool         $isSeq
     * @param  int          $indent
     * @param  string|null  $key
     * @return array{container: array, is_seq: bool, indent: int, key: string|null}
     */
    private function &makeFrame(
        array &$container,
        bool $isSeq,
        int $indent,
        ?string $key,
    ): array {
        $frame = [
            'container' => null,
            'is_seq' => $isSeq,
            'indent' => $indent,
            'key' => $key,
        ];
        $frame['container'] = &$container;

        return $frame;
    }

    // ─── Private: Block Scalars ──────────────────────────────────────

    /**
     * Flush accumulated block scalar lines into the target.
     *
     * @param array<int, array{container: array, is_seq: bool, indent: int, key: string|null}> &$stack
     * @param array{key: string|null, type: string, indent: int, lines: list<string>, target?: mixed} &$scalar
     */
    private function flushBlockScalar(
        array &$stack,
        array &$scalar,
        int $stackIdx,
    ): void {
        $text = $scalar['lines'];

        // Strip trailing blank lines
        $last = \count($text) - 1;

        while ($last >= 0 && \trim($text[$last]) === '') {
            \array_pop($text);
            $last--;
        }

        $result = \implode("\n", $text);

        if ($scalar['type'] === '>') {
            $result = (string) \preg_replace('/\n{2,}/', "\n\n", $result);
            $result = \str_replace("\n", ' ', $result);
            $result = \trim($result);
        }

        $result = \rtrim($result);

        if (isset($scalar['target'])) {
            $scalar['target'] = $result;
        }
    }

    // ─── Private: Inline Parsers ─────────────────────────────────────

    /**
     * Parse an inline YAML mapping like `{key: val, key2: val2}`.
     *
     * @return array<string, mixed>
     */
    private function parseInlineMapping(string $inner): array
    {
        $result = [];
        $trimmed = \trim($inner);

        if ($trimmed === '') {
            return $result;
        }

        $pairs = $this->splitByCommaOutsideQuotes($trimmed);

        foreach ($pairs as $pair) {
            $trimmedPair = \trim($pair);

            if ($trimmedPair === '') {
                continue;
            }

            $colonPos = $this->findColonOutsideQuotes($trimmedPair);

            if ($colonPos === null) {
                continue;
            }

            $key = \trim(\substr($trimmedPair, 0, $colonPos));
            $val = \trim(\substr($trimmedPair, $colonPos + 1));
            $key = $this->unquoteString($key);

            if (\str_starts_with($val, '{')) {
                $result[$key] = $this->parseInlineMapping(\substr($val, 1, -1));
            } elseif (\str_starts_with($val, '[')) {
                $result[$key] = $this->parseInlineSequence(\substr($val, 1, -1));
            } else {
                $result[$key] = $this->parseScalar($val);
            }
        }

        return $result;
    }

    /**
     * Parse an inline YAML sequence like `[a, b, c]`.
     *
     * @return list<mixed>
     */
    private function parseInlineSequence(string $inner): array
    {
        $result = [];
        $trimmed = \trim($inner);

        if ($trimmed === '') {
            return $result;
        }

        $items = $this->splitByCommaOutsideQuotes($trimmed);

        foreach ($items as $item) {
            $trimmedItem = \trim($item);

            if ($trimmedItem === '') {
                continue;
            }

            if (\str_starts_with($trimmedItem, '{')) {
                $result[] = $this->parseInlineMapping(\substr($trimmedItem, 1, -1));
            } elseif (\str_starts_with($trimmedItem, '[')) {
                $result[] = $this->parseInlineSequence(\substr($trimmedItem, 1, -1));
            } else {
                $result[] = $this->parseScalar($trimmedItem);
            }
        }

        return $result;
    }

    // ─── Private: Scalar Parsing ─────────────────────────────────────

    /**
     * Parse a YAML scalar value into its PHP equivalent.
     */
    private function parseScalar(string $value): mixed
    {
        $trimmed = \trim($value);

        if ($trimmed === '') {
            return null;
        }

        // Quoted strings
        if (
            ($trimmed[0] === '"' && \str_ends_with($trimmed, '"'))
            || ($trimmed[0] === "'" && \str_ends_with($trimmed, "'"))
        ) {
            return $this->parseQuotedString($trimmed);
        }

        $lower = \strtolower($trimmed);

        // Null values
        if ($lower === 'null' || $lower === '~') {
            return null;
        }

        // Booleans
        if ($lower === 'true' || $lower === 'yes' || $lower === 'on') {
            return true;
        }

        if ($lower === 'false' || $lower === 'no' || $lower === 'off') {
            return false;
        }

        // Hex integer
        if (\preg_match('/^[+-]?0[xX][0-9a-fA-F_]+$/', $trimmed)) {
            $cleaned = \ltrim(\str_replace('_', '', $trimmed), '+');
            $neg = $cleaned[0] === '-';
            $digits = \substr(\ltrim($cleaned, '-'), 2);

            return $neg ? -\intval($digits, 16) : \intval($digits, 16);
        }

        // Octal integer
        if (\preg_match('/^[+-]?0[oO][0-7_]+$/', $trimmed)) {
            $cleaned = \ltrim(\str_replace('_', '', $trimmed), '+');
            $neg = $cleaned[0] === '-';
            $digits = \substr(\ltrim($cleaned, '-'), 2);

            return $neg ? -\intval($digits, 8) : \intval($digits, 8);
        }

        // Numeric
        if (\preg_match('/^[+-]?\d+(\.\d+)?([eE][+-]?\d+)?$/', $trimmed)) {
            if (\str_contains($trimmed, '.') || \preg_match('/[eE]/', $trimmed)) {
                return (float) $trimmed;
            }

            return (int) $trimmed;
        }

        // Special floats
        if (\in_array($lower, ['.inf', '+.inf', '.infinity', '+.infinity'], true)) {
            return \INF;
        }

        if (\in_array($lower, ['-.inf', '-.infinity'], true)) {
            return -\INF;
        }

        if ($lower === '.nan') {
            return \NAN;
        }

        // String
        return $trimmed;
    }

    /**
     * Parse a quoted string, processing escape sequences for double quotes.
     */
    private function parseQuotedString(string $value): string
    {
        $quote = $value[0];
        $inner = \substr($value, 1, -1);

        if ($quote === "'") {
            return \str_replace("''", "'", $inner);
        }

        return $this->decodeDoubleQuoted($inner);
    }

    /**
     * Decode a double-quoted string, processing YAML escape sequences.
     */
    private function decodeDoubleQuoted(string $value): string
    {
        $result = '';
        $len = \strlen($value);
        $i = 0;

        while ($i < $len) {
            if ($value[$i] === '\\' && $i + 1 < $len) {
                $next = $value[$i + 1];

                $result .= match ($next) {
                    '0' => "\x00",
                    'a' => "\x07",
                    'b' => "\x08",
                    't' => "\t",
                    'n' => "\n",
                    'v' => "\x0B",
                    'f' => "\x0C",
                    'r' => "\r",
                    'e' => "\x1B",
                    ' ' => ' ',
                    '"' => '"',
                    '/' => '/',
                    '\\' => '\\',
                    'N' => "\u{0085}",
                    '_' => "\u{00A0}",
                    'L' => "\u{2028}",
                    'P' => "\u{2029}",
                    'x' => $this->decodeHexEscape($value, $i + 2, 2),
                    'u' => $this->decodeHexEscape($value, $i + 2, 4),
                    'U' => $this->decodeHexEscape($value, $i + 2, 8),
                    default => $next,
                };

                // Hex escapes need additional advancement past the hex digits
                $i += match ($next) {
                    'x' => 4,
                    'u' => 6,
                    'U' => 10,
                    default => 2,
                };
                continue;
            }

            $result .= $value[$i];
            $i++;
        }

        return $result;
    }

    /**
     * Decode a hex escape sequence like \xNN, \uNNNN, \UNNNNNNNN.
     */
    private function decodeHexEscape(string $value, int $start, int $length): string
    {
        $hex = \substr($value, $start, $length);

        if (\preg_match('/^[0-9a-fA-F]{' . $length . '}$/', $hex)) {
            $codePoint = \intval($hex, 16);

            if ($codePoint <= 0x10FFFF) {
                if ($codePoint < 0x80) {
                    return \chr($codePoint);
                }

                if ($codePoint < 0x800) {
                    return \chr(0xC0 | ($codePoint >> 6))
                        . \chr(0x80 | ($codePoint & 0x3F));
                }

                if ($codePoint < 0x10000) {
                    return \chr(0xE0 | ($codePoint >> 12))
                        . \chr(0x80 | (($codePoint >> 6) & 0x3F))
                        . \chr(0x80 | ($codePoint & 0x3F));
                }

                return \chr(0xF0 | ($codePoint >> 18))
                    . \chr(0x80 | (($codePoint >> 12) & 0x3F))
                    . \chr(0x80 | (($codePoint >> 6) & 0x3F))
                    . \chr(0x80 | ($codePoint & 0x3F));
            }
        }

        return '';
    }

    // ─── Private: String Utilities ───────────────────────────────────

    /**
     * Unquote a string if it's quoted.
     */
    private function unquoteString(string $value): string
    {
        $trimmed = \trim($value);

        if ($trimmed === '') {
            return $trimmed;
        }

        if (
            ($trimmed[0] === '"' && \str_ends_with($trimmed, '"'))
            || ($trimmed[0] === "'" && \str_ends_with($trimmed, "'"))
        ) {
            return $this->parseQuotedString($trimmed);
        }

        return $trimmed;
    }

    /**
     * Find the first ':' that is not inside quotes.
     */
    private function findColonOutsideQuotes(string $line): ?int
    {
        $inDouble = false;
        $inSingle = false;
        $escape = false;
        $len = \strlen($line);

        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($ch === '\\' && $inDouble) {
                $escape = true;
                continue;
            }

            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                continue;
            }

            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                continue;
            }

            if ($inDouble || $inSingle) {
                continue;
            }

            if ($ch === ':') {
                if ($i + 1 < $len) {
                    $next = $line[$i + 1];

                    if ($next === ' ' || $next === "\t") {
                        return $i;
                    }

                    if (\in_array($next, ['{', '[', '|', '>'], true)) {
                        return $i;
                    }

                    // Allow colon without space for inline cases like key:val
                    if ($i === 0) {
                        continue;
                    }

                    // Relaxed: allow colon followed by non-space for inline values
                    // Only if this is NOT a URL-like pattern (word:// or word@)
                    if ($next === '/' || $next === '\\' || $next === '@') {
                        continue;
                    }

                    return $i;
                }

                return $i;
            }
        }

        return null;
    }

    /**
     * Split a string by commas outside quotes and nested brackets.
     *
     * @return list<string>
     */
    private function splitByCommaOutsideQuotes(string $input): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $inDouble = false;
        $inSingle = false;
        $escape = false;
        $len = \strlen($input);

        for ($i = 0; $i < $len; $i++) {
            $ch = $input[$i];

            if ($escape) {
                $current .= $ch;
                $escape = false;
                continue;
            }

            if ($ch === '\\' && $inDouble) {
                $current .= $ch;
                $escape = true;
                continue;
            }

            if ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
                $current .= $ch;
                continue;
            }

            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
                $current .= $ch;
                continue;
            }

            if ($inDouble || $inSingle) {
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
                $parts[] = \trim($current);
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

    // ─── Private: Encode ─────────────────────────────────────────────

    /**
     * Recursively encode a data node into YAML lines.
     *
     * @param  mixed        $data     The node to encode.
     * @param  list<string> &$lines   Accumulated output lines.
     * @param  int          $indent   Current indentation level.
     */
    private function encodeNode(
        mixed $data,
        array &$lines,
        int $indent,
    ): void {
        $prefix = \str_repeat(' ', $indent);

        if (!\is_array($data)) {
            $lines[] = $prefix . $this->encodeInlineValue($data);

            return;
        }

        if ($data === []) {
            $lines[] = $prefix . '{}';

            return;
        }

        if (\array_is_list($data)) {
            // Sequence
            foreach ($data as $item) {
                if (\is_array($item) && !\array_is_list($item)) {
                    // Sequence of mappings
                    $this->encodeSequenceMapping($item, $lines, $indent);
                } else {
                    // Scalar or list items in sequence
                    $valueStr = $this->encodeInlineValue($item);
                    $lines[] = $prefix . '- ' . $valueStr;
                }
            }
        } else {
            // Mapping
            foreach ($data as $key => $value) {
                $keyStr = $this->encodeKey($key);

                if (\is_array($value) && !\array_is_list($value)) {
                    $lines[] = $prefix . $keyStr . ':';
                    $this->encodeMappingValue($value, $lines, $indent + self::INDENT);
                } else {
                    $lines[] = $prefix . $keyStr . ': ' . $this->encodeInlineValue($value);
                }
            }
        }
    }

    /**
     * Encode a mapping within a sequence (the - prefix only on the first key).
     *
     * @param  array<mixed> $data
     * @param  list<string> &$lines
     * @param  int          $indent
     */
    private function encodeSequenceMapping(
        array $data,
        array &$lines,
        int $indent,
    ): void {
        $prefix = \str_repeat(' ', $indent);
        $first = true;

        foreach ($data as $key => $value) {
            $keyStr = $this->encodeKey($key);

            if ($first) {
                $line = $prefix . '- ' . $keyStr . ': ';
            } else {
                $line = \str_repeat(' ', $indent + 2) . $keyStr . ': ';
            }

            if (\is_array($value) && !\array_is_list($value)) {
                // Nested values align under the key at indent + 4
                // (key is at indent + 2 after '- ', children at +2 more)
                $lines[] = $line;
                $this->encodeMappingValue($value, $lines, $indent + self::INDENT + self::INDENT);
            } else {
                $lines[] = $line . $this->encodeInlineValue($value);
            }

            $first = false;
        }
    }

    /**
     * Encode a mapping value (sub-keys indented on subsequent lines).
     *
     * @param  array<mixed> $data
     * @param  list<string> &$lines
     * @param  int          $indent
     */
    private function encodeMappingValue(
        array $data,
        array &$lines,
        int $indent,
    ): void {
        foreach ($data as $key => $value) {
            $keyStr = $this->encodeKey($key);
            $prefix = \str_repeat(' ', $indent);
            $line = $prefix . $keyStr . ': ';

            if (\is_array($value) && !\array_is_list($value)) {
                $lines[] = $line;
                $this->encodeMappingValue($value, $lines, $indent + self::INDENT);
            } elseif (\is_array($value) && \array_is_list($value)) {
                $lines[] = $line . $this->encodeInlineValue($value);
            } else {
                $lines[] = $line . $this->encodeInlineValue($value);
            }
        }
    }

    /**
     * Encode a value inline (for inline sequences, inline mappings, scalars).
     */
    private function encodeInlineValue(mixed $value): string
    {
        if ($value === null) {
            return '~';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        if (\is_float($value)) {
            if (\is_infinite($value)) {
                return $value === -\INF ? '-.inf' : '.inf';
            }

            if (\is_nan($value)) {
                return '.nan';
            }

            $str = (string) $value;

            if (!\str_contains($str, '.')) {
                return $str . '.0';
            }

            return $str;
        }

        if (\is_string($value)) {
            return $this->encodeString($value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $this->encodeString($value->format(\DateTimeInterface::RFC3339));
        }

        if (\is_array($value)) {
            if (\array_is_list($value)) {
                return $this->encodeInlineSequenceValue($value);
            }

            /** @var array<string, mixed> $assoc */
            $assoc = $value;

            return $this->encodeInlineMappingValue($assoc);
        }

        return '~';
    }

    /**
     * Encode a list array as an inline YAML sequence.
     *
     * @param list<mixed> $items
     */
    private function encodeInlineSequenceValue(array $items): string
    {
        if ($items === []) {
            return '[]';
        }

        $parts = [];

        foreach ($items as $item) {
            $parts[] = $this->encodeInlineValue($item);
        }

        return '[' . \implode(', ', $parts) . ']';
    }

    /**
     * Encode an associative array as an inline YAML mapping.
     *
     * @param array<string, mixed> $data
     */
    private function encodeInlineMappingValue(array $data): string
    {
        if ($data === []) {
            return '{}';
        }

        $parts = [];

        foreach ($data as $key => $value) {
            $parts[] = $this->encodeKey($key) . ': ' . $this->encodeInlineValue($value);
        }

        return '{ ' . \implode(', ', $parts) . ' }';
    }

    /**
     * Encode a key, quoting if necessary.
     */
    private function encodeKey(string $key): string
    {
        if (\preg_match('/^[A-Za-z0-9_\-]+$/', $key)) {
            return $key;
        }

        return $this->encodeString($key, true);
    }

    /**
     * Encode a string for YAML output, quoting when needed.
     *
     * @param  bool $forceQuote Always quote the string.
     */
    private function encodeString(string $value, bool $forceQuote = false): string
    {
        if ($value === '') {
            return "''";
        }

        $lower = \strtolower($value);

        $looksLikeBool = \in_array($lower, ['true', 'false', 'yes', 'no', 'on', 'off'], true);
        $looksLikeNull = \in_array($lower, ['null', '~'], true);
        $looksLikeNumber = \is_numeric($value);
        $needsQuoting = \str_contains($value, ': ')
            || \str_contains($value, '#')
            || \str_contains($value, "\n")
            || \str_contains($value, ', ')
            || \str_contains($value, '[')
            || \str_contains($value, ']')
            || \str_contains($value, '{')
            || \str_contains($value, '}')
            || \trim($value) !== $value;

        $hasSpecial = $needsQuoting
            || $looksLikeBool
            || $looksLikeNull
            || $looksLikeNumber
            || $forceQuote;

        if (!$hasSpecial) {
            return $value;
        }

        // Prefer single quotes (simpler), fall back to double if value contains '
        if (\str_contains($value, "'")) {
            return '"' . $this->escapeDoubleQuoted($value) . '"';
        }

        return "'" . $value . "'";
    }

    /**
     * Escape a string for double-quoted YAML output.
     */
    private function escapeDoubleQuoted(string $value): string
    {
        $result = '';
        $len = \strlen($value);

        for ($i = 0; $i < $len; $i++) {
            $ch = $value[$i];
            $code = \ord($ch);

            $result .= match (true) {
                $ch === '\\' => '\\\\',
                $ch === '"' => '\\"',
                $ch === "\x00" => '\\0',
                $ch === "\x07" => '\\a',
                $ch === "\x08" => '\\b',
                $ch === "\t" => '\\t',
                $ch === "\n" => '\\n',
                $ch === "\x0B" => '\\v',
                $ch === "\x0C" => '\\f',
                $ch === "\r" => '\\r',
                $ch === "\x1B" => '\\e',
                $code < 0x20 || $code === 0x7F => '\\x' . \strtoupper(\dechex($code)),
                default => $ch,
            };
        }

        return $result;
    }
}
