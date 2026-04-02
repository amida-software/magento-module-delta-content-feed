<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\ResourceModel;

use DateTimeImmutable;
use Magento\Framework\App\ResourceConnection;

class DeadLetter
{
    private const TABLE = 'amida_product_delta_dead_letter';

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    public function add(array $row): void
    {
        $this->resourceConnection->getConnection()->insert($this->getTable(), $row);
    }

    public function count(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from($this->getTable(), 'COUNT(*)');
        return (int)$connection->fetchOne($select);
    }

    public function deleteOlderThan(DateTimeImmutable $cutoff): int
    {
        return $this->resourceConnection->getConnection()->delete(
            $this->getTable(),
            ['created_at < ?' => $cutoff->format('Y-m-d H:i:s')]
        );
    }

    private function getTable(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
