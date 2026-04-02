<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

class StateSnapshot
{
    private const TABLE = 'amida_product_delta_state';

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getByProductAndStore(int $productId, string $storeCode): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->where('entity_id = ?', $productId)
            ->where('store_code = ?', $storeCode);

        $rows = $connection->fetchAll($select);
        $result = [];
        foreach ($rows as $row) {
            $row['state'] = json_decode((string)$row['state_json'], true) ?: [];
            $result[(string)$row['stream_code']] = $row;
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function upsertMany(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->resourceConnection->getConnection()->insertOnDuplicate(
            $this->getTable(),
            $rows,
            ['sku', 'is_enabled', 'state_hash', 'state_json']
        );
    }

    public function deleteProduct(int $productId): void
    {
        $this->resourceConnection->getConnection()->delete($this->getTable(), ['entity_id = ?' => $productId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchSnapshotRows(string $stream, string $storeCode, int $afterStateId, int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->where('stream_code = ?', $stream)
            ->where('store_code = ?', $storeCode)
            ->where('state_id > ?', $afterStateId)
            ->order('state_id ASC')
            ->limit($limit);

        return $connection->fetchAll($select);
    }

    public function count(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from($this->getTable(), 'COUNT(*)');
        return (int)$connection->fetchOne($select);
    }

    public function truncate(): void
    {
        $this->resourceConnection->getConnection()->truncateTable($this->getTable());
    }

    private function getTable(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
