<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Tests\Driver;

use Monkeyslegion\PolySyntax\Driver\JsonDriver;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;
use Monkeyslegion\PolySyntax\Exception\EncodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JsonDriverTest extends TestCase
{
    private JsonDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new JsonDriver();
    }

    #[Test]
    public function itReportsJsonSyntax(): void
    {
        self::assertSame(Syntax::JSON, $this->driver->supportedSyntax());
    }

    // ─── Decode ────────────────────────────────────────────────────

    #[Test]
    public function itDecodesAFlatObject(): void
    {
        $result = $this->driver->decode('{"name":"John","age":30}');

        self::assertSame(['name' => 'John', 'age' => 30], $result);
    }

    #[Test]
    public function itDecodesNestedStructures(): void
    {
        $result = $this->driver->decode('{"user":{"name":"Alice","roles":["admin","editor"]}}');

        self::assertSame([
            'user' => [
                'name' => 'Alice',
                'roles' => ['admin', 'editor'],
            ],
        ], $result);
    }

    #[Test]
    public function itDecodesAnEmptyObject(): void
    {
        $result = $this->driver->decode('{}');

        self::assertSame([], $result);
    }

    #[Test]
    public function itDecodesAnArray(): void
    {
        $result = $this->driver->decode('["a","b","c"]');

        self::assertSame(['a', 'b', 'c'], $result);
    }

    #[Test]
    public function itDecodesSpecialUnicode(): void
    {
        $result = $this->driver->decode('{"emoji":"🚀","currency":"€"}');

        self::assertSame(['emoji' => '🚀', 'currency' => '€'], $result);
    }

    #[Test]
    public function itThrowsOnMalformedJson(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('Failed to decode JSON');

        $this->driver->decode('{broken');
    }

    #[Test]
    public function itThrowsOnJsonPrimitive(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('did not return an array');

        $this->driver->decode('"just a string"');
    }

    #[Test]
    public function itThrowsOnNullInput(): void
    {
        $this->expectException(DecodeException::class);

        $this->driver->decode('null');
    }

    #[Test]
    public function itThrowsOnEmptyString(): void
    {
        $this->expectException(DecodeException::class);

        $this->driver->decode('');
    }

    // ─── Encode ────────────────────────────────────────────────────

    #[Test]
    public function itEncodesAnArray(): void
    {
        $result = $this->driver->encode(['name' => 'John', 'age' => 30]);

        self::assertJson($result);
        self::assertStringContainsString('"name"', $result);
        self::assertStringContainsString('"John"', $result);
    }

    #[Test]
    public function itEncodesUnicodeWithoutEscaping(): void
    {
        $result = $this->driver->encode(['emoji' => '🚀']);

        self::assertStringContainsString('🚀', $result);
    }

    #[Test]
    public function itEncodesSlashesWithoutEscaping(): void
    {
        $result = $this->driver->encode(['url' => 'https://example.com/path']);

        self::assertStringContainsString('https://example.com/path', $result);
        self::assertStringNotContainsString('\\/', $result);
    }

    #[Test]
    public function itEncodesAnEmptyArray(): void
    {
        $result = $this->driver->encode([]);

        self::assertSame('[]', $result);
    }

    #[Test]
    public function itEncodesNullValues(): void
    {
        $result = $this->driver->encode(['a' => null, 'b' => 0]);

        self::assertStringContainsString('null', $result);
    }

    // ─── Round-Trip ────────────────────────────────────────────────

    #[Test]
    public function itRoundTripsSimpleData(): void
    {
        $original = '{"name":"John","age":30,"active":true}';
        $data = $this->driver->decode($original);
        $encoded = $this->driver->encode($data);

        self::assertJsonStringEqualsJsonString($original, $encoded);
    }

    #[Test]
    public function itRoundTripsNestedData(): void
    {
        $original = '{"user":{"name":"Alice","roles":["admin","editor"],"metadata":{"lastLogin":"2026-01-15"}}}';
        $data = $this->driver->decode($original);
        $encoded = $this->driver->encode($data);

        self::assertJsonStringEqualsJsonString($original, $encoded);
    }

    #[Test]
    #[DataProvider('provideRoundTripCases')]
    public function itRoundTripsVariousData(string $json): void
    {
        $data = $this->driver->decode($json);
        $encoded = $this->driver->encode($data);

        self::assertJsonStringEqualsJsonString($json, $encoded);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideRoundTripCases(): array
    {
        return [
            'flat object'        => ['{"key":"value","count":42,"ok":true}'],
            'nested arrays'      => ['{"items":[1,2,3],"flags":[true,false]}'],
            'mixed types'        => ['{"string":"text","int":-1,"float":3.14,"bool":false,"null":null}'],
            'deeply nested'      => ['{"a":{"b":{"c":{"d":"deep"}}}}'],
            'unicode strings'    => ['{"text":"Hello 世界 🌍"}'],
            'special characters' => ['{"text":"tab\\tnew\\nline"}'],
        ];
    }

    // ─── Encode edge cases ─────────────────────────────────────────

    #[Test]
    public function itEncodesDeeplyNestedDataWithDefaultDepth(): void
    {
        $result = $this->driver->encode(['a' => ['b' => ['c' => 'deep']]]);

        self::assertJson($result);
        self::assertStringContainsString('deep', $result);
    }

    // ─── Custom Options ────────────────────────────────────────────

    #[Test]
    public function itRespectsCustomEncodeFlags(): void
    {
        $pretty = new JsonDriver(
            encodeFlags: \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT,
        );

        $result = $pretty->encode(['a' => 1]);

        self::assertStringContainsString("\n", $result);
    }

    #[Test]
    public function itRespectsCustomDepth(): void
    {
        $shallow = new JsonDriver(depth: 1);

        // Deeply nested data should fail at depth 1
        $this->expectException(EncodeException::class);

        $shallow->encode(['a' => ['b' => ['c' => 'deep']]]);
    }

    #[Test]
    public function itEnforcesMinimumDepthOfOne(): void
    {
        $driver = new JsonDriver(depth: 0);

        // Depth 0 is clamped to 1, so flat data should work
        $result = $driver->encode(['key' => 'value']);
        self::assertJson($result);
    }
}
