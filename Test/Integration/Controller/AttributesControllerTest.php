<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Integration\Controller;

use Amida\ProductDeltaFeed\Model\Config;

/**
 * @magentoAppArea frontend
 */
class AttributesControllerTest extends AbstractFeedControllerTest
{
    public function testAttributesEndpointReturnsDictionaryJson(): void
    {
        $this->configWriter->save('amida_productdeltafeed/streams/attributes_enabled', 1);
        $this->cacheTypeList->cleanType('config');

        // all=1 bypasses the admin include/exclude filter so 'name' is present regardless of config.
        $this->dispatch('amidafeed/v1/attributes/key/integration-key?store=default&codes=name&all=1');
        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        self::assertStringContainsString('application/json', (string)$this->getResponse()->getHeader('Content-Type')->getFieldValue());
        $payload = json_decode($this->getResponse()->getBody(), true);
        self::assertSame(2, $payload['schema_version']);
        self::assertSame('attributes', $payload['entity']);
        self::assertSame('default', $payload['store_code']);
        self::assertArrayHasKey('attributes', $payload);
        self::assertArrayNotHasKey('items', $payload);
        self::assertIsArray($payload['attributes']);
        // The attributes map is keyed by attribute code (not numeric id).
        foreach ($payload['attributes'] as $code => $attribute) {
            self::assertIsString($code);
            self::assertDoesNotMatchRegularExpression('/^\d+$/', (string)$code);
            self::assertSame($code, $attribute['code'] ?? null);
            self::assertArrayNotHasKey('id', $attribute);
            self::assertArrayHasKey('label', $attribute);
            self::assertArrayHasKey('labels', $attribute);
            self::assertArrayNotHasKey('is_visible', $attribute);
            self::assertArrayNotHasKey('is_visible_on_front', $attribute);
            self::assertArrayNotHasKey('is_required', $attribute);
            self::assertArrayNotHasKey('admin', $attribute['labels']);
            self::assertArrayNotHasKey('product_types', $attribute);
            self::assertArrayNotHasKey('attribute_set_ids', $attribute);
            self::assertArrayNotHasKey('attribute_groups', $attribute);
        }
        foreach ($payload['product_types'] as $type) {
            self::assertArrayHasKey('code', $type);
            self::assertArrayHasKey('attribute_codes', $type);
            self::assertArrayNotHasKey('attribute_ids', $type);
            self::assertArrayNotHasKey('product_count', $type);
            foreach ($type['attribute_codes'] as $attributeCode) {
                self::assertIsString($attributeCode);
                self::assertArrayHasKey($attributeCode, $payload['attributes']);
            }
        }
        foreach ($payload['attribute_sets'] as $set) {
            self::assertArrayHasKey('groups', $set);
            self::assertArrayNotHasKey('product_count', $set);
            foreach ($set['groups'] as $group) {
                self::assertArrayHasKey('attribute_codes', $group);
                self::assertArrayNotHasKey('attribute_ids', $group);
                self::assertArrayNotHasKey('attributes', $group);
                foreach ($group['attribute_codes'] as $attributeCode) {
                    self::assertIsString($attributeCode);
                    self::assertArrayHasKey($attributeCode, $payload['attributes']);
                }
            }
        }
    }

    public function testAttributesEndpointAllFlagBypassesConfigFilter(): void
    {
        $this->configWriter->save('amida_productdeltafeed/streams/attributes_enabled', 1);
        // Exclude 'name' via the content config: the default (filtered) view must drop it,
        // while ?all=1 must still include it.
        $this->configWriter->save('amida_productdeltafeed/content/include_attributes', '');
        $this->configWriter->save('amida_productdeltafeed/content/exclude_attributes', 'name');
        $this->cacheTypeList->cleanType('config');

        $this->dispatch('amidafeed/v1/attributes/key/integration-key?store=default&all=1');
        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        $all = json_decode($this->getResponse()->getBody(), true)['attributes'] ?? [];
        self::assertArrayHasKey('name', $all, 'all=1 must bypass the exclude_attributes filter');
    }

    public function testAttributesEndpointKeepsItemsOnlyForExplicitSchemaV1(): void
    {
        $this->configWriter->save('amida_productdeltafeed/streams/attributes_enabled', 1);
        $this->cacheTypeList->cleanType('config');

        $this->dispatch('amidafeed/v1/attributes/key/integration-key?store=default&codes=name&schema=v1');
        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        $payload = json_decode($this->getResponse()->getBody(), true);
        self::assertSame(1, $payload['schema_version']);
        self::assertArrayHasKey('items', $payload);
    }

    public function testAttributesEndpointCanDisableOptions(): void
    {
        $this->configWriter->save('amida_productdeltafeed/streams/attributes_enabled', 1);
        $this->cacheTypeList->cleanType('config');

        $this->dispatch('amidafeed/v1/attributes/key/integration-key?store=default&load_options=0');
        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        $payload = json_decode($this->getResponse()->getBody(), true);
        $selectableCount = 0;
        $withOptionsCount = 0;
        foreach ($payload['attributes'] as $attribute) {
            self::assertArrayNotHasKey('options', $attribute);
            if (in_array($attribute['kind'], ['select', 'multiselect', 'boolean'], true)) {
                $selectableCount++;
                if (array_key_exists('options_count', $attribute)) {
                    self::assertIsInt($attribute['options_count']);
                    self::assertGreaterThan(0, $attribute['options_count']);
                    $withOptionsCount++;
                }
            }
        }
        if ($selectableCount > 0) {
            self::assertGreaterThan(0, $withOptionsCount);
        }
    }

    public function testAttributesEndpointCanDisableOptionsWithJsonBooleanFalse(): void
    {
        $this->configWriter->save('amida_productdeltafeed/streams/attributes_enabled', 1);
        $this->cacheTypeList->cleanType('config');

        $this->getRequest()->setMethod('POST');
        $this->getRequest()->setContent(json_encode(['load_options' => false], JSON_THROW_ON_ERROR));
        $this->dispatch('amidafeed/v1/attributes/key/integration-key?store=default');

        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        $payload = json_decode($this->getResponse()->getBody(), true);
        foreach ($payload['attributes'] as $attribute) {
            self::assertArrayNotHasKey('options', $attribute);
        }
    }

    public function testSnapshotAttributesEndpointCanDisableOptions(): void
    {
        $this->configWriter->save('amida_productdeltafeed/streams/attributes_enabled', 1);
        $this->cacheTypeList->cleanType('config');

        $this->dispatch('amidafeed/v1/snapshot/key/integration-key/stream/attributes?store=default&load_options=0&format=json');
        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        $payload = json_decode($this->getResponse()->getBody(), true);
        self::assertSame(2, $payload['schema_version']);
        self::assertArrayNotHasKey('items', $payload);
        foreach ($payload['attributes'] as $attribute) {
            self::assertArrayNotHasKey('options', $attribute);
            self::assertArrayNotHasKey('product_types', $attribute);
            self::assertArrayNotHasKey('attribute_set_ids', $attribute);
            self::assertArrayNotHasKey('attribute_groups', $attribute);
            if (array_key_exists('options_count', $attribute)) {
                self::assertIsInt($attribute['options_count']);
                self::assertGreaterThan(0, $attribute['options_count']);
            }
        }
    }
}
