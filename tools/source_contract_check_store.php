<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'Model/Store/StoreMetadataService.php',
    'Model/Store/AttributeDictionaryService.php',
    'Controller/V1/Store.php',
    'Controller/V1/Attributes.php',
];

$forbidden = [
    'CollectionFactory' => 'Store metadata/counts must not use Magento collections.',
    'getCollection(' => 'Store metadata/counts must not load collections.',
    'ProductRepositoryInterface' => 'Store metadata/counts must not load product repository.',
    'CategoryRepositoryInterface' => 'Store metadata/counts must not load category repository.',
    'curl_' => 'Default store metadata path must not call external network.',
    'GuzzleHttp' => 'Default store metadata path must not call external network.',
    'file_get_contents("http' => 'Default store metadata path must not call external network.',
    "file_get_contents('http" => 'Default store metadata path must not call external network.',
    'OpenAI' => 'Magento module must not generate descriptions via LLM.',
    'ChatGPT' => 'Magento module must not generate descriptions via LLM.',
    'LLM' => 'Magento module must not generate descriptions via LLM.',
];

foreach ($files as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: $file\n");
        exit(1);
    }
    $contents = file_get_contents($path);
    foreach ($forbidden as $needle => $message) {
        if (str_contains($contents, $needle)) {
            fwrite(STDERR, "Forbidden token '$needle' in $file: $message\n");
            exit(1);
        }
    }
}

$storeService = file_get_contents($root . '/Model/Store/StoreMetadataService.php');
if (!str_contains($storeService, "'source_map'")) {
    fwrite(STDERR, "Store endpoint must expose source_map only as a separate debug object.\n");
    exit(1);
}
if (!str_contains($storeService, 'sort_order') || !str_contains($storeService, '_source') || !str_contains($storeService, 'unset(')) {
    fwrite(STDERR, "Internal page source markers must be stripped before response.\n");
    exit(1);
}

$config = file_get_contents($root . '/etc/config.xml');
foreach (['endpoint_enabled', 'allow_include_sources', 'attributes_enabled'] as $needle) {
    if (!str_contains($config, $needle)) {
        fwrite(STDERR, "Missing config default: $needle\n");
        exit(1);
    }
}

echo "Store source contract OK\n";
