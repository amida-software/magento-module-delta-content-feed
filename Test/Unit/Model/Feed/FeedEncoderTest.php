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
    public function testEncodesOfferAndCategoryDictionaryPayloads(): void
    {
        $encoder = new FeedEncoder(new ProtoWriter());
        $offerEncoded = $encoder->encodeSnapshotEnvelope([
            'schema_version' => 1,
            'stream' => 'offer',
            'store_code' => 'default',
            'from_state_id' => 0,
            'to_state_id' => 1,
            'has_more' => false,
            'changes_highwater_event_id' => 1,
        ], [[
            'state_id' => 1,
            'product_id' => 10,
            'sku' => 'SKU-10',
            'stream' => 'offer',
            'store_code' => 'default',
            'state_hash' => 'hash',
            'payload' => [
                'enabled' => true,
                'deleted' => false,
                'offer' => [
                    'product_id' => 10,
                    'sku' => 'SKU-10',
                    'magento_type_id' => 'simple',
                    'prices' => ['old' => 100.0, 'current' => 80.0, 'currency' => 'EUR', 'source' => 'direct_sql_eav'],
                    'availability' => 'in_stock',
                    'qty' => 7.0,
                    'is_salable' => true,
                    'is_in_stock' => true,
                    'stock_status' => 'in_stock',
                    'source_updated_at' => '2026-05-25 10:00:00',
                ],
            ],
        ]]);

        $categoryEncoded = $encoder->encodeCategorySnapshotEnvelope([
            'schema_version' => 1,
            'stream' => 'categories',
            'store_code' => 'default',
            'from_state_id' => 0,
            'to_state_id' => 1,
            'has_more' => false,
            'changes_highwater_event_id' => 1,
        ], [[
            'state_id' => 1,
            'category_id' => 12,
            'store_code' => 'default',
            'state_hash' => 'hash',
            'payload' => [
                'enabled' => true,
                'deleted' => false,
                'category' => [
                    'category_id' => 12,
                    'external_id' => '12',
                    'enabled' => true,
                    'store_code' => 'default',
                    'parent_id' => 2,
                    'url' => 'https://shop.test/perfume',
                    'name' => 'Perfume',
                    'title' => 'Perfume',
                    'description' => 'Fragrances',
                ],
            ],
        ]]);

        self::assertStringContainsString('SKU-10', $offerEncoded);
        self::assertStringContainsString('EUR', $offerEncoded);
        self::assertStringContainsString('direct_sql_eav', $offerEncoded);
        self::assertStringContainsString('Perfume', $categoryEncoded);
        self::assertStringContainsString('https://shop.test/perfume', $categoryEncoded);
    }

}
