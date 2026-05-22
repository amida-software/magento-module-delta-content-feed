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

    public function testEncodesCuratedProductState(): void
    {
        $encoder = new FeedEncoder(new ProtoWriter());
        $encoded = $encoder->encodeSnapshotEnvelope([
            'schema_version' => 1,
            'stream' => 'curated',
            'store_code' => 'ua',
            'from_state_id' => 0,
            'to_state_id' => 1,
            'has_more' => false,
            'changes_highwater_event_id' => 1,
        ], [[
            'state_id' => 1,
            'product_id' => 10,
            'sku' => 'SKU-10',
            'stream' => 'curated',
            'store_code' => 'ua',
            'updated_at' => '2026-05-20 12:00:00',
            'state_hash' => 'hash',
            'payload' => [
                'enabled' => true,
                'deleted' => false,
                'curated' => [
                    'sku' => 'SKU-10',
                    'prices' => ['old' => 1200.0, 'new' => 950.0],
                    'availability' => ['is_available' => true, 'qty' => 5.0],
                    'name' => 'Idole',
                    'description' => 'Long perfume description',
                    'url_key' => 'idole',
                    'images' => ['https://www.jan.com.ua/media/catalog/product/i/d/idole.jpg'],
                    'brand' => 'Lubin',
                    'product_type' => 'Perfume',
                    'magento_type_id' => 'simple',
                    'category_ids' => [3],
                    'notes' => ['cumin', 'sandal'],
                    'related_products' => [[
                        'relation' => 'related',
                        'product_id' => 11,
                        'sku' => 'REL-11',
                        'type_id' => 'simple',
                        'position' => 1,
                    ]],
                ],
            ],
        ]]);

        self::assertStringContainsString('Idole', $encoded);
        self::assertStringContainsString('https://www.jan.com.ua/media/catalog/product/i/d/idole.jpg', $encoded);
        self::assertStringContainsString('cumin', $encoded);
        self::assertStringContainsString('REL-11', $encoded);
    }
}
