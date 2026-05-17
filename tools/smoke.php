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

echo "Smoke OK\n";
