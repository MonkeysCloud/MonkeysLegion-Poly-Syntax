<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Benchmarks;

/**
 * Stores benchmark timing results for a single operation.
 *
 * @immutable
 */
final class BenchmarkResult
{
    /**
     * @param string $driver   Driver name (e.g. "JsonDriver").
     * @param string $size     Size label (e.g. "10 KB").
     * @param string $struct   Structure type (e.g. "flat").
     * @param string $op       Operation name (e.g. "encode").
     * @param float  $timeMs   Average time in milliseconds.
     * @param float  $minMs    Minimum time in milliseconds.
     * @param float  $maxMs    Maximum time in milliseconds.
     * @param int    $bytes    Payload byte size for throughput calculation.
     */
    public function __construct(
        public readonly string $driver,
        public readonly string $size,
        public readonly string $struct,
        public readonly string $op,
        public readonly float $timeMs,
        public readonly float $minMs,
        public readonly float $maxMs,
        public readonly int $bytes,
    ) {}

    /**
     * Throughput in MB/s.
     */
    public function throughputMBs(): float
    {
        if ($this->timeMs <= 0.0 || $this->bytes <= 0) {
            return 0.0;
        }

        return ($this->bytes / 1_048_576) / ($this->timeMs / 1000);
    }
}
