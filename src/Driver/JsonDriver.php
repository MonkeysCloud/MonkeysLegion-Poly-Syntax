<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Driver;

use Monkeyslegion\PolySyntax\Contract\DriverInterface;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;
use Monkeyslegion\PolySyntax\Exception\EncodeException;

/**
 * Driver for JSON format transformation.
 *
 * Uses native `json_decode` / `json_encode` with strict error flags.
 *
 * ## Decode flags
 * - `JSON_THROW_ON_ERROR` — exceptions on malformed input
 * - `JSON_INVALID_UTF8_IGNORE` — graceful handling of invalid byte sequences
 *
 * ## Encode flags
 * - `JSON_THROW_ON_ERROR` — exceptions on unserializable data
 * - `JSON_UNESCAPED_UNICODE` — preserves Unicode characters
 * - `JSON_UNESCAPED_SLASHES` — readable URLs
 * - `JSON_INVALID_UTF8_IGNORE` — graceful handling of invalid byte sequences
 */
final class JsonDriver implements DriverInterface
{
    /**
     * Bitmask of flags passed to `json_encode`.
     */
    private readonly int $encodeFlags;

    /**
     * Bitmask of flags passed to `json_decode`.
     */
    private readonly int $decodeFlags;

    /**
     * Maximum recursion depth for `json_decode`.
     */
    private readonly int $depth;

    /**
     * @param  int|null $encodeFlags Optional override for encode flags.
     * @param  int|null $decodeFlags Optional override for decode flags.
     * @param  int      $depth       Maximum nesting depth (default 512).
     */
    public function __construct(
        ?int $encodeFlags = null,
        ?int $decodeFlags = null,
        int $depth = 512,
    ) {
        $this->encodeFlags = $encodeFlags ?? (
            \JSON_THROW_ON_ERROR
            | \JSON_UNESCAPED_UNICODE
            | \JSON_UNESCAPED_SLASHES
            | \JSON_INVALID_UTF8_IGNORE
        );
        $this->decodeFlags = $decodeFlags ?? (
            \JSON_THROW_ON_ERROR
            | \JSON_INVALID_UTF8_IGNORE
        );
        $this->depth = \max(1, $depth);
    }

    #[\Override]
    public function supportedSyntax(): Syntax
    {
        return Syntax::JSON;
    }

    #[\Override]
    public function decode(string $input): array
    {
        try {
            /** @var array<mixed> $result */
            $result = \json_decode($input, true, $this->depth, $this->decodeFlags);

            if (!\is_array($result)) {
                throw new DecodeException(
                    \sprintf(
                        'JSON decode did not return an array (got %s)',
                        \get_debug_type($result),
                    ),
                );
            }

            return $result;
        } catch (\JsonException $e) {
            throw new DecodeException(
                \sprintf('Failed to decode JSON: %s', $e->getMessage()),
                previous: $e,
            );
        }
    }

    #[\Override]
    public function encode(array $data): string
    {
        try {
            $result = \json_encode($data, $this->encodeFlags, $this->depth);

            if ($result === false) {
                throw new EncodeException(
                    'JSON encoding failed: unable to serialize data',
                );
            }

            return $result;
        } catch (\JsonException $e) {
            throw new EncodeException(
                \sprintf('Failed to encode JSON: %s', $e->getMessage()),
                previous: $e,
            );
        }
    }
}
