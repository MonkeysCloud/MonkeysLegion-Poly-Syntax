<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Stream;

use Monkeyslegion\PolySyntax\Contract\StreamingDecoderInterface;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;

/**
 * Streaming JSON decoder with incremental feed-and-drain API.
 *
 * Processes a JSON array `[...]` element by element as chunks arrive,
 * buffering incomplete data between feeds.
 *
 * ## Usage
 *
 * ```php
 * $decoder = new JsonStreamingDecoder();
 * $decoder->feed('[{"id":1},{"id":2}]');
 * $decoder->end();
 *
 * while (($row = $decoder->next()) !== null) {
 *     process($row);
 * }
 * ```
 *
 * ## Limitations
 *
 * - Root value MUST be a JSON array (`[...]`).
 * - Elements must be comma-separated at the top level.
 * - Maximum decode depth is 512 (PHP default).
 */
final class JsonStreamingDecoder implements StreamingDecoderInterface
{
    private const DECODE_DEPTH = 512;
    private const DECODE_FLAGS = \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_IGNORE;

    private string $buffer = '';
    private bool $ended = false;
    private int $pos = 0;

    /** @var list<mixed> */
    private array $queue = [];

    /** @var int Bracket-depth tracking for element boundary detection. */
    private int $depth = 0;

    /** @var int Byte offset of the current incomplete element in the buffer. */
    private int $elementStart = 0;

    /** @var bool Whether we've seen the opening `[` of the root array. */
    private bool $inArray = false;

    /** @var bool Whether we're inside a JSON string (for brace/escape tracking). */
    private bool $inString = false;

    /** @var bool Whether the previous char was an escape backslash. */
    private bool $escaped = false;

    public function feed(string $chunk): void
    {
        if ($this->ended) {
            throw new DecodeException('Cannot feed data after end()');
        }

        $this->buffer .= $chunk;
        $this->drain();
    }

    public function end(): void
    {
        $this->ended = true;
        $this->drain();

        // If there's residual non-whitespace in the buffer that hasn't
        // been extracted, try decoding it as a single element.
        if ($this->buffer !== '') {
            $trimmed = \trim($this->buffer);

            if ($trimmed !== '' && $trimmed !== '[' && $trimmed !== ']') {
                try {
                    $this->queue[] = \json_decode(
                        $trimmed,
                        true,
                        self::DECODE_DEPTH,
                        self::DECODE_FLAGS,
                    );
                } catch (\JsonException) {
                    // Silently ignore — incomplete data
                }
            }

            $this->buffer = '';
        }
    }

    public function next(): mixed
    {
        if ($this->queue !== []) {
            $this->pos++;
            return \array_shift($this->queue);
        }

        return null;
    }

    public function supportedSyntax(): Syntax
    {
        return Syntax::JSON;
    }

    public function reset(): void
    {
        $this->buffer = '';
        $this->ended = false;
        $this->pos = 0;
        $this->queue = [];
        $this->depth = 0;
        $this->elementStart = 0;
        $this->inArray = false;
        $this->inString = false;
        $this->escaped = false;
    }

    public function position(): int
    {
        return $this->pos;
    }

    /**
     * Scan the buffer and extract complete top-level JSON elements.
     */
    private function drain(): void
    {
        $len = \strlen($this->buffer);

        if ($len === 0) {
            return;
        }

        // Phase 1: Find the opening `[`
        if (!$this->inArray) {
            $pos = 0;

            while ($pos < $len && \str_contains(" \t\n\r", $this->buffer[$pos])) {
                $pos++;
            }

            if ($pos >= $len) {
                return;
            }

            if ($this->buffer[$pos] !== '[') {
                throw new DecodeException(
                    'JsonStreamingDecoder expects a JSON array starting with \'[\' (top-level array required)',
                );
            }

            $this->inArray = true;
            $this->elementStart = $pos + 1;
            $this->depth = 1;

            if ($pos + 1 >= $len) {
                return;
            }
        }

        // Phase 2: Walk the buffer looking for element boundaries.
        // Reset depth to 1 (inside root array) each time we scan,
        // because element pointers always start after the opening `[`.
        $this->depth = 1;

        for ($i = $this->elementStart; $i < $len; $i++) {
            $ch = $this->buffer[$i];

            // Escape handling
            if ($this->escaped) {
                $this->escaped = false;
                continue;
            }

            if ($ch === '\\' && $this->inString) {
                $this->escaped = true;
                continue;
            }

            // String toggle
            if ($ch === '"') {
                $this->inString = !$this->inString;
                continue;
            }

            // Inside a string — skip bracket tracking
            if ($this->inString) {
                continue;
            }

            // Bracket tracking
            if ($ch === '{' || $ch === '[') {
                $this->depth++;
                continue;
            }

            if ($ch === '}' || $ch === ']') {
                $this->depth--;

                // Root array closing bracket
                if ($this->depth === 0 && $ch === ']') {
                    $elementStr = \trim(
                        \substr($this->buffer, $this->elementStart, $i - $this->elementStart),
                    );

                    if ($elementStr !== '') {
                        $this->queueElement($elementStr);
                    }

                    // Trim consumed portion (including the `]`)
                    $this->buffer = '';
                    $this->inArray = false;
                    return;
                }

                continue;
            }

            // Comma at root array depth (1) = end of current element
            if ($ch === ',' && $this->depth === 1) {
                $elementStr = \trim(
                    \substr($this->buffer, $this->elementStart, $i - $this->elementStart),
                );

                $this->elementStart = $i + 1;

                if ($elementStr !== '') {
                    $this->queueElement($elementStr);
                }

                continue;
            }
        }

        // No complete element found. Reset string/escape state.
        $this->inString = false;
        $this->escaped = false;
    }

    /**
     * Parse and queue a complete JSON element string.
     */
    private function queueElement(string $element): void
    {
        try {
            $this->queue[] = \json_decode($element, true, self::DECODE_DEPTH, self::DECODE_FLAGS);
        } catch (\JsonException $e) {
            throw new DecodeException(
                \sprintf('Failed to decode JSON element: %s', $e->getMessage()),
                previous: $e,
            );
        }
    }
}
