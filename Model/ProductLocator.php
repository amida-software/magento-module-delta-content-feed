<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model;

use Magento\Framework\App\ResourceConnection;

class ProductLocator
{
    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    public function getIdBySku(string $sku): ?int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_entity');
        $select = $connection->select()
            ->from($table, ['entity_id'])
            ->where('sku = ?', $sku)
            ->limit(1);

        $result = $connection->fetchOne($select);
        return $result !== false ? (int)$result : null;
    }

    public function getSkuById(int $productId): ?string
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_entity');
        $select = $connection->select()
            ->from($table, ['sku'])
            ->where('entity_id = ?', $productId)
            ->limit(1);

        $result = $connection->fetchOne($select);
        return $result !== false ? (string)$result : null;
    }
}
