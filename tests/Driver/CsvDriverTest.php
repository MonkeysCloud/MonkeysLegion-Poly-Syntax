<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Tests\Driver;

use Monkeyslegion\PolySyntax\Driver\CsvDriver;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;
use Monkeyslegion\PolySyntax\Exception\EncodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CsvDriverTest extends TestCase
{
    private CsvDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new CsvDriver();
    }

    #[Test]
    public function itReportsCsvSyntax(): void
    {
        self::assertSame(Syntax::CSV, $this->driver->supportedSyntax());
    }

    // ─── Decode ────────────────────────────────────────────────────

    #[Test]
    public function itDecodesWithHeadersByDefault(): void
    {
        $csv = "name,age,active\nJohn,30,true\nAlice,25,false";

        $result = $this->driver->decode($csv);

        self::assertCount(2, $result);
        self::assertSame(['name' => 'John', 'age' => '30', 'active' => 'true'], $result[0]);
        self::assertSame(['name' => 'Alice', 'age' => '25', 'active' => 'false'], $result[1]);
    }

    #[Test]
    public function itDecodesEmptyInputAsEmptyArray(): void
    {
        $result = $this->driver->decode('');

        self::assertSame([], $result);
    }

    #[Test]
    public function itDecodesWhitespaceOnlyInputAsEmptyArray(): void
    {
        $result = $this->driver->decode("   \n  \n  ");

        self::assertSame([], $result);
    }

    #[Test]
    public function itDecodesSingleRow(): void
    {
        $csv = "name,value\nhello,world";

        $result = $this->driver->decode($csv);

        self::assertCount(1, $result);
        self::assertSame(['name' => 'hello', 'value' => 'world'], $result[0]);
    }

    #[Test]
    public function itDecodesHeadersOnly(): void
    {
        $csv = "name,age";

        $result = $this->driver->decode($csv);

        self::assertSame([], $result);
    }

    #[Test]
    public function itDecodesWithManualHeaders(): void
    {
        $manual = new CsvDriver(headers: ['full_name', 'years_old']);

        $csv = "John,30\nAlice,25";

        $result = $manual->decode($csv);

        self::assertIsArray($result[0]);
        self::assertIsArray($result[1]);
        /** @var array<string, string> $first */
        $first = $result[0];
        self::assertSame('John', $first['full_name']);
        self::assertSame('30', $first['years_old']);
        /** @var array<string, string> $second */
        $second = $result[1];
        self::assertSame('Alice', $second['full_name']);
    }

    #[Test]
    public function itDecodesWithoutHeaders(): void
    {
        $noHeaders = new CsvDriver(hasHeaders: false);

        $csv = "a,b,c\n1,2,3";

        $result = $noHeaders->decode($csv);

        self::assertSame(['a', 'b', 'c'], $result[0]);
        self::assertSame(['1', '2', '3'], $result[1]);
    }

    #[Test]
    public function itRespectsMaxRows(): void
    {
        $limited = new CsvDriver(maxRows: 1);

        $csv = "name\nAlice\nBob\nCharlie";

        $result = $limited->decode($csv);

        self::assertCount(1, $result);
    }

    #[Test]
    public function itHandlesQuotedFields(): void
    {
        $csv = 'name,description' . "\n" . 'Book,"A great, well-written book"';

        $result = $this->driver->decode($csv);

        self::assertIsArray($result[0]);
        /** @var array<string, string> $firstRow */
        $firstRow = $result[0];
        self::assertSame('A great, well-written book', $firstRow['description']);
    }

    #[Test]
    public function itHandlesCustomDelimiter(): void
    {
        $tsv = new CsvDriver(delimiter: "\t");

        $csv = "name\tage\nJohn\t30";

        $result = $tsv->decode($csv);

        self::assertSame(['name' => 'John', 'age' => '30'], $result[0]);
    }

    // ─── Encode ────────────────────────────────────────────────────

    #[Test]
    public function itEncodesAssociativeArrays(): void
    {
        $data = [
            ['name' => 'John', 'age' => '30'],
            ['name' => 'Alice', 'age' => '25'],
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('name,age', $result);
        self::assertStringContainsString('John,30', $result);
        self::assertStringContainsString('Alice,25', $result);
    }

    #[Test]
    public function itEncodesEmptyArrayAsEmptyString(): void
    {
        $result = $this->driver->encode([]);

        self::assertSame('', $result);
    }

    #[Test]
    public function itEncodesWithoutHeadersWhenDisabled(): void
    {
        $noHeaders = new CsvDriver(hasHeaders: false);

        $data = [
            ['John', '30'],
            ['Alice', '25'],
        ];

        $result = $noHeaders->encode($data);

        self::assertStringNotContainsString('name', $result);
    }

    // ─── Round-Trip ────────────────────────────────────────────────

    #[Test]
    public function itRoundTripsSimpleData(): void
    {
        $original = "name,age,active\nJohn,30,true\nAlice,25,false";

        $data = $this->driver->decode($original);
        $encoded = $this->driver->encode($data);

        // Normalise line endings for comparison
        $normalisedOriginal = \str_replace(["\r\n", "\r"], "\n", $original);
        $normalisedEncoded = \str_replace(["\r\n", "\r"], "\n", $encoded);

        self::assertSame($normalisedOriginal, $normalisedEncoded);
    }

    #[Test]
    public function itRoundTripsCustomDelimiter(): void
    {
        $tsv = new CsvDriver(delimiter: "\t");

        $original = "name\tage\nJohn\t30\nAlice\t25";

        $data = $tsv->decode($original);
        $encoded = $tsv->encode($data);

        $normalisedOriginal = \str_replace(["\r\n", "\r"], "\n", $original);
        $normalisedEncoded = \str_replace(["\r\n", "\r"], "\n", $encoded);

        self::assertSame($normalisedOriginal, $normalisedEncoded);
    }

    // ─── Edge Cases ────────────────────────────────────────────────

    #[Test]
    public function itEncodesWithBooleanAndNullValues(): void
    {
        $data = [
            ['flag' => true, 'empty' => null, 'name' => 'test'],
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('true', $result);
    }

    #[Test]
    public function itEncodesWithFloatValues(): void
    {
        $data = [
            ['price' => 19.99, 'name' => 'widget'],
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('19.99', $result);
    }

    #[Test]
    public function itEncodesWithIntegerValues(): void
    {
        $data = [
            ['count' => 42, 'name' => 'items'],
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('42', $result);
        self::assertStringContainsString('items', $result);
    }

    #[Test]
    public function itHandlesNonArrayRowGracefully(): void
    {
        $data = [
            ['name' => 'Alice'],
            'not_an_array',
            ['name' => 'Bob'],
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('Alice', $result);
        self::assertStringContainsString('Bob', $result);
    }

    // ─── Additional Edge Cases ─────────────────────────────────────

    #[Test]
    public function itEncodesWithObjectValueAsFallthrough(): void
    {
        // Non-scalar values (objects, arrays) should fall through to empty string
        $data = [
            ['name' => 'test', 'obj' => new \stdClass()],
        ];

        // Should not throw — empty string is returned for the object
        $result = $this->driver->encode($data);
        self::assertStringContainsString('name', $result);
    }

    #[Test]
    public function itThrowsOnNonArrayEncodeInput(): void
    {
        $this->expectException(EncodeException::class);
        $this->expectExceptionMessage('CSV encoding requires an array of associative arrays');

        $this->driver->encode(['a', 'b', 'c']);
    }

    #[Test]
    public function itHandlesExtraColumnsInRowGracefully(): void
    {
        // If a row has fewer columns than headers, missing values should be empty
        $data = [
            ['a' => '1', 'b' => '2'],
            ['a' => '3'],  // missing 'b'
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('a,b', $result);
        self::assertStringContainsString('1,2', $result);
        self::assertStringContainsString('3,', $result);
    }

    // ─── Constructor Validation ────────────────────────────────────

    #[Test]
    public function itThrowsOnInvalidDelimiter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV delimiter must be a single character');

        new CsvDriver(delimiter: '**');
    }

    #[Test]
    public function itThrowsOnInvalidEnclosure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV enclosure must be a single character');

        new CsvDriver(enclosure: '""');
    }
}
