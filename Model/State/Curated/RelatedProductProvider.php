<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\State\Curated;

use Magento\Framework\App\ResourceConnection;

class RelatedProductProvider
{
    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRelatedProducts(int $productId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['l' => $this->resourceConnection->getTableName('catalog_product_link')], [
                'product_id' => 'linked_product_id',
            ])
            ->join(
                ['lt' => $this->resourceConnection->getTableName('catalog_product_link_type')],
                'lt.link_type_id = l.link_type_id',
                ['relation' => 'code']
            )
            ->joinLeft(
                ['pla' => $this->resourceConnection->getTableName('catalog_product_link_attribute')],
                'pla.link_type_id = l.link_type_id AND pla.product_link_attribute_code = \'position\'',
                []
            )
            ->joinLeft(
                ['pos' => $this->resourceConnection->getTableName('catalog_product_link_attribute_int')],
                'pos.link_id = l.link_id AND pos.product_link_attribute_id = pla.product_link_attribute_id',
                ['position' => 'value']
            )
            ->join(
                ['p' => $this->resourceConnection->getTableName('catalog_product_entity')],
                'p.entity_id = l.linked_product_id',
                ['sku', 'type_id']
            )
            ->where('l.product_id = ?', $productId)
            ->order(['lt.code ASC', 'pos.value ASC', 'l.linked_product_id ASC']);

        $relatedProducts = [];
        foreach ($connection->fetchAll($select) as $row) {
            $relatedProducts[] = [
                'relation' => (string)$row['relation'],
                'product_id' => (int)$row['product_id'],
                'sku' => (string)$row['sku'],
                'type_id' => (string)$row['type_id'],
                'position' => (int)$row['position'],
            ];
        }

        return $relatedProducts;
    }
}
