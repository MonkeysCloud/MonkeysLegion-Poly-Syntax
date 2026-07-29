<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Driver;

use Monkeyslegion\PolySyntax\Contract\DriverInterface;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;
use Monkeyslegion\PolySyntax\Exception\EncodeException;

/**
 * Driver for CSV format transformation.
 *
 * Uses native `fgetcsv` / `fputcsv` with configurable delimiter,
 * enclosure, and escape characters. By default the first row is
 * treated as a header row and decoded as associative arrays.
 *
 * ## Decoding (CSV → Array of Arrays)
 * - The first row is used as headers by default.
 * - Each subsequent row is decoded as an associative array keyed by headers.
 * - If `$headers` are manually provided, the first row is treated as data.
 * - Empty input returns an empty array.
 * - Rows are not limited by default — configurable via `$maxRows`.
 *
 * ## Encoding (Array → CSV)
 * - Keys from the first element are used as the header row.
 * - Nested arrays are not supported — values are cast to string.
 * - Empty array input returns an empty string.
 */
final class CsvDriver implements DriverInterface
{
    /**
     * Column delimiter character.
     *
     * @var non-empty-string
     */
    private readonly string $delimiter;

    /**
     * Field enclosure character.
     *
     * @var non-empty-string
     */
    private readonly string $enclosure;

    /**
     * Escape character.
     */
    private readonly string $escape;

    /**
     * Whether the first row contains headers.
     */
    private readonly bool $hasHeaders;

    /**
     * Manual header overrides. When provided the first row is treated as data.
     *
     * @var list<string>|null
     */
    private readonly ?array $headers;

    /**
     * Maximum number of rows to parse (0 = unlimited).
     */
    private readonly int $maxRows;

    /**
     * @param  non-empty-string $delimiter       Field delimiter (default ",").
     * @param  non-empty-string $enclosure       Field enclosure (default '"').
     * @param  string           $escape          Escape character (default "\\").
     * @param  bool             $hasHeaders      Whether the first row is a header row (default true).
     * @param  list<string>|null $headers        Optional manual header override.
     * @param  int              $maxRows         Maximum rows to parse (0 = unlimited, default 0).
     */
    public function __construct(
        string $delimiter = ',',
        string $enclosure = '"',
        string $escape = '\\',
        bool $hasHeaders = true,
        ?array $headers = null,
        int $maxRows = 0,
    ) {
        if (\mb_strlen($delimiter) !== 1) {
            throw new \InvalidArgumentException(
                \sprintf('CSV delimiter must be a single character, got "%s"', $delimiter),
            );
        }

        if (\mb_strlen($enclosure) !== 1) {
            throw new \InvalidArgumentException(
                \sprintf('CSV enclosure must be a single character, got "%s"', $enclosure),
            );
        }

        $this->delimiter = $delimiter;
        $this->enclosure = $enclosure;
        $this->escape = $escape;
        $this->hasHeaders = $hasHeaders;
        $this->headers = $headers;
        $this->maxRows = $maxRows;
    }

    #[\Override]
    public function supportedSyntax(): Syntax
    {
        return Syntax::CSV;
    }

    #[\Override]
    public function decode(string $input): array
    {
        $trimmed = \trim($input);

        if ($trimmed === '') {
            return [];
        }

        /** @var list<list<string>> $rows */
        $rows = [];
        $stream = \fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw new DecodeException('Failed to open temporary stream for CSV parsing');
        }

        try {
            \fwrite($stream, $trimmed);
            \rewind($stream);

            // Read all rows
            while (($row = $this->readCsvRow($stream)) !== null) {
                $rows[] = $row;
            }
        } finally {
            \fclose($stream);
        }

        if ($rows === []) {
            return [];
        }

        // Determine headers
        $headerRow = $this->headers ?? ($this->hasHeaders ? $rows[0] : null);

        if ($headerRow === null) {
            /** @var list<list<string>> */
            return $rows;
        }

        // Shift off the header row if it was auto-detected
        $dataRows = $this->headers !== null || !$this->hasHeaders
            ? $rows
            : \array_slice($rows, 1);

        // Apply maxRows limit to data rows (after header processing)
        if ($this->maxRows > 0 && \count($dataRows) > $this->maxRows) {
            $dataRows = \array_slice($dataRows, 0, $this->maxRows);
        }

        return \array_map(
            fn (array $row): array => $this->applyHeaders($headerRow, $row),
            $dataRows,
        );
    }

    #[\Override]
    public function encode(array $data): string
    {
        if ($data === []) {
            return '';
        }

        /** @var array<string, string> $first */
        $first = $data[0] ?? [];

        if (!\is_array($first)) {
            throw new EncodeException(
                'CSV encoding requires an array of associative arrays',
            );
        }

        $stream = \fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw new EncodeException('Failed to open temporary stream for CSV encoding');
        }

        try {
            // Write header row from keys of the first element
            $headers = \array_keys($first);

            if ($headers !== [] && $this->hasHeaders) {
                $this->writeCsvRow($stream, $headers);
            }

            // Write data rows
            foreach ($data as $row) {
                if (!\is_array($row)) {
                    continue;
                }

                /** @var list<string> $values */
                $values = [];

                foreach ($headers as $key) {
                    $values[] = $this->formatField($row[$key] ?? '');
                }

                $this->writeCsvRow($stream, $values);
            }

            \rewind($stream);
            $result = \stream_get_contents($stream);

            if ($result === false) {
                throw new EncodeException('Failed to read CSV output from stream');
            }

            // Trim trailing newline added by fputcsv
            return \rtrim($result, "\r\n");
        } finally {
            \fclose($stream);
        }
    }

    // ─── Private Helpers ───────────────────────────────────────────

    /**
     * Read a single CSV row from the stream.
     *
     * @param  resource $stream The stream resource.
     * @return list<string>|null The row fields, or null at EOF/error.
     */
    private function readCsvRow($stream): ?array
    {
        $row = \fgetcsv($stream, 0, $this->delimiter, $this->enclosure, $this->escape);

        if (!\is_array($row)) {
            return null;
        }

        return \array_map(\strval(...), $row);
    }

    /**
     * Write a CSV row to the stream.
     *
     * @param  resource    $stream The stream resource.
     * @param  list<string> $fields The fields to write.
     */
    private function writeCsvRow($stream, array $fields): void
    {
        \fputcsv($stream, $fields, $this->delimiter, $this->enclosure, $this->escape);
    }

    /**
     * Apply headers to a row, aligning fields by position.
     *
     * @param  list<string> $headers The header names.
     * @param  list<string> $row     The row values.
     * @return array<string, string>  The associative array.
     */
    private function applyHeaders(array $headers, array $row): array
    {
        $result = [];

        foreach ($headers as $index => $header) {
            $result[$header] = $row[$index] ?? '';
        }

        return $result;
    }

    /**
     * Format a field value for CSV output.
     *
     * @param  mixed  $value The raw value.
     * @return string        The formatted string.
     */
    private function formatField(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        if (\is_float($value) || \is_int($value)) {
            return (string) $value;
        }

        if (\is_string($value)) {
            return $value;
        }

        return '';
    }
}
