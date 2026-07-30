<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Tests\Utility;

use Monkeyslegion\PolySyntax\Driver\CsvDriver;
use Monkeyslegion\PolySyntax\Driver\JsonDriver;
use Monkeyslegion\PolySyntax\Driver\TomlDriver;
use Monkeyslegion\PolySyntax\Driver\XmlDriver;
use Monkeyslegion\PolySyntax\Driver\YamlDriver;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\UnsupportedSyntaxException;
use Monkeyslegion\PolySyntax\Transformer;
use Monkeyslegion\PolySyntax\Utility\FormatComparison;
use Monkeyslegion\PolySyntax\Utility\TokenEstimate;
use Monkeyslegion\PolySyntax\Utility\TokenOptimizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TokenOptimizerTest extends TestCase
{
    private Transformer $transformer;

    private TokenOptimizer $optimizer;

    protected function setUp(): void
    {
        $this->transformer = new Transformer();
        $this->transformer->registerDriver(new JsonDriver());
        $this->transformer->registerDriver(new XmlDriver());
        $this->transformer->registerDriver(new CsvDriver());
        $this->transformer->registerDriver(new YamlDriver());
        $this->transformer->registerDriver(new TomlDriver());

        $this->optimizer = new TokenOptimizer($this->transformer);
    }

    // ─── TokenEstimate ──────────────────────────────────────────────

    #[Test]
    public function tokenEstimateHasCorrectProperties(): void
    {
        $estimate = new TokenEstimate(
            syntax: Syntax::JSON,
            characters: 100,
            bytes: 100,
            estimatedTokens: 28,
            tokensPerByte: 0.283,
        );

        self::assertSame(Syntax::JSON, $estimate->syntax);
        self::assertSame(100, $estimate->characters);
        self::assertSame(100, $estimate->bytes);
        self::assertSame(28, $estimate->estimatedTokens);
        self::assertSame(0.283, $estimate->tokensPerByte);
    }

    #[Test]
    public function tokenEstimateLabelMatchesSyntaxLabel(): void
    {
        $estimate = new TokenEstimate(
            syntax: Syntax::XML,
            characters: 0,
            bytes: 0,
            estimatedTokens: 0,
            tokensPerByte: 0.0,
        );

        self::assertSame('XML', $estimate->label());
        self::assertSame('xml', $estimate->format());
    }

    #[Test]
    public function tokenEstimateYamlLabel(): void
    {
        $estimate = new TokenEstimate(
            syntax: Syntax::YAML,
            characters: 0,
            bytes: 0,
            estimatedTokens: 0,
            tokensPerByte: 0.0,
        );

        self::assertSame('YAML', $estimate->label());
        self::assertSame('yaml', $estimate->format());
    }

    #[Test]
    public function tokenEstimateTomlLabel(): void
    {
        $estimate = new TokenEstimate(
            syntax: Syntax::TOML,
            characters: 0,
            bytes: 0,
            estimatedTokens: 0,
            tokensPerByte: 0.0,
        );

        self::assertSame('TOML', $estimate->label());
        self::assertSame('toml', $estimate->format());
    }

    #[Test]
    public function tokenEstimateCsvLabel(): void
    {
        $estimate = new TokenEstimate(
            syntax: Syntax::CSV,
            characters: 0,
            bytes: 0,
            estimatedTokens: 0,
            tokensPerByte: 0.0,
        );

        self::assertSame('CSV', $estimate->label());
        self::assertSame('csv', $estimate->format());
    }

    // ─── FormatComparison ───────────────────────────────────────────

    #[Test]
    public function formatComparisonReportsBeneficialSwap(): void
    {
        $from = new TokenEstimate(Syntax::JSON, 100, 100, 50, 0.5);
        $to   = new TokenEstimate(Syntax::YAML, 60, 60, 30, 0.5);
        $comparison = new FormatComparison($from, $to, 20, 40.0, 1.67);

        self::assertTrue($comparison->isBeneficial());
        self::assertSame(20, $comparison->savingsTokens);
        self::assertSame(40.0, $comparison->savingsPercent);
        self::assertSame(1.67, $comparison->reductionFactor);
    }

    #[Test]
    public function formatComparisonReportsCostlySwap(): void
    {
        $from = new TokenEstimate(Syntax::JSON, 100, 100, 30, 0.3);
        $to   = new TokenEstimate(Syntax::XML, 200, 200, 70, 0.35);
        $comparison = new FormatComparison($from, $to, -40, -133.33, 0.43);

        self::assertFalse($comparison->isBeneficial());
    }

    #[Test]
    public function formatComparisonSummaryContainsFormatNames(): void
    {
        $from = new TokenEstimate(Syntax::JSON, 100, 100, 50, 0.5);
        $to   = new TokenEstimate(Syntax::CSV, 50, 50, 10, 0.2);
        $comparison = new FormatComparison($from, $to, 40, 80.0, 5.0);

        $summary = $comparison->summary();

        self::assertStringContainsString('JSON', $summary);
        self::assertStringContainsString('CSV', $summary);
        self::assertStringContainsString('40', $summary);
        self::assertStringContainsString('80.0%', $summary);
        self::assertStringContainsString('saves', $summary);
    }

    #[Test]
    public function formatComparisonSummaryShowsCostDirection(): void
    {
        $from = new TokenEstimate(Syntax::CSV, 50, 50, 10, 0.2);
        $to   = new TokenEstimate(Syntax::XML, 200, 200, 70, 0.35);
        $comparison = new FormatComparison($from, $to, -60, -600.0, 0.14);

        $summary = $comparison->summary();

        self::assertStringContainsString('costs', $summary);
        self::assertStringContainsString('more', $summary);
    }

    // ─── TokenOptimizer: estimate() ─────────────────────────────────

    #[Test]
    public function itEstimatesTokensForJsonString(): void
    {
        $result = $this->optimizer->estimate('{"name":"John","age":30}', Syntax::JSON);

        self::assertSame(Syntax::JSON, $result->syntax);
        self::assertGreaterThan(0, $result->characters);
        self::assertGreaterThan(0, $result->bytes);
        self::assertGreaterThan(0, $result->estimatedTokens);

        // JSON at ~290 tokens/KB ⇒ ~0.283 per byte
        // 24 chars = 24 bytes ⇒ ~7 tokens
        self::assertSame(24, $result->characters);
        self::assertSame(24, $result->bytes);
    }

    #[Test]
    public function itEstimatesTokensForXmlString(): void
    {
        $result = $this->optimizer->estimate(
            '<root><name>John</name><age>30</age></root>',
            Syntax::XML,
        );

        self::assertSame(Syntax::XML, $result->syntax);
        self::assertGreaterThan(0, $result->estimatedTokens);

        // XML at ~350 tokens/KB ⇒ ~0.342 per byte
        self::assertSame(43, $result->characters);
        self::assertSame(43, $result->bytes);
    }

    #[Test]
    public function itEstimatesZeroTokensForEmptyString(): void
    {
        $result = $this->optimizer->estimate('', Syntax::JSON);

        self::assertSame(0, $result->characters);
        self::assertSame(0, $result->bytes);
        self::assertSame(0, $result->estimatedTokens);
    }

    #[Test]
    public function itEstimatesUsingFormatSpecificCoefficients(): void
    {
        $sameInput = 'key = "value"' . "\n" . 'count = 42';

        $jsonEstimates = $this->optimizer->estimate($sameInput, Syntax::JSON);
        $yamlEstimates = $this->optimizer->estimate($sameInput, Syntax::YAML);

        // Same input → same bytes → different coefficients → different token counts
        self::assertSame($jsonEstimates->bytes, $yamlEstimates->bytes);
        self::assertNotSame(
            $jsonEstimates->tokensPerByte,
            $yamlEstimates->tokensPerByte,
        );
    }

    #[Test]
    #[DataProvider('provideFormatSpecificCoefficients')]
    public function itUsesCorrectTokenCoefficient(Syntax $syntax, float $expectedTokensPerKb): void
    {
        $input = \str_repeat('a', 1024);

        $result = $this->optimizer->estimate($input, $syntax);

        // Allow small floating point rounding
        $expectedPerByte = $expectedTokensPerKb / 1024.0;
        self::assertEqualsWithDelta($expectedPerByte, $result->tokensPerByte, 0.001);
    }

    /**
     * @return array<string, array{0: Syntax, 1: float}>
     */
    public static function provideFormatSpecificCoefficients(): array
    {
        return [
            'JSON 290' => [Syntax::JSON, 290.0],
            'XML 350'  => [Syntax::XML, 350.0],
            'CSV 160'  => [Syntax::CSV, 160.0],
            'YAML 180' => [Syntax::YAML, 180.0],
            'TOML 190' => [Syntax::TOML, 190.0],
        ];
    }

    #[Test]
    public function itPreservesInputStringIntegrity(): void
    {
        $input = '{"preserve":"exact content 🎯"}';

        $estimate = $this->optimizer->estimate($input, Syntax::JSON);

        self::assertSame('{"preserve":"exact content 🎯"}', $input);
        self::assertGreaterThan(0, $estimate->estimatedTokens);
    }

    // ─── TokenOptimizer: estimateData() ─────────────────────────────

    #[Test]
    public function itEstimatesTokensFromData(): void
    {
        $data = ['name' => 'John', 'age' => 30];

        $result = $this->optimizer->estimateData($data, Syntax::JSON);

        self::assertSame(Syntax::JSON, $result->syntax);
        self::assertGreaterThan(0, $result->characters);
        self::assertGreaterThan(0, $result->bytes);
        self::assertGreaterThan(0, $result->estimatedTokens);
    }

    #[Test]
    public function itEstimatesTokensFromDataForAllFormats(): void
    {
        $data = ['name' => 'John', 'age' => 30];

        foreach (Syntax::all() as $syntax) {
            // CSV requires tabular data (list of rows) — skip for assoc data
            if ($syntax === Syntax::CSV) {
                continue;
            }

            $result = $this->optimizer->estimateData($data, $syntax);

            self::assertSame($syntax, $result->syntax);
            self::assertGreaterThan(0, $result->characters);
        }
    }

    #[Test]
    public function itEstimatesTokensFromTabularDataForCsv(): void
    {
        $data = [
            ['name' => 'John', 'age' => 30],
            ['name' => 'Jane', 'age' => 25],
        ];

        $result = $this->optimizer->estimateData($data, Syntax::CSV);

        self::assertSame(Syntax::CSV, $result->syntax);
        self::assertGreaterThan(0, $result->characters);
    }

    #[Test]
    public function estimateDataThrowsForUnregisteredFormat(): void
    {
        $transformer = new Transformer();

        // JSON is not registered
        $optimizer = new TokenOptimizer($transformer);

        $this->expectException(UnsupportedSyntaxException::class);
        $this->expectExceptionMessage('json');

        $optimizer->estimateData(['key' => 'value'], Syntax::JSON);
    }

    #[Test]
    public function estimateDataWithEmptyArray(): void
    {
        $result = $this->optimizer->estimateData([], Syntax::JSON);

        // JSON encodes [] as '[]' which is 2 characters
        self::assertSame(2, $result->characters);
        self::assertSame(2, $result->bytes);
    }

    // ─── TokenOptimizer: compare() ──────────────────────────────────

    #[Test]
    public function itComparesTwoFormats(): void
    {
        $data = ['name' => 'John', 'age' => 30];

        $comparison = $this->optimizer->compare($data, Syntax::JSON, Syntax::CSV);

        self::assertSame(Syntax::JSON, $comparison->from->syntax);
        self::assertSame(Syntax::CSV, $comparison->to->syntax);
    }

    #[Test]
    public function itReturnsZeroSavingsWhenComparingSameFormat(): void
    {
        $data = ['name' => 'John', 'age' => 30];

        $comparison = $this->optimizer->compare($data, Syntax::JSON, Syntax::JSON);

        self::assertSame(0, $comparison->savingsTokens);
        self::assertSame(0.0, $comparison->savingsPercent);
        self::assertEqualsWithDelta(1.0, $comparison->reductionFactor, 0.01);
    }

    #[Test]
    public function itReportsCsvAsMoreTokenEfficientThanJson(): void
    {
        $data = [
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
        ];

        $comparison = $this->optimizer->compare($data, Syntax::JSON, Syntax::CSV);

        // CSV should be more token-efficient than JSON for tabular data
        self::assertTrue(
            $comparison->isBeneficial(),
            'CSV should save tokens over JSON for tabular data',
        );
    }

    #[Test]
    public function itReportsYamlAsMoreTokenEfficientThanJson(): void
    {
        $data = [
            'server' => [
                'host' => 'localhost',
                'port' => 8080,
            ],
            'database' => [
                'name' => 'app_db',
                'user' => 'admin',
            ],
        ];

        $comparison = $this->optimizer->compare($data, Syntax::JSON, Syntax::YAML);

        // YAML should be more token-efficient than JSON for structured data
        self::assertTrue(
            $comparison->isBeneficial(),
            'YAML should save tokens over JSON for structured data',
        );
    }

    #[Test]
    public function itReportsXmlAsLessTokenEfficientThanJson(): void
    {
        $data = ['name' => 'John', 'age' => 30];

        $comparison = $this->optimizer->compare($data, Syntax::JSON, Syntax::XML);

        // XML should be less token-efficient than JSON (more verbose)
        self::assertFalse(
            $comparison->isBeneficial(),
            'XML should cost more tokens than JSON',
        );
    }

    #[Test]
    public function compareThrowsForUnregisteredFormat(): void
    {
        $transformer = new Transformer();
        $transformer->registerDriver(new JsonDriver());

        $optimizer = new TokenOptimizer($transformer);

        $this->expectException(UnsupportedSyntaxException::class);

        $optimizer->compare(['key' => 'value'], Syntax::JSON, Syntax::XML);
    }

    // ─── TokenOptimizer: analyzeAll() ───────────────────────────────

    #[Test]
    public function itAnalyzesAllRegisteredFormats(): void
    {
        $data = ['name' => 'John', 'age' => 30];

        $comparisons = $this->optimizer->analyzeAll($data);

        // 5 registered formats → 4 comparisons (excluding the worst/baseline)
        self::assertCount(4, $comparisons);

        // All comparisons should have a 'from' format
        foreach ($comparisons as $format => $comparison) {
            self::assertInstanceOf(FormatComparison::class, $comparison);
            self::assertSame(Syntax::from($format), $comparison->to->syntax);
        }
    }

    #[Test]
    public function analyzeAllReturnsEmptyArrayForSingleFormat(): void
    {
        $transformer = new Transformer();
        $transformer->registerDriver(new JsonDriver());

        $optimizer = new TokenOptimizer($transformer);

        $comparisons = $optimizer->analyzeAll(['key' => 'value']);

        self::assertSame([], $comparisons);
    }

    #[Test]
    public function analyzeAllWithEmptyData(): void
    {
        $comparisons = $this->optimizer->analyzeAll([]);

        self::assertCount(4, $comparisons);
    }

    #[Test]
    public function analyzeAllIncludesOnlyRegisteredFormats(): void
    {
        $transformer = new Transformer();
        $transformer->registerDriver(new JsonDriver());
        $transformer->registerDriver(new YamlDriver());

        $optimizer = new TokenOptimizer($transformer);

        $comparisons = $optimizer->analyzeAll(['key' => 'value']);

        // 2 formats → 1 comparison
        self::assertCount(1, $comparisons);
        self::assertArrayHasKey('yaml', $comparisons);
    }

    #[Test]
    public function analyzeAllWithNestedData(): void
    {
        $data = [
            'application' => [
                'name' => 'MyApp',
                'version' => '2.1.0',
                'settings' => [
                    'debug' => false,
                    'max_connections' => 100,
                    'timeout' => 30.5,
                ],
                'features' => ['auth', 'api', 'admin'],
            ],
            'database' => [
                'host' => 'db.example.com',
                'port' => 5432,
                'credentials' => [
                    'user' => 'app_user',
                    'database' => 'app_production',
                ],
            ],
        ];

        $comparisons = $this->optimizer->analyzeAll($data);

        self::assertCount(4, $comparisons);

        // All should have positive savings since XML is always the worst
        foreach ($comparisons as $comparison) {
            self::assertInstanceOf(FormatComparison::class, $comparison);
            self::assertGreaterThan(0, $comparison->savingsTokens);
        }
    }

    // ─── Integration: real-world payloads ───────────────────────────

    #[Test]
    public function itEstimatesLargePayloadTokens(): void
    {
        $tabularData = [];
        for ($i = 0; $i < 100; $i++) {
            $tabularData[] = [
                'id' => $i,
                'name' => 'Item ' . $i,
                'active' => $i % 2 === 0,
                'score' => $i * 1.5,
            ];
        }

        $json = $this->transformer->encode($tabularData, Syntax::JSON);
        $csv = $this->transformer->encode($tabularData, Syntax::CSV);

        $jsonEstimate = $this->optimizer->estimate($json, Syntax::JSON);
        $csvEstimate = $this->optimizer->estimate($csv, Syntax::CSV);

        self::assertGreaterThan(0, $jsonEstimate->estimatedTokens);
        self::assertGreaterThan(0, $csvEstimate->estimatedTokens);

        // CSV should have fewer tokens for tabular data
        self::assertLessThan(
            $jsonEstimate->estimatedTokens,
            $csvEstimate->estimatedTokens,
            'CSV should have fewer estimated tokens than JSON for 100-row data',
        );
    }

    #[Test]
    public function itEstimatesCsvForAssocArrayWithStringKeysAsEmpty(): void
    {
        // CSV encode with assoc array (non-tabular) returns empty string
        $result = $this->optimizer->estimateData(
            ['name' => 'John', 'age' => 30],
            Syntax::CSV,
        );

        self::assertSame(0, $result->characters);
        self::assertSame(0, $result->estimatedTokens);
    }

    #[Test]
    public function itComparesAllFormatsForRealWorldConfig(): void
    {
        $config = [
            'server' => [
                'host' => '0.0.0.0',
                'port' => 8080,
                'workers' => 4,
            ],
            'logging' => [
                'level' => 'info',
                'file' => '/var/log/app.log',
            ],
            'database' => [
                'driver' => 'postgres',
                'host' => 'localhost',
                'port' => 5432,
                'name' => 'myapp',
                'user' => 'admin',
                'pool' => [
                    'min' => 2,
                    'max' => 10,
                ],
            ],
        ];

        // Compare JSON → YAML (expected to be beneficial)
        $jsonToYaml = $this->optimizer->compare($config, Syntax::JSON, Syntax::YAML);
        self::assertTrue($jsonToYaml->isBeneficial());

        // Compare JSON → TOML (expected to be beneficial)
        $jsonToToml = $this->optimizer->compare($config, Syntax::JSON, Syntax::TOML);
        self::assertTrue($jsonToToml->isBeneficial());

        // Compare JSON → XML (expected to be costly)
        $jsonToXml = $this->optimizer->compare($config, Syntax::JSON, Syntax::XML);
        self::assertFalse($jsonToXml->isBeneficial());
    }
}
