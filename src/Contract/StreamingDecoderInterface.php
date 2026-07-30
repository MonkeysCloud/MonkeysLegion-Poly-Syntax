<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Contract;

use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;

/**
 * Contract for streaming (chunk-based) data decoders.
 *
 * Unlike `DriverInterface::decode()` which requires the entire payload
 * in memory at once, streaming decoders process data incrementally via
 * repeated `feed()` calls. This enables processing of arbitrarily large
 * files without loading them entirely into memory.
 *
 * ## Usage
 *
 * ```php
 * $decoder = $transformer->createStreamDecoder(Syntax::CSV);
 *
 * foreach ($fileChunks as $chunk) {
 *     $decoder->feed($chunk);
 *
 *     while (($row = $decoder->next()) !== null) {
 *         processRow($row);
 *     }
 * }
 *
 * $decoder->end();
 *
 * while (($row = $decoder->next()) !== null) {
 *     processRow($row);
 * }
 * ```
 */
interface StreamingDecoderInterface
{
    /**
     * Return the syntax this streaming decoder handles.
     *
     * @return Syntax The format identifier.
     */
    public function supportedSyntax(): Syntax;

    /**
     * Feed a chunk of data into the decoder.
     *
     * Partial data (e.g. a JSON element split across chunk boundaries)
     * is buffered internally. Use `next()` to drain any complete items
     * after each feed, and call `end()` when all chunks are sent.
     *
     * @param  string $chunk A piece of the input data.
     * @return void
     *
     * @throws DecodeException When malformed data is encountered or
     *                         feed() is called after end().
     */
    public function feed(string $chunk): void;

    /**
     * Signal that all data has been sent, flushing any remaining buffer.
     *
     * After calling `end()`, subsequent calls to `feed()` must throw
     * a `DecodeException`.
     *
     * @return void
     */
    public function end(): void;

    /**
     * Return the next decoded item, or null when the stream is exhausted.
     *
     * @return mixed|null The next decoded item, or null if none available.
     */
    public function next(): mixed;

    /**
     * Reset the internal state so the decoder can be reused for a new stream.
     */
    public function reset(): void;

    /**
     * Return the count of items consumed via `next()`.
     *
     * @return int The number of items yielded so far.
     */
    public function position(): int;
}
