<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Exception;

/**
 * Base exception for all PolySyntax transformer errors.
 *
 * All domain-specific exceptions extend this class, allowing
 * consumers to catch a single exception type if granularity
 * is not required.
 */
class TransformerException extends \RuntimeException
{
    /**
     * @param  string          $message  Human-readable error description.
     * @param  int             $code     Optional error code.
     * @param  \Throwable|null $previous Optional previous exception for chaining.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
