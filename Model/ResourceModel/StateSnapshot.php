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
     * @param string[] $skus
     * @return array<int, array<string, mixed>>
     */
    public function fetchSnapshotRows(string $stream, string $storeCode, int $afterStateId, int $limit, array $skus = []): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->where('stream_code = ?', $stream)
            ->where('store_code = ?', $storeCode)
            ->where('state_id > ?', $afterStateId)
            ->order('state_id ASC')
            ->limit($limit);
        $skus = $this->normalizeSkus($skus);
        if ($skus !== []) {
            $select->where('sku IN (?)', $skus);
        }

        return $connection->fetchAll($select);
    }

    /**
     * @param string[] $skus
     * @return array<string, array<string, mixed>>
     */
    public function fetchStateMapBySkus(string $stream, string $storeCode, array $skus): array
    {
        $skus = $this->normalizeSkus($skus);
        if ($skus === []) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->where('stream_code = ?', $stream)
            ->where('store_code = ?', $storeCode)
            ->where('sku IN (?)', $skus);
        $rows = $connection->fetchAll($select);
        $result = [];
        foreach ($rows as $row) {
            $row['state'] = json_decode((string)$row['state_json'], true) ?: [];
            $result[(string)$row['sku']] = $row;
        }
        return $result;
    }

    /**
     * @param string[] $skus
     * @return array<int, array<string, mixed>>
     */
    public function fetchSnapshotRowsBySkus(string $stream, string $storeCode, array $skus, int $limit): array
    {
        $skus = $this->normalizeSkus($skus);
        if ($skus === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->where('stream_code = ?', $stream)
            ->where('store_code = ?', $storeCode)
            ->where('sku IN (?)', $skus)
            ->order('sku ASC')
            ->limit($limit);

        $rows = $connection->fetchAll($select);
        $rank = array_flip($skus);
        usort($rows, static function (array $left, array $right) use ($rank): int {
            return ($rank[(string)$left['sku']] ?? PHP_INT_MAX) <=> ($rank[(string)$right['sku']] ?? PHP_INT_MAX);
        });

        return $rows;
    }

    /**
     * @param string[] $skus
     * @return array<string, array<string, mixed>>
     */
    public function fetchStateRowsBySkus(string $stream, string $storeCode, array $skus): array
    {
        $rows = $this->fetchSnapshotRowsBySkus($stream, $storeCode, $skus, max(1, count($skus)));
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['sku']] = $row;
        }
        return $result;
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

    /**
     * @param string[] $skus
     * @return string[]
     */
    private function normalizeSkus(array $skus): array
    {
        $normalized = [];
        foreach ($skus as $sku) {
            $sku = trim((string)$sku);
            if ($sku !== '') {
                $normalized[] = $sku;
            }
        }
        return array_values(array_unique($normalized));
    }

    private function getTable(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
