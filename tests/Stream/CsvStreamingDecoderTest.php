<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Tests\Stream;

use Monkeyslegion\PolySyntax\Exception\DecodeException;
use Monkeyslegion\PolySyntax\Stream\CsvStreamingDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CsvStreamingDecoderTest extends TestCase
{
    // ─── Basic Operation ───────────────────────────────────────────

    #[Test]
    public function itStreamsSimpleRowsWithHeaders(): void
    {
        $decoder = new CsvStreamingDecoder();

        $decoder->feed("name,age\nAlice,30\nBob,25");
        $decoder->end();

        $row1 = $decoder->next();
        $row2 = $decoder->next();
        $row3 = $decoder->next();

        self::assertSame(['name' => 'Alice', 'age' => '30'], $row1);
        self::assertSame(['name' => 'Bob', 'age' => '25'], $row2);
        self::assertNull($row3);
    }

    #[Test]
    public function itStreamsWithoutHeaders(): void
    {
        $decoder = new CsvStreamingDecoder(hasHeaders: false);

        $decoder->feed("Alice,30\nBob,25");
        $decoder->end();

        $row1 = $decoder->next();
        $row2 = $decoder->next();
        $row3 = $decoder->next();

        self::assertSame(['Alice', '30'], $row1);
        self::assertSame(['Bob', '25'], $row2);
        self::assertNull($row3);
    }

    #[Test]
    public function itStreamsWithManualHeaders(): void
    {
        $decoder = new CsvStreamingDecoder(
            headers: ['name', 'age'],
        );

        $decoder->feed("Alice,30\nBob,25");
        $decoder->end();

        $row1 = $decoder->next();
        $row2 = $decoder->next();

        self::assertSame(['name' => 'Alice', 'age' => '30'], $row1);
        self::assertSame(['name' => 'Bob', 'age' => '25'], $row2);
    }

    // ─── Chunk Boundary Handling ───────────────────────────────────

    #[Test]
    public function itHandlesChunkBoundaries(): void
    {
        $decoder = new CsvStreamingDecoder();

        $decoder->feed("name,age\nAli");
        $decoder->feed("ce,30\nBob");
        $decoder->feed(",25");
        $decoder->end();

        $rows = [];

        while (($row = $decoder->next()) !== null) {
            $rows[] = $row;
        }

        self::assertCount(2, $rows);
        self::assertSame(['name' => 'Alice', 'age' => '30'], $rows[0]);
        self::assertSame(['name' => 'Bob', 'age' => '25'], $rows[1]);
    }

    #[Test]
    public function itHandlesMultiLineQuotedFieldsAcrossChunks(): void
    {
        $decoder = new CsvStreamingDecoder();

        $decoder->feed("name,bio\nAlice,\"Hello\nwor");
        $decoder->feed("ld\nsecond line\"");
        $decoder->end();

        $row = $decoder->next();

        self::assertNotNull($row);
        self::assertSame('Alice', $row['name']);
        self::assertSame("Hello\nworld\nsecond line", $row['bio']);
    }

    // ─── Empty Input ───────────────────────────────────────────────

    #[Test]
    public function itReturnsNullForEmptyInput(): void
    {
        $decoder = new CsvStreamingDecoder();

        $decoder->feed('');
        $decoder->end();

        self::assertNull($decoder->next());
    }

    #[Test]
    public function itReturnsNullForBlankInput(): void
    {
        $decoder = new CsvStreamingDecoder();

        $decoder->feed("\n\n  \n");
        $decoder->end();

        self::assertNull($decoder->next());
    }

    // ─── Edge Cases ────────────────────────────────────────────────

    #[Test]
    public function itHandlesTrailingNewline(): void
    {
        $decoder = new CsvStreamingDecoder(hasHeaders: false);

        $decoder->feed("a,b\n");
        $decoder->end();

        self::assertSame(['a', 'b'], $decoder->next());
        self::assertNull($decoder->next());
    }

    #[Test]
    public function itHandlesQuotedFields(): void
    {
        $decoder = new CsvStreamingDecoder(hasHeaders: false);

        $decoder->feed("\"hello, world\",\"she said \"\"hi\"\"\"\n");
        $decoder->end();

        $row = $decoder->next();

        self::assertNotNull($row);
        self::assertSame('hello, world', $row[0]);
        self::assertSame('she said "hi"', $row[1]);
    }

    #[Test]
    public function itReportsPosition(): void
    {
        $decoder = new CsvStreamingDecoder(hasHeaders: false);

        self::assertSame(0, $decoder->position());

        $decoder->feed("a\nb\n");
        $decoder->end();

        $decoder->next();
        self::assertSame(1, $decoder->position());

        $decoder->next();
        self::assertSame(2, $decoder->position());
    }

    #[Test]
    public function itRejectsFeedAfterEnd(): void
    {
        $decoder = new CsvStreamingDecoder();

        $decoder->end();

        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('Cannot feed data after end()');

        $decoder->feed('more data');
    }

    // ─── Custom Options ────────────────────────────────────────────

    #[Test]
    public function itSupportsCustomDelimiter(): void
    {
        $decoder = new CsvStreamingDecoder(
            delimiter: ';',
            hasHeaders: false,
        );

        $decoder->feed("a;b\nc;d");
        $decoder->end();

        self::assertSame(['a', 'b'], $decoder->next());
        self::assertSame(['c', 'd'], $decoder->next());
    }

    #[Test]
    public function itRejectsInvalidDelimiter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CsvStreamingDecoder(delimiter: '||');
    }

    #[Test]
    public function itRejectsInvalidEnclosure(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CsvStreamingDecoder(enclosure: '""');
    }

    // ─── Incremental Feed Pattern ──────────────────────────────────

    #[Test]
    public function itWorksWithIncrementalFeedAndDrainPattern(): void
    {
        $decoder = new CsvStreamingDecoder();
        $allRows = [];

        // Feed in small chunks, draining between feeds
        $decoder->feed("name,val\n");

        while (($row = $decoder->next()) !== null) {
            $allRows[] = $row;
        }

        $decoder->feed("one,1\n");

        while (($row = $decoder->next()) !== null) {
            $allRows[] = $row;
        }

        $decoder->feed("two,2\n");
        $decoder->end();

        while (($row = $decoder->next()) !== null) {
            $allRows[] = $row;
        }

        self::assertCount(2, $allRows);
        self::assertSame(['name' => 'one', 'val' => '1'], $allRows[0]);
        self::assertSame(['name' => 'two', 'val' => '2'], $allRows[1]);
    }

    #[Test]
    public function itHandlesSingleRowInput(): void
    {
        $decoder = new CsvStreamingDecoder(hasHeaders: false);

        $decoder->feed("justOne");
        $decoder->end();

        self::assertSame(['justOne'], $decoder->next());
        self::assertNull($decoder->next());
    }
}
