<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Zend_Db_Expr;

class SnapshotResource
{
    private const TABLE = 'amida_product_delta_state';

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    public function upsert(string $streamCode, int $productId, int $storeId, string $sku, bool $isEnabled, array $payload): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $payload = $this->canonicalize($payload);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $payloadHash = hash('sha256', $payloadJson);
        $storeCode = $this->resolveStoreCode($storeId, $payload);

        $select = $connection->select()
            ->from($table)
            ->where('stream_code = ?', $streamCode)
            ->where('entity_id = ?', $productId)
            ->where('store_code = ?', $storeCode)
            ->limit(1);
        $existing = $connection->fetchRow($select) ?: null;

        $data = [
            'stream_code' => $streamCode,
            'entity_id' => $productId,
            'store_code' => $storeCode,
            'sku' => $sku,
            'is_enabled' => $isEnabled ? 1 : 0,
            'state_json' => $payloadJson,
            'state_hash' => $payloadHash,
        ];

        if ($existing !== null) {
            $changed = $existing['state_hash'] !== $payloadHash
                || (int)$existing['is_enabled'] !== ($isEnabled ? 1 : 0)
                || $existing['sku'] !== $sku;

            if ($changed) {
                $connection->update($table, $data, ['state_id = ?' => (int)$existing['state_id']]);
            }

            return [
                'exists' => true,
                'changed' => $changed,
                'old_enabled' => (bool)$existing['is_enabled'],
                'old_hash' => (string)$existing['state_hash'],
                'payload_json' => $payloadJson,
                'payload_hash' => $payloadHash,
            ];
        }

        $connection->insert($table, $data);
        return [
            'exists' => false,
            'changed' => true,
            'old_enabled' => null,
            'old_hash' => null,
            'payload_json' => $payloadJson,
            'payload_hash' => $payloadHash,
        ];
    }

    public function fetchSnapshotBatch(string $streamCode, int $afterRowId, int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $select = $connection->select()
            ->from($table, [
                'row_id' => 'state_id',
                'product_id' => 'entity_id',
                'store_id' => new Zend_Db_Expr('0'),
                'sku',
                'is_enabled',
                'entity_updated_at' => 'updated_at',
                'payload_json' => 'state_json',
                'payload_hash' => 'state_hash',
                'stream_code',
                'store_code',
            ])
            ->where('stream_code = ?', $streamCode)
            ->where('state_id > ?', $afterRowId)
            ->where('is_enabled = 1')
            ->order('state_id ASC')
            ->limit($limit);
        return $connection->fetchAll($select);
    }

    public function fetchByProductId(int $productId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $select = $connection->select()
            ->from($table, [
                'row_id' => 'state_id',
                'product_id' => 'entity_id',
                'store_id' => new Zend_Db_Expr('0'),
                'sku',
                'is_enabled',
                'entity_updated_at' => 'updated_at',
                'payload_json' => 'state_json',
                'payload_hash' => 'state_hash',
                'stream_code',
                'store_code',
            ])
            ->where('entity_id = ?', $productId);
        return $connection->fetchAll($select);
    }

    public function fetchBySku(string $sku): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $select = $connection->select()
            ->from($table, [
                'row_id' => 'state_id',
                'product_id' => 'entity_id',
                'store_id' => new Zend_Db_Expr('0'),
                'sku',
                'is_enabled',
                'entity_updated_at' => 'updated_at',
                'payload_json' => 'state_json',
                'payload_hash' => 'state_hash',
                'stream_code',
                'store_code',
            ])
            ->where('sku = ?', $sku);
        return $connection->fetchAll($select);
    }

    public function deleteByProductId(int $productId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $connection->delete($table, ['entity_id = ?' => $productId]);
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

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
