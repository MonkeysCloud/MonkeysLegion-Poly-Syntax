<?php

declare(strict_types=1);

namespace Monkeyslegion\PolySyntax\Tests\Driver;

use Monkeyslegion\PolySyntax\Placeholder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlaceholderTest extends TestCase
{
    #[Test]
    public function itReportsVersion(): void
    {
        $placeholder = new Placeholder();
        $version = $placeholder->version();

        self::assertSame('0.1.0-dev', $version);
    }
}
