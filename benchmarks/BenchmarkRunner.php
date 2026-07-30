<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Benchmarks;

use Monkeyslegion\PolySyntax\Driver\CsvDriver;
use Monkeyslegion\PolySyntax\Driver\JsonDriver;
use Monkeyslegion\PolySyntax\Driver\TomlDriver;
use Monkeyslegion\PolySyntax\Driver\XmlDriver;
use Monkeyslegion\PolySyntax\Driver\YamlDriver;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Transformer;

/**
 * Runs encode/decode benchmarks across all drivers and payload sizes,
 * measuring both timing and memory consumption.
 */
final class BenchmarkRunner
{
    /** @var int Iterations per benchmark (after warmup). */
    private const ITERATIONS = 5;

    /** @var int Warmup iterations (discarded). */
    private const WARMUP = 1;

    private Transformer $transformer;

    /** @var array<string, array{0: string, 1: Syntax}> */
    private array $drivers;

    public function __construct()
    {
        $this->transformer = new Transformer();
        $this->transformer
            ->registerDriver(new JsonDriver())
            ->registerDriver(new XmlDriver())
            ->registerDriver(new CsvDriver())
            ->registerDriver(new YamlDriver())
            ->registerDriver(new TomlDriver());

        $this->drivers = [
            'JsonDriver' => ['JSON', Syntax::JSON],
            'XmlDriver'  => ['XML',  Syntax::XML],
            'CsvDriver'  => ['CSV',  Syntax::CSV],
            'YamlDriver' => ['YAML', Syntax::YAML],
            'TomlDriver' => ['TOML', Syntax::TOML],
        ];
    }

    /**
     * Run all benchmarks and return results.
     *
     * @return list<BenchmarkResult>
     */
    public function runAll(): array
    {
        $results = [];

        foreach (PayloadGenerator::sizes() as $sizeLabel => $sizeBytes) {
            $structs = [
                'flat'    => PayloadGenerator::flat($sizeBytes),
                'tabular' => PayloadGenerator::tabular($sizeBytes),
                'nested'  => PayloadGenerator::nested($sizeBytes),
            ];

            foreach ($structs as $structName => $data) {
                foreach ($this->drivers as $driverName => [$label, $syntax]) {
                    // Skip CSV for non-tabular data (assoc arrays produce empty output)
                    if ($driverName === 'CsvDriver' && $structName !== 'tabular') {
                        continue;
                    }

                    $encodeResult = $this->benchmarkEncode($data, $syntax);
                    $results[] = new BenchmarkResult(
                        driver: $driverName,
                        size: $sizeLabel,
                        struct: $structName,
                        op: 'encode',
                        timeMs: $encodeResult['avg'],
                        minMs: $encodeResult['min'],
                        maxMs: $encodeResult['max'],
                        bytes: $encodeResult['bytes'],
                        peakMemoryBytes: $encodeResult['peakMem'],
                        memoryChangeBytes: $encodeResult['memChange'],
                    );

                    if ($encodeResult['output'] !== '') {
                        $decodeResult = $this->benchmarkDecode(
                            $encodeResult['output'],
                            $syntax,
                        );
                        $results[] = new BenchmarkResult(
                            driver: $driverName,
                            size: $sizeLabel,
                            struct: $structName,
                            op: 'decode',
                            timeMs: $decodeResult['avg'],
                            minMs: $decodeResult['min'],
                            maxMs: $decodeResult['max'],
                            bytes: $encodeResult['bytes'],
                            peakMemoryBytes: $decodeResult['peakMem'],
                            memoryChangeBytes: $decodeResult['memChange'],
                        );
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Benchmark encoding: measure timing + memory.
     *
     * @param  array<mixed> $data
     * @return array{avg: float, min: float, max: float, bytes: int, output: string,
     *   peakMem: int, memChange: int}
     */
    private function benchmarkEncode(array $data, Syntax $syntax): array
    {
        $times = [];
        $peakMem = 0;
        $memChange = 0;

        for ($i = 0; $i < self::WARMUP + self::ITERATIONS; $i++) {
            $memBefore = \memory_get_usage(true);
            $start = \hrtime(true);
            $output = $this->transformer->encode($data, $syntax);
            $end = \hrtime(true);
            $memAfter = \memory_get_usage(true);

            if ($i >= self::WARMUP) {
                $times[] = ($end - $start) / 1_000_000; // ns → ms
                $iterationPeak = \max(\memory_get_peak_usage(true), $memAfter);

                if ($iterationPeak > $peakMem) {
                    $peakMem = $iterationPeak;
                }

                $delta = $memAfter - $memBefore;

                if ($delta > $memChange) {
                    $memChange = $delta;
                }
            }
        }

        /** @var list<float> $times */

        if ($times === []) {
            return [
                'avg'       => 0.0,
                'min'       => 0.0,
                'max'       => 0.0,
                'bytes'     => \strlen($output),
                'output'    => $output,
                'peakMem'   => $peakMem,
                'memChange' => $memChange,
            ];
        }

        return [
            'avg'       => \array_sum($times) / \count($times),
            'min'       => \min($times),
            'max'       => \max($times),
            'bytes'     => \strlen($output),
            'output'    => $output,
            'peakMem'   => $peakMem,
            'memChange' => $memChange,
        ];
    }

    /**
     * Benchmark decoding: measure timing + memory.
     *
     * @return array{avg: float, min: float, max: float, peakMem: int, memChange: int}
     */
    private function benchmarkDecode(string $input, Syntax $syntax): array
    {
        $times = [];
        $peakMem = 0;
        $memChange = 0;

        for ($i = 0; $i < self::WARMUP + self::ITERATIONS; $i++) {
            $memBefore = \memory_get_usage(true);
            $start = \hrtime(true);
            $this->transformer->decode($input, $syntax);
            $end = \hrtime(true);
            $memAfter = \memory_get_usage(true);

            if ($i >= self::WARMUP) {
                $times[] = ($end - $start) / 1_000_000;
                $iterationPeak = \max(\memory_get_peak_usage(true), $memAfter);

                if ($iterationPeak > $peakMem) {
                    $peakMem = $iterationPeak;
                }

                $delta = $memAfter - $memBefore;

                if ($delta > $memChange) {
                    $memChange = $delta;
                }
            }
        }

        /** @var list<float> $times */

        if ($times === []) {
            return [
                'avg'       => 0.0,
                'min'       => 0.0,
                'max'       => 0.0,
                'peakMem'   => $peakMem,
                'memChange' => $memChange,
            ];
        }

        return [
            'avg'       => \array_sum($times) / \count($times),
            'min'       => \min($times),
            'max'       => \max($times),
            'peakMem'   => $peakMem,
            'memChange' => $memChange,
        ];
    }

    /** Size ordering for correct numeric sort in output table. */
    private const SIZE_ORDER = [
        '1 KB'   => 0,
        '10 KB'  => 1,
        '100 KB' => 2,
        '1 MB'   => 3,
    ];

    /**
     * Format results as a markdown table.
     *
     * @param  list<BenchmarkResult> $results
     * @return string
     */
    public static function formatResults(array $results): string
    {
        // Group by driver
        $groups = [];

        foreach ($results as $r) {
            $groups[$r->driver][] = $r;
        }

        $output = "# Poly-Syntax Benchmark Results\n\n";
        $output .= "> _Generated " . \date('Y-m-d H:i:s') . " — PHP " . \PHP_VERSION . "_\n\n";
        $output .= "| Driver | Size | Struct | Op | Time (ms) | Min | Max | Throughput | Peak Mem | Mem Δ | Efficiency |\n";
        $output .= "|--------|-----:|--------|----|----------:|----:|----:|-----------:|---------:|------:|-----------:|\n";

        foreach ($groups as $driverName => $driverResults) {
            \usort(
                $driverResults,
                static fn (BenchmarkResult $a, BenchmarkResult $b): int => [
                    self::SIZE_ORDER[$a->size] ?? 99,
                    $a->struct,
                    $a->op,
                ] <=> [
                    self::SIZE_ORDER[$b->size] ?? 99,
                    $b->struct,
                    $b->op,
                ],
            );

            foreach ($driverResults as $r) {
                $output .= \sprintf(
                    "| %s | %s | %s | %s | %.2f | %.2f | %.2f | %.2f MB/s | %s | %s | %.2f |\n",
                    $driverName,
                    $r->size,
                    $r->struct,
                    $r->op,
                    $r->timeMs,
                    $r->minMs,
                    $r->maxMs,
                    $r->throughputMBs(),
                    $r->peakMemoryKB(),
                    $r->memoryChangeKB(),
                    $r->memoryEfficiency(),
                );
            }
        }

        return $output;
    }
}
