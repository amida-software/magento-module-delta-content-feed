<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\State\Curated;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Store\Model\StoreManagerInterface;

class CategoryProvider
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCategories(int $productId, string $storeCode): array
    {
        $connection = $this->resourceConnection->getConnection();
        $store = $this->storeManager->getStore($storeCode);
        $storeId = (int)$store->getId();
        $rootCategoryId = (int)$store->getRootCategoryId();
        $nameAttributeId = (int)$this->eavConfig->getAttribute(Category::ENTITY, 'name')->getAttributeId();
        $urlKeyAttributeId = (int)$this->eavConfig->getAttribute(Category::ENTITY, 'url_key')->getAttributeId();

        $select = $connection->select()
            ->from(['cp' => $this->resourceConnection->getTableName('catalog_category_product')], [
                'category_id',
                'position',
            ])
            ->join(
                ['c' => $this->resourceConnection->getTableName('catalog_category_entity')],
                'c.entity_id = cp.category_id',
                ['path_ids' => 'path', 'level']
            )
            ->joinLeft(
                ['name_default' => $this->resourceConnection->getTableName('catalog_category_entity_varchar')],
                'name_default.entity_id = cp.category_id AND name_default.attribute_id = ' . $nameAttributeId . ' AND name_default.store_id = 0',
                []
            )
            ->joinLeft(
                ['name_store' => $this->resourceConnection->getTableName('catalog_category_entity_varchar')],
                'name_store.entity_id = cp.category_id AND name_store.attribute_id = ' . $nameAttributeId . ' AND name_store.store_id = ' . $storeId,
                []
            )
            ->joinLeft(
                ['url_default' => $this->resourceConnection->getTableName('catalog_category_entity_varchar')],
                'url_default.entity_id = cp.category_id AND url_default.attribute_id = ' . $urlKeyAttributeId . ' AND url_default.store_id = 0',
                []
            )
            ->joinLeft(
                ['url_store' => $this->resourceConnection->getTableName('catalog_category_entity_varchar')],
                'url_store.entity_id = cp.category_id AND url_store.attribute_id = ' . $urlKeyAttributeId . ' AND url_store.store_id = ' . $storeId,
                []
            )
            ->columns([
                'name' => new Expression('COALESCE(name_store.value, name_default.value, \'\')'),
                'url_key' => new Expression('COALESCE(url_store.value, url_default.value, \'\')'),
            ])
            ->where('cp.product_id = ?', $productId)
            ->order(['c.level ASC', 'cp.position ASC', 'cp.category_id ASC']);

        $rows = $connection->fetchAll($select);
        if ($rows === []) {
            return [];
        }

        $categoryNames = $this->fetchCategoryNames($rows, $storeId, $nameAttributeId);
        $categories = [];
        foreach ($rows as $row) {
            $path = $this->pathNames((string)$row['path_ids'], $categoryNames, $rootCategoryId);
            $categories[] = [
                'category_id' => (int)$row['category_id'],
                'name' => (string)($row['name'] ?? ''),
                'url_key' => (string)($row['url_key'] ?? ''),
                'path' => $path,
                'position' => (int)$row['position'],
            ];
        }

        return $categories;
    }

    /**
     * @param array<int, array<string, mixed>> $categoryRows
     * @return array<int, string>
     */
    private function fetchCategoryNames(array $categoryRows, int $storeId, int $nameAttributeId): array
    {
        $ids = [];
        foreach ($categoryRows as $row) {
            foreach (explode('/', (string)$row['path_ids']) as $id) {
                $id = (int)$id;
                if ($id > 1) {
                    $ids[] = $id;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['c' => $this->resourceConnection->getTableName('catalog_category_entity')], ['entity_id'])
            ->joinLeft(
                ['name_default' => $this->resourceConnection->getTableName('catalog_category_entity_varchar')],
                'name_default.entity_id = c.entity_id AND name_default.attribute_id = ' . $nameAttributeId . ' AND name_default.store_id = 0',
                []
            )
            ->joinLeft(
                ['name_store' => $this->resourceConnection->getTableName('catalog_category_entity_varchar')],
                'name_store.entity_id = c.entity_id AND name_store.attribute_id = ' . $nameAttributeId . ' AND name_store.store_id = ' . $storeId,
                []
            )
            ->columns(['name' => new Expression('COALESCE(name_store.value, name_default.value, \'\')')])
            ->where('c.entity_id IN (?)', $ids);

        $names = [];
        foreach ($connection->fetchAll($select) as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '') {
                $names[(int)$row['entity_id']] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<int, string> $categoryNames
     * @return string[]
     */
    private function pathNames(string $pathIds, array $categoryNames, int $rootCategoryId): array
    {
        $path = [];
        foreach (explode('/', $pathIds) as $id) {
            $id = (int)$id;
            if ($id <= 1 || $id === $rootCategoryId) {
                continue;
            }
            if (!isset($categoryNames[$id])) {
                continue;
            }
            $path[] = $categoryNames[$id];
        }

        return $path;
    }
}
