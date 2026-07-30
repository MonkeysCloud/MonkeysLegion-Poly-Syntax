<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Stream;

use Monkeyslegion\PolySyntax\Contract\StreamingDecoderInterface;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;

/**
 * Streaming CSV decoder with incremental feed-and-drain API.
 *
 * Handles multi-line quoted fields across chunk boundaries by tracking
 * quote state during line-splitting.
 *
 * ## Usage
 *
 * ```php
 * $decoder = new CsvStreamingDecoder();
 * $decoder->feed("name,age\nAlice,30\nBob,25");
 * $decoder->end();
 *
 * while (($row = $decoder->next()) !== null) {
 *     process($row); // ['name' => 'Alice', 'age' => '30']
 * }
 * ```
 */
final class CsvStreamingDecoder implements StreamingDecoderInterface
{
    private string $buffer = '';

    /** @var list<string>|null */
    private ?array $headers = null;

    /** @var list<array<string, string>|list<string>> */
    private array $queue = [];

    private bool $ended = false;

    private int $pos = 0;

    private string $delimiter;

    private string $enclosure;

    /** @var list<string>|null */
    private ?array $manualHeaders;

    private bool $hasHeaders;

    /**
     * @param list<string>|null $headers    Manual headers (null = auto-detect from first row).
     * @param bool              $hasHeaders Whether the first row contains headers.
     * @param string            $delimiter  Field delimiter (single byte).
     * @param string            $enclosure  Field enclosure character (single byte).
     */
    public function __construct(
        ?array $headers = null,
        bool $hasHeaders = true,
        string $delimiter = ',',
        string $enclosure = '"',
    ) {
        if (\strlen($delimiter) !== 1) {
            throw new \InvalidArgumentException(
                \sprintf('Delimiter must be a single character, got %d', \strlen($delimiter)),
            );
        }

        if (\strlen($enclosure) !== 1) {
            throw new \InvalidArgumentException(
                \sprintf('Enclosure must be a single character, got %d', \strlen($enclosure)),
            );
        }

        $this->delimiter = $delimiter;
        $this->enclosure = $enclosure;
        $this->hasHeaders = $hasHeaders;
        $this->manualHeaders = $headers;
    }

    public function feed(string $chunk): void
    {
        if ($this->ended) {
            throw new DecodeException('Cannot feed data after end()');
        }

        $this->buffer .= $chunk;
        $this->flushLines();
    }

    public function end(): void
    {
        $this->ended = true;

        // Flush remaining buffer
        $trimmed = \trim($this->buffer);

        if ($trimmed !== '') {
            $this->processLine($trimmed);
        }

        $this->buffer = '';
    }

    /**
     * @return array<string, string>|list<string>|null
     */
    public function next(): array|null
    {
        if ($this->queue === []) {
            return null;
        }

        $row = \array_shift($this->queue);
        $this->pos++;

        return $row;
    }

    public function position(): int
    {
        return $this->pos;
    }

    /**
     * @return list<string>|null
     */
    public function headers(): ?array
    {
        return $this->headers;
    }

    public function supportedSyntax(): Syntax
    {
        return Syntax::CSV;
    }

    public function reset(): void
    {
        $this->buffer = '';
        $this->headers = null;
        $this->queue = [];
        $this->ended = false;
        $this->pos = 0;
    }

    /**
     * @param list<string> $headers
     */
    public function setHeaders(array $headers): void
    {
        /** @var list<string> $headers */
        $this->manualHeaders = $headers;
        /** @var list<string> $headers */
        $this->headers = $headers;
        $this->hasHeaders = false; // manual headers override auto-detect
    }

    /**
     * Split the buffer into complete CSV lines, respecting quoted fields
     * that may contain embedded newlines.
     */
    private function flushLines(): void
    {
        if ($this->buffer === '') {
            return;
        }

        // If the buffer ends with \n, the last line extracted by
        // splitCSVLines is actually complete (nothing follows the \n).
        $endsWithNewline = \str_ends_with($this->buffer, "\n");

        $lines = $this->splitCSVLines($this->buffer);

        if ($lines === []) {
            return;
        }

        if ($endsWithNewline) {
            // All lines are complete — process all of them
            foreach ($lines as $line) {
                $this->processLine($line);
            }

            $this->buffer = '';
        } else {
            // The last line is incomplete — keep in buffer, process the rest
            $this->buffer = (string) \array_pop($lines);

            foreach ($lines as $line) {
                $this->processLine($line);
            }
        }
    }

    /**
     * Split CSV data into complete lines, respecting quoted fields.
     *
     * A newline inside a quoted field (enclosure chars) is NOT a line
     * boundary. This method walks the buffer character by character
     * tracking quote state.
     *
     * @return list<string>
     */
    private function splitCSVLines(string $data): array
    {
        $lines = [];
        $current = '';
        $inQuote = false;
        $len = \strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $ch = $data[$i];

            if ($ch === "\n" && !$inQuote) {
                $lines[] = $current;
                $current = '';
                continue;
            }

            if ($ch === $this->enclosure) {
                // Toggle quote state (handling escaped enclosures like "")
                // Only toggle if we're not escaping
                $inQuote = !$inQuote;
            }

            $current .= $ch;
        }

        // Keep trailing data (after the last newline or inside a quoted field)
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function processLine(string $line): void
    {
        /** @var list<string> $row */
        $row = \str_getcsv($line, $this->delimiter, $this->enclosure);

        if (!\is_array($row) || $row === [null]) {
            return;
        }

        // Resolve headers on first data row
        if ($this->headers === null) {
            if ($this->manualHeaders !== null) {
                $this->headers = $this->manualHeaders;
            } elseif ($this->hasHeaders) {
                $this->headers = $row;
                return; // header row consumed, not yielded
            } else {
                // No headers mode
                $this->headers = [];
            }
        }

        if ($this->headers === []) {
            // No headers mode — yield raw row
            $this->queue[] = $row;
        } else {
            // Map by header
            $mapped = [];

            for ($i = 0, $c = \count($row); $i < $c; $i++) {
                $key = $this->headers[$i] ?? (string) $i;
                $mapped[$key] = $row[$i];
            }

            $this->queue[] = $mapped;
        }
    }
}
