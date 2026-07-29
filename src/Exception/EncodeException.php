<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Exception;

/**
 * Thrown when a driver fails to encode an array into the target format.
 *
 * This covers serialization failures, type incompatibilities, or
 * any data that cannot be represented in the target format.
 */
final class EncodeException extends TransformerException
{
    /**
     * @param  string          $message  Description of the encode failure.
     * @param  int             $code     Optional error code.
     * @param  \Throwable|null $previous Optional previous exception for chaining.
     */
    public function __construct(
        string $message = 'Failed to encode data',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
