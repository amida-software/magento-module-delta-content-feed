<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

class CategoryStateSnapshot
{
    private const TABLE = 'amida_product_delta_category_state';

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function upsertMany(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->resourceConnection->getConnection()->insertOnDuplicate(
            $this->getTable(),
            $rows,
            ['parent_id', 'is_enabled', 'state_hash', 'state_json']
        );
    }

    /** @return array<string, mixed>|null */
    public function getByCategoryAndStore(int $categoryId, string $storeCode): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->where('category_id = ?', $categoryId)
            ->where('store_code = ?', $storeCode)
            ->limit(1);
        $row = $connection->fetchRow($select);
        if (!is_array($row)) {
            return null;
        }
        $row['state'] = json_decode((string)$row['state_json'], true) ?: [];
        return $row;
    }

    public function deleteCategory(int $categoryId): void
    {
        $this->resourceConnection->getConnection()->delete($this->getTable(), ['category_id = ?' => $categoryId]);
    }

    /**
     * @param int[] $categoryIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchSnapshotRows(string $storeCode, int $afterStateId, int $limit, array $categoryIds = []): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->where('store_code = ?', $storeCode)
            ->where('state_id > ?', $afterStateId)
            ->order('state_id ASC')
            ->limit($limit);
        $categoryIds = array_values(array_filter(array_unique(array_map('intval', $categoryIds)), static fn (int $id): bool => $id > 0));
        if ($categoryIds !== []) {
            $select->where('category_id IN (?)', $categoryIds);
        }
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
