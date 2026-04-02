<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Integration\Controller;

/**
 * @magentoAppArea frontend
 */
class HealthControllerTest extends AbstractFeedControllerTest
{
    public function testHealthEndpointReturnsJson(): void
    {
        $this->dispatch('amidafeed/v1/health/key/integration-key');
        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        self::assertStringContainsString('application/json', (string)$this->getResponse()->getHeader('Content-Type')->getFieldValue());
        self::assertStringContainsString('module_enabled', $this->getResponse()->getBody());
    }
}
