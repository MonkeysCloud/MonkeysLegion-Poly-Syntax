<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Tests\Driver;

use Monkeyslegion\PolySyntax\Driver\TomlDriver;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TomlDriverTest extends TestCase
{
    private TomlDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new TomlDriver();
    }

    #[Test]
    public function itReportsTomlSyntax(): void
    {
        self::assertSame(Syntax::TOML, $this->driver->supportedSyntax());
    }

    // ─── Decode ────────────────────────────────────────────────────

    #[Test]
    public function itDecodesSimpleKeyValuePairs(): void
    {
        $toml = <<<'TOML'
str = "Hello"
int = 42
float = 3.14
bool = true
neg = -1
TOML;

        $result = $this->driver->decode($toml);

        self::assertSame('Hello', $result['str']);
        self::assertSame(42, $result['int']);
        self::assertSame(3.14, $result['float']);
        self::assertTrue($result['bool']);
        self::assertSame(-1, $result['neg']);
    }

    #[Test]
    public function itDecodesBooleanFalse(): void
    {
        $result = $this->driver->decode('flag = false');

        self::assertFalse($result['flag']);
    }

    #[Test]
    public function itDecodesEmptyInputAsEmptyArray(): void
    {
        self::assertSame([], $this->driver->decode(''));
        self::assertSame([], $this->driver->decode("   \n  \n  "));
    }

    #[Test]
    public function itDecodesStringsWithEscapeSequences(): void
    {
        $toml = <<<'TOML'
tab = "hello\tworld"
newline = "line1\nline2"
quote = "she said \"hi\""
backslash = "path\\to\\file"
TOML;

        $result = $this->driver->decode($toml);

        self::assertSame("hello\tworld", $result['tab']);
        self::assertSame("line1\nline2", $result['newline']);
        self::assertSame('she said "hi"', $result['quote']);
        self::assertSame('path\\to\\file', $result['backslash']);
    }

    #[Test]
    public function itDecodesLiteralStrings(): void
    {
        $toml = <<<'TOML'
path = 'C:\Windows\System32'
regex = '<\i\c*\s*>'
TOML;

        $result = $this->driver->decode($toml);

        self::assertSame('C:\Windows\System32', $result['path']);
        self::assertSame('<\i\c*\s*>', $result['regex']);
    }

    #[Test]
    public function itDecodesMultiLineBasicStrings(): void
    {
        $toml = <<<'TOML'
str = """
The quick brown \
fox jumps over \
the lazy dog."""
TOML;

        $result = $this->driver->decode($toml);

        self::assertSame('The quick brown fox jumps over the lazy dog.', $result['str']);
    }

    #[Test]
    public function itDecodesMultiLineLiteralStrings(): void
    {
        $toml = <<<'TOML'
str = '''
I [dw]on't need \d{2} apples'''
TOML;

        $result = $this->driver->decode($toml);

        self::assertSame("I [dw]on't need \\d{2} apples", $result['str']);
    }

    #[Test]
    public function itDecodesQuotedKeys(): void
    {
        $toml = <<<'TOML'
"127.0.0.1" = "localhost"
"character encoding" = "UTF-8"
'key with spaces' = "value"
TOML;

        $result = $this->driver->decode($toml);

        self::assertSame('localhost', $result['127.0.0.1']);
        self::assertSame('UTF-8', $result['character encoding']);
        self::assertSame('value', $result['key with spaces']);
    }

    #[Test]
    public function itDecodesIntegerBases(): void
    {
        $toml = <<<'TOML'
hex = 0xDEAD_BEEF
oct = 0o755
bin = 0b11010110
TOML;

        $result = $this->driver->decode($toml);

        self::assertSame(0xDEADBEEF, $result['hex']);
        self::assertSame(0o755, $result['oct']);
        self::assertSame(0b11010110, $result['bin']);
    }

    #[Test]
    public function itDecodesFloatsWithUnderscores(): void
    {
        $toml = <<<'TOML'
flt1 = 224_617.445_991_228
flt2 = 1e6
flt3 = 6.626e-34
TOML;

        $result = $this->driver->decode($toml);

        self::assertSame(224617.445991228, $result['flt1']);
        self::assertSame(1_000_000.0, $result['flt2']);
        self::assertSame(6.626e-34, $result['flt3']);
    }

    #[Test]
    public function itDecodesSpecialFloats(): void
    {
        $toml = <<<'TOML'
inf1 = inf
inf2 = +inf
neg_inf = -inf
nan1 = nan
nan2 = +nan
nan3 = -nan
TOML;

        $result = $this->driver->decode($toml);

        self::assertTrue(\is_infinite($result['inf1']) && $result['inf1'] > 0);
        self::assertTrue(\is_infinite($result['inf2']) && $result['inf2'] > 0);
        self::assertTrue(\is_infinite($result['neg_inf']) && $result['neg_inf'] < 0);
        self::assertTrue(\is_nan($result['nan1']));
        self::assertTrue(\is_nan($result['nan2']));
        self::assertTrue(\is_nan($result['nan3']));
    }

    #[Test]
    public function itDecodesComments(): void
    {
        $toml = <<<'TOML'
# This is a full line comment
key = "value" # inline comment
# Another comment
TOML;

        $result = $this->driver->decode($toml);

        self::assertSame(['key' => 'value'], $result);
    }

    #[Test]
    public function itDecodesDottedKeys(): void
    {
        $toml = <<<'TOML'
name = "Orange"
physical.color = "orange"
physical.shape = "round"
site."google.com" = true
TOML;

        $result = $this->driver->decode($toml);

        self::assertSame('Orange', $result['name']);
        self::assertSame('orange', $result['physical']['color']);
        self::assertSame('round', $result['physical']['shape']);
        self::assertTrue($result['site']['google.com']);
    }

    #[Test]
    public function itDecodesTables(): void
    {
        $toml = <<<'TOML'
[server]
host = "192.168.1.1"
port = 8080

[server.admin]
user = "admin"
enabled = true
TOML;

        $result = $this->driver->decode($toml);

        self::assertSame('192.168.1.1', $result['server']['host']);
        self::assertSame(8080, $result['server']['port']);
        self::assertSame('admin', $result['server']['admin']['user']);
        self::assertTrue($result['server']['admin']['enabled']);
    }

    #[Test]
    public function itDecodesArrayOfTables(): void
    {
        $toml = <<<'TOML'
[[products]]
name = "Hammer"
sku = 738594937

[[products]]

[[products]]
name = "Nail"
sku = 284758393
TOML;

        $result = $this->driver->decode($toml);

        self::assertCount(3, $result['products']);
        self::assertSame('Hammer', $result['products'][0]['name']);
        self::assertSame(738594937, $result['products'][0]['sku']);
        self::assertSame([], $result['products'][1]);
        self::assertSame('Nail', $result['products'][2]['name']);
    }

    #[Test]
    public function itDecodesArrays(): void
    {
        $toml = <<<'TOML'
integers = [1, 2, 3]
colors = ["red", "yellow", "green"]
nested = [[1, 2], [3, 4, 5]]
trailing = [1,]
TOML;

        $result = $this->driver->decode($toml);

        self::assertSame([1, 2, 3], $result['integers']);
        self::assertSame(['red', 'yellow', 'green'], $result['colors']);
        self::assertSame([[1, 2], [3, 4, 5]], $result['nested']);
        self::assertSame([1], $result['trailing']);
    }

    #[Test]
    public function itDecodesInlineTables(): void
    {
        $toml = <<<'TOML'
point = { x = 1, y = 2 }
name = { first = "Tom", last = "Preston-Werner" }
empty = {}
TOML;

        $result = $this->driver->decode($toml);

        self::assertSame(['x' => 1, 'y' => 2], $result['point']);
        self::assertSame('Tom', $result['name']['first']);
        self::assertSame('Preston-Werner', $result['name']['last']);
        self::assertSame([], $result['empty']);
    }

    #[Test]
    public function itDecodesIntegersWithUnderscores(): void
    {
        $result = $this->driver->decode('million = 1_000_000');
        self::assertSame(1_000_000, $result['million']);
    }

    #[Test]
    public function itDecodesIntegersWithSigns(): void
    {
        $toml = <<<'TOML'
positive = +99
negative = -17
zero = 0
TOML;

        $result = $this->driver->decode($toml);

        self::assertSame(99, $result['positive']);
        self::assertSame(-17, $result['negative']);
        self::assertSame(0, $result['zero']);
    }

    #[Test]
    public function itDecodesDatetimeValues(): void
    {
        $toml = <<<'TOML'
odt = 1979-05-27T07:32:00Z
ldt = 1979-05-27T07:32:00
ld = 1979-05-27
lt1 = 07:32:00
lt2 = 07:32:00.999999
TOML;

        $result = $this->driver->decode($toml);

        self::assertInstanceOf(\DateTimeImmutable::class, $result['odt']);
        self::assertSame('1979-05-27T07:32:00+00:00', $result['odt']->format(\DateTimeInterface::RFC3339));
        self::assertInstanceOf(\DateTimeImmutable::class, $result['ldt']);
        self::assertSame('1979-05-27', $result['ld']);
        self::assertSame('07:32:00', $result['lt1']);
        self::assertSame('07:32:00.999999', $result['lt2']);
    }

    #[Test]
    public function itThrowsOnMalformedToml(): void
    {
        $this->expectException(DecodeException::class);

        $this->driver->decode('key =');
    }

    #[Test]
    public function itThrowsOnInvalidTableHeader(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('Invalid table header');

        $this->driver->decode('[incomplete');
    }

    #[Test]
    public function itThrowsOnDuplicateKey(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('Duplicate key');

        $this->driver->decode("key = 1\nkey = 2");
    }

    #[Test]
    public function itThrowsOnUnrecognisedValue(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('Unrecognised value');

        $this->driver->decode('key = @invalid');
    }

    // ─── Encode ────────────────────────────────────────────────────

    #[Test]
    public function itEncodesSimpleValues(): void
    {
        $data = [
            'string' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool_true' => true,
            'bool_false' => false,
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('string = "hello"', $result);
        self::assertStringContainsString('int = 42', $result);
        self::assertStringContainsString('float = 3.14', $result);
        self::assertStringContainsString('bool_true = true', $result);
        self::assertStringContainsString('bool_false = false', $result);
    }

    #[Test]
    public function itEncodesSpecialFloats(): void
    {
        $result = $this->driver->encode([
            'inf' => \INF,
            'neg_inf' => -\INF,
            'nan' => \NAN,
        ]);

        self::assertStringContainsString('inf = inf', $result);
        self::assertStringContainsString('neg_inf = -inf', $result);
        self::assertStringContainsString('nan = nan', $result);
    }

    #[Test]
    public function itEncodesNullAsEmptyString(): void
    {
        $result = $this->driver->encode(['key' => null]);

        self::assertStringContainsString('key = ""', $result);
    }

    #[Test]
    public function itEncodesEscapedStrings(): void
    {
        $result = $this->driver->encode([
            'quote' => 'said "hello"',
            'backslash' => 'a\\b',
            'tab' => "a\tb",
        ]);

        self::assertStringContainsString('quote = "said \\"hello\\""', $result);
        self::assertStringContainsString('backslash = "a\\\\b"', $result);
        self::assertStringContainsString('tab = "a\\tb"', $result);
    }

    #[Test]
    public function itEncodesQuotedKeys(): void
    {
        $result = $this->driver->encode([
            '127.0.0.1' => 'localhost',
            'spaced key' => 'value',
        ]);

        self::assertStringContainsString('"127.0.0.1" = "localhost"', $result);
        self::assertStringContainsString('"spaced key" = "value"', $result);
    }

    #[Test]
    public function itEncodesArrays(): void
    {
        $result = $this->driver->encode([
            'list' => [1, 2, 3],
            'empty_list' => [],
            'nested' => [['a', 'b'], ['c']],
        ]);

        self::assertStringContainsString('list = [1, 2, 3]', $result);
        self::assertStringContainsString('empty_list = []', $result);
    }

    #[Test]
    public function itEncodesInlineTables(): void
    {
        $result = $this->driver->encode([
            'point' => ['x' => 1, 'y' => 2],
            'config' => ['enabled' => true, 'name' => 'test'],
        ]);

        self::assertStringContainsString('point = { x = 1, y = 2 }', $result);
        self::assertStringContainsString('config = { enabled = true, name = "test" }', $result);
    }

    #[Test]
    public function itEncodesEmptyArrayAsEmptyString(): void
    {
        $result = $this->driver->encode([]);

        self::assertSame('', $result);
    }

    #[Test]
    public function itEncodesNestedTables(): void
    {
        $data = [
            'title' => 'Config',
            'server' => [
                'host' => 'localhost',
                'port' => 8080,
            ],
            'database' => [
                'name' => 'test',
                'credentials' => [
                    'user' => 'admin',
                    'pass' => 'secret',
                ],
            ],
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('title = "Config"', $result);
        self::assertStringContainsString('server = { host = "localhost", port = 8080 }', $result);
        self::assertStringContainsString('[database]', $result);
        self::assertStringContainsString('name = "test"', $result);
        self::assertStringContainsString('credentials = { user = "admin", pass = "secret" }', $result);
    }

    #[Test]
    public function itEncodesArrayOfTables(): void
    {
        $data = [
            'products' => [
                ['name' => 'Hammer', 'sku' => 738594937],
                ['name' => 'Nail', 'sku' => 284758393],
            ],
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('[[products]]', $result);
        self::assertStringContainsString('name = "Hammer"', $result);
        self::assertStringContainsString('sku = 738594937', $result);
        self::assertStringContainsString('name = "Nail"', $result);
    }

    #[Test]
    public function itEncodesDatetimeAsRfc3339(): void
    {
        $dt = new \DateTimeImmutable('2026-01-15T10:30:00+00:00');
        $result = $this->driver->encode(['date' => $dt]);

        self::assertStringContainsString('date = "2026-01-15T10:30:00+00:00"', $result);
    }

    // ─── Round-Trip ────────────────────────────────────────────────

    #[Test]
    public function itRoundTripsSimpleData(): void
    {
        $original = <<<'TOML'
str = "Hello"
int = 42
float = 3.14
bool = true
negative = -1
TOML;

        $data = $this->driver->decode($original);
        $encoded = $this->driver->encode($data);
        $reDecoded = $this->driver->decode($encoded);

        self::assertSame('Hello', $reDecoded['str']);
        self::assertSame(42, $reDecoded['int']);
        self::assertEquals(3.14, $reDecoded['float']);
        self::assertTrue($reDecoded['bool']);
        self::assertSame(-1, $reDecoded['negative']);
    }

    #[Test]
    public function itRoundTripsNestedTables(): void
    {
        $original = <<<'TOML'
title = "Config"

[server]
host = "localhost"
port = 8080

[server.admin]
user = "admin"
enabled = true
TOML;

        $data = $this->driver->decode($original);
        $encoded = $this->driver->encode($data);
        $reDecoded = $this->driver->decode($encoded);

        self::assertSame('Config', $reDecoded['title']);
        self::assertSame('localhost', $reDecoded['server']['host']);
        self::assertSame(8080, $reDecoded['server']['port']);
        self::assertSame('admin', $reDecoded['server']['admin']['user']);
        self::assertTrue($reDecoded['server']['admin']['enabled']);
    }

    #[Test]
    public function itRoundTripsArrayOfTables(): void
    {
        $original = <<<'TOML'
[[products]]
name = "Hammer"
sku = 738594937

[[products]]
name = "Nail"
sku = 284758393

[[products]]
name = "Bolt"
sku = 123456789
TOML;

        $data = $this->driver->decode($original);
        $encoded = $this->driver->encode($data);
        $reDecoded = $this->driver->decode($encoded);

        self::assertCount(3, $reDecoded['products']);
        self::assertSame('Hammer', $reDecoded['products'][0]['name']);
        self::assertSame('Nail', $reDecoded['products'][1]['name']);
        self::assertSame('Bolt', $reDecoded['products'][2]['name']);
    }

    #[Test]
    public function itRoundTripsWithInlineTables(): void
    {
        $original = <<<'TOML'
database = { host = "localhost", port = 5432, name = "testdb" }
enabled = true
TOML;

        $data = $this->driver->decode($original);
        $encoded = $this->driver->encode($data);
        $reDecoded = $this->driver->decode($encoded);

        self::assertSame('localhost', $reDecoded['database']['host']);
        self::assertSame(5432, $reDecoded['database']['port']);
        self::assertSame('testdb', $reDecoded['database']['name']);
        self::assertTrue($reDecoded['enabled']);
    }

    #[Test]
    public function itRoundTripsDottedKeys(): void
    {
        $original = <<<'TOML'
title = "Main Config"
server.host = "192.168.1.1"
server.port = 9000
TOML;

        $data = $this->driver->decode($original);
        $encoded = $this->driver->encode($data);
        $reDecoded = $this->driver->decode($encoded);

        self::assertSame('Main Config', $reDecoded['title']);
        self::assertSame('192.168.1.1', $reDecoded['server']['host']);
        self::assertSame(9000, $reDecoded['server']['port']);
    }

    #[Test]
    public function itRoundTripsIntegers(): void
    {
        $data = [
            'decimal' => 42,
            'negative' => -100,
            'zero' => 0,
            'large' => 2_147_483_647,
        ];

        $encoded = $this->driver->encode($data);
        $reDecoded = $this->driver->decode($encoded);

        self::assertSame(42, $reDecoded['decimal']);
        self::assertSame(-100, $reDecoded['negative']);
        self::assertSame(0, $reDecoded['zero']);
        self::assertSame(2_147_483_647, $reDecoded['large']);
    }

    // ─── Real-World Example ────────────────────────────────────────

    #[Test]
    public function itDecodesRealWorldConfig(): void
    {
        $toml = <<<'TOML'
# This is a TOML document

title = "Example Config"

[owner]
name = "Alice"
dob = 1979-05-27T07:32:00Z

[database]
server = "192.168.1.1"
ports = [8001, 8001, 8002]
connection_max = 5000
enabled = true

[servers.alpha]
ip = "10.0.0.1"
dc = "eqdc10"

[servers.beta]
ip = "10.0.0.2"
dc = "eqdc10"

[[clients]]
name = "client-a"

[[clients]]
name = "client-b"
TOML;

        $result = $this->driver->decode($toml);

        self::assertSame('Example Config', $result['title']);
        self::assertSame('Alice', $result['owner']['name']);
        self::assertInstanceOf(\DateTimeImmutable::class, $result['owner']['dob']);
        self::assertSame('192.168.1.1', $result['database']['server']);
        self::assertSame([8001, 8001, 8002], $result['database']['ports']);
        self::assertSame(5000, $result['database']['connection_max']);
        self::assertTrue($result['database']['enabled']);
        self::assertSame('10.0.0.1', $result['servers']['alpha']['ip']);
        self::assertSame('10.0.0.2', $result['servers']['beta']['ip']);
        self::assertCount(2, $result['clients']);
        self::assertSame('client-a', $result['clients'][0]['name']);
        self::assertSame('client-b', $result['clients'][1]['name']);
    }

    #[Test]
    public function itDetectsDecodeKeyCollisionWithTable(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('Cannot define table');

        $this->driver->decode("foo = 1\n[foo.bar]\nkey = 2");
    }

    #[Test]
    public function itDetectsDecodeKeyCollisionWithArrayTable(): void
    {
        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('Cannot define array of tables');

        $this->driver->decode("foo = 1\n[[foo.bar]]\nkey = 2");
    }

    // (indent parameter reserved for future use)
}
