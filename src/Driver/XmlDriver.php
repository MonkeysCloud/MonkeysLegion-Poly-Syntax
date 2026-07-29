<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Driver;

use Monkeyslegion\PolySyntax\Contract\DriverInterface;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;
use Monkeyslegion\PolySyntax\Exception\EncodeException;

/**
 * Driver for XML format transformation.
 *
 * Uses SimpleXML for parsing and DOMDocument for writing with
 * XXE protection enabled by default.
 *
 * ## XML ↔ Array Conversion Rules
 *
 * ### Decoding (XML → Array)
 * - Elements become keys, text content becomes values.
 * - Attributes are stored under a special `@attributes` key.
 * - Multiple elements with the same tag name become a list.
 * - Empty elements decode to an empty string.
 * - Nested elements create nested arrays.
 *
 * ### Encoding (Array → XML)
 * - Scalar values become element text content.
 * - Nested arrays create child elements.
 * - An `@attributes` array key creates XML attributes on the parent element.
 * - The `@text` key sets text content on the parent element.
 * - Integer keys are prefixed with `item` to form valid XML tags.
 *
 * ## XXE Protection
 *
 * External entity loading is disabled by default using
 * `LIBXML_NOENT` omitted to prevent XXE attacks on untrusted input.
 */
final class XmlDriver implements DriverInterface
{
    /**
     * Name of the root element when encoding.
     *
     * @var non-empty-string
     */
    private readonly string $rootElement;

    /**
     * Default namespace to declare on the root element.
     */
    private readonly ?string $defaultNamespace;

    /**
     * LibXML options for parsing.
     */
    private readonly int $libxmlOptions;

    /**
     * @param  non-empty-string $rootElement       Root element name for encoding (default "root").
     * @param  int|null         $libxmlOptions     LibXML parser options. Default disables NET and
     *                                             NSCLEAN for clean output.
     * @param  string|null      $defaultNamespace  Optional default namespace URI.
     */
    public function __construct(
        string $rootElement = 'root',
        ?int $libxmlOptions = null,
        ?string $defaultNamespace = null,
    ) {
        $this->rootElement = $rootElement;
        $this->defaultNamespace = $defaultNamespace;
        $this->libxmlOptions = $libxmlOptions ?? (
            \LIBXML_NONET      // Disable network access
            | \LIBXML_NSCLEAN  // Strip redundant namespace declarations
            | \LIBXML_PARSEHUGE // Allow deep nesting and large text nodes
        );
    }

    #[\Override]
    public function supportedSyntax(): Syntax
    {
        return Syntax::XML;
    }

    #[\Override]
    public function decode(string $input): array
    {
        if (\trim($input) === '') {
            throw new DecodeException('Cannot decode empty XML input');
        }

        // Suppress libxml warnings and capture errors manually
        $useErrors = \libxml_use_internal_errors(true);

        try {
            $xml = \simplexml_load_string(
                $input,
                \SimpleXMLElement::class,
                $this->libxmlOptions,
            );
        } finally {
            \libxml_use_internal_errors($useErrors);
        }

        if ($xml === false) {
            $errors = $this->collectLibxmlErrors();

            throw new DecodeException(
                \sprintf('Failed to parse XML: %s', $errors),
            );
        }

        $result = $this->xmlToArray($xml);

        if (!\is_array($result)) {
            throw new DecodeException(
                'Root XML element did not produce an array result',
            );
        }

        return $result;
    }

    #[\Override]
    public function encode(array $data): string
    {
        if ($data === []) {
            return \sprintf('<?xml version="1.0"?>%s<%s/>', "\n", $this->rootElement);
        }

        $xml = new \SimpleXMLElement(
            \sprintf('<?xml version="1.0"?><%s/>', $this->rootElement),
            $this->libxmlOptions,
        );

        if ($this->defaultNamespace !== null) {
            $xml->addAttribute('xmlns', $this->defaultNamespace);
        }

        try {
            $this->arrayToXml($data, $xml);
        } catch (\Throwable $e) {
            throw new EncodeException(
                \sprintf('Failed to encode XML: %s', $e->getMessage()),
                previous: $e,
            );
        }

        $result = $xml->asXML();

        // @codeCoverageIgnoreStart
        if ($result === false) {
            throw new EncodeException('Failed to produce XML output');
        }
        // @codeCoverageIgnoreEnd

        return $result;
    }

    // ─── Private Helpers ───────────────────────────────────────────

    /**
     * Convert a SimpleXMLElement tree to a native PHP array or string.
     *
     * @param  \SimpleXMLElement $xml The XML node to convert.
     * @return array<mixed>|string    The resulting PHP array or text content.
     */
    private function xmlToArray(\SimpleXMLElement $xml): array|string
    {
        $text = (string) $xml;
        $attrs = $xml->attributes();
        $children = $xml->children();

        // Has child elements → process recursively
        if ($children !== null && $children->count() > 0) {
            $result = [];

            if ($attrs !== null && $attrs->count() > 0) {
                $result['@attributes'] = $this->attributesToArray($attrs);
            }

            /** @var array<string, list<\SimpleXMLElement>> $grouped */
            $grouped = [];

            foreach ($children as $child) {
                /** @var \SimpleXMLElement $child */
                $grouped[$child->getName()][] = $child;
            }

            foreach ($grouped as $tagName => $elements) {
                $converted = \array_map(
                    fn (\SimpleXMLElement $el): array|string => $this->xmlToArray($el),
                    $elements,
                );

                if (\count($elements) === 1) {
                    $result[$tagName] = $converted[0];
                } else {
                    $result[$tagName] = $converted;
                }
            }

            return $result;
        }

        // Leaf node — return text content
        // If it also has attributes, wrap them together
        if ($attrs !== null && $attrs->count() > 0) {
            return [
                '@attributes' => $this->attributesToArray($attrs),
                '@text'       => $text,
            ];
        }

        return $text;
    }

    /**
     * Convert a PHP array to SimpleXML child elements.
     *
     * @param  array<mixed>      $data The data to add.
     * @param  \SimpleXMLElement $xml  The parent XML node.
     */
    private function arrayToXml(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            if ($key === '@attributes' && \is_array($value)) {
                foreach ($value as $attrName => $attrValue) {
                    /** @phpstan-ignore cast.string */
                    $xml->addAttribute((string) $attrName, (string) $attrValue);
                }

                continue;
            }

            if ($key === '@text') {
                // Set text content on the parent element
                $dom = \dom_import_simplexml($xml);
                /** @phpstan-ignore notIdentical.alwaysTrue */
                if ($dom !== null) {
                    /** @phpstan-ignore cast.string */
                    $dom->textContent = (string) $value;
                }

                continue;
            }

            // Integer keys need a valid XML element name
            $elementName = \is_int($key) ? 'item' : (string) $key;

            if (\is_array($value)) {
                $child = $xml->addChild($elementName);

                if ($child !== null) {
                    $this->arrayToXml($value, $child);
                }
            } elseif (\is_string($value)) {
                $xml->addChild($elementName, $value);
            } elseif (\is_scalar($value)) {
                $xml->addChild($elementName, (string) $value);
            }
        }
    }

    /**
     * Extract attributes from a SimpleXMLElement into a string-keyed array.
     *
     * @param  \SimpleXMLElement $attrs The attributes node.
     * @return array<string, string>
     */
    private function attributesToArray(\SimpleXMLElement $attrs): array
    {
        $result = [];

        foreach ($attrs as $name => $value) {
            $result[(string) $name] = (string) $value;
        }

        return $result;
    }

    /**
     * Collect and format libxml errors.
     *
     * @return string
     */
    private function collectLibxmlErrors(): string
    {
        $errors = \libxml_get_errors();
        \libxml_clear_errors();

        $messages = \array_map(
            static fn (\LibXMLError $e): string => \sprintf(
                '[%s] Line %d, Col %d: %s',
                match ($e->level) {
                    \LIBXML_ERR_WARNING => 'WARNING',
                    \LIBXML_ERR_ERROR   => 'ERROR',
                    \LIBXML_ERR_FATAL   => 'FATAL',
                    default             => 'UNKNOWN',
                },
                $e->line,
                $e->column,
                \trim($e->message),
            ),
            $errors,
        );

        return \implode('; ', $messages);
    }
}
