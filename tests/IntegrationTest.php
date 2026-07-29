<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Tests;

use Monkeyslegion\PolySyntax\Driver\CsvDriver;
use Monkeyslegion\PolySyntax\Driver\JsonDriver;
use Monkeyslegion\PolySyntax\Driver\XmlDriver;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Transformer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests that exercise the full pipeline with real drivers.
 */
final class IntegrationTest extends TestCase
{
    private Transformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new Transformer();
        $this->transformer
            ->registerDriver(new JsonDriver())
            ->registerDriver(new XmlDriver())
            ->registerDriver(new CsvDriver());
    }

    // ─── JSON ↔ XML ────────────────────────────────────────────────

    #[Test]
    public function itTransformsJsonToXml(): void
    {
        $json = '{"name":"John","age":"30"}';
        $xml = $this->transformer->transform($json, Syntax::JSON, Syntax::XML);

        self::assertStringContainsString('<name>John</name>', $xml);
        self::assertStringContainsString('<age>30</age>', $xml);
    }

    #[Test]
    public function itTransformsXmlToJson(): void
    {
        $xml = '<root><name>Alice</name><role>admin</role></root>';
        $json = $this->transformer->transform($xml, Syntax::XML, Syntax::JSON);

        self::assertJson($json);
        self::assertStringContainsString('"Alice"', $json);
        self::assertStringContainsString('"admin"', $json);
    }

    #[Test]
    public function itRoundTripsJsonThroughXml(): void
    {
        // Use string-only values — XML has no type system, so integers/floats
        // become strings when round-tripping through XML
        $original = '{"name":"Bob","score":"95"}';

        $xml = $this->transformer->transform($original, Syntax::JSON, Syntax::XML);
        $back = $this->transformer->transform($xml, Syntax::XML, Syntax::JSON);

        self::assertJsonStringEqualsJsonString($original, $back);
    }

    // ─── JSON ↔ CSV ────────────────────────────────────────────────

    #[Test]
    public function itTransformsJsonToCsv(): void
    {
        $json = '[{"name":"John","age":"30"},{"name":"Alice","age":"25"}]';
        $csv = $this->transformer->transform($json, Syntax::JSON, Syntax::CSV);

        self::assertStringContainsString('name,age', $csv);
        self::assertStringContainsString('John,30', $csv);
        self::assertStringContainsString('Alice,25', $csv);
    }

    #[Test]
    public function itTransformsCsvToJson(): void
    {
        $csv = "name,age\nBob,40\nCarol,35";
        $json = $this->transformer->transform($csv, Syntax::CSV, Syntax::JSON);

        self::assertJson($json);
        self::assertStringContainsString('"Bob"', $json);
        self::assertStringContainsString('"Carol"', $json);
    }

    // ─── CSV round-trip ───────────────────────────────────────────

    #[Test]
    public function itRoundTripsCsv(): void
    {
        $csv = "name,age\nJohn,30\nAlice,25";
        $json = $this->transformer->transform($csv, Syntax::CSV, Syntax::JSON);
        $back = $this->transformer->transform($json, Syntax::JSON, Syntax::CSV);

        self::assertSame($csv, $back);
    }

    // ─── All Three Drivers Registered ──────────────────────────────

    #[Test]
    public function itSupportsAllThreeFormats(): void
    {
        self::assertTrue($this->transformer->supports(Syntax::JSON));
        self::assertTrue($this->transformer->supports(Syntax::XML));
        self::assertTrue($this->transformer->supports(Syntax::CSV));
        self::assertCount(3, $this->transformer->supportedSyntaxes());
    }
}
