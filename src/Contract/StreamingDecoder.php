<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Contract;

use Monkeyslegion\PolySyntax\Exception\DecodeException;

/**
 * Contract for streaming/decoding data incrementally from chunks.
 *
 * Unlike {@see DriverInterface::decode()} which loads the entire payload
 * into memory, a streaming decoder processes data in chunks, yielding
 * one decoded row at a time. This is essential for large files where
 * the full payload would exceed available memory.
 *
 * ## Usage
 *
 * ```php
 * $decoder = new CsvStreamingDecoder();
 *
 * foreach (file('./large-file.csv', FILE_IGNORE_NEW_LINES) as $line) {
 *     $decoder->feed($line);
 *
 *     while (($row = $decoder->next()) !== null) {
 *         // Process one row at a time
 *     }
 * }
 *
 * $decoder->end();
 *
 * while (($row = $decoder->next()) !== null) {
 *     // Process remaining rows from the last chunk
 * }
 * ```
 */
interface StreamingDecoder
{
    /**
     * Feed a chunk of input data to the decoder.
     *
     * Chunks can be any size — partial lines, single characters,
     * or multi-line blocks. The decoder buffers incomplete data
     * internally.
     *
     * @param  string $chunk Raw data chunk.
     *
     * @throws DecodeException When the chunk contains malformed data.
     */
    public function feed(string $chunk): void;

    /**
     * Signal that the entire input has been fed.
     *
     * Must be called after the last `feed()` call to flush any
     * remaining buffered data.
     *
     * @throws DecodeException When the remaining buffer is malformed.
     */
    public function end(): void;

    /**
     * Return the next decoded row, or null when no more rows are
     * available.
     *
     * Call this after each `feed()` to drain decoded rows from the
     * internal buffer before feeding more data.
     *
     * @return array<string, mixed>|list<mixed>|null The next decoded row.
     *
     * @throws DecodeException When buffered data is malformed.
     */
    public function next(): array|null;

    /**
     * Return the current position (line/row number) in the input.
     *
     * Useful for error reporting when a chunk causes a parse failure.
     *
     * @return int The 1-based line or row number.
     */
    public function position(): int;
}
