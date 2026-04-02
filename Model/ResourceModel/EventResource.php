<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

class EventResource
{
    private const TABLE = 'amida_product_delta_event';

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    public function append(
        string $streamCode,
        string $op,
        int $productId,
        int $storeId,
        string $sku,
        bool $isEnabled,
        array $payload
    ): int {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $data = [
            'stream_code' => $streamCode,
            'origin_stream' => $streamCode,
            'entity_id' => $productId,
            'sku' => $sku,
            'store_code' => $this->resolveStoreCode($storeId, $payload),
            'event_type' => $this->mapLegacyOperationToEventType($op, $isEnabled),
            'schema_version' => 1,
            'payload_version' => 1,
            'changed_fields_json' => '[]',
            'payload_json' => $payloadJson,
            'payload_hash' => hash('sha256', $payloadJson),
            'source_updated_at' => ($payload['updated_at'] ?? '') !== '' ? (string)$payload['updated_at'] : null,
        ];
        $connection->insert($table, $data);
        return (int)$connection->lastInsertId($table);
    }

    public function fetchBatch(string $streamCode, int $afterEventId, int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $select = $connection->select()
            ->from($table, [
                'event_id',
                'payload_json',
                'payload_hash',
                'op' => new \Zend_Db_Expr("CASE event_type WHEN 'STATUS_ONLY' THEN 'disable_status_only' WHEN 'TOMBSTONE' THEN 'delete' WHEN 'UPSERT_FULL' THEN 'enable_full' ELSE 'upsert' END"),
            ])
            ->where('stream_code = ?', $streamCode)
            ->where('event_id > ?', $afterEventId)
            ->order('event_id ASC')
            ->limit($limit);
        return $connection->fetchAll($select);
    }

    private function resolveStoreCode(int $storeId, array $payload): string
    {
        if (isset($payload['store_code']) && $payload['store_code'] !== '') {
            return (string)$payload['store_code'];
        }
        if (isset($payload['store_id'])) {
            return (string)$payload['store_id'];
        }
        return (string)$storeId;
    }

    private function mapLegacyOperationToEventType(string $op, bool $isEnabled): string
    {
        return match ($op) {
            'delete' => 'TOMBSTONE',
            'disable_status_only' => 'STATUS_ONLY',
            'enable_full' => 'UPSERT_FULL',
            default => $isEnabled ? 'UPSERT_PARTIAL' : 'STATUS_ONLY',
        };
    }
}
