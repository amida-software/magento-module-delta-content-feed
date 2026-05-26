<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Integration\Controller;

use Amida\ProductDeltaFeed\Model\Config;

/**
 * @magentoAppArea frontend
 */
class StoreControllerTest extends AbstractFeedControllerTest
{
    public function testStoreEndpointReturnsJsonPassport(): void
    {
        $this->configWriter->save(Config::XML_PATH_STORE_ENDPOINT_ENABLED, 1);
        $this->configWriter->save(Config::XML_PATH_STORE_DESCRIPTION_OVERRIDE, 'Demo <b>description</b>');
        $this->configWriter->save(Config::XML_PATH_STORE_ALLOW_INCLUDE_SOURCES, 1);
        $this->cacheTypeList->cleanType('config');

        $this->dispatch('amidafeed/v1/store/key/integration-key?store=default&include_counts=0&include_sitemap=0&include_pages=0&include_sources=1');
        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        self::assertStringContainsString('application/json', (string)$this->getResponse()->getHeader('Content-Type')->getFieldValue());
        $payload = json_decode($this->getResponse()->getBody(), true);
        self::assertSame('store', $payload['entity']);
        self::assertArrayHasKey('languages', $payload);
        self::assertSame('Demo description', $payload['store']['description']);
        self::assertArrayHasKey('source_map', $payload);
    }
}
