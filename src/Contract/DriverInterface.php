<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Contract;

use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;
use Monkeyslegion\PolySyntax\Exception\EncodeException;

/**
 * Contract every format driver must implement.
 *
 * Drivers are stateless transformers that convert between a specific
 * data format (JSON, XML, CSV, etc.) and a native PHP array.
 */
interface DriverInterface
{
    /**
     * Return the syntax this driver handles.
     *
     * @return Syntax The format identifier.
     */
    public function supportedSyntax(): Syntax;

    /**
     * Decode a string payload into a native PHP array.
     *
     * @param  string $input  The raw format string to decode.
     * @return array<mixed>   The decoded PHP array.
     *
     * @throws DecodeException When the input cannot be parsed.
     */
    public function decode(string $input): array;

    /**
     * Encode a native PHP array into the target format string.
     *
     * @param  array<mixed> $data  The PHP array to encode.
     * @return string               The formatted output string.
     *
     * @throws EncodeException When the data cannot be serialized.
     */
    public function encode(array $data): string;
}
