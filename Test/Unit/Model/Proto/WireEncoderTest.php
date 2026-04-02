<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\Proto;

use Amida\ProductDeltaFeed\Model\Proto\WireEncoder;
use PHPUnit\Framework\TestCase;

class WireEncoderTest extends TestCase
{
    public function testVarintEncoding(): void
    {
        $encoder = new WireEncoder();
        self::assertSame("\x08\x96\x01", $encoder->uintField(1, 150));
    }

    public function testStringEncoding(): void
    {
        $encoder = new WireEncoder();
        self::assertSame("\x0a\x03sku", $encoder->stringField(1, 'sku'));
    }
}
