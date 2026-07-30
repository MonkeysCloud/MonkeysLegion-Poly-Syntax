<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Utility;

use Monkeyslegion\PolySyntax\Enum\Syntax;

/**
 * Immutable result of a token estimation for a single format.
 *
 * @immutable
 */
final class TokenEstimate
{
    /**
     * @param Syntax $syntax          The format this estimate applies to.
     * @param int    $characters      Total characters in the formatted string.
     * @param int    $bytes           Total byte size of the formatted string (UTF-8 safe).
     * @param int    $estimatedTokens Estimated token count for an LLM context window.
     * @param float  $tokensPerByte   Token density coefficient used for the estimate.
     */
    public function __construct(
        public readonly Syntax $syntax,
        public readonly int $characters,
        public readonly int $bytes,
        public readonly int $estimatedTokens,
        public readonly float $tokensPerByte,
    ) {
    }

    /**
     * Human-readable format label.
     *
     * @return non-empty-string
     */
    public function label(): string
    {
        return $this->syntax->label();
    }

    /**
     * Format-agnostic identifier string.
     *
     * @return non-empty-string
     */
    public function format(): string
    {
        return $this->syntax->value;
    }
}
