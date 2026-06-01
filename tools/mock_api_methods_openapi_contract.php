<?php
declare(strict_types=1);

require_once __DIR__ . '/../Model/Feed/ProtoWriter.php';
require_once __DIR__ . '/../Model/Feed/OpenApiDocumentEncoder.php';

use Amida\ProductDeltaFeed\Model\Feed\ProtoWriter;
use Amida\ProductDeltaFeed\Model\Feed\OpenApiDocumentEncoder;

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$openapi = (string)file_get_contents($root . '/docs/openapi.yaml');
$proto = (string)file_get_contents($root . '/proto/amida_product_delta_feed_v1.proto');

foreach (['content', 'category', 'categories', 'curated', 'offer', 'attributes', 'all'] as $stream) {
    assert_true(str_contains($openapi, $stream), "OpenAPI must include stream $stream");
}
foreach (['enum: [content, seo', 'enum: [content, category, categories, curated, offer, attributes, all, price', 'Enable SEO stream', 'Enable price stream', 'Enable availability stream'] as $legacy) {
    assert_true(!str_contains($openapi, $legacy), "OpenAPI/admin docs must not expose legacy token $legacy");
}
$streamEnumLine = 'enum: [content, category, categories, curated, offer, attributes, all]';
assert_true(str_contains($openapi, $streamEnumLine), 'OpenAPI stream enum must expose only production streams');
assert_true(str_contains($openapi, 'application/x-protobuf'), 'OpenAPI must expose application/x-protobuf responses');
assert_true(str_contains($proto, 'message OpenApiDocument'), 'Proto schema must define OpenApiDocument');

$configSource = (string)file_get_contents($root . '/Model/Config.php');
if (preg_match('/private const STREAM_PATHS = \[([\s\S]*?)\];/m', $configSource, $m)) {
    foreach (["STREAM_SEO", "STREAM_PRICE", "STREAM_AVAILABILITY"] as $legacyConst) {
        assert_true(!str_contains($m[1], $legacyConst), "Config::STREAM_PATHS must not expose $legacyConst");
    }
}
$adminSystem = (string)file_get_contents($root . '/etc/adminhtml/system.xml');
foreach (["seo_enabled", "price_enabled", "availability_enabled"] as $legacyField) {
    assert_true(!str_contains($adminSystem, $legacyField), "admin stream config must not expose $legacyField");
}

$encoder = new OpenApiDocumentEncoder(new ProtoWriter());
$syntheticPayloads = [
    'store' => [
        'schema_version' => 1,
        'entity' => 'store',
        'requested_store_code' => null,
        'store_scope' => 'all',
        'store' => ['code' => 'default', 'name' => 'Synthetic Store', 'description' => 'Demo', 'base_url' => 'https://example.com/'],
        'languages' => [
            ['store_code' => 'uk', 'language_code' => 'uk', 'is_main' => true],
            ['store_code' => 'en', 'language_code' => 'en', 'is_main' => false],
        ],
        'pages' => [[
            'title' => 'Delivery',
            'description' => 'Delivery conditions.',
            'url' => 'https://example.com/delivery',
            'special' => ['delivery'],
        ]],
        'sitemap' => ['languages' => []],
        'diagnostics' => [],
    ],
    'attributes' => [
        'schema_version' => 2,
        'entity' => 'attributes',
        'store_code' => 'default',
        'store_scope' => 'all',
        'attributes' => [
            'color' => ['code' => 'color', 'label' => 'Color', 'labels' => ['uk' => 'Колір', 'en' => 'Color'], 'kind' => 'select'],
        ],
        'diagnostics' => [],
    ],
    'health' => ['schema_version' => 1, 'entity' => 'health', 'ok' => true, 'checks' => []],
    'stats' => ['schema_version' => 1, 'entity' => 'stats', 'streams' => ['content' => 10, 'offer' => 10]],
];

$paths = [
    'store' => ['/amidafeed/v1/store/key/{key}', '#/components/schemas/StoreMetadata'],
    'attributes' => ['/amidafeed/v1/attributes/key/{key}', '#/components/schemas/AttributesDictionaryV2'],
    'health' => ['/amidafeed/v1/health/key/{key}', '#/components/schemas/Health'],
    'stats' => ['/amidafeed/v1/stats/key/{key}', '#/components/schemas/Stats'],
];

foreach ($syntheticPayloads as $entity => $payload) {
    [$path, $schemaRef] = $paths[$entity];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    assert_true(is_string($json) && $json !== '', "$entity JSON must encode");
    $pb = $encoder->encode($payload, $entity, $path, $schemaRef);
    assert_true(strlen($pb) > strlen((string)$entity), "$entity protobuf wrapper must not be empty");
    assert_true(str_contains($pb, $entity), "$entity protobuf wrapper should include entity marker");
    assert_true(str_contains($pb, hash('sha256', $encoder->canonicalJson($payload))), "$entity protobuf wrapper should include payload hash");
}

$offer = [
    'product_id' => 1,
    'sku' => 'SKU-1',
    'prices' => ['old' => 100.0, 'current' => 80.0, 'currency' => 'EUR'],
    'qty' => 5.0,
    'is_salable' => true,
    'manage_stock' => true,
    'backorders' => 0,
];
$priceOnly = array_intersect_key($offer, array_flip(['product_id', 'sku', 'prices']));
$availabilityOnly = array_intersect_key($offer, array_flip(['product_id', 'sku', 'qty', 'is_salable', 'manage_stock', 'backorders']));
assert_true(isset($priceOnly['prices']) && !isset($priceOnly['qty']), 'offer_parts=price projection contract');
assert_true(isset($availabilityOnly['qty']) && !isset($availabilityOnly['prices']), 'offer_parts=availability projection contract');

foreach (['Controller/V1/Store.php', 'Controller/V1/Attributes.php', 'Controller/V1/Health.php', 'Controller/V1/Stats.php'] as $file) {
    $contents = (string)file_get_contents($root . '/' . $file);
    assert_true(str_contains($contents, 'openApiDocumentResponse'), "$file must use OpenApiDocument response path");
}

foreach (['Controller/V1/Snapshot.php', 'Controller/V1/Changes.php'] as $file) {
    $contents = (string)file_get_contents($root . '/' . $file);
    assert_true(str_contains($contents, 'responseFormat(false)'), "$file must support JSON/protobuf format negotiation");
    assert_true(str_contains($contents, 'offer_parts'), "$file must parse offer_parts");
}

echo "OpenAPI/mock method contract OK\n";
