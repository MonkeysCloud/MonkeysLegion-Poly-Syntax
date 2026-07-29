<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Exception;

/**
 * Thrown when a driver fails to decode an input string.
 *
 * This covers malformed input, syntax errors, or any unexpected
 * data that does not conform to the expected format.
 */
final class DecodeException extends TransformerException
{
    /**
     * @param  string          $message  Description of the decode failure.
     * @param  int             $code     Optional error code.
     * @param  \Throwable|null $previous Optional previous exception for chaining.
     */
    public function __construct(
        string $message = 'Failed to decode input',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
