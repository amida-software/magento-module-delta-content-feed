<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$servicePath = $root . '/Model/Store/AttributeDictionaryService.php';
$specPath = $root . '/docs/SPEC_STORE_ENDPOINT.md';
$testingPath = $root . '/docs/TESTING_STORE_ENDPOINT.md';

foreach ([$servicePath, $specPath, $testingPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing file: $path\n");
        exit(1);
    }
}

$service = file_get_contents($servicePath);
$requiredServiceTokens = [
    "'product_types'" => 'Attributes response must expose used product types.',
    "'attribute_sets'" => 'Attributes response must expose the used attribute-set/group tree.',
    'eav_entity_attribute' => 'Attributes must be filtered to assigned attribute sets/groups.',
    'eav_attribute_group' => 'Attribute set tree must include groups.',
    'loadValuedAttributeIds' => 'Attributes must be filtered to attributes with non-empty product values.',
    'catalog_product_entity_' => 'Value filtering must inspect product EAV value tables.',
    'catalog_product_website' => 'Product type/set/value filters must be scoped to the store website.',
    "'labels'" => 'Attribute and option payloads must include store-code label maps.',
    "'admin_label'" => 'Admin labels must be emitted separately when they differ from localized labels.',
    "'attribute_codes'" => 'Product type and attribute-set trees must expose attribute code lists.',
    "'apply_to'" => 'Product type attribute lists must be derived from catalog_eav_attribute.apply_to.',
];
foreach ($requiredServiceTokens as $needle => $message) {
    if (!str_contains($service, $needle)) {
        fwrite(STDERR, "Missing service contract token '$needle': $message\n");
        exit(1);
    }
}

$spec = file_get_contents($specPath);
foreach (['product_types', 'attribute_sets', 'labels', 'admin_label', 'attribute_codes', 'attribute_ids', 'load_options', 'schema=v1', 'schema_version', 'non-empty product value', 'Railway'] as $needle) {
    if (!str_contains($spec, $needle)) {
        fwrite(STDERR, "SPEC_STORE_ENDPOINT.md must document $needle for attributes.\n");
        exit(1);
    }
}

$testing = file_get_contents($testingPath);
foreach (['before/after timing', 'Railway', 'attribute_sets', 'product_types', 'load_options=0', 'format=json'] as $needle) {
    if (!str_contains($testing, $needle)) {
        fwrite(STDERR, "TESTING_STORE_ENDPOINT.md must document $needle validation.\n");
        exit(1);
    }
}

echo "Attribute dictionary contract OK\n";
