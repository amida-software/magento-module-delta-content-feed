<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\ResourceModel;

use DateTimeImmutable;
use Magento\Framework\App\ResourceConnection;

class ChangeLog
{
    private const TABLE = 'amida_product_delta_event';

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
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
    public function fetchChanges(string $stream, string $storeCode, int $afterEventId, int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->where('stream_code = ?', $stream)
            ->where('store_code = ?', $storeCode)
            ->where('event_id > ?', $afterEventId)
            ->order('event_id ASC')
            ->limit($limit);

        return $connection->fetchAll($select);
    }

    public function getLastEventId(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from($this->getTable(), 'MAX(event_id)');
        return (int)($connection->fetchOne($select) ?: 0);
    }

    public function getOldestRetainedEventId(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from($this->getTable(), 'MIN(event_id)');
        return (int)($connection->fetchOne($select) ?: 0);
    }

    public function deleteOlderThan(DateTimeImmutable $cutoff): int
    {
        return $this->resourceConnection->getConnection()->delete(
            $this->getTable(),
            ['created_at < ?' => $cutoff->format('Y-m-d H:i:s')]
        );
    }

    public function countByStream(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable(), ['stream_code', 'cnt' => 'COUNT(*)'])
            ->group('stream_code');
        return $connection->fetchPairs($select);
    }

    private function getTable(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
