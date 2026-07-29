<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Tests\Driver;

use Monkeyslegion\PolySyntax\Driver\XmlDriver;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class XmlDriverTest extends TestCase
{
    private XmlDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new XmlDriver();
    }

    #[Test]
    public function itReportsXmlSyntax(): void
    {
        self::assertSame(Syntax::XML, $this->driver->supportedSyntax());
    }

    // ─── Decode ────────────────────────────────────────────────────

    #[Test]
    public function itDecodesSimpleElements(): void
    {
        $result = $this->driver->decode('<root><name>John</name><age>30</age></root>');

        self::assertSame(['name' => 'John', 'age' => '30'], $result);
    }

    #[Test]
    public function itDecodesNestedElements(): void
    {
        $xml = <<<'XML'
        <root>
            <user>
                <name>Alice</name>
                <role>admin</role>
            </user>
        </root>
        XML;

        $result = $this->driver->decode($xml);

        self::assertSame([
            'user' => [
                'name' => 'Alice',
                'role' => 'admin',
            ],
        ], $result);
    }

    #[Test]
    public function itDecodesElementsWithAttributes(): void
    {
        $xml = '<root><item id="42" status="active">Hello</item></root>';

        $result = $this->driver->decode($xml);

        self::assertArrayHasKey('item', $result);
        /** @var array<mixed> $item */
        $item = $result['item'];
        self::assertArrayHasKey('@attributes', $item);
        /** @var array<string, string> $attrs */
        $attrs = $item['@attributes'];
        self::assertSame('42', $attrs['id']);
        self::assertSame('active', $attrs['status']);
        self::assertArrayHasKey('@text', $item);
        self::assertSame('Hello', $item['@text']);
    }

    #[Test]
    public function itDecodesMultipleChildrenWithSameTag(): void
    {
        $xml = '<root><item>a</item><item>b</item><item>c</item></root>';

        $result = $this->driver->decode($xml);

        self::assertSame(['item' => ['a', 'b', 'c']], $result);
    }

    #[Test]
    public function itDecodesEmptyInput(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('Cannot decode empty XML input');

        $this->driver->decode('');
    }

    #[Test]
    public function itThrowsOnMalformedXml(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('Failed to parse XML');

        $this->driver->decode('<root><broken></root>');
    }

    #[Test]
    public function itDecodesSelfClosingElements(): void
    {
        $result = $this->driver->decode('<root><empty/><name>John</name></root>');

        self::assertArrayHasKey('name', $result);
        self::assertSame('John', $result['name']);
    }

    // ─── Encode ────────────────────────────────────────────────────

    #[Test]
    public function itEncodesSimpleData(): void
    {
        $result = $this->driver->encode(['name' => 'John', 'age' => '30']);

        self::assertStringContainsString('<name>John</name>', $result);
        self::assertStringContainsString('<age>30</age>', $result);
        self::assertStringContainsString('<root>', $result);
        self::assertStringContainsString('</root>', $result);
    }

    #[Test]
    public function itEncodesNestedData(): void
    {
        $result = $this->driver->encode([
            'user' => [
                'name' => 'Alice',
                'role' => 'admin',
            ],
        ]);

        self::assertStringContainsString('<user>', $result);
        self::assertStringContainsString('<name>Alice</name>', $result);
        self::assertStringContainsString('<role>admin</role>', $result);
        self::assertStringContainsString('</user>', $result);
    }

    #[Test]
    public function itEncodesAttributes(): void
    {
        $result = $this->driver->encode([
            'item' => [
                '@attributes' => ['id' => '42', 'status' => 'active'],
                'value' => 'Hello',
            ],
        ]);

        self::assertStringContainsString('id="42"', $result);
        self::assertStringContainsString('status="active"', $result);
        self::assertStringContainsString('<value>Hello</value>', $result);
    }

    #[Test]
    public function itEncodesEmptyArray(): void
    {
        $result = $this->driver->encode([]);

        self::assertStringContainsString('<root/>', $result);
    }

    // ─── Round-Trip ────────────────────────────────────────────────

    #[Test]
    #[DataProvider('provideRoundTripCases')]
    public function itRoundTripsSimpleData(string $xml, array $expected): void
    {
        $decoded = $this->driver->decode($xml);
        self::assertSame($expected, $decoded);

        $encoded = $this->driver->encode($decoded);

        $reDecoded = $this->driver->decode($encoded);
        self::assertSame($expected, $reDecoded);
    }

    /**
     * @return array<string, array{0: string, 1: array<mixed>}>
     */
    public static function provideRoundTripCases(): array
    {
        return [
            'flat elements' => [
                '<root><name>John</name><age>30</age></root>',
                ['name' => 'John', 'age' => '30'],
            ],
            'nested' => [
                '<root><user><name>Alice</name><role>admin</role></user></root>',
                ['user' => ['name' => 'Alice', 'role' => 'admin']],
            ],
            'text values' => [
                '<root><flag>true</flag></root>',
                ['flag' => 'true'],
            ],
        ];
    }

    // ─── Custom Options ────────────────────────────────────────────

    #[Test]
    public function itUsesCustomRootElement(): void
    {
        $custom = new XmlDriver(rootElement: 'document');

        $result = $custom->encode(['key' => 'value']);

        self::assertStringContainsString('<document>', $result);
        self::assertStringContainsString('</document>', $result);
        self::assertStringNotContainsString('<root>', $result);
    }

    #[Test]
    public function itEncodesWithDefaultNamespace(): void
    {
        $namespaced = new XmlDriver(
            rootElement: 'root',
            defaultNamespace: 'https://example.com/ns',
        );

        $result = $namespaced->encode(['key' => 'value']);

        self::assertStringContainsString('xmlns="https://example.com/ns"', $result);
    }

    #[Test]
    public function itEncodesWithIntegerKeys(): void
    {
        $result = $this->driver->encode([
            ['apple', 'banana'],
            ['cherry', 'date'],
        ]);

        self::assertStringContainsString('<item>', $result);
        self::assertStringContainsString('apple', $result);
        self::assertStringContainsString('cherry', $result);
    }

    #[Test]
    public function itEncodesTextContentOnParentElement(): void
    {
        $result = $this->driver->encode([
            'item' => [
                '@attributes' => ['id' => '1'],
                '@text' => 'Hello World',
            ],
        ]);

        self::assertStringContainsString('id="1"', $result);
        self::assertStringContainsString('Hello World', $result);
    }

    // ─── XXE Protection ────────────────────────────────────────────

    #[Test]
    public function itBlocksXxeAttacks(): void
    {
        $xxePayload = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE foo [
            <!ENTITY xxe SYSTEM "file:///etc/passwd">
        ]>
        <root>&xxe;</root>
        XML;

        try {
            $this->driver->decode($xxePayload);
            self::expectNotToPerformAssertions();
        } catch (DecodeException) {
            self::expectNotToPerformAssertions();
        }
    }

    // ─── Edge Cases ────────────────────────────────────────────────

    #[Test]
    public function itPreservesTextContentInLeafElementsWithAttributes(): void
    {
        $xml = '<root><item id="1" type="widget">Hello World</item></root>';

        $result = $this->driver->decode($xml);

        self::assertArrayHasKey('item', $result);
        $item = $result['item'];
        self::assertIsArray($item);
        self::assertArrayHasKey('@text', $item);
        self::assertSame('Hello World', $item['@text']);
    }

    #[Test]
    public function itEncodesNestedArraysWithChildrenAndAttributes(): void
    {
        $result = $this->driver->encode([
            'user' => [
                '@attributes' => ['id' => '1'],
                'name' => 'Alice',
                'role' => 'admin',
            ],
        ]);

        self::assertStringContainsString('id="1"', $result);
        self::assertStringContainsString('<name>Alice</name>', $result);
        self::assertStringContainsString('<role>admin</role>', $result);
    }

    #[Test]
    public function itDecodesElementsWithBothChildrenAndAttributes(): void
    {
        $xml = '<root><user id="5"><name>Bob</name><role>editor</role></user></root>';

        $result = $this->driver->decode($xml);

        self::assertArrayHasKey('user', $result);
        /** @var array<mixed> $user */
        $user = $result['user'];
        self::assertArrayHasKey('@attributes', $user);
        /** @var array<string, string> $attrs */
        $attrs = $user['@attributes'];
        self::assertSame('5', $attrs['id']);
        self::assertSame('Bob', $user['name']);
        self::assertSame('editor', $user['role']);
    }

    #[Test]
    public function itEncodesMixedContentWithIntegerKeys(): void
    {
        $result = $this->driver->encode([
            'metadata' => ['version' => '1.0'],
            ['apple'],
            ['banana'],
        ]);

        self::assertStringContainsString('<item>', $result);
        self::assertStringContainsString('<metadata>', $result);
    }

    #[Test]
    public function itEncodesWithMultipleIntegerKeyedItems(): void
    {
        $result = $this->driver->encode([
            ['a', 'b'],
            ['c', 'd'],
            ['e', 'f'],
        ]);

        self::assertStringContainsString('<item>a</item>', $result);
        self::assertStringContainsString('<item>c</item>', $result);
        self::assertStringContainsString('<item>e</item>', $result);
    }

    #[Test]
    public function itEncodesScalarValues(): void
    {
        $result = $this->driver->encode([
            'count' => 42,
            'active' => true,
            'score' => 9.5,
        ]);

        self::assertStringContainsString('<count>42</count>', $result);
        self::assertStringContainsString('<active>1</active>', $result);
        self::assertStringContainsString('<score>9.5</score>', $result);
    }

    #[Test]
    public function itRoundTripsWithNamespace(): void
    {
        $namespaced = new XmlDriver(defaultNamespace: 'https://ns.example.com');

        $encoded = $namespaced->encode(['key' => 'value']);
        $decoded = $namespaced->decode($encoded);

        self::assertSame(['key' => 'value'], $decoded);
    }
}
