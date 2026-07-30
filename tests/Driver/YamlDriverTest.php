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
    public function itDecodesHexAndOctalIntegers(): void
    {
        $yaml = <<<'YAML'
hex: 0xFF
octal: 0o77
neg_hex: -0x1A
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame(255, $result['hex']);
        self::assertSame(63, $result['octal']);
        self::assertSame(-26, $result['neg_hex']);
    }

    #[Test]
    public function itDecodesScientificNotation(): void
    {
        $yaml = <<<'YAML'
sci: 1.5e3
small: 1e-3
neg: -2.5E2
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame(1500.0, $result['sci']);
        self::assertSame(0.001, $result['small']);
        self::assertSame(-250.0, $result['neg']);
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
    public function itDecodesBareTopLevelSequence(): void
    {
        $yaml = <<<'YAML'
- apple
- banana
- cherry
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame(['apple', 'banana', 'cherry'], $result);
    }

    #[Test]
    public function itDecodesNullInSequence(): void
    {
        $yaml = <<<'YAML'
items:
  - a
  - ~
  - b
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame('a', $result['items'][0]);
        self::assertNull($result['items'][1]);
        self::assertSame('b', $result['items'][2]);
    }

    #[Test]
    public function itDecodesInlineSequenceInsideInlineMapping(): void
    {
        $yaml = 'config: { list: [1, 2, 3], enabled: true }';

        $result = $this->driver->decode($yaml);

        self::assertSame([1, 2, 3], $result['config']['list']);
        self::assertTrue($result['config']['enabled']);
    }

    #[Test]
    public function itDecodesInlineMappingInsideInlineSequence(): void
    {
        $yaml = 'items: [{ a: 1, b: 2 }, { c: 3 }]';

        $result = $this->driver->decode($yaml);

        self::assertCount(2, $result['items']);
        self::assertSame(1, $result['items'][0]['a']);
        self::assertSame(2, $result['items'][0]['b']);
        self::assertSame(3, $result['items'][1]['c']);
    }

    #[Test]
    public function itDecodesOnlyCommentsAsEmpty(): void
    {
        $yaml = <<<'YAML'
# just a comment
# another one
YAML;

        self::assertSame([], $this->driver->decode($yaml));
    }

    #[Test]
    public function itDecodesKeysWithDots(): void
    {
        $yaml = <<<'YAML'
site.google.com: search
v1.2.3: version
127.0.0.1: localhost
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame('search', $result['site.google.com']);
        self::assertSame('version', $result['v1.2.3']);
        self::assertSame('localhost', $result['127.0.0.1']);
    }

    #[Test]
    public function itDecodesValueStartingWithColon(): void
    {
        $yaml = 'key: :value';

        $result = $this->driver->decode($yaml);

        // The value is just a string starting with colon
        self::assertSame(':value', $result['key']);
    }

    #[Test]
    public function itDecodesNestedSequenceOfMappingsDeep(): void
    {
        $yaml = <<<'YAML'
items:
  - database:
      name: test
      port: 5432
  - database:
      name: prod
      port: 3306
YAML;

        $result = $this->driver->decode($yaml);

        self::assertCount(2, $result['items']);
        self::assertSame('test', $result['items'][0]['database']['name']);
        self::assertSame(5432, $result['items'][0]['database']['port']);
        self::assertSame('prod', $result['items'][1]['database']['name']);
        self::assertSame(3306, $result['items'][1]['database']['port']);
    }

    #[Test]
    public function itDecodesEmptyInlineMapping(): void
    {
        $yaml = 'data: {}';

        $result = $this->driver->decode($yaml);

        self::assertSame([], $result['data']);
    }

    #[Test]
    public function itDecodesEmptyInlineSequence(): void
    {
        $yaml = 'data: []';

        $result = $this->driver->decode($yaml);

        self::assertSame([], $result['data']);
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

    #[Test]
    public function itDecodesAllDoubleQuoteEscapes(): void
    {
        $yaml = <<<'YAML'
null_char: "\0"
alert: "\a"
backspace: "\b"
tab: "\t"
newline: "\n"
vtab: "\v"
formfeed: "\f"
carriage: "\r"
escape: "\e"
space: "\ "
dblquote: "\""
fslash: "\/"
backslash: "\\\\"
nextline: "\N"
nbsp: "\_"
linesep: "\L"
paragraph: "\P"
hex: "\x41"
unicode: "\u00E9"
unicode4: "\u2603"
long: "\U0001F600"
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame("\x00", $result['null_char']);
        self::assertSame("\x07", $result['alert']);
        self::assertSame("\x08", $result['backspace']);
        self::assertSame("\t", $result['tab']);
        self::assertSame("\n", $result['newline']);
        self::assertSame("\x0B", $result['vtab']);
        self::assertSame("\x0C", $result['formfeed']);
        self::assertSame("\r", $result['carriage']);
        self::assertSame("\x1B", $result['escape']);
        self::assertSame(' ', $result['space']);
        self::assertSame('"', $result['dblquote']);
        self::assertSame('/', $result['fslash']);
        // 4 backslashes in YAML double-quotes = 2 literal backslashes
        self::assertIsString($result['backslash']);
        self::assertSame(2, \strlen($result['backslash']));
        self::assertSame("\u{0085}", $result['nextline']);
        self::assertSame("\u{00A0}", $result['nbsp']);
        self::assertSame("\u{2028}", $result['linesep']);
        self::assertSame("\u{2029}", $result['paragraph']);
        self::assertSame('A', $result['hex']);
        self::assertSame("\u{00E9}", $result['unicode']); // é = U+00E9

        // Snowman (\u2603) and emoji (\U0001F600) — check they decode to multi-byte strings
        self::assertIsString($result['unicode4']);
        self::assertSame(3, \strlen($result['unicode4'])); // U+2603 is 3 bytes UTF-8
        self::assertSame("\u{2603}", $result['unicode4']); // actual byte value
        self::assertIsString($result['long']);
        self::assertSame(4, \strlen($result['long']));     // U+1F600 is 4 bytes UTF-8
        self::assertSame("\u{1F600}", $result['long']);   // actual byte value
    }

    #[Test]
    public function itDecodesSingleQuotesContainingDoubleQuotes(): void
    {
        $yaml = "key: 'he said \"hello\"'";

        $result = $this->driver->decode($yaml);

        self::assertSame('he said "hello"', $result['key']);
    }

    #[Test]
    public function itDecodesDoubleQuotesContainingSingleQuotes(): void
    {
        $yaml = 'key: "it\'s nice"';

        $result = $this->driver->decode($yaml);

        self::assertSame("it's nice", $result['key']);
    }

    #[Test]
    public function itDecodesMixedSequenceTypes(): void
    {
        $yaml = <<<'YAML'
mixed:
  - 42
  - hello
  - true
  - 3.14
  - null
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame(42, $result['mixed'][0]);
        self::assertSame('hello', $result['mixed'][1]);
        self::assertTrue($result['mixed'][2]);
        self::assertSame(3.14, $result['mixed'][3]);
        self::assertNull($result['mixed'][4]);
    }

    #[Test]
    public function itDecodesBareDashAtEndOfLine(): void
    {
        $yaml = <<<'YAML'
list:
  -
    key: value
  -
    other: data
YAML;

        $result = $this->driver->decode($yaml);

        self::assertCount(2, $result['list']);
        self::assertSame('value', $result['list'][0]['key']);
        self::assertSame('data', $result['list'][1]['other']);
    }

    #[Test]
    public function itDecodesCommentInsideDoubleQuotedString(): void
    {
        $result = $this->driver->decode('key: "text # not comment"');

        self::assertSame('text # not comment', $result['key']);
    }

    #[Test]
    public function itDecodesCommentInsideSingleQuotedString(): void
    {
        $result = $this->driver->decode("key: 'text # not comment'");

        self::assertSame('text # not comment', $result['key']);
    }

    #[Test]
    public function itDecodesUrlLikeValue(): void
    {
        $yaml = <<<'YAML'
url: http://example.com
secure: https://api.test.com/path?q=1
ftp: ftp://files.example.org
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame('http://example.com', $result['url']);
        self::assertSame('https://api.test.com/path?q=1', $result['secure']);
        self::assertSame('ftp://files.example.org', $result['ftp']);
    }

    #[Test]
    public function itDecodesPositiveHexInteger(): void
    {
        $yaml = <<<'YAML'
hex: +0xFF
neg: -0x1A
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame(255, $result['hex']);
        self::assertSame(-26, $result['neg']);
    }

    #[Test]
    public function itDecodesHexWithUnderscores(): void
    {
        $result = $this->driver->decode('hex: 0xDE_AD_BE_EF');

        self::assertSame(0xDEADBEEF, $result['hex']);
    }

    #[Test]
    public function itDecodesFoldedBlockWithBlankLines(): void
    {
        $yaml = <<<'YAML'
text: >
  paragraph one

  paragraph two
YAML;

        $result = $this->driver->decode($yaml);

        // Folded block converts all newlines to spaces
        // (blank lines are stripped by normalisation before reaching block scalar parser)
        self::assertIsString($result['text']);
        self::assertStringContainsString("paragraph one paragraph two", $result['text']);
    }

    #[Test]
    public function itDecodesEscapedQuoteBeforeComment(): void
    {
        $result = $this->driver->decode('key: "value \"# not comment"');

        // The escaped quote does NOT end the string, so # is part of the value
        self::assertSame('value "# not comment', $result['key']);
    }

    #[Test]
    public function itDecodesInfinityVariants(): void
    {
        $yaml = <<<'YAML'
dot_inf: .inf
plus_inf: +.inf
dot_infinity: .infinity
plus_infinity: +.infinity
neg_dot_inf: -.inf
neg_infinity: -.infinity
nan: .nan
YAML;

        $result = $this->driver->decode($yaml);

        self::assertTrue(\is_infinite($result['dot_inf']) && $result['dot_inf'] > 0);
        self::assertTrue(\is_infinite($result['plus_inf']) && $result['plus_inf'] > 0);
        self::assertTrue(\is_infinite($result['dot_infinity']) && $result['dot_infinity'] > 0);
        self::assertTrue(\is_infinite($result['plus_infinity']) && $result['plus_infinity'] > 0);
        self::assertTrue(\is_infinite($result['neg_dot_inf']) && $result['neg_dot_inf'] < 0);
        self::assertTrue(\is_infinite($result['neg_infinity']) && $result['neg_infinity'] < 0);
        self::assertTrue(\is_nan($result['nan']));
    }

    #[Test]
    public function itDecodesCodepointAboveMaxReturnEmptyString(): void
    {
        // \U00110000 is above 0x10FFFF — decodeHexEscape returns ''
        $yaml = 'key: "\\U00110000"';
        $result = $this->driver->decode($yaml);

        self::assertSame('', $result['key']);
    }

    #[Test]
    public function itDecodesInvalidHexEscapeAsEmptyString(): void
    {
        // \xZZ is not valid hex — decodeHexEscape returns ''
        $yaml = 'key: "\\xZZ"';
        $result = $this->driver->decode($yaml);

        self::assertSame('', $result['key']);
    }

    #[Test]
    public function itDecodesNonStandardEscapeAsLiteralChar(): void
    {
        // \c is not a defined escape — default arm in match returns $next
        $yaml = 'key: "hello\\cworld"';
        $result = $this->driver->decode($yaml);

        self::assertSame('hellocworld', $result['key']);
    }

    #[Test]
    public function itDecodesStripCommentWithSingleQuoteAfterColon(): void
    {
        // Tests the $inSingle toggle in stripComment
        $yaml = "key: 'value with # hash' # comment";
        $result = $this->driver->decode($yaml);

        self::assertSame('value with # hash', $result['key']);
    }

    #[Test]
    public function itDecodesOctalsWithUnderscores(): void
    {
        $result = $this->driver->decode('octal: 0o77_77');

        self::assertSame(0o7777, $result['octal']);
    }

    #[Test]
    public function itDecodesOctalWithSign(): void
    {
        $yaml = <<<'YAML'
pos: +0o77
neg: -0o77
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame(63, $result['pos']);
        self::assertSame(-63, $result['neg']);
    }

    #[Test]
    public function itDecodesNullInSequenceItems(): void
    {
        $yaml = <<<'YAML'
nested:
  - null
  - ~
YAML;

        $result = $this->driver->decode($yaml);

        self::assertNull($result['nested'][0]);
        self::assertNull($result['nested'][1]);
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
    public function itEncodesDoubleQuotedWithApostrophe(): void
    {
        $data = [
            "it's" => 'works',
        ];

        $result = $this->driver->encode($data);

        // Key contains ' so it should use double quotes
        self::assertStringContainsString('"it\'s":', $result);
        self::assertStringContainsString('works', $result);
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
    public function itEncodesNewlinesInStrings(): void
    {
        $data = [
            'message' => "line one\nline two",
        ];

        $result = $this->driver->encode($data);

        // Single-quote with literal newline (no escape processing in single quotes)
        self::assertStringContainsString('message:', $result);
        self::assertStringContainsString("'line one\nline two'", $result);
    }

    #[Test]
    public function itEncodesListArrayAsSequence(): void
    {
        $data = ['zero', 'one'];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('- zero', $result);
        self::assertStringContainsString('- one', $result);
    }

    #[Test]
    public function itEncodesInlineMappingInSequence(): void
    {
        $data = [
            ['name' => 'Alice', 'score' => 95],
            ['name' => 'Bob', 'score' => 87],
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('name: Alice', $result);
        self::assertStringContainsString('score: 95', $result);
        self::assertStringContainsString('name: Bob', $result);
    }

    #[Test]
    public function itEncodesBoolLikeKeysAsBare(): void
    {
        $data = [
            'true' => 'yes-value',
            'false' => 'no-value',
        ];

        $result = $this->driver->encode($data);

        // 'true' and 'false' are valid bare YAML keys, so they're not quoted
        self::assertStringContainsString('true: yes-value', $result);
        self::assertStringContainsString('false: no-value', $result);
    }

    #[Test]
    public function itEncodesNestedEmptyArraysAsBraces(): void
    {
        $data = [
            'config' => ['nested' => []],
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('nested: []', $result);
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

    #[Test]
    public function itEncodesEmptyStringAsQuoted(): void
    {
        $result = $this->driver->encode(['empty' => '']);

        self::assertStringContainsString("empty: ''", $result);
    }

    #[Test]
    public function itEncodesStringWithCommaSpace(): void
    {
        $result = $this->driver->encode(['list' => 'a, b, c']);

        self::assertStringContainsString("list: 'a, b, c'", $result);
    }

    #[Test]
    public function itEncodesStringWithBrackets(): void
    {
        $data = [
            'has_bracket' => 'value [ref]',
            'has_brace' => 'data {key}',
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString("has_bracket: 'value [ref]'", $result);
        self::assertStringContainsString("has_brace: 'data {key}'", $result);
    }

    #[Test]
    public function itEncodesStringWithLeadingWhitespace(): void
    {
        $result = $this->driver->encode(['padded' => '  indented']);

        self::assertStringContainsString("padded: '  indented'", $result);
    }

    #[Test]
    public function itEncodesIntegerZero(): void
    {
        $result = $this->driver->encode(['count' => 0]);

        self::assertStringContainsString('count: 0', $result);
    }

    #[Test]
    public function itEncodesFloatWithoutDecimalPart(): void
    {
        $result = $this->driver->encode(['pi' => 3.0]);

        self::assertStringContainsString('pi: 3.0', $result);
    }

    #[Test]
    public function itEncodesKeyWithSpecialCharacters(): void
    {
        $data = [
            'key@host' => 'value1',
            'data!point' => 'value2',
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString("'key@host': value1", $result);
        self::assertStringContainsString("'data!point': value2", $result);
    }

    #[Test]
    public function itEncodesListOfLists(): void
    {
        $data = [
            'matrix' => [[1, 2], [3, 4]],
        ];

        $result = $this->driver->encode($data);

        // List of lists encoded as inline sequences
        self::assertStringContainsString('matrix: [[1, 2], [3, 4]]', $result);
    }

    #[Test]
    public function itEncodesSequenceMappingWithThreeItems(): void
    {
        $data = [
            'items' => [
                ['x' => 1, 'y' => 2],
                ['x' => 3, 'y' => 4],
                ['x' => 5, 'y' => 6],
            ],
        ];

        $result = $this->driver->encode($data);

        // Sequence of mappings uses inline format
        self::assertStringContainsString('items: [{ x: 1, y: 2 }, { x: 3, y: 4 }, { x: 5, y: 6 }]', $result);
    }

    #[Test]
    public function itEncodesSequenceMappingWithNestedSubArrays(): void
    {
        $data = [
            'items' => [
                ['x' => 1, 'nested' => ['a' => 'b']],
                ['x' => 2, 'nested' => ['c' => 'd']],
            ],
        ];

        $result = $this->driver->encode($data);

        // Non-first items in sequence mapping with nested sub-arrays
        self::assertStringContainsString('items:', $result);
        self::assertStringContainsString('{ x: 1, nested: { a: b } }', $result);
        self::assertStringContainsString('{ x: 2, nested: { c: d } }', $result);
    }

    #[Test]
    public function itEncodesControlCharactersViaDoubleQuote(): void
    {
        $data = [
            'name' => "it's, a\x01test\x02end",
        ];

        $result = $this->driver->encode($data);

        // ', ' triggers $needsQuoting, ' forces double-quote mode
        // Control chars become \xNN escapes (single-digit: \x1 not \x01)
        self::assertStringContainsString('name: "it\'s, a\\x1test\\x2end"', $result);
    }

    #[Test]
    public function itEncodesDelCharacterAsHex(): void
    {
        // Use a value with both a single quote and ', ' to force double-quote mode,
        // then DEL (0x7F) gets encoded as \x7F by escapeDoubleQuoted
        $data = [
            'name' => "test\x7Fend's, more",
        ];

        $result = $this->driver->encode($data);

        // ', ' triggers $needsQuoting, ' forces double-quote mode,
        // DEL (0x7F) encoded as \x7F via escapeDoubleQuoted
        self::assertStringContainsString('\\x7F', $result);
    }

    #[Test]
    public function itEncodesNullValueAsTilde(): void
    {
        $result = $this->driver->encode(['key' => null]);

        self::assertStringContainsString('key: ~', $result);
    }

    #[Test]
    public function itEncodesNonArrayData(): void
    {
        // encodeNode with non-array — only first element used
        $data = [
            'scalar' => 'just a string',
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('scalar: just a string', $result);
    }

    #[Test]
    public function itEncodesMappingValueWithInlineList(): void
    {
        // encodeMappingValue with sub-value that is a list (array_is_list)
        $data = [
            'config' => [
                'modes' => [1, 2, 3],
            ],
        ];

        $result = $this->driver->encode($data);

        self::assertStringContainsString('config:', $result);
        self::assertStringContainsString('  modes: [1, 2, 3]', $result);
    }

    #[Test]
    public function itEncodesDeeplyNestedMappings(): void
    {
        $data = [
            'a' => [
                'b' => [
                    'c' => [
                        'value' => 'deep',
                    ],
                ],
            ],
        ];

        $result = $this->driver->encode($data);

        // Verify deeply nested structure
        $lines = \explode("\n", \trim($result));
        self::assertStringContainsString('a:', $result);
        self::assertStringContainsString('    value: deep', $result);
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

    // ─── Branch Coverage ───────────────────────────────────────────

    #[Test]
    public function itDecodesValuesWithSlashesAndAtSigns(): void
    {
        // Tests $next === '/' and $next === '@' in findColonOutsideQuotes
        // URL with :// to exercise '/' branch, email with @ to exercise '@' branch
        $yaml = <<<'YAML'
url: http://example.com
proxy: socks5://proxy.local
email: user@example.com
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame('http://example.com', $result['url']);
        self::assertSame('socks5://proxy.local', $result['proxy']);
        self::assertSame('user@example.com', $result['email']);
    }

    #[Test]
    public function itDecodesBlockScalarWithOnlyBlanks(): void
    {
        // Tests $last >= 0 vs $last > 0 at line ~538
        // Block scalar with all blank lines → $last starts at 0
        $yaml = <<<'YAML'
text: |


YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame('', $result['text']);
    }

    #[Test]
    public function itDecodesKeyWithColonAfterInlineStuff(): void
    {
        // Tests colon at position 0: $i === 0 branch at line ~903
        $yaml = <<<'YAML'
:starting-with-colon: preserved
YAML;

        $result = $this->driver->decode($yaml);

        self::assertSame('preserved', $result[':starting-with-colon']);
    }

    #[Test]
    public function itDecodesDoubleQuotedWithEscapedBackslash(): void
    {
        // Tests $escape toggle in findColonOutsideQuotes (line ~846)
        // Double-quoted string with escaped backslash containing colon
        $yaml = 'key: "value\\\\:escaped"';

        $result = $this->driver->decode($yaml);

        self::assertSame('value\\:escaped', $result['key']);
    }
}
