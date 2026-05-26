<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Category;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Direct SQL category dictionary reader.
 */
class CategoryStateBuilder
{
    /** @var array<string, int> */
    private array $attributeIds = [];

    /** @var array<string, string> */
    private array $linkFields = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildState(int $categoryId, string $storeCode): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_category_entity');
        $row = $connection->fetchRow(
            $connection->select()
                ->from($table)
                ->where('entity_id = ?', $categoryId)
                ->limit(1)
        );
        if (!is_array($row) || empty($row['entity_id'])) {
            return null;
        }

        return $this->buildFromRow($row, $storeCode);
    }

    /**
     * @return int[]
     */
    public function getVisibleCategoryIdsForStore(string $storeCode): array
    {
        $connection = $this->resourceConnection->getConnection();
        $store = $this->storeManager->getStore($storeCode);
        $rootId = (int)$store->getRootCategoryId();
        $table = $this->resourceConnection->getTableName('catalog_category_entity');
        $rootPath = '1/' . $rootId . '/%';
        $select = $connection->select()
            ->from($table, ['entity_id'])
            ->where('(entity_id = ' . $rootId . ' OR path LIKE ' . $connection->quote($rootPath) . ')')
            ->order(['level ASC', 'position ASC', 'entity_id ASC']);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildFromRow(array $row, string $storeCode): array
    {
        $store = $this->storeManager->getStore($storeCode);
        $storeId = (int)$store->getId();
        $linkId = $this->getCategoryLinkValue($row);
        $categoryId = (int)$row['entity_id'];

        $name = (string)($this->getCategoryValue('catalog_category_entity_varchar', $linkId, 'name', $storeId) ?? '');
        $urlKey = (string)($this->getCategoryValue('catalog_category_entity_varchar', $linkId, 'url_key', $storeId) ?? '');
        $urlPath = (string)($this->getCategoryValue('catalog_category_entity_varchar', $linkId, 'url_path', $storeId) ?? '');
        $description = (string)($this->getCategoryValue('catalog_category_entity_text', $linkId, 'description', $storeId) ?? '');
        $metaTitle = (string)($this->getCategoryValue('catalog_category_entity_varchar', $linkId, 'meta_title', $storeId) ?? '');
        $metaDescription = (string)($this->getCategoryValue('catalog_category_entity_text', $linkId, 'meta_description', $storeId) ?? '');
        $isActive = $this->getCategoryValue('catalog_category_entity_int', $linkId, 'is_active', $storeId);
        $includeInMenu = $this->getCategoryValue('catalog_category_entity_int', $linkId, 'include_in_menu', $storeId);
        $enabled = $isActive === null || (int)$isActive === 1;
        $baseUrl = rtrim((string)$store->getBaseUrl(), '/');
        $url = $this->buildUrl($baseUrl, $urlPath !== '' ? $urlPath : $urlKey);

        return [
            'enabled' => $enabled,
            'deleted' => false,
            'category' => [
                'category_id' => $categoryId,
                'external_id' => (string)$categoryId,
                'enabled' => $enabled,
                'store_code' => $storeCode,
                'parent_id' => (int)($row['parent_id'] ?? 0) ?: null,
                'path' => (string)($row['path'] ?? ''),
                'level' => (int)($row['level'] ?? 0),
                'position' => (int)($row['position'] ?? 0),
                'url_key' => $urlKey,
                'url_path' => $urlPath,
                'url' => $url,
                'name' => $name,
                'title' => $metaTitle !== '' ? $metaTitle : $name,
                'description' => $description,
                'meta_title' => $metaTitle,
                'meta_description' => $metaDescription,
                'include_in_menu' => $includeInMenu !== null ? (bool)(int)$includeInMenu : true,
                'source_updated_at' => (string)($row['updated_at'] ?? ''),
            ],
        ];
    }

    private function buildUrl(string $baseUrl, string $path): string
    {
        $path = trim($path, '/');
        if ($path === '') {
            return $baseUrl;
        }
        return $baseUrl . '/' . $path;
    }

    private function getCategoryValue(string $tableName, int $linkId, string $attributeCode, int $storeId): mixed
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName($tableName);
        $attributeId = $this->getCategoryAttributeId($attributeCode);
        if ($attributeId <= 0 || !$this->tableExists($table)) {
            return null;
        }

        $linkField = $this->getLinkField($table);
        $select = $connection->select()
            ->from($table, ['store_id', 'value'])
            ->where($linkField . ' = ?', $linkId)
            ->where('attribute_id = ?', $attributeId)
            ->where('store_id IN (?)', array_values(array_unique([0, $storeId])))
            ->order('store_id ASC');

        $default = null;
        $storeValue = null;
        foreach ($connection->fetchAll($select) as $valueRow) {
            if ((int)$valueRow['store_id'] === 0) {
                $default = $valueRow['value'];
            } elseif ((int)$valueRow['store_id'] === $storeId) {
                $storeValue = $valueRow['value'];
            }
        }

        return $storeValue !== null ? $storeValue : $default;
    }

    private function getCategoryAttributeId(string $attributeCode): int
    {
        if (!array_key_exists($attributeCode, $this->attributeIds)) {
            try {
                $this->attributeIds[$attributeCode] = (int)$this->eavConfig
                    ->getAttribute(Category::ENTITY, $attributeCode)
                    ->getAttributeId();
            } catch (\Throwable) {
                $this->attributeIds[$attributeCode] = 0;
            }
        }
        return $this->attributeIds[$attributeCode];
    }

    /** @param array<string, mixed> $category */
    private function getCategoryLinkValue(array $category): int
    {
        $table = $this->resourceConnection->getTableName('catalog_category_entity');
        $linkField = $this->getLinkField($table);
        return isset($category[$linkField]) ? (int)$category[$linkField] : (int)$category['entity_id'];
    }

    private function getLinkField(string $table): string
    {
        if (!isset($this->linkFields[$table])) {
            $columns = [];
            try {
                $columns = $this->resourceConnection->getConnection()->describeTable($table);
            } catch (\Throwable) {
                // fallback below
            }
            $this->linkFields[$table] = isset($columns['row_id']) ? 'row_id' : 'entity_id';
        }
        return $this->linkFields[$table];
    }

    private function tableExists(string $table): bool
    {
        try {
            return (bool)$this->resourceConnection->getConnection()->isTableExists($table);
        } catch (\Throwable) {
            return true;
        }
    }
}
