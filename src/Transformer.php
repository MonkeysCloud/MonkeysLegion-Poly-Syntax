<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax;

use Monkeyslegion\PolySyntax\Contract\DriverInterface;
use Monkeyslegion\PolySyntax\Contract\StreamingDecoderInterface;
use Monkeyslegion\PolySyntax\Enum\Syntax;
use Monkeyslegion\PolySyntax\Exception\DecodeException;
use Monkeyslegion\PolySyntax\Exception\EncodeException;
use Monkeyslegion\PolySyntax\Exception\UnsupportedSyntaxException;
use Monkeyslegion\PolySyntax\Stream\CsvStreamingDecoder;
use Monkeyslegion\PolySyntax\Stream\JsonStreamingDecoder;
use Monkeyslegion\PolySyntax\Stream\TomlStreamingDecoder;

/**
 * Facade for format transformation orchestration.
 *
 * The Transformer manages a registry of format drivers and provides
 * a unified API for decoding, encoding, and transforming between
 * data representation formats.
 *
 * Supports both built-in `Syntax` enum formats and user-registered
 * custom syntax strings, as well as chained transformations through
 * multiple intermediate formats (A → B → C).
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
 *
 * // Chained transformation: JSON → YAML → TOML
 * $toml  = $transformer->transformChain('{"key":"value"}', Syntax::JSON, Syntax::YAML, Syntax::TOML);
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
     * Register a driver with the transformer using its supported syntax.
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
     * Register a driver with an explicit syntax key.
     *
     * Unlike `registerDriver()`, this method allows registering a driver
     * under any string key, not just the value of its `supportedSyntax()`.
     * This is useful for custom user-land drivers whose format does not
     * correspond to any `Syntax` enum case.
     *
     * If a driver for the same key is already registered, it will be replaced.
     *
     * @param  string          $syntax The custom syntax identifier.
     * @param  DriverInterface $driver The driver instance to register.
     * @return self                    Instance for method chaining.
     */
    public function registerSyntax(string $syntax, DriverInterface $driver): self
    {
        $this->drivers[$syntax] = $driver;

        return $this;
    }

    /**
     * Check whether a driver is registered for the given syntax.
     *
     * Accepts both `Syntax` enum values and custom string identifiers.
     *
     * @param  Syntax|string $syntax The format to check.
     * @return bool                  True if a driver is available.
     */
    public function supports(Syntax|string $syntax): bool
    {
        return isset($this->drivers[$this->resolveKey($syntax)]);
    }

    /**
     * Return all registered syntax keys.
     *
     * @return list<string>
     */
    public function registeredSyntaxes(): array
    {
        return \array_keys($this->drivers);
    }

    /**
     * Return all registered syntaxes as `Syntax` enum values.
     *
     * Only syntaxes that correspond to a `Syntax` enum case are returned.
     * Custom string-only syntaxes are excluded.
     *
     * @return list<Syntax>
     */
    public function supportedSyntaxes(): array
    {
        $result = [];

        foreach ($this->drivers as $key => $driver) {
            $syntax = $driver->supportedSyntax();

            if ($syntax->value === $key) {
                $result[] = $syntax;
            }
        }

        return $result;
    }

    /**
     * Decode a string payload into a PHP array using the registered driver.
     *
     * @param  string        $input  The raw format string to decode.
     * @param  Syntax|string $syntax The format of the input string.
     * @return array<mixed>          The decoded PHP array.
     *
     * @throws UnsupportedSyntaxException When no driver is registered for the syntax.
     * @throws DecodeException            When the input cannot be parsed.
     */
    public function decode(string $input, Syntax|string $syntax): array
    {
        return $this->getDriver($syntax)->decode($input);
    }

    /**
     * Encode a PHP array into a string using the registered driver.
     *
     * @param  array<mixed>  $data   The PHP array to encode.
     * @param  Syntax|string $syntax The target format.
     * @return string                 The formatted output string.
     *
     * @throws UnsupportedSyntaxException When no driver is registered for the syntax.
     * @throws EncodeException            When the data cannot be serialized.
     */
    public function encode(array $data, Syntax|string $syntax): string
    {
        return $this->getDriver($syntax)->encode($data);
    }

    /**
     * Transform a string payload from one format to another.
     *
     * This is a convenience method that performs a decode followed
     * by an encode in a single call.
     *
     * @param  string        $input The raw input string in the source format.
     * @param  Syntax|string $from  The source format.
     * @param  Syntax|string $to    The target format.
     * @return string                The transformed output string.
     *
     * @throws UnsupportedSyntaxException When either format has no registered driver.
     * @throws DecodeException            When the input cannot be parsed.
     * @throws EncodeException            When the data cannot be serialized.
     */
    public function transform(string $input, Syntax|string $from, Syntax|string $to): string
    {
        return $this->encode(
            $this->decode($input, $from),
            $to,
        );
    }

    /**
     * Transform a payload through multiple intermediate formats (A → B → C → …).
     *
     * Each format in the chain acts as a round-trip: the intermediate formats
     * are decoded and re-encoded to ensure data fidelity at every step.
     * The final format in the chain is the output format.
     *
     * At least two syntaxes must be provided (source and target).
     *
     * @param  string        $input The raw input string in the first format.
     * @param  Syntax|string ...$chain  The ordered list of formats. Minimum 2.
     * @return string                   The transformed output in the last format.
     *
     * @throws UnsupportedSyntaxException When a format in the chain has no driver.
     * @throws DecodeException            When the input cannot be parsed.
     * @throws EncodeException            When the data cannot be serialized.
     */
    public function transformChain(string $input, Syntax|string ...$chain): string
    {
        $count = \count($chain);

        if ($count < 2) {
            throw new \InvalidArgumentException(
                \sprintf(
                    'transformChain requires at least 2 syntaxes, %d given',
                    $count,
                ),
            );
        }

        $data = $this->decode($input, $chain[0]);

        for ($i = 1; $i < $count - 1; $i++) {
            $data = $this->decode(
                $this->encode($data, $chain[$i]),
                $chain[$i],
            );
        }

        return $this->encode($data, $chain[$count - 1]);
    }

    /**
     * Create a streaming decoder for the given format.
     *
     * Built-in streaming decoders:
     * - CSV (`Syntax::CSV`) → `CsvStreamingDecoder`
     * - JSON (`Syntax::JSON`) → `JsonStreamingDecoder`
     *
     * @param  Syntax|string $syntax The format to stream-decode.
     * @return StreamingDecoderInterface
     *
     * @throws UnsupportedSyntaxException When no streaming decoder is available.
     */
    public function createStreamDecoder(Syntax|string $syntax): StreamingDecoderInterface
    {
        $key = $this->resolveKey($syntax);

        return match ($key) {
            'csv'  => new CsvStreamingDecoder(),
            'json' => new JsonStreamingDecoder(),
            'toml' => new TomlStreamingDecoder(),
            default => throw new UnsupportedSyntaxException($syntax),
        };
    }

    /**
     * Stream-decode data by feeding chunks and draining items.
     *
     * This is a convenience wrapper around `createStreamDecoder()` that
     * feeds chunks through an iterator and yields decoded items.
     *
     * @param  iterable<string> $chunks  An iterable of data chunks.
     * @param  Syntax|string    $syntax  The format to decode.
     * @return iterable<mixed>           Yields decoded items one at a time.
     *
     * @throws UnsupportedSyntaxException When no streaming decoder is available.
     * @throws DecodeException            When malformed data is encountered.
     */
    public function decodeStream(iterable $chunks, Syntax|string $syntax): iterable
    {
        $decoder = $this->createStreamDecoder($syntax);

        foreach ($chunks as $chunk) {
            $decoder->feed($chunk);

            while (($item = $decoder->next()) !== null) {
                yield $item;
            }
        }

        $decoder->end();

        while (($item = $decoder->next()) !== null) {
            yield $item;
        }
    }

    /**
     * Retrieve the driver for the given syntax key.
     *
     * @param  Syntax|string $syntax The format identifier.
     * @return DriverInterface        The registered driver.
     *
     * @throws UnsupportedSyntaxException When no driver is registered.
     */
    private function getDriver(Syntax|string $syntax): DriverInterface
    {
        return $this->drivers[$this->resolveKey($syntax)]
            ?? throw new UnsupportedSyntaxException($syntax);
    }

    /**
     * Resolve a Syntax|string to its string key.
     *
     * @param  Syntax|string $syntax The format identifier.
     * @return string                The string key for driver lookup.
     */
    private function resolveKey(Syntax|string $syntax): string
    {
        return $syntax instanceof Syntax ? $syntax->value : $syntax;
    }
}
