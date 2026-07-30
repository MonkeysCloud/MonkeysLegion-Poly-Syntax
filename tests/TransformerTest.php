<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Tests;

use Monkeyslegion\PolySyntax\Contract\DriverInterface;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;
use Monkeyslegion\PolySyntax\Exception\EncodeException;
use Monkeyslegion\PolySyntax\Exception\UnsupportedSyntaxException;
use Monkeyslegion\PolySyntax\Transformer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransformerTest extends TestCase
{
    private Transformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new Transformer();
    }

    // ─── Registration & Support ────────────────────────────────────

    #[Test]
    public function itSupportsNoSyntaxesByDefault(): void
    {
        self::assertFalse($this->transformer->supports(Syntax::JSON));
        self::assertSame([], $this->transformer->supportedSyntaxes());
    }

    #[Test]
    public function itSupportsARegisteredSyntax(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());

        self::assertTrue($this->transformer->supports(Syntax::JSON));
        self::assertFalse($this->transformer->supports(Syntax::XML));
    }

    #[Test]
    public function itReportsAllRegisteredSyntaxes(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());
        $this->transformer->registerDriver($this->createMockXmlDriver());

        $supported = $this->transformer->supportedSyntaxes();

        self::assertContains(Syntax::JSON, $supported);
        self::assertContains(Syntax::XML, $supported);
        self::assertCount(2, $supported);
    }

    #[Test]
    public function itReturnsSequentiallyIndexedSyntaxes(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());
        $this->transformer->registerDriver($this->createMockXmlDriver());

        $supported = $this->transformer->supportedSyntaxes();

        self::assertArrayHasKey(0, $supported);
        self::assertArrayHasKey(1, $supported);
        self::assertCount(2, $supported);
    }

    #[Test]
    public function itReplacesADriverWhenRegisteringDuplicateSyntax(): void
    {
        $driverA = $this->createJsonDriver();
        $driverB = $this->createMock(DriverInterface::class);
        $driverB->method('supportedSyntax')->willReturn(Syntax::JSON);
        $driverB->method('decode')->willReturn(['replaced' => true]);
        $driverB->method('encode')->willReturn('{"replaced":true}');

        $this->transformer->registerDriver($driverA);
        $this->transformer->registerDriver($driverB);

        self::assertSame(
            ['replaced' => true],
            $this->transformer->decode('{}', Syntax::JSON),
        );

        self::assertSame(
            '{"replaced":true}',
            $this->transformer->encode([], Syntax::JSON),
        );
    }

    #[Test]
    public function itSupportsFluentRegistration(): void
    {
        $result = $this->transformer->registerDriver($this->createJsonDriver());

        self::assertSame($this->transformer, $result);
    }

    // ─── Custom Syntax Registration (registerSyntax) ───────────────

    #[Test]
    public function itRegistersDriverWithCustomSyntaxKey(): void
    {
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('supportedSyntax')->willReturn(Syntax::JSON);
        $driver->method('decode')->willReturn(['custom' => true]);
        $driver->method('encode')->willReturn('custom-output');

        $this->transformer->registerSyntax('my-format', $driver);

        self::assertTrue($this->transformer->supports('my-format'));
        self::assertSame(['custom' => true], $this->transformer->decode('input', 'my-format'));
        self::assertSame('custom-output', $this->transformer->encode([], 'my-format'));
    }

    #[Test]
    public function customSyntaxKeyDoesNotAffectSupportedSyntaxes(): void
    {
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('supportedSyntax')->willReturn(Syntax::JSON);
        $driver->method('decode')->willReturn([]);
        $driver->method('encode')->willReturn('');

        $this->transformer->registerSyntax('custom-format', $driver);

        // supportedSyntaxes() only returns Syntax enum cases matching their key
        self::assertSame([], $this->transformer->supportedSyntaxes());
    }

    #[Test]
    public function registeredSyntaxesReturnsAllKeys(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());
        $this->transformer->registerSyntax('custom', $this->createJsonDriver());

        $all = $this->transformer->registeredSyntaxes();

        self::assertContains('json', $all);
        self::assertContains('custom', $all);
        self::assertCount(2, $all);
    }

    #[Test]
    public function itReplacesCustomSyntaxKeyOnDuplicate(): void
    {
        $driverA = $this->createMock(DriverInterface::class);
        $driverA->method('supportedSyntax')->willReturn(Syntax::JSON);
        $driverA->method('decode')->willReturn(['first' => true]);
        $driverA->method('encode')->willReturn('first');

        $driverB = $this->createMock(DriverInterface::class);
        $driverB->method('supportedSyntax')->willReturn(Syntax::JSON);
        $driverB->method('decode')->willReturn(['second' => true]);
        $driverB->method('encode')->willReturn('second');

        $this->transformer->registerSyntax('dup', $driverA);
        $this->transformer->registerSyntax('dup', $driverB);

        self::assertSame(['second' => true], $this->transformer->decode('x', 'dup'));
    }

    #[Test]
    public function customSyntaxKeyWithNonStringSupportedSyntax(): void
    {
        // Driver returns Syntax::JSON but is registered under 'custom'
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('supportedSyntax')->willReturn(Syntax::JSON);
        $driver->method('decode')->willReturn(['surprise' => true]);
        $driver->method('encode')->willReturn('surprise-output');

        $this->transformer->registerSyntax('surprise', $driver);

        // 'json' key not affected — the driver is only under 'surprise'
        self::assertFalse($this->transformer->supports(Syntax::JSON));
        self::assertTrue($this->transformer->supports('surprise'));
    }

    #[Test]
    public function fluentRegistrationWithCustomSyntax(): void
    {
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('supportedSyntax')->willReturn(Syntax::YAML);
        $driver->method('decode')->willReturn([]);
        $driver->method('encode')->willReturn('');

        $result = $this->transformer->registerSyntax('my-fmt', $driver);

        self::assertSame($this->transformer, $result);
    }

    // ─── supports() with string keys ───────────────────────────────

    #[Test]
    public function supportsAcceptsStringKey(): void
    {
        $this->transformer->registerSyntax('msgpack', $this->createJsonDriver());

        self::assertTrue($this->transformer->supports('msgpack'));
        self::assertFalse($this->transformer->supports('unknown'));
    }

    // ─── Decode ─────────────────────────────────────────────────────

    #[Test]
    public function itDecodesUsingTheCorrectDriver(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());

        $result = $this->transformer->decode('{"name":"test"}', Syntax::JSON);

        self::assertSame(['name' => 'test'], $result);
    }

    #[Test]
    public function itDecodesUsingStringSyntaxKey(): void
    {
        $this->transformer->registerSyntax('my-json', $this->createJsonDriver());

        $result = $this->transformer->decode('{"key":"value"}', 'my-json');

        self::assertSame(['key' => 'value'], $result);
    }

    #[Test]
    public function itThrowsUnsupportedSyntaxOnDecodeWithNoDriver(): void
    {
        $this->expectException(UnsupportedSyntaxException::class);
        $this->expectExceptionMessage('toml');

        $this->transformer->decode('key = "value"', Syntax::TOML);
    }

    #[Test]
    public function itThrowsUnsupportedSyntaxOnDecodeWithUnknownString(): void
    {
        $this->expectException(UnsupportedSyntaxException::class);
        $this->expectExceptionMessage('unknown-fmt');

        $this->transformer->decode('some data', 'unknown-fmt');
    }

    #[Test]
    public function itThrowsDecodeExceptionWhenDriverFails(): void
    {
        $failing = $this->createMock(DriverInterface::class);
        $failing->method('supportedSyntax')->willReturn(Syntax::JSON);
        $failing->method('decode')->willThrowException(new DecodeException('Boom'));

        $this->transformer->registerDriver($failing);

        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('Boom');

        $this->transformer->decode('{}', Syntax::JSON);
    }

    // ─── Encode ─────────────────────────────────────────────────────

    #[Test]
    public function itEncodesUsingTheCorrectDriver(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());

        $result = $this->transformer->encode(['name' => 'test'], Syntax::JSON);

        self::assertJson($result);
    }

    #[Test]
    public function itEncodesUsingStringSyntaxKey(): void
    {
        $this->transformer->registerSyntax('my-json', $this->createJsonDriver());

        $result = $this->transformer->encode(['name' => 'Alice'], 'my-json');

        self::assertStringContainsString('Alice', $result);
    }

    #[Test]
    public function itThrowsUnsupportedSyntaxOnEncodeWithNoDriver(): void
    {
        $this->expectException(UnsupportedSyntaxException::class);
        $this->expectExceptionMessage('xml');

        $this->transformer->encode(['key' => 'value'], Syntax::XML);
    }

    #[Test]
    public function itThrowsUnsupportedSyntaxOnEncodeWithUnknownString(): void
    {
        $this->expectException(UnsupportedSyntaxException::class);
        $this->expectExceptionMessage('undefined');

        $this->transformer->encode([], 'undefined');
    }

    #[Test]
    public function itThrowsEncodeExceptionWhenDriverFails(): void
    {
        $failing = $this->createMock(DriverInterface::class);
        $failing->method('supportedSyntax')->willReturn(Syntax::JSON);
        $failing->method('encode')->willThrowException(new EncodeException('Boom'));

        $this->transformer->registerDriver($failing);

        $this->expectException(EncodeException::class);
        $this->expectExceptionMessage('Boom');

        $this->transformer->encode([], Syntax::JSON);
    }

    // ─── Transform ──────────────────────────────────────────────────

    #[Test]
    public function itTransformsBetweenFormats(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());
        $this->transformer->registerDriver($this->createMockXmlDriver());

        $result = $this->transformer->transform(
            '{"name":"test","value":42}',
            Syntax::JSON,
            Syntax::XML,
        );

        self::assertStringContainsString('<name>test</name>', $result);
        self::assertStringContainsString('<value>42</value>', $result);
    }

    #[Test]
    public function itTransformsUsingStringSyntaxKeys(): void
    {
        $this->transformer->registerSyntax('json-alt', $this->createJsonDriver());
        $this->transformer->registerSyntax('xml-alt', $this->createMockXmlDriver());

        $result = $this->transformer->transform(
            '{"msg":"hello"}',
            'json-alt',
            'xml-alt',
        );

        self::assertStringContainsString('<msg>hello</msg>', $result);
    }

    #[Test]
    public function itThrowsOnTransformWhenSourceHasNoDriver(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());

        $this->expectException(UnsupportedSyntaxException::class);
        $this->expectExceptionMessage('xml');

        $this->transformer->transform('<root/>', Syntax::XML, Syntax::JSON);
    }

    #[Test]
    public function itThrowsOnTransformWhenTargetHasNoDriver(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());

        $this->expectException(UnsupportedSyntaxException::class);
        $this->expectExceptionMessage('xml');

        $this->transformer->transform('{}', Syntax::JSON, Syntax::XML);
    }

    #[Test]
    public function itThrowsOnTransformWhenBothFormatsHaveNoDrivers(): void
    {
        $this->expectException(UnsupportedSyntaxException::class);

        $this->transformer->transform('{}', Syntax::JSON, Syntax::XML);
    }

    #[Test]
    public function itThrowsOnTransformWithUnknownStringKeys(): void
    {
        $this->expectException(UnsupportedSyntaxException::class);
        $this->expectExceptionMessage('foo');

        $this->transformer->transform('data', 'foo', 'bar');
    }

    // ─── Identity Transform ─────────────────────────────────────────

    #[Test]
    public function itCanTransformToTheSameFormat(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());

        $input  = '{"hello":"world"}';
        $output = $this->transformer->transform($input, Syntax::JSON, Syntax::JSON);

        self::assertSame($input, $output);
    }

    #[Test]
    public function itCanTransformToTheSameCustomSyntax(): void
    {
        $driver = $this->createDriverWithOutput('echo');

        $this->transformer->registerSyntax('echo', $driver);

        $output = $this->transformer->transform('input', 'echo', 'echo');

        self::assertSame('echo', $output);
    }

    // ─── Transform Chain (A → B → C) ──────────────────────────────

    #[Test]
    public function itTransformsThroughTwoFormats(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());
        $this->transformer->registerDriver($this->createMockXmlDriver());

        // JSON → XML → XML — chain through intermediate format
        $result = $this->transformer->transformChain(
            '{"name":"test"}',
            Syntax::JSON,
            Syntax::XML,
        );

        self::assertStringContainsString('<name>test</name>', $result);
    }

    #[Test]
    public function itTransformsThroughThreeFormats(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());
        $this->transformer->registerDriver($this->createMockXmlDriver());
        $this->transformer->registerDriver($this->createMockYamlDriver());

        // JSON → XML → YAML
        $result = $this->transformer->transformChain(
            '{"name":"test","value":42}',
            Syntax::JSON,
            Syntax::XML,
            Syntax::YAML,
        );

        // YAML output should contain the value
        self::assertStringContainsString('name', $result);
        self::assertStringContainsString('test', $result);
    }

    #[Test]
    public function transformChainWithCustomSyntaxKeys(): void
    {
        $this->transformer->registerSyntax('a', $this->createJsonDriver());
        $this->transformer->registerSyntax('b', $this->createMockXmlDriver());
        $this->transformer->registerSyntax('c', $this->createMockYamlDriver());

        // a → b → c
        $result = $this->transformer->transformChain('{"hello":"world"}', 'a', 'b', 'c');

        self::assertStringContainsString('hello', $result);
        self::assertStringContainsString('world', $result);
    }

    #[Test]
    public function transformChainThrowsWithSingleSyntax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 2 syntaxes');

        $this->transformer->transformChain('{}', Syntax::JSON);
    }

    #[Test]
    public function transformChainThrowsWithNoSyntaxes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->transformer->transformChain('{}');
    }

    #[Test]
    public function transformChainThrowsWhenIntermediateFormatHasNoDriver(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());

        $this->expectException(UnsupportedSyntaxException::class);
        $this->expectExceptionMessage('yaml');

        $this->transformer->transformChain('{}', Syntax::JSON, Syntax::YAML, Syntax::XML);
    }

    #[Test]
    public function transformChainPreservesDataThroughRoundTrip(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());

        // JSON → JSON → JSON — should preserve data
        $result = $this->transformer->transformChain(
            '{"key":"value","num":42,"flag":true}',
            Syntax::JSON,
            Syntax::JSON,
            Syntax::JSON,
        );

        self::assertJsonStringEqualsJsonString(
            '{"key":"value","num":42,"flag":true}',
            $result,
        );
    }

    #[Test]
    public function transformChainWithFourFormats(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());
        $this->transformer->registerDriver($this->createMockXmlDriver());
        $this->transformer->registerDriver($this->createMockYamlDriver());
        $this->transformer->registerDriver($this->createMockTomlDriver());

        // JSON → XML → YAML → TOML
        $result = $this->transformer->transformChain(
            '{"a":1,"b":2}',
            Syntax::JSON,
            Syntax::XML,
            Syntax::YAML,
            Syntax::TOML,
        );

        self::assertStringContainsString('a', $result);
        self::assertStringContainsString('b', $result);
    }

    #[Test]
    public function transformChainWithCustomAndEnumSyntaxesMixed(): void
    {
        $this->transformer->registerSyntax('custom-json', $this->createJsonDriver());
        $this->transformer->registerDriver($this->createMockYamlDriver());

        // custom-json → YAML
        $result = $this->transformer->transformChain(
            '{"hello":"world"}',
            'custom-json',
            Syntax::YAML,
        );

        self::assertStringContainsString('hello', $result);
    }

    // ─── registeredSyntaxes ─────────────────────────────────────────

    #[Test]
    public function registeredSyntaxesReturnsEmptyByDefault(): void
    {
        self::assertSame([], $this->transformer->registeredSyntaxes());
    }

    #[Test]
    public function registeredSyntaxesContainsAllKeysWithCustomAndEnum(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());
        $this->transformer->registerDriver($this->createMockXmlDriver());
        $this->transformer->registerSyntax('custom', $this->createJsonDriver());

        $keys = $this->transformer->registeredSyntaxes();

        self::assertContains('json', $keys);
        self::assertContains('xml', $keys);
        self::assertContains('custom', $keys);
        self::assertCount(3, $keys);
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * Create a mock JSON driver that produces deterministic output.
     */
    private function createJsonDriver(?string $encodedOverride = null): DriverInterface
    {
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('supportedSyntax')->willReturn(Syntax::JSON);

        $driver
            ->method('decode')
            ->willReturnCallback(
                static function (string $input): array {
                    /** @var array<mixed> $decoded */
                    $decoded = \json_decode($input, true, 512, \JSON_THROW_ON_ERROR);

                    return $decoded;
                },
            );

        $driver
            ->method('encode')
            ->willReturnCallback(
                static fn (array $data): string =>
                    $encodedOverride
                    ?? \json_encode($data, \JSON_THROW_ON_ERROR),
            );

        return $driver;
    }

    /**
     * Create a mock XML driver for round-trip testing.
     */
    private function createMockXmlDriver(): DriverInterface
    {
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('supportedSyntax')->willReturn(Syntax::XML);

        $driver
            ->method('decode')
            ->willReturnCallback(static function (string $input): array {
                /** @var \SimpleXMLElement $xml */
                $xml = \simplexml_load_string($input);
                $result = [];

                foreach ($xml->children() as $child) {
                    /** @var \SimpleXMLElement $child */
                    $result[$child->getName()] = (string) $child;
                }

                return $result;
            });

        $driver
            ->method('encode')
            ->willReturnCallback(static function (array $data): string {
                $xml = new \SimpleXMLElement('<root/>');

                foreach ($data as $key => $value) {
                    $xml->addChild((string) $key, (string) $value);
                }

                return $xml->asXML() ?: '<?xml version="1.0"?><root/>';
            });

        return $driver;
    }

    /**
     * Create a mock YAML driver that decodes YAML-like simple lines.
     */
    private function createMockYamlDriver(): DriverInterface
    {
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('supportedSyntax')->willReturn(Syntax::YAML);

        $driver
            ->method('decode')
            ->willReturnCallback(static function (string $input): array {
                $result = [];

                foreach (\explode("\n", $input) as $line) {
                    $line = \trim($line);

                    if ($line === '' || $line[0] === '#') {
                        continue;
                    }

                    if (\str_contains($line, ': ')) {
                        [$key, $value] = \explode(': ', $line, 2);
                        $result[$key] = \trim($value);
                    }
                }

                return $result;
            });

        $driver
            ->method('encode')
            ->willReturnCallback(static function (array $data): string {
                $lines = [];

                foreach ($data as $key => $value) {
                    if (\is_bool($value)) {
                        $lines[] = $key . ': ' . ($value ? 'true' : 'false');
                    } elseif (\is_int($value) || \is_float($value)) {
                        $lines[] = $key . ': ' . $value;
                    } else {
                        $lines[] = $key . ': ' . (string) $value;
                    }
                }

                return \implode("\n", $lines) . "\n";
            });

        return $driver;
    }

    /**
     * Create a mock TOML driver for chained transform testing.
     */
    private function createMockTomlDriver(): DriverInterface
    {
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('supportedSyntax')->willReturn(Syntax::TOML);

        $driver
            ->method('decode')
            ->willReturnCallback(static function (string $input): array {
                $result = [];

                foreach (\explode("\n", $input) as $line) {
                    $line = \trim($line);

                    if ($line === '' || $line[0] === '#' || $line[0] === '[') {
                        continue;
                    }

                    if (\str_contains($line, ' = ')) {
                        [$key, $value] = \explode(' = ', $line, 2);
                        $result[\trim($key)] = \trim($value, '"');
                    }
                }

                return $result;
            });

        $driver
            ->method('encode')
            ->willReturnCallback(static function (array $data): string {
                $lines = [];

                foreach ($data as $key => $value) {
                    if (\is_bool($value)) {
                        $lines[] = $key . ' = ' . ($value ? 'true' : 'false');
                    } elseif (\is_int($value) || \is_float($value)) {
                        $lines[] = $key . ' = ' . $value;
                    } else {
                        $lines[] = $key . ' = "' . (string) $value . '"';
                    }
                }

                return \implode("\n", $lines) . "\n";
            });

        return $driver;
    }

    /**
     * Create a driver with a fixed encode/decode output for deterministic tests.
     */
    private function createDriverWithOutput(string $output): DriverInterface
    {
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('supportedSyntax')->willReturn(Syntax::JSON);
        $driver->method('decode')->willReturn(['preserved' => true]);
        $driver->method('encode')->willReturn($output);

        return $driver;
    }
}
