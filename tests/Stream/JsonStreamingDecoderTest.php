<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Tests\Stream;

use Monkeyslegion\PolySyntax\Exception\DecodeException;
use Monkeyslegion\PolySyntax\Stream\JsonStreamingDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JsonStreamingDecoderTest extends TestCase
{
    #[Test]
    public function itStreamsArrayOfObjects(): void
    {
        $decoder = new JsonStreamingDecoder();

        $decoder->feed('[{"id":1,"name":"Alice"},{"id":2,"name":"Bob"}]');
        $decoder->end();

        $row1 = $decoder->next();
        $row2 = $decoder->next();
        $row3 = $decoder->next();

        self::assertSame(['id' => 1, 'name' => 'Alice'], $row1);
        self::assertSame(['id' => 2, 'name' => 'Bob'], $row2);
        self::assertNull($row3);
    }

    #[Test]
    public function itStreamsSingleObjectArray(): void
    {
        $decoder = new JsonStreamingDecoder();

        $decoder->feed('[{"msg":"hello"}]');
        $decoder->end();

        self::assertSame(['msg' => 'hello'], $decoder->next());
        self::assertNull($decoder->next());
    }

    #[Test]
    public function itHandlesChunkBoundaries(): void
    {
        $decoder = new JsonStreamingDecoder();

        $decoder->feed('[{"id":1');
        $decoder->feed(',"name":"Alice"');
        $decoder->feed('},{"id":2,"name":"Bob"}]');
        $decoder->end();

        $rows = [];

        while (($row = $decoder->next()) !== null) {
            $rows[] = $row;
        }

        self::assertCount(2, $rows);
        self::assertSame(['id' => 1, 'name' => 'Alice'], $rows[0]);
        self::assertSame(['id' => 2, 'name' => 'Bob'], $rows[1]);
    }

    #[Test]
    public function itHandlesSplitObjectBoundaries(): void
    {
        $decoder = new JsonStreamingDecoder();

        $decoder->feed('[{"a');
        $decoder->feed('":1},{"b":2}]');
        $decoder->end();

        self::assertSame(['a' => 1], $decoder->next());
        self::assertSame(['b' => 2], $decoder->next());
        self::assertNull($decoder->next());
    }

    #[Test]
    public function itReturnsNullForEmptyArray(): void
    {
        $decoder = new JsonStreamingDecoder();

        $decoder->feed('[]');
        $decoder->end();

        self::assertNull($decoder->next());
    }

    #[Test]
    public function itReturnsNullForEmptyInput(): void
    {
        $decoder = new JsonStreamingDecoder();

        $decoder->feed('');
        $decoder->end();

        self::assertNull($decoder->next());
    }

    #[Test]
    public function itHandlesWhitespaceBetweenElements(): void
    {
        $decoder = new JsonStreamingDecoder();

        $decoder->feed('[  { "x": 1 }  ,  { "y": 2 }  ]');
        $decoder->end();

        self::assertSame(['x' => 1], $decoder->next());
        self::assertSame(['y' => 2], $decoder->next());
        self::assertNull($decoder->next());
    }

    #[Test]
    public function itHandlesNestedObjects(): void
    {
        $decoder = new JsonStreamingDecoder();

        $decoder->feed('[{"a":{"b":{"c":1}}},{"d":2}]');
        $decoder->end();

        $row1 = $decoder->next();
        $row2 = $decoder->next();

        self::assertSame(['b' => ['c' => 1]], $row1['a']);
        self::assertSame(['d' => 2], $row2);
        self::assertNull($decoder->next());
    }

    #[Test]
    public function itHandlesNestedArrays(): void
    {
        $decoder = new JsonStreamingDecoder();

        $decoder->feed('[{"items":[1,2,3]},{"items":[4,5]}]');
        $decoder->end();

        $row1 = $decoder->next();
        $row2 = $decoder->next();

        self::assertSame([1, 2, 3], $row1['items']);
        self::assertSame([4, 5], $row2['items']);
    }

    #[Test]
    public function itHandlesStringsWithBracesInside(): void
    {
        $decoder = new JsonStreamingDecoder();

        $decoder->feed('[{"code":"if (x) { y }"},{"code":"fn() => [a]"}]');
        $decoder->end();

        $row1 = $decoder->next();
        $row2 = $decoder->next();

        self::assertSame('if (x) { y }', $row1['code']);
        self::assertSame('fn() => [a]', $row2['code']);
    }

    #[Test]
    public function itHandlesEscapedQuotesInsideStrings(): void
    {
        $decoder = new JsonStreamingDecoder();

        $decoder->feed('[{"msg":"he said \"hello\""},{"msg":"ok"}]');
        $decoder->end();

        $row1 = $decoder->next();
        $row2 = $decoder->next();

        self::assertSame('he said "hello"', $row1['msg']);
        self::assertSame('ok', $row2['msg']);
    }

    #[Test]
    public function itWorksWithIncrementalFeedAndDrainPattern(): void
    {
        $decoder = new JsonStreamingDecoder();
        $allRows = [];

        $decoder->feed('[{"n":1');

        while (($row = $decoder->next()) !== null) {
            $allRows[] = $row;
        }

        $decoder->feed('},{"n":2}]');
        $decoder->end();

        while (($row = $decoder->next()) !== null) {
            $allRows[] = $row;
        }

        self::assertCount(2, $allRows);
        self::assertSame(['n' => 1], $allRows[0]);
        self::assertSame(['n' => 2], $allRows[1]);
    }

    #[Test]
    public function itReportsElementCount(): void
    {
        $decoder = new JsonStreamingDecoder();

        self::assertSame(0, $decoder->position());

        $decoder->feed('[{"a":1},{"b":2}]');
        $decoder->end();

        $decoder->next();
        self::assertSame(1, $decoder->position());

        $decoder->next();
        self::assertSame(2, $decoder->position());
    }

    #[Test]
    public function itRejectsNonArrayInput(): void
    {
        $decoder = new JsonStreamingDecoder();

        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('top-level array');

        $decoder->feed('{"single": "object"}');
    }

    #[Test]
    public function itRejectsScalarInput(): void
    {
        $decoder = new JsonStreamingDecoder();

        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('expects a JSON array starting with');

        $decoder->feed('"just a string"');
    }

    #[Test]
    public function itRejectsFeedAfterEnd(): void
    {
        $decoder = new JsonStreamingDecoder();

        $decoder->end();

        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('Cannot feed data after end()');

        $decoder->feed('more data');
    }

    #[Test]
    public function itHandlesDeeplyNestedMixedStructures(): void
    {
        $decoder = new JsonStreamingDecoder();

        $decoder->feed('[{' . "\n" . '  "user": {' . "\n" . '    "name": "Alice",' . "\n" . '    "roles": ["admin", {' . "\n" . '      "permission": "write"' . "\n" . '    }]' . "\n" . '  }' . "\n" . '}]');
        $decoder->end();

        $row = $decoder->next();

        self::assertSame('Alice', $row['user']['name']);
        self::assertSame('admin', $row['user']['roles'][0]);
        self::assertSame('write', $row['user']['roles'][1]['permission']);
    }
}
