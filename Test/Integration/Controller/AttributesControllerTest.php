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

        $this->dispatch('amidafeed/v1/attributes/key/integration-key?store=default&codes=name');
        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        self::assertStringContainsString('application/json', (string)$this->getResponse()->getHeader('Content-Type')->getFieldValue());
        $payload = json_decode($this->getResponse()->getBody(), true);
        self::assertSame(2, $payload['schema_version']);
        self::assertSame('attributes', $payload['entity']);
        self::assertSame('default', $payload['store_code']);
        self::assertArrayHasKey('attributes', $payload);
        self::assertArrayNotHasKey('items', $payload);
        self::assertIsArray($payload['attributes']);
        $attributeIds = array_keys($payload['attributes']);
        foreach ($attributeIds as $attributeId) {
            self::assertMatchesRegularExpression('/^\d+$/', (string)$attributeId);
        }
        foreach ($payload['attributes'] as $attribute) {
            self::assertArrayHasKey('id', $attribute);
            self::assertIsInt($attribute['id']);
            self::assertArrayHasKey('code', $attribute);
            self::assertArrayHasKey('label', $attribute);
            self::assertArrayHasKey('labels', $attribute);
            self::assertArrayNotHasKey('options_count', $attribute);
            self::assertArrayNotHasKey('admin', $attribute['labels']);
            self::assertArrayNotHasKey('product_types', $attribute);
            self::assertArrayNotHasKey('attribute_set_ids', $attribute);
            self::assertArrayNotHasKey('attribute_groups', $attribute);
        }
        foreach ($payload['product_types'] as $type) {
            self::assertArrayHasKey('code', $type);
            self::assertArrayHasKey('attribute_ids', $type);
            self::assertArrayNotHasKey('attribute_codes', $type);
            self::assertArrayNotHasKey('product_count', $type);
            foreach ($type['attribute_ids'] as $attributeId) {
                self::assertIsInt($attributeId);
                self::assertArrayHasKey((string)$attributeId, $payload['attributes']);
            }
        }
        foreach ($payload['attribute_sets'] as $set) {
            self::assertArrayHasKey('groups', $set);
            self::assertArrayNotHasKey('product_count', $set);
            foreach ($set['groups'] as $group) {
                self::assertArrayHasKey('attribute_ids', $group);
                self::assertArrayNotHasKey('attribute_codes', $group);
                self::assertArrayNotHasKey('attributes', $group);
                foreach ($group['attribute_ids'] as $attributeId) {
                    self::assertIsInt($attributeId);
                    self::assertArrayHasKey((string)$attributeId, $payload['attributes']);
                }
            }
        }
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
