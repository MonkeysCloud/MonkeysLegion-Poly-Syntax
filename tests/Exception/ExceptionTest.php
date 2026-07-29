<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Tests\Exception;

use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;
use Monkeyslegion\PolySyntax\Exception\EncodeException;
use Monkeyslegion\PolySyntax\Exception\TransformerException;
use Monkeyslegion\PolySyntax\Exception\UnsupportedSyntaxException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    #[Test]
    public function itCreatesTransformerException(): void
    {
        $e = new TransformerException('test', 42);

        self::assertSame('test', $e->getMessage());
        self::assertSame(42, $e->getCode());
    }

    #[Test]
    public function itCreatesDecodeExceptionWithDefaultMessage(): void
    {
        $e = new DecodeException();

        self::assertSame('Failed to decode input', $e->getMessage());
        self::assertInstanceOf(TransformerException::class, $e);
    }

    #[Test]
    public function itCreatesDecodeExceptionWithCustomMessage(): void
    {
        $e = new DecodeException('Custom error', 1);

        self::assertSame('Custom error', $e->getMessage());
        self::assertSame(1, $e->getCode());
    }

    #[Test]
    public function itCreatesEncodeExceptionWithDefaultMessage(): void
    {
        $e = new EncodeException();

        self::assertSame('Failed to encode data', $e->getMessage());
        self::assertInstanceOf(TransformerException::class, $e);
    }

    #[Test]
    public function itCreatesEncodeExceptionWithCustomMessage(): void
    {
        $e = new EncodeException('Custom error', 1);

        self::assertSame('Custom error', $e->getMessage());
        self::assertSame(1, $e->getCode());
    }

    #[Test]
    public function itCreatesUnsupportedSyntaxException(): void
    {
        $e = new UnsupportedSyntaxException(Syntax::JSON);

        self::assertStringContainsString('json', $e->getMessage());
        self::assertInstanceOf(TransformerException::class, $e);
    }

    #[Test]
    public function itChainsPreviousException(): void
    {
        $previous = new \RuntimeException('previous');
        $e = new DecodeException('wrapped', 0, $previous);

        self::assertSame($previous, $e->getPrevious());
    }
}
