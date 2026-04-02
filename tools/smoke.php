<?php
declare(strict_types=1);

require __DIR__ . '/../Model/Proto/WireEncoder.php';
require __DIR__ . '/../Model/Proto/FeedEncoder.php';
require __DIR__ . '/../Model/Policy/EventDecision.php';

use Amida\ProductDeltaFeed\Model\Policy\EventDecision;
use Amida\ProductDeltaFeed\Model\Proto\FeedEncoder;
use Amida\ProductDeltaFeed\Model\Proto\WireEncoder;

$wire = new WireEncoder();
if ($wire->uintField(1, 150) !== "\x08\x96\x01") {
    fwrite(STDERR, "Wire encoder smoke test failed\n");
    exit(1);
}

$feed = new FeedEncoder($wire);
$blob = $feed->encodeEnvelope([
    'module_version' => '1.0.0',
    'mode' => 'changes',
    'stream' => 'content',
    'next_cursor' => 1,
    'has_more' => false,
    'item_count' => 1,
    'items' => [[
        'product_id' => 1,
        'store_id' => 1,
        'sku' => 'SKU-1',
        'op' => 'upsert',
        'is_enabled' => true,
        'attributes' => [['code' => 'name', 'value' => 'Test']],
        'categories' => [],
        'event_id' => 1,
        'updated_at' => '2026-03-30T00:00:00Z',
    ]],
    'generated_at_unix' => 1,
]);
if ($blob === '') {
    fwrite(STDERR, "Feed encoder smoke test failed\n");
    exit(1);
}

$policy = new EventDecision();
if ($policy->decide(true, true, false, true) !== EventDecision::OP_DISABLE_STATUS_ONLY) {
    fwrite(STDERR, "Policy smoke test failed\n");
    exit(1);
}

echo "Smoke OK\n";
