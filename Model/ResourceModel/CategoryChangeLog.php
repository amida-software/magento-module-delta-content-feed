<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\ResourceModel;

use DateTimeImmutable;
use Magento\Framework\App\ResourceConnection;

class CategoryChangeLog
{
    private const TABLE = 'amida_product_delta_category_event';

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function insertMany(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->resourceConnection->getConnection()->insertMultiple($this->getTable(), $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param int[] $categoryIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchChanges(
        string $storeCode,
        int $afterEventId,
        int $limit,
        ?string $changedFrom = null,
        ?string $changedTo = null,
        array $categoryIds = []
    ): array {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->where('store_code = ?', $storeCode)
            ->where('event_id > ?', $afterEventId)
            ->order('event_id ASC')
            ->limit($limit);

        if ($changedFrom !== null && $changedFrom !== '') {
            $select->where('created_at >= ?', $changedFrom);
        }
        if ($changedTo !== null && $changedTo !== '') {
            $select->where('created_at < ?', $changedTo);
        }
        $categoryIds = array_values(array_filter(array_unique(array_map('intval', $categoryIds)), static fn (int $id): bool => $id > 0));
        if ($categoryIds !== []) {
            $select->where('category_id IN (?)', $categoryIds);
        }

        return $connection->fetchAll($select);
    }

    public function getLastEventId(): int
    {
        return (int)($this->resourceConnection->getConnection()->fetchOne(
            $this->resourceConnection->getConnection()->select()->from($this->getTable(), 'MAX(event_id)')
        ) ?: 0);
    }

    public function getOldestRetainedEventId(): int
    {
        return (int)($this->resourceConnection->getConnection()->fetchOne(
            $this->resourceConnection->getConnection()->select()->from($this->getTable(), 'MIN(event_id)')
        ) ?: 0);
    }

    public function deleteOlderThan(DateTimeImmutable $cutoff): int
    {
        return $this->resourceConnection->getConnection()->delete(
            $this->getTable(),
            ['created_at < ?' => $cutoff->format('Y-m-d H:i:s')]
        );
    }

    public function count(): int
    {
        return (int)$this->resourceConnection->getConnection()->fetchOne(
            $this->resourceConnection->getConnection()->select()->from($this->getTable(), 'COUNT(*)')
        );
    }

    private function getTable(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
