<?php
declare(strict_types=1);

function assert_contains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "Missing contract marker: {$message}\n");
        exit(1);
    }
}

function assert_not_contains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        fwrite(STDERR, "Forbidden contract marker: {$message}\n");
        exit(1);
    }
}

$offerProvider = (string)file_get_contents(__DIR__ . '/../Model/Offer/DirectSqlOfferProvider.php');
$directTables = [
    'catalog_product_entity_decimal',
    'catalog_product_entity_datetime',
    'cataloginventory_stock_item',
    'inventory_source_item',
    'inventory_source_stock_link',
    'inventory_stock_sales_channel',
    'inventory_reservation',
    'eav_attribute',
];
foreach ($directTables as $table) {
    assert_contains($table, $offerProvider, "DirectSqlOfferProvider must read {$table}");
}
foreach (['catalog_product_index_price', 'cataloginventory_stock_status', 'StockRegistry', 'GetProductSalableQty', 'IsProductSalable', 'getFinalPrice'] as $forbidden) {
    assert_not_contains($forbidden, $offerProvider, "DirectSqlOfferProvider must not use {$forbidden}");
}
if (preg_match('/inventory_stock_\d+/', $offerProvider)) {
    fwrite(STDERR, "Forbidden MSI stock index table marker: inventory_stock_<id>\n");
    exit(1);
}

$snapshotService = (string)file_get_contents(__DIR__ . '/../Model/Feed/SnapshotService.php');
assert_contains('fetchSnapshotRowsBySkus', $snapshotService, 'SKU snapshot lookup must bypass cursor snapshot rows');
assert_contains('X-Amida-Mode', $snapshotService, 'snapshot mode must be surfaced for diagnostics');
assert_contains('offer_state_missing', $snapshotService, 'include_offer missing diagnostics must exist');

$dbSchema = (string)file_get_contents(__DIR__ . '/../etc/db_schema.xml');
assert_contains('amida_product_delta_category_state', $dbSchema, 'category state table must be declared');
assert_contains('name="parent_id"', $dbSchema, 'category state table must persist parent_id used by snapshot rows');

$categoryStateSnapshot = (string)file_get_contents(__DIR__ . '/../Model/ResourceModel/CategoryStateSnapshot.php');
assert_contains("'parent_id'", $categoryStateSnapshot, 'category state snapshot upsert must include parent_id');

$proto = (string)file_get_contents(__DIR__ . '/../proto/amida_product_delta_feed_v1.proto');
assert_contains('message OfferState', $proto, 'offer proto message');
assert_contains('message CategoryEntityState', $proto, 'category entity proto message');
assert_contains('string sku = 4;', $proto, 'diagnostics expose SKU');
assert_contains('uint64 category_id = 5;', $proto, 'diagnostics expose category_id');

echo "Source contract OK\n";
