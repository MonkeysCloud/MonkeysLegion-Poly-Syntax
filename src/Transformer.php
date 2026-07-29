<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax;

use Monkeyslegion\PolySyntax\Contract\DriverInterface;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;
use Monkeyslegion\PolySyntax\Exception\EncodeException;
use Monkeyslegion\PolySyntax\Exception\UnsupportedSyntaxException;

/**
 * Facade for format transformation orchestration.
 *
 * The Transformer manages a registry of format drivers and provides
 * a unified API for decoding, encoding, and transforming between
 * data representation formats.
 *
 * ## Usage
 *
 * ```php
 * $transformer = new Transformer();
 * $transformer->registerDriver(new JsonDriver());
 *
 * $data  = $transformer->decode('{"key":"value"}', Syntax::JSON);
 * $yaml  = $transformer->encode($data, Syntax::YAML);
 * $xml   = $transformer->transform('{"key":"value"}', Syntax::JSON, Syntax::XML);
 * ```
 */
final class Transformer
{
    /**
     * Registered drivers keyed by their syntax value.
     *
     * @var array<string, DriverInterface>
     */
    private array $drivers = [];

    /**
     * Register a driver with the transformer.
     *
     * If a driver for the same syntax is already registered,
     * it will be replaced.
     *
     * @param  DriverInterface $driver The driver instance to register.
     * @return self                    Instance for method chaining.
     */
    public function registerDriver(DriverInterface $driver): self
    {
        $this->drivers[$driver->supportedSyntax()->value] = $driver;

        return $this;
    }

    /**
     * Check whether a driver is registered for the given syntax.
     *
     * @param  Syntax $syntax The format to check.
     * @return bool           True if a driver is available.
     */
    public function supports(Syntax $syntax): bool
    {
        return isset($this->drivers[$syntax->value]);
    }

    /**
     * Return all syntaxes that have a registered driver.
     *
     * @return list<Syntax>
     */
    public function supportedSyntaxes(): array
    {
        return \array_values(
            \array_map(
                static fn (DriverInterface $driver): Syntax => $driver->supportedSyntax(),
                $this->drivers,
            ),
        );
    }

    /**
     * Decode a string payload into a PHP array using the registered driver.
     *
     * @param  string $input  The raw format string to decode.
     * @param  Syntax $syntax The format of the input string.
     * @return array<mixed>   The decoded PHP array.
     *
     * @throws UnsupportedSyntaxException When no driver is registered for the syntax.
     * @throws DecodeException            When the input cannot be parsed.
     */
    public function decode(string $input, Syntax $syntax): array
    {
        return $this->getDriver($syntax)->decode($input);
    }

    /**
     * Encode a PHP array into a string using the registered driver.
     *
     * @param  array<mixed> $data   The PHP array to encode.
     * @param  Syntax       $syntax The target format.
     * @return string                The formatted output string.
     *
     * @throws UnsupportedSyntaxException When no driver is registered for the syntax.
     * @throws EncodeException            When the data cannot be serialized.
     */
    public function encode(array $data, Syntax $syntax): string
    {
        return $this->getDriver($syntax)->encode($data);
    }

    /**
     * Transform a string payload from one format to another.
     *
     * This is a convenience method that performs a decode followed
     * by an encode in a single call.
     *
     * @param  string $input The raw input string in the source format.
     * @param  Syntax $from  The source format.
     * @param  Syntax $to    The target format.
     * @return string         The transformed output string.
     *
     * @throws UnsupportedSyntaxException When either format has no registered driver.
     * @throws DecodeException            When the input cannot be parsed.
     * @throws EncodeException            When the data cannot be serialized.
     */
    public function transform(string $input, Syntax $from, Syntax $to): string
    {
        return $this->encode(
            $this->decode($input, $from),
            $to,
        );
    }

    /**
     * Retrieve the driver for the given syntax.
     *
     * @param  Syntax $syntax The requested format.
     * @return DriverInterface The registered driver.
     *
     * @throws UnsupportedSyntaxException When no driver is registered.
     */
    private function getDriver(Syntax $syntax): DriverInterface
    {
        return $this->drivers[$syntax->value]
            ?? throw new UnsupportedSyntaxException($syntax);
    }
}
