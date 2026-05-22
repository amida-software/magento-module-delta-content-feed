<?php
declare(strict_types=1);

require __DIR__ . '/../Model/Feed/ProtoWriter.php';
require __DIR__ . '/../Model/Feed/FeedEncoder.php';

use Amida\ProductDeltaFeed\Model\Feed\FeedEncoder;
use Amida\ProductDeltaFeed\Model\Feed\ProtoWriter;

$writer = new ProtoWriter();
if ($writer->uint64(1, 150) !== "\x08\x96\x01") {
    fwrite(STDERR, "Proto writer smoke test failed\n");
    exit(1);
}

$feed = new FeedEncoder($writer);
$blob = $feed->encodeChangesEnvelope([
    'schema_version' => 1,
    'stream' => 'content',
    'store_code' => 'default',
    'from_event_id' => 0,
    'to_event_id' => 1,
    'has_more' => false,
    'cursor_expired' => false,
], [[
    'event_id' => 1,
    'stream' => 'content',
    'origin_stream' => 'content',
    'product_id' => 1,
    'sku' => 'SKU-1',
    'store_code' => 'default',
    'event_type' => 'UPSERT_FULL',
    'changed_fields' => ['name'],
    'payload_version' => 1,
    'payload_hash' => 'hash',
    'payload' => [
        'enabled' => true,
        'attributes' => [[
            'code' => 'name',
            'kind' => 'string',
            'is_null' => false,
            'string_value' => 'Test',
        ]],
        'deleted' => false,
    ],
]]);
if ($blob === '') {
    fwrite(STDERR, "Feed encoder smoke test failed\n");
    exit(1);
}

$curatedBlob = $feed->encodeSnapshotEnvelope([
    'schema_version' => 1,
    'stream' => 'curated',
    'store_code' => 'default',
    'from_state_id' => 0,
    'to_state_id' => 1,
    'has_more' => false,
    'changes_highwater_event_id' => 1,
], [[
    'state_id' => 1,
    'product_id' => 1,
    'sku' => 'SKU-1',
    'stream' => 'curated',
    'store_code' => 'default',
    'payload' => [
        'enabled' => true,
        'deleted' => false,
        'curated' => [
            'sku' => 'SKU-1',
            'prices' => ['old' => 100.0, 'new' => 90.0],
            'images' => ['https://example.com/media/catalog/product/s/k/sku-1.jpg'],
            'notes' => ['amber'],
        ],
    ],
]]);
if ($curatedBlob === '' || !str_contains($curatedBlob, 'SKU-1')) {
    fwrite(STDERR, "Curated feed encoder smoke test failed\n");
    exit(1);
}

echo "Smoke OK\n";
