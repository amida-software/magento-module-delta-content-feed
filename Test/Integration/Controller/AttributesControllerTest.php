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
        self::assertSame('attributes', $payload['entity']);
        self::assertSame('default', $payload['store_code']);
        self::assertIsArray($payload['items']);
    }
}
