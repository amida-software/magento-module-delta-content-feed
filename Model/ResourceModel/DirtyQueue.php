<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

class DirtyQueue
{
    private const TABLE = 'amida_product_delta_dirty';

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    public function enqueue(int $productId, int $storeId, int $reasonFlags, ?string $sku = null): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->getTable(), [
            'entity_id' => $productId,
            'sku' => $sku,
            'store_id' => $storeId,
            'reason_flags' => $reasonFlags,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchBatch(int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->order('dirty_id ASC')
            ->limit($limit);

        return $connection->fetchAll($select);
    }

    /**
     * @param int[] $dirtyIds
     */
    public function deleteByIds(array $dirtyIds): void
    {
        if ($dirtyIds === []) {
            return;
        }
        $this->resourceConnection->getConnection()->delete($this->getTable(), ['dirty_id IN (?)' => $dirtyIds]);
    }

    /**
     * @param int[] $dirtyIds
     */
    public function markFailed(array $dirtyIds, string $error, int $maxAttempts = 5): void
    {
        if ($dirtyIds === []) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->getTable(),
            [
                'attempts' => new \Zend_Db_Expr('attempts + 1'),
                'last_error' => mb_substr($error, 0, 65000),
            ],
            ['dirty_id IN (?)' => $dirtyIds]
        );

        $select = $connection->select()
            ->from($this->getTable(), ['dirty_id'])
            ->where('dirty_id IN (?)', $dirtyIds)
            ->where('attempts >= ?', $maxAttempts);
        $toDelete = array_map('intval', $connection->fetchCol($select));
        if ($toDelete !== []) {
            $connection->delete($this->getTable(), ['dirty_id IN (?)' => $toDelete]);
        }
    }

    public function count(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from($this->getTable(), 'COUNT(*)');
        return (int)$connection->fetchOne($select);
    }

    private function getTable(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
