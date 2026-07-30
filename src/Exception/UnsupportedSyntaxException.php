<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Exception;

use Monkeyslegion\PolySyntax\Enum\Syntax;

/**
 * Thrown when a requested format has no registered driver.
 *
 * This typically means the consumer forgot to register a driver
 * for the given syntax before attempting a transformation.
 */
final class UnsupportedSyntaxException extends TransformerException
{
    /**
     * @param  Syntax|string  $syntax   The unsupported format identifier.
     * @param  int             $code     Optional error code.
     * @param  \Throwable|null $previous Optional previous exception for chaining.
     */
    public function __construct(
        Syntax|string $syntax,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $label = $syntax instanceof Syntax ? $syntax->value : $syntax;

        parent::__construct(
            \sprintf('No driver registered for syntax "%s"', $label),
            $code,
            $previous,
        );
    }
}
