<?php
declare(strict_types=1);

require_once __DIR__ . '/../Model/Feed/ProtoWriter.php';
require_once __DIR__ . '/../Model/Feed/FeedEncoder.php';

use Amida\ProductDeltaFeed\Model\Feed\FeedEncoder;
use Amida\ProductDeltaFeed\Model\Feed\ProtoWriter;

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$encoder = new FeedEncoder(new ProtoWriter());

$offerPayload = $encoder->encodeSnapshotEnvelope([
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
    'sku' => 'SKU-MOCK-1',
    'stream' => 'offer',
    'store_code' => 'default',
    'state_hash' => 'hash',
    'payload' => [
        'enabled' => true,
        'deleted' => false,
        'offer' => [
            'product_id' => 10,
            'sku' => 'SKU-MOCK-1',
            'prices' => ['old' => 100.0, 'current' => 80.0, 'currency' => 'EUR', 'source' => 'direct_sql_eav'],
            'availability' => 'in_stock',
            'qty' => 4.0,
            'is_salable' => true,
            'is_in_stock' => true,
            'stock_status' => 'in_stock',
        ],
    ],
]]);
assert_true(str_contains($offerPayload, 'SKU-MOCK-1'), 'offer SKU encoded');
assert_true(str_contains($offerPayload, 'EUR'), 'offer currency encoded');
assert_true(str_contains($offerPayload, 'direct_sql_eav'), 'offer direct SQL source marker encoded');

$categoryPayload = $encoder->encodeCategorySnapshotEnvelope([
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
    'stream' => 'categories',
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
assert_true(str_contains($categoryPayload, 'Perfume'), 'category name encoded');
assert_true(str_contains($categoryPayload, 'https://shop.test/perfume'), 'category URL encoded');

$providerSource = file_get_contents(__DIR__ . '/../Model/Offer/DirectSqlOfferProvider.php') ?: '';
foreach (['catalog_product_entity_decimal', 'cataloginventory_stock_item', 'inventory_source_item', 'inventory_reservation'] as $required) {
    assert_true(str_contains($providerSource, $required), "direct SQL source table {$required} referenced");
}
foreach (['catalog_product_index_price', 'cataloginventory_stock_status', 'inventory_stock_1', 'StockRegistry', 'GetProductSalableQty', 'IsProductSalable', 'getFinalPrice'] as $forbidden) {
    assert_true(!str_contains($providerSource, $forbidden), "forbidden index/API marker {$forbidden} absent");
}

$dbSchema = file_get_contents(__DIR__ . '/../etc/db_schema.xml') ?: '';
assert_true(str_contains($dbSchema, 'amida_product_delta_category_event'), 'category event table declared');
assert_true(str_contains($dbSchema, 'AMIDA_PRODUCT_DELTA_EVENT_STREAM_STORE_SKU_EVENT'), 'SKU-filter event index declared');

fwrite(STDOUT, "OK: mock offer/category smoke checks passed\n");
