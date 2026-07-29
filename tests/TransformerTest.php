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
        $this->transformer->registerDriver($this->createXmlDriver());

        $supported = $this->transformer->supportedSyntaxes();

        self::assertContains(Syntax::JSON, $supported);
        self::assertContains(Syntax::XML, $supported);
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

    // ─── Decode ─────────────────────────────────────────────────────

    #[Test]
    public function itDecodesUsingTheCorrectDriver(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());

        $result = $this->transformer->decode('{"name":"test"}', Syntax::JSON);

        self::assertSame(['name' => 'test'], $result);
    }

    #[Test]
    public function itThrowsUnsupportedSyntaxOnDecodeWithNoDriver(): void
    {
        $this->expectException(UnsupportedSyntaxException::class);
        $this->expectExceptionMessage('toml');

        $this->transformer->decode('key = "value"', Syntax::TOML);
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
    public function itThrowsUnsupportedSyntaxOnEncodeWithNoDriver(): void
    {
        $this->expectException(UnsupportedSyntaxException::class);
        $this->expectExceptionMessage('xml');

        $this->transformer->encode(['key' => 'value'], Syntax::XML);
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
        $this->transformer->registerDriver($this->createXmlDriver());

        $result = $this->transformer->transform(
            '{"name":"test","value":42}',
            Syntax::JSON,
            Syntax::XML,
        );

        self::assertStringContainsString('<name>test</name>', $result);
        self::assertStringContainsString('<value>42</value>', $result);
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

    // ─── Identity Transform ─────────────────────────────────────────

    #[Test]
    public function itCanTransformToTheSameFormat(): void
    {
        $this->transformer->registerDriver($this->createJsonDriver());

        $input  = '{"hello":"world"}';
        $output = $this->transformer->transform($input, Syntax::JSON, Syntax::JSON);

        self::assertSame($input, $output);
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
    private function createXmlDriver(): DriverInterface
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
}
