<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Utility;

/**
 * Result of comparing token estimates between two formats.
 *
 * @immutable
 */
final class FormatComparison
{
    /**
     * @param TokenEstimate $from            The source (baseline) estimate.
     * @param TokenEstimate $to              The target estimate.
     * @param int           $savingsTokens   Absolute tokens saved (negative means increase).
     * @param float         $savingsPercent  Percentage saved (negative means increase).
     * @param float         $reductionFactor How many times smaller the target is (1.0 = same).
     */
    public function __construct(
        public readonly TokenEstimate $from,
        public readonly TokenEstimate $to,
        public readonly int $savingsTokens,
        public readonly float $savingsPercent,
        public readonly float $reductionFactor,
    ) {}

    /**
     * Whether switching to the target format saves tokens.
     *
     * @return bool
     */
    public function isBeneficial(): bool
    {
        return $this->savingsTokens > 0;
    }

    /**
     * Formatted summary string for display.
     *
     * @return non-empty-string
     */
    public function summary(): string
    {
        $dir = $this->isBeneficial() ? 'saves' : 'costs';

        return \sprintf(
            'Switching from %s to %s %s %d tokens (%.1f%% %s) — %.2f× reduction',
            $this->from->label(),
            $this->to->label(),
            $dir,
            \abs($this->savingsTokens),
            \abs($this->savingsPercent),
            $this->isBeneficial() ? 'saved' : 'more',
            $this->reductionFactor,
        );
    }
}
