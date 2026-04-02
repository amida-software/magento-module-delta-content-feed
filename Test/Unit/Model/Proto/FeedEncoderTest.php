<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\Proto;

use Amida\ProductDeltaFeed\Model\Proto\FeedEncoder;
use Amida\ProductDeltaFeed\Model\Proto\WireEncoder;
use PHPUnit\Framework\TestCase;

class FeedEncoderTest extends TestCase
{
    public function testEnvelopeEncodingProducesNonEmptyBytes(): void
    {
        $encoder = new FeedEncoder(new WireEncoder());
        $bytes = $encoder->encodeEnvelope([
            'module_version' => '1.0.0',
            'mode' => 'changes',
            'stream' => 'content',
            'next_cursor' => 12,
            'has_more' => false,
            'item_count' => 1,
            'items' => [[
                'product_id' => 10,
                'store_id' => 1,
                'sku' => 'ABC',
                'op' => 'upsert',
                'is_enabled' => true,
                'attributes' => [['code' => 'name', 'value' => 'Test']],
                'categories' => [],
                'event_id' => 12,
                'updated_at' => '2026-03-30T00:00:00Z',
            ]],
            'generated_at_unix' => 1,
        ]);

        self::assertNotSame('', $bytes);
    }
}
