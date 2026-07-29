<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Tests\Enum;

use Monkeyslegion\PolySyntax\Enum\Syntax;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SyntaxTest extends TestCase
{
    #[Test]
    public function itHasAllExpectedValues(): void
    {
        self::assertSame('json', Syntax::JSON->value);
        self::assertSame('yaml', Syntax::YAML->value);
        self::assertSame('toml', Syntax::TOML->value);
        self::assertSame('xml', Syntax::XML->value);
        self::assertSame('csv', Syntax::CSV->value);
    }

    #[Test]
    public function itReturnsHumanReadableLabels(): void
    {
        self::assertSame('JSON', Syntax::JSON->label());
        self::assertSame('YAML', Syntax::YAML->label());
        self::assertSame('TOML', Syntax::TOML->label());
        self::assertSame('XML', Syntax::XML->label());
        self::assertSame('CSV', Syntax::CSV->label());
    }

    #[Test]
    public function itReturnsAllSyntaxes(): void
    {
        $all = Syntax::all();

        self::assertCount(5, $all);
        self::assertContains(Syntax::JSON, $all);
        self::assertContains(Syntax::YAML, $all);
        self::assertContains(Syntax::TOML, $all);
        self::assertContains(Syntax::XML, $all);
        self::assertContains(Syntax::CSV, $all);
    }
}
