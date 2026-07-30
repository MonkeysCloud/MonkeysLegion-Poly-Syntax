<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Tests\Stream;

use Monkeyslegion\PolySyntax\Exception\DecodeException;
use Monkeyslegion\PolySyntax\Stream\TomlStreamingDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TomlStreamingDecoderTest extends TestCase
{
    // ─── Basic Operation ───────────────────────────────────────────

    #[Test]
    public function itStreamsRootKeysAsSingleSection(): void
    {
        $decoder = new TomlStreamingDecoder();

        $decoder->feed("title = \"My App\"\nversion = 1");
        $decoder->end();

        $section = $decoder->next();

        self::assertNotNull($section);
        self::assertSame('My App', $section['title'] ?? null);
        self::assertSame(1, $section['version'] ?? null);
        self::assertNull($decoder->next());
    }

    #[Test]
    public function itStreamsTableHeadersAsSeparateSections(): void
    {
        $decoder = new TomlStreamingDecoder();

        $decoder->feed(
            "title = \"My App\"\n"
            . "[server]\n"
            . "host = \"localhost\"\n"
            . "port = 8080\n"
            . "[database]\n"
            . "name = \"test\"",
        );
        $decoder->end();

        $sections = \iterator_to_array($this->drain($decoder));

        self::assertCount(3, $sections);
        self::assertSame('My App', $sections[0]['title']);
        self::assertSame('localhost', $sections[1]['server']['host']);
        self::assertSame(8080, $sections[1]['server']['port']);
        self::assertSame('test', $sections[2]['database']['name']);
    }

    #[Test]
    public function itStreamsArrayOfTablesAsIndividualEntries(): void
    {
        $decoder = new TomlStreamingDecoder();

        $decoder->feed(
            "[[server]]\n"
            . "name = \"web-01\"\n"
            . "port = 8080\n"
            . "[[server]]\n"
            . "name = \"web-02\"\n"
            . "port = 8081",
        );
        $decoder->end();

        $sections = \iterator_to_array($this->drain($decoder));

        self::assertCount(2, $sections);
        self::assertSame('web-01', $sections[0]['server']['name']);
        self::assertSame(8080, $sections[0]['server']['port']);
        self::assertSame('web-02', $sections[1]['server']['name']);
        self::assertSame(8081, $sections[1]['server']['port']);
    }

    // ─── Chunk Boundary Handling ───────────────────────────────────

    #[Test]
    public function itHandlesChunkBoundariesAcrossLines(): void
    {
        $decoder = new TomlStreamingDecoder();

        $decoder->feed("title = \"My Ap");
        $decoder->feed("p\"\n[x]");
        $decoder->feed("\nval = 42");
        $decoder->end();

        $sections = \iterator_to_array($this->drain($decoder));

        self::assertCount(2, $sections);
        self::assertSame('My App', $sections[0]['title']);
        self::assertSame(42, $sections[1]['x']['val']);
    }

    #[Test]
    public function itHandlesSplitAcrossArrayOfTables(): void
    {
        $decoder = new TomlStreamingDecoder();

        $decoder->feed("[[it");
        $decoder->feed("em]]\nkey = 1\n[[item]]\nk");
        $decoder->feed("ey = 2");
        $decoder->end();

        $sections = \iterator_to_array($this->drain($decoder));

        self::assertCount(2, $sections);
        self::assertSame(1, $sections[0]['item']['key']);
        self::assertSame(2, $sections[1]['item']['key']);
    }

    // ─── Multi-line Strings ────────────────────────────────────────

    #[Test]
    public function itHandlesMultiLineBasicStrings(): void
    {
        $decoder = new TomlStreamingDecoder();

        $decoder->feed("desc = \"\"\"\nline1\nline2\"\"\"\n[x]\nv = 1");
        $decoder->end();

        $sections = \iterator_to_array($this->drain($decoder));

        self::assertCount(2, $sections);
        self::assertSame("line1\nline2", $sections[0]['desc']);
    }

    #[Test]
    public function itHandlesMultiLineBasicStringsAcrossChunks(): void
    {
        $decoder = new TomlStreamingDecoder();

        $decoder->feed("desc = \"\"\"\nhel");
        $decoder->feed("lo\"\"\"\n[x]\nv = 1");
        $decoder->end();

        $sections = \iterator_to_array($this->drain($decoder));

        self::assertCount(2, $sections);
        self::assertSame('hello', $sections[0]['desc']);
    }

    #[Test]
    public function itHandlesMultiLineLiteralStrings(): void
    {
        $decoder = new TomlStreamingDecoder();

        $decoder->feed("sql = '''\nSELECT *\nFROM users'''\n[y]\nk = 2");
        $decoder->end();

        $sections = \iterator_to_array($this->drain($decoder));

        self::assertCount(2, $sections);
        self::assertSame("SELECT *\nFROM users", $sections[0]['sql']);
    }

    // ─── Empty Input ───────────────────────────────────────────────

    #[Test]
    public function itReturnsNullForEmptyInput(): void
    {
        $decoder = new TomlStreamingDecoder();

        $decoder->feed('');
        $decoder->end();

        self::assertNull($decoder->next());
    }

    #[Test]
    public function itReturnsNullForCommentsOnly(): void
    {
        $decoder = new TomlStreamingDecoder();

        $decoder->feed("# just a comment\n# another\n");
        $decoder->end();

        self::assertNull($decoder->next());
    }

    // ─── Edge Cases ────────────────────────────────────────────────

    #[Test]
    public function itHandlesDottedKeys(): void
    {
        $decoder = new TomlStreamingDecoder();

        $decoder->feed("a.b.c = 42\nx.y = \"hello\"");
        $decoder->end();

        $section = $decoder->next();

        self::assertNotNull($section);
        self::assertSame(42, $section['a']['b']['c']);
        self::assertSame('hello', $section['x']['y']);
    }

    #[Test]
    public function itHandlesArraysAndInlineTables(): void
    {
        $decoder = new TomlStreamingDecoder();

        $decoder->feed("nums = [1, 2, 3]\ncfg = {a = 1, b = \"x\"}");
        $decoder->end();

        $section = $decoder->next();

        self::assertNotNull($section);
        self::assertSame([1, 2, 3], $section['nums']);
        self::assertSame(['a' => 1, 'b' => 'x'], $section['cfg']);
    }

    #[Test]
    public function itHandlesBooleanAndNumericTypes(): void
    {
        $decoder = new TomlStreamingDecoder();

        $decoder->feed("flag = true\ncount = 42\npi = 3.14\nneg = -1");
        $decoder->end();

        $section = $decoder->next();

        self::assertNotNull($section);
        self::assertTrue($section['flag']);
        self::assertSame(42, $section['count']);
        self::assertSame(3.14, $section['pi']);
        self::assertSame(-1, $section['neg']);
    }

    #[Test]
    public function itRejectsFeedAfterEnd(): void
    {
        $decoder = new TomlStreamingDecoder();

        $decoder->end();

        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('Cannot feed data after end()');

        $decoder->feed('more data');
    }

    #[Test]
    public function itReportsPosition(): void
    {
        $decoder = new TomlStreamingDecoder();

        self::assertSame(0, $decoder->position());

        $decoder->feed("a = 1\n[b]\nc = 2");
        $decoder->end();

        $decoder->next();
        self::assertSame(1, $decoder->position());

        $decoder->next();
        self::assertSame(2, $decoder->position());

        self::assertNull($decoder->next());
    }

    #[Test]
    public function itResetsState(): void
    {
        $decoder = new TomlStreamingDecoder();

        $decoder->feed("a = 1\n[b]\nc = 2");
        $decoder->end();

        \iterator_to_array($this->drain($decoder));

        $decoder->reset();

        $decoder->feed("x = \"new\"");
        $decoder->end();

        $section = $decoder->next();

        self::assertNotNull($section);
        self::assertSame('new', $section['x']);
        self::assertNull($decoder->next());
    }

    #[Test]
    public function itSkipsEmptyTables(): void
    {
        $decoder = new TomlStreamingDecoder();

        $decoder->feed("a = 1\n[empty]\n[b]\nc = 2");
        $decoder->end();

        $sections = \iterator_to_array($this->drain($decoder));

        // [empty] table has no keys — should not be yielded
        self::assertCount(2, $sections);
        self::assertSame(1, $sections[0]['a']);
        self::assertSame(2, $sections[1]['b']['c']);
    }

    // ─── Helper ────────────────────────────────────────────────────

    /**
     * Drain all items from a decoder into an array.
     *
     * @return list<mixed>
     */
    private function drain(TomlStreamingDecoder $decoder): array
    {
        $items = [];

        while (($item = $decoder->next()) !== null) {
            $items[] = $item;
        }

        return $items;
    }
}
