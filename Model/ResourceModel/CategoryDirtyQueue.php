<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

class CategoryDirtyQueue
{
    private const TABLE = 'amida_product_delta_category_dirty';

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    public function enqueue(int $categoryId, int $storeId = 0, int $reasonFlags = 0, string $reasonCode = 'save'): void
    {
        if ($categoryId <= 0) {
            return;
        }
        $this->resourceConnection->getConnection()->insert($this->getTable(), [
            'category_id' => $categoryId,
            'store_id' => max(0, $storeId),
            'reason_flags' => $reasonFlags,
            'reason_code' => $reasonCode,
        ]);
    }

    public function markDirty(int $categoryId, int $storeId = 0, int $reasonFlags = 0, string $reasonCode = 'save'): void
    {
        $this->enqueue($categoryId, $storeId, $reasonFlags, $reasonCode);
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
        $dirtyIds = array_values(array_filter(array_map('intval', $dirtyIds), static fn (int $id): bool => $id > 0));
        if ($dirtyIds === []) {
            return;
        }
        $this->resourceConnection->getConnection()->delete($this->getTable(), ['dirty_id IN (?)' => $dirtyIds]);
    }

    /**
     * @param int[] $dirtyIds
     */
    public function markFailed(array $dirtyIds, string $error): void
    {
        $dirtyIds = array_values(array_filter(array_map('intval', $dirtyIds), static fn (int $id): bool => $id > 0));
        if ($dirtyIds === []) {
            return;
        }
        $this->resourceConnection->getConnection()->update(
            $this->getTable(),
            ['attempts' => new \Zend_Db_Expr('attempts + 1'), 'last_error' => mb_substr($error, 0, 65535)],
            ['dirty_id IN (?)' => $dirtyIds]
        );
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
