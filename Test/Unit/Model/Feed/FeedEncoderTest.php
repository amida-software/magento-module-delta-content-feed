<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\Feed;

use Amida\ProductDeltaFeed\Model\Feed\FeedEncoder;
use Amida\ProductDeltaFeed\Model\Feed\ProtoWriter;
use PHPUnit\Framework\TestCase;

class FeedEncoderTest extends TestCase
{
    public function testProducesDeterministicBinaryEnvelope(): void
    {
        $encoder = new FeedEncoder(new ProtoWriter());
        $meta = [
            'schema_version' => 1,
            'stream' => 'all',
            'store_code' => 'default',
            'from_event_id' => 0,
            'to_event_id' => 1,
            'has_more' => false,
            'cursor_expired' => false,
        ];
        $items = [[
            'event_id' => 1,
            'stream' => 'all',
            'origin_stream' => 'content',
            'product_id' => 10,
            'sku' => 'sku-10',
            'store_code' => 'default',
            'event_type' => 'UPSERT_FULL',
            'changed_fields' => ['name'],
            'source_updated_at' => '2026-03-30 12:00:00',
            'emitted_at' => '2026-03-30 12:00:01',
            'payload_version' => 1,
            'payload_hash' => 'abc',
            'payload' => [
                'enabled' => true,
                'attributes' => [
                    ['code' => 'name', 'kind' => 'string', 'is_null' => false, 'string_value' => 'Hello', 'labels' => [], 'list_values' => []],
                ],
                'deleted' => false,
            ],
        ]];

        $first = $encoder->encodeChangesEnvelope($meta, $items);
        $second = $encoder->encodeChangesEnvelope($meta, $items);

        self::assertNotSame('', $first);
        self::assertSame($first, $second);
    }
}
