<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Benchmarks;

/**
 * Generates test payloads at configurable byte sizes for benchmarking.
 *
 * Produces both flat and nested data structures that exercise
 * different code paths in each driver.
 */
final class PayloadGenerator
{
    /**
     * Generate a flat associative array targeting roughly $targetBytes.
     *
     * @return array<string, mixed>
     */
    public static function flat(int $targetBytes): array
    {
        $data = [];
        $size = 0;
        $i = 0;

        while ($size < $targetBytes) {
            $key = 'key_' . $i;
            $value = match ($i % 5) {
                0 => 'value_' . \str_repeat('x', 10 + ($i % 20)),
                1 => $i * 1000,
                2 => ($i % 2) === 0,
                3 => null,
                4 => $i * 1.5,
            };
            $data[$key] = $value;
            $size += \strlen($key) + \strlen((string) $value) + 4;
            $i++;
        }

        return $data;
    }

    /**
     * Generate a nested structure (mapping with sub-mappings and sequences).
     *
     * @return array<string, mixed>
     */
    public static function nested(int $targetBytes): array
    {
        $rows = \max(1, (int) \round($targetBytes / 500));
        $data = [];

        for ($i = 0; $i < $rows; $i++) {
            $group = 'group_' . ($i % 10);
            $data[$group][] = [
                'id' => $i,
                'name' => 'Item-' . $i,
                'active' => ($i % 2) === 0,
                'score' => $i * 0.5,
                'tags' => ['tag-' . ($i % 5), 'tag-' . (($i + 1) % 5)],
                'meta' => [
                    'created' => '2026-01-' . \str_pad((string) ($i % 28 + 1), 2, '0', \STR_PAD_LEFT),
                    'priority' => $i % 3,
                ],
            ];
        }

        return $data;
    }

    /**
     * Generate tabular data (list of assoc arrays with consistent keys).
     *
     * @return list<array<string, mixed>>
     */
    public static function tabular(int $targetBytes): array
    {
        $columns = ['id', 'name', 'email', 'age', 'active', 'score', 'role'];
        $colLen = \array_sum(\array_map(\strlen(...), $columns)) + \count($columns) * 5;
        $rows = \max(1, (int) \round($targetBytes / $colLen));
        $data = [];

        for ($i = 0; $i < $rows; $i++) {
            $data[] = [
                'id' => $i + 1,
                'name' => 'User-' . $i,
                'email' => 'user' . $i . '@example.com',
                'age' => 20 + ($i % 40),
                'active' => ($i % 3) !== 0,
                'score' => \round($i * 0.75, 2),
                'role' => ['admin', 'editor', 'viewer'][$i % 3],
            ];
        }

        return $data;
    }

    /**
     * Return sizes to benchmark: label => bytes.
     *
     * @return array<string, int>
     */
    public static function sizes(): array
    {
        return [
            '1 KB'   => 1_024,
            '10 KB'  => 10_240,
            '100 KB' => 102_400,
            '1 MB'   => 1_048_576,
        ];
    }
}
