<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Benchmarks;

/**
 * Stores benchmark timing and memory results for a single operation.
 *
 * @immutable
 */
final class BenchmarkResult
{
    /**
     * @param string $driver           Driver name (e.g. "JsonDriver").
     * @param string $size             Size label (e.g. "10 KB").
     * @param string $struct           Structure type (e.g. "flat").
     * @param string $op               Operation name (e.g. "encode").
     * @param float  $timeMs           Average time in milliseconds.
     * @param float  $minMs            Minimum time in milliseconds.
     * @param float  $maxMs            Maximum time in milliseconds.
     * @param int    $bytes            Payload byte size for throughput calculation.
     * @param int    $peakMemoryBytes  Peak memory usage during the operation in bytes.
     * @param int    $memoryChangeBytes Memory delta (after - before) in bytes.
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
        public readonly int $peakMemoryBytes = 0,
        public readonly int $memoryChangeBytes = 0,
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

    /**
     * Peak memory in human-readable KB.
     */
    public function peakMemoryKB(): string
    {
        $kb = (int) \round($this->peakMemoryBytes / 1024);

        if ($kb >= 1024) {
            return \sprintf('%.1f MB', $kb / 1024);
        }

        return $kb . ' KB';
    }

    /**
     * Memory change in human-readable form.
     */
    public function memoryChangeKB(): string
    {
        $kb = (int) \round($this->memoryChangeBytes / 1024);

        if ($kb > 0) {
            return '+' . $kb . ' KB';
        }

        return $kb . ' KB';
    }

    /**
     * Efficiency ratio: bytes allocated per byte processed.
     * Lower is better. 1.0 means 1:1 input-to-memory ratio.
     */
    public function memoryEfficiency(): float
    {
        if ($this->bytes <= 0 || $this->memoryChangeBytes <= 0) {
            return 0.0;
        }

        return (float) $this->memoryChangeBytes / $this->bytes;
    }
}
