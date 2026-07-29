<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Tests\Driver;

use Monkeyslegion\PolySyntax\Driver\YamlDriver;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class YamlDriverTest extends TestCase
{
    private YamlDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new YamlDriver();
    }

    #[Test]
    public function itReportsYamlSyntax(): void
    {
        self::assertSame(Syntax::YAML, $this->driver->supportedSyntax());
    }

    // ─── Decode ────────────────────────────────────────────────────

    #[Test]
    public function itDecodesSimpleKeyValuePairs(): void
    {
        $yaml = <<<'YAML'
str: Hello
int: 42
float: 3.14
bool: true
neg: -1
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame('Hello', $result['str']);
        self::assertSame(42, $result['int']);
        self::assertSame(3.14, $result['float']);
        self::assertTrue($result['bool']);
        self::assertSame(-1, $result['neg']);
    }

    #[Test]
    public function itDecodesBooleanAlternatives(): void
    {
        $yaml = <<<'YAML'
yes_val: yes
no_val: no
on_val: on
off_val: off
YAML;

        $result = $this->driver->decode($yaml);

        self::assertTrue($result['yes_val']);
        self::assertFalse($result['no_val']);
        self::assertTrue($result['on_val']);
        self::assertFalse($result['off_val']);
    }

    #[Test]
    public function itDecodesNullValues(): void
    {
        $yaml = <<<'YAML'
null_val: null
tilde_val: ~
empty_val:
YAML;

        $result = $this->driver->decode($yaml);

        self::assertNull($result['null_val']);
        self::assertNull($result['tilde_val']);
        self::assertSame([], $result['empty_val']); // empty yields empty array
    }

    #[Test]
    public function itDecodesEmptyInputAsEmptyArray(): void
    {
        self::assertSame([], $this->driver->decode(''));
        self::assertSame([], $this->driver->decode("   \n  \n  "));
    }

    #[Test]
    public function itDecodesQuotedStrings(): void
    {
        $yaml = <<<'YAML'
double: "hello \"world\""
single: 'it''s a test'
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame('hello "world"', $result['double']);
        self::assertSame("it's a test", $result['single']);
    }

    #[Test]
    public function itDecodesNestedMappings(): void
    {
        $yaml = <<<'YAML'
server:
  host: localhost
  port: 8080
database:
  name: testdb
  user: admin
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame('localhost', $result['server']['host']);
        self::assertSame(8080, $result['server']['port']);
        self::assertSame('testdb', $result['database']['name']);
        self::assertSame('admin', $result['database']['user']);
    }

    #[Test]
    public function itDecodesSequences(): void
    {
        $yaml = <<<'YAML'
items:
  - apple
  - banana
  - cherry
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame(['apple', 'banana', 'cherry'], $result['items']);
    }

    #[Test]
    public function itDecodesSequenceOfMappings(): void
    {
        $yaml = <<<'YAML'
employees:
  - name: Alice
    role: Developer
  - name: Bob
    role: Designer
YAML;

        $result = $this->driver->decode($yaml);

        self::assertCount(2, $result['employees']);
        self::assertSame('Alice', $result['employees'][0]['name']);
        self::assertSame('Developer', $result['employees'][0]['role']);
        self::assertSame('Bob', $result['employees'][1]['name']);
        self::assertSame('Designer', $result['employees'][1]['role']);
    }

    #[Test]
    public function itDecodesNestedSequenceInMapping(): void
    {
        $yaml = <<<'YAML'
config:
  server: localhost
  ports:
    - 8080
    - 9090
  enabled: true
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame('localhost', $result['config']['server']);
        self::assertSame([8080, 9090], $result['config']['ports']);
        self::assertTrue($result['config']['enabled']);
    }

    #[Test]
    public function itDecodesInlineMappings(): void
    {
        $yaml = <<<'YAML'
point: { x: 1, y: 2 }
config: { enabled: true, name: test }
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame(1, $result['point']['x']);
        self::assertSame(2, $result['point']['y']);
        self::assertTrue($result['config']['enabled']);
        self::assertSame('test', $result['config']['name']);
    }

    #[Test]
    public function itDecodesInlineSequences(): void
    {
        $yaml = <<<'YAML'
list: [1, 2, 3]
colors: [red, green, blue]
mixed: [1, two, 3.0]
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame([1, 2, 3], $result['list']);
        self::assertSame(['red', 'green', 'blue'], $result['colors']);
        self::assertSame([1, 'two', 3.0], $result['mixed']);
    }

    #[Test]
    public function itDecodesComments(): void
    {
        $yaml = <<<'YAML'
# Full line comment
key: value # inline comment
# Another comment
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame(['key' => 'value'], $result);
    }

    #[Test]
    public function itDecodesMixedIndentation(): void
    {
        $yaml = <<<'YAML'
deep:
  level1:
    level2:
      value: found
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame('found', $result['deep']['level1']['level2']['value']);
    }

    #[Test]
    public function itDecodesSequenceOfInlineMappings(): void
    {
        $yaml = <<<'YAML'
items:
  - { name: Alice, age: 30 }
  - { name: Bob, age: 25 }
YAML;

        $result = $this->driver->decode($yaml);

        self::assertCount(2, $result['items']);
        self::assertSame('Alice', $result['items'][0]['name']);
        self::assertSame(25, $result['items'][1]['age']);
    }

    #[Test]
    public function itDecodesBlockScalarLiteral(): void
    {
        $yaml = <<<'YAML'
text: |
  line one
  line two
  line three
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame("line one\nline two\nline three", $result['text']);
    }

    #[Test]
    public function itDecodesBlockScalarFolded(): void
    {
        $yaml = <<<'YAML'
text: >
  This is a
  folded block
  scalar
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame('This is a folded block scalar', $result['text']);
    }

    #[Test]
    public function itDecodesSequenceStartingWithInlineMappingKeyValue(): void
    {
        $yaml = <<<'YAML'
- name: Alice
  age: 30
- name: Bob
  age: 25
YAML;

        $result = $this->driver->decode($yaml);

        self::assertCount(2, $result);
        self::assertSame('Alice', $result[0]['name']);
        self::assertSame(30, $result[0]['age']);
        self::assertSame('Bob', $result[1]['name']);
        self::assertSame(25, $result[1]['age']);
    }

    #[Test]
    public function itDecodesEmptyMapping(): void
    {
        $yaml = <<<'YAML'
empty: {}
not_empty:
  key: value
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame([], $result['empty']);
        self::assertSame('value', $result['not_empty']['key']);
    }

    #[Test]
    public function itDecodesSpecialFloats(): void
    {
        $yaml = <<<'YAML'
inf: .inf
neg_inf: -.inf
nan: .nan
YAML;

        $result = $this->driver->decode($yaml);

        self::assertTrue(\is_infinite($result['inf']) && $result['inf'] > 0);
        self::assertTrue(\is_infinite($result['neg_inf']) && $result['neg_inf'] < 0);
        self::assertTrue(\is_nan($result['nan']));
    }

    #[Test]
    public function itDecodesNumericBoolsAndNullStrings(): void
    {
        $yaml = <<<'YAML'
str_true: 'true'
str_false: 'false'
str_null: 'null'
str_yes: 'yes'
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame('true', $result['str_true']);
        self::assertSame('false', $result['str_false']);
        self::assertSame('null', $result['str_null']);
        self::assertSame('yes', $result['str_yes']);
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
            'null_val' => null,
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('string: hello', $result);
        self::assertStringContainsString('int: 42', $result);
        self::assertStringContainsString('float: 3.14', $result);
        self::assertStringContainsString('bool_true: true', $result);
        self::assertStringContainsString('bool_false: false', $result);
        self::assertStringContainsString('null_val: ~', $result);
    }

    #[Test]
    public function itEncodesNestedMappings(): void
    {
        $data = [
            'server' => [
                'host' => 'localhost',
                'port' => 8080,
            ],
            'database' => [
                'name' => 'testdb',
            ],
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('server:', $result);
        self::assertStringContainsString('  host: localhost', $result);
        self::assertStringContainsString('  port: 8080', $result);
        self::assertStringContainsString('database:', $result);
        self::assertStringContainsString('  name: testdb', $result);
    }

    #[Test]
    public function itEncodesSequences(): void
    {
        $data = [
            'items' => ['apple', 'banana', 'cherry'],
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('items: [apple, banana, cherry]', $result);
    }

    #[Test]
    public function itEncodesSequenceOfMappings(): void
    {
        $data = [
            'employees' => [
                ['name' => 'Alice', 'role' => 'Developer'],
                ['name' => 'Bob', 'role' => 'Designer'],
            ],
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('employees:', $result);
        self::assertStringContainsString('[{ name: Alice, role: Developer }', $result);
        self::assertStringContainsString('{ name: Bob, role: Designer }', $result);
    }

    #[Test]
    public function itEncodesStringsWithSpecialCharacters(): void
    {
        $data = [
            'quote' => 'said "hello"',
            'colon' => 'key: value',
            'hash' => 'path#1',
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('quote: said "hello"', $result);
        self::assertStringContainsString("colon: 'key: value'", $result);
        self::assertStringContainsString("hash: 'path#1'", $result);
    }

    #[Test]
    public function itEncodesQuotedKeys(): void
    {
        $data = [
            '127.0.0.1' => 'localhost',
            'spaced key' => 'value',
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString("'127.0.0.1': localhost", $result);
        self::assertStringContainsString("'spaced key': value", $result);
    }

    #[Test]
    public function itEncodesEmptyArrayAsCurlyBraces(): void
    {
        $result = $this->driver->encode([]);

        self::assertSame("{}\n", $result);
    }

    #[Test]
    public function itEncodesEmptyNestedArrays(): void
    {
        $data = ['empty' => []];
        $result = $this->driver->encode($data);

        self::assertStringContainsString('empty: []', $result);
    }

    #[Test]
    public function itEncodesInlineSequencesAndMappings(): void
    {
        $data = [
            'list' => [1, 2, 3],
            'config' => ['enabled' => true, 'name' => 'test'],
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('list: [1, 2, 3]', $result);
        self::assertStringContainsString('config:', $result);
        self::assertStringContainsString('  enabled: true', $result);
        self::assertStringContainsString('  name: test', $result);
    }

    #[Test]
    public function itEncodesSpecialFloats(): void
    {
        $result = $this->driver->encode([
            'inf' => \INF,
            'neg_inf' => -\INF,
            'nan' => \NAN,
        ]);

        self::assertStringContainsString('inf: .inf', $result);
        self::assertStringContainsString('neg_inf: -.inf', $result);
        self::assertStringContainsString('nan: .nan', $result);
    }

    #[Test]
    public function itEncodesBoolLikeStringsAsQuoted(): void
    {
        $data = [
            'str_true' => 'true',
            'str_false' => 'false',
            'str_null' => 'null',
            'str_yes' => 'yes',
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString("str_true: 'true'", $result);
        self::assertStringContainsString("str_false: 'false'", $result);
        self::assertStringContainsString("str_null: 'null'", $result);
        self::assertStringContainsString("str_yes: 'yes'", $result);
    }

    #[Test]
    public function itEncodesNumericStringsAsQuoted(): void
    {
        $data = [
            'zip' => '90210',
            'version' => '12.5',
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString("zip: '90210'", $result);
        self::assertStringContainsString("version: '12.5'", $result);
    }

    #[Test]
    public function itEncodesDateTimeAsRfc3339(): void
    {
        $dt = new \DateTimeImmutable('2026-01-15T10:30:00+00:00');
        $result = $this->driver->encode(['date' => $dt]);

        self::assertStringContainsString('date: 2026-01-15T10:30:00+00:00', $result);
    }

    // ─── Round-Trip ────────────────────────────────────────────────

    #[Test]
    public function itRoundTripsSimpleData(): void
    {
        $original = <<<'YAML'
str: Hello
int: 42
float: 3.14
bool: true
negative: -1
YAML;

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
    public function itRoundTripsNestedStructures(): void
    {
        $original = <<<'YAML'
title: Config

server:
  host: localhost
  port: 8080

database:
  name: test
  credentials:
    user: admin
    pass: secret
YAML;

        $data = $this->driver->decode($original);
        $encoded = $this->driver->encode($data);
        $reDecoded = $this->driver->decode($encoded);

        self::assertSame('Config', $reDecoded['title']);
        self::assertSame('localhost', $reDecoded['server']['host']);
        self::assertSame(8080, $reDecoded['server']['port']);
        self::assertSame('test', $reDecoded['database']['name']);
        self::assertSame('admin', $reDecoded['database']['credentials']['user']);
        self::assertSame('secret', $reDecoded['database']['credentials']['pass']);
    }

    #[Test]
    public function itRoundTripsSequenceOfMappings(): void
    {
        $original = <<<'YAML'
products:
  - name: Hammer
    sku: 738594937
  - name: Nail
    sku: 284758393
  - name: Bolt
    sku: 123456789
YAML;

        $data = $this->driver->decode($original);
        $encoded = $this->driver->encode($data);
        $reDecoded = $this->driver->decode($encoded);

        self::assertCount(3, $reDecoded['products']);
        self::assertSame('Hammer', $reDecoded['products'][0]['name']);
        self::assertSame('Nail', $reDecoded['products'][1]['name']);
        self::assertSame('Bolt', $reDecoded['products'][2]['name']);
        self::assertSame(738594937, $reDecoded['products'][0]['sku']);
    }

    #[Test]
    public function itRoundTripsInlineStructures(): void
    {
        $original = <<<'YAML'
database: { host: localhost, port: 5432, name: testdb }
enabled: true
ports: [8080, 9090]
YAML;

        $data = $this->driver->decode($original);
        $encoded = $this->driver->encode($data);
        $reDecoded = $this->driver->decode($encoded);

        self::assertSame('localhost', $reDecoded['database']['host']);
        self::assertSame(5432, $reDecoded['database']['port']);
        self::assertSame('testdb', $reDecoded['database']['name']);
        self::assertTrue($reDecoded['enabled']);
        self::assertSame([8080, 9090], $reDecoded['ports']);
    }

    #[Test]
    public function itRoundTripsEmptyAndNullValues(): void
    {
        $data = [
            'empty_map' => [],
            'null_val' => null,
            'present' => 'hello',
        ];

        $encoded = $this->driver->encode($data);
        $reDecoded = $this->driver->decode($encoded);

        self::assertSame([], $reDecoded['empty_map']);
        self::assertNull($reDecoded['null_val']);
        self::assertSame('hello', $reDecoded['present']);
    }

    #[Test]
    public function itRoundTripsMixedSequence(): void
    {
        $data = [
            'items' => [1, 'two', 3.0, true, null],
        ];

        $encoded = $this->driver->encode($data);
        $reDecoded = $this->driver->decode($encoded);

        self::assertSame(1, $reDecoded['items'][0]);
        self::assertSame('two', $reDecoded['items'][1]);
        self::assertSame(3.0, $reDecoded['items'][2]);
        self::assertTrue($reDecoded['items'][3]);
        self::assertNull($reDecoded['items'][4]);
    }

    // ─── Real-World Example ────────────────────────────────────────

    #[Test]
    public function itDecodesRealWorldConfig(): void
    {
        $yaml = <<<'YAML'
# Application configuration
app:
  name: MyApp
  version: 2.0
  debug: true

server:
  host: 0.0.0.0
  port: 3000
  cors:
    enabled: true
    origins:
      - http://localhost:8080
      - https://example.com

database:
  driver: postgres
  host: localhost
  port: 5432
  credentials:
    user: admin
    password: secret
  pool:
    min: 2
    max: 10

features:
  - name: auth
    enabled: true
  - name: logging
    enabled: false
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame('MyApp', $result['app']['name']);
        self::assertSame(2.0, $result['app']['version']);
        self::assertTrue($result['app']['debug']);
        self::assertSame('0.0.0.0', $result['server']['host']);
        self::assertSame(3000, $result['server']['port']);
        self::assertTrue($result['server']['cors']['enabled']);
        self::assertSame('http://localhost:8080', $result['server']['cors']['origins'][0]);
        self::assertSame('https://example.com', $result['server']['cors']['origins'][1]);
        self::assertSame('postgres', $result['database']['driver']);
        self::assertSame('admin', $result['database']['credentials']['user']);
        self::assertSame(10, $result['database']['pool']['max']);
        self::assertCount(2, $result['features']);
        self::assertSame('auth', $result['features'][0]['name']);
        self::assertTrue($result['features'][0]['enabled']);
        self::assertFalse($result['features'][1]['enabled']);
    }

    #[Test]
    public function itDecodesMultiDocumentYaml(): void
    {
        $yaml = <<<'YAML'
key: first
---
key: second
YAML;

        $result = $this->driver->decode($yaml);

        // Multi-document: parser should handle --- separators
        self::assertSame('second', $result['key']);
    }
}
