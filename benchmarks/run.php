<?php

declare(strict_types=1);

/**
 * Poly-Syntax Benchmark Suite Runner.
 *
 * Usage: php benchmarks/run.php
 *
 * Benchmarks all 5 drivers (JSON, XML, CSV, YAML, TOML) across
 * multiple payload sizes (1 KB → 1 MB) and structure types
 * (flat, tabular, nested).
 */

namespace Monkeyslegion\PolySyntax\Benchmarks;

// Load autoloader
$autoload = __DIR__ . '/../vendor/autoload.php';

if (!\file_exists($autoload)) {
    \fwrite(\STDERR, "ERROR: Composer autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

require $autoload;

echo "Poly-Syntax Benchmark Suite\n";
echo "==========================\n";
echo "PHP Version: " . \PHP_VERSION . "\n";
echo "Platform: " . \PHP_OS . "\n";
echo "Memory Limit: " . \ini_get('memory_limit') . "\n";
echo "\n";

$runner = new BenchmarkRunner();

echo "Running benchmarks...\n";
$start = \hrtime(true);
$results = $runner->runAll();
$end = \hrtime(true);
$totalTime = ($end - $start) / 1_000_000_000;

echo \sprintf("Done in %.2f seconds.\n\n", $totalTime);

echo BenchmarkRunner::formatResults($results);

echo "\n";
echo \sprintf("Total benchmark time: %.2f seconds\n", $totalTime);
