<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Integration\Controller;

/**
 * @magentoAppArea frontend
 */
class SnapshotControllerTest extends AbstractFeedControllerTest
{
    public function testSnapshotEndpointReturnsProtobufPayload(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('amida_product_delta_state');
        $connection->insert($table, [
            'entity_id' => 10,
            'sku' => 'sku-10',
            'store_code' => 'default',
            'stream_code' => 'content',
            'is_enabled' => 1,
            'state_hash' => 'hash',
            'state_json' => '{"enabled":true,"attributes":[],"deleted":false}',
        ]);

        $this->dispatch('amidafeed/v1/snapshot/key/integration-key/stream/content?store=default&after_state_id=0');
        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        self::assertSame('application/x-protobuf', (string)$this->getResponse()->getHeader('Content-Type')->getFieldValue());
        self::assertNotSame('', $this->getResponse()->getBody());
    }

    public function testSnapshotEndpointReturnsJsonWhenRequested(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('amida_product_delta_state');
        $connection->insert($table, [
            'entity_id' => 11,
            'sku' => 'sku-11',
            'store_code' => 'default',
            'stream_code' => 'content',
            'is_enabled' => 1,
            'state_hash' => 'hash-json',
            'state_json' => '{"enabled":true,"attributes":[],"deleted":false}',
        ]);

        $this->dispatch('amidafeed/v1/snapshot/key/integration-key/stream/content?store=default&after_state_id=0&format=json');
        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        self::assertSame('application/json', (string)$this->getResponse()->getHeader('Content-Type')->getFieldValue());
        $payload = json_decode($this->getResponse()->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('content', $payload['stream']);
        self::assertArrayHasKey('items', $payload);
    }
}
