<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Enum;

/**
 * Strongly-typed format identifiers supported by the transformer.
 *
 * Each case maps to a canonical string identifier used for driver
 * registration and format routing.
 */
enum Syntax: string
{
    case JSON = 'json';
    case YAML = 'yaml';
    case TOML = 'toml';
    case XML  = 'xml';
    case CSV  = 'csv';

    /**
     * Human-readable label for display purposes.
     *
     * @return non-empty-string
     */
    public function label(): string
    {
        return match ($this) {
            self::JSON => 'JSON',
            self::YAML => 'YAML',
            self::TOML => 'TOML',
            self::XML  => 'XML',
            self::CSV  => 'CSV',
        };
    }

    /**
     * All supported syntaxes.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            self::JSON,
            self::YAML,
            self::TOML,
            self::XML,
            self::CSV,
        ];
    }
}
