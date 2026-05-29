<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Integration\Controller;

/**
 * @magentoAppArea frontend
 */
class ChangesControllerTest extends AbstractFeedControllerTest
{
    public function testChangesEndpointReturnsProtobufPayload(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('amida_product_delta_event');
        $connection->insert($table, [
            'stream_code' => 'all',
            'origin_stream' => 'content',
            'entity_id' => 10,
            'sku' => 'sku-10',
            'store_code' => 'default',
            'event_type' => 'UPSERT_FULL',
            'schema_version' => 1,
            'payload_version' => 1,
            'changed_fields_json' => '["name"]',
            'payload_json' => '{"enabled":true,"attributes":[],"deleted":false}',
            'payload_hash' => 'hash',
        ]);

        $this->dispatch('amidafeed/v1/changes/key/integration-key/stream/all?store=default&after_event_id=0');
        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        self::assertSame('application/x-protobuf', (string)$this->getResponse()->getHeader('Content-Type')->getFieldValue());
        self::assertNotSame('', $this->getResponse()->getBody());
    }

    public function testChangesEndpointReturnsJsonWhenRequested(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('amida_product_delta_event');
        $connection->insert($table, [
            'stream_code' => 'all',
            'origin_stream' => 'content',
            'entity_id' => 11,
            'sku' => 'sku-11',
            'store_code' => 'default',
            'event_type' => 'UPSERT_FULL',
            'schema_version' => 1,
            'payload_version' => 1,
            'changed_fields_json' => '["name"]',
            'payload_json' => '{"enabled":true,"attributes":[],"deleted":false}',
            'payload_hash' => 'hash-json',
        ]);

        $this->dispatch('amidafeed/v1/changes/key/integration-key/stream/all?store=default&after_event_id=0&format=json');
        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        self::assertSame('application/json', (string)$this->getResponse()->getHeader('Content-Type')->getFieldValue());
        $payload = json_decode($this->getResponse()->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('all', $payload['stream']);
        self::assertArrayHasKey('changes', $payload);
    }
}
