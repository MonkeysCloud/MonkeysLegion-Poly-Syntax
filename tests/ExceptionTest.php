<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Tests;

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
    public function transformerExceptionHasDefaultCodeZero(): void
    {
        $e = new TransformerException();

        self::assertSame(0, $e->getCode());
    }

    #[Test]
    public function decodeExceptionHasDefaultCodeZero(): void
    {
        $e = new DecodeException();

        self::assertSame(0, $e->getCode());
    }

    #[Test]
    public function encodeExceptionHasDefaultCodeZero(): void
    {
        $e = new EncodeException();

        self::assertSame(0, $e->getCode());
    }

    #[Test]
    public function unsupportedSyntaxExceptionHasDefaultCodeZero(): void
    {
        $e = new UnsupportedSyntaxException(Syntax::JSON);

        self::assertSame(0, $e->getCode());
    }
}
