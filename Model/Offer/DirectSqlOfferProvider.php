<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Offer;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds offer state by reading Magento source tables directly.
 *
 * This intentionally avoids price/stock index tables and avoids ProductRepository / Inventory APIs
 * on the hot offer path. The goal is to export the sellable SKU state from the same persisted
 * product/price/inventory data that Magento uses as source-of-truth before index projection.
 */
class DirectSqlOfferProvider
{
    /** @var array<string, int> */
    private array $attributeIds = [];

    /** @var array<string, string> */
    private array $linkFields = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly OfferMath $offerMath
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildByProductId(int $productId, string $storeCode, ?bool $enabled = null): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        $connection = $this->resourceConnection->getConnection();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $product = $connection->fetchRow(
            $connection->select()
                ->from($productTable)
                ->where('entity_id = ?', $productId)
                ->limit(1)
        );

        if (!is_array($product) || empty($product['sku'])) {
            return null;
        }

        return $this->buildFromProductRow($product, $storeCode, $enabled);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildBySku(string $sku, string $storeCode, ?bool $enabled = null): ?array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $connection = $this->resourceConnection->getConnection();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $product = $connection->fetchRow(
            $connection->select()
                ->from($productTable)
                ->where('sku = ?', $sku)
                ->limit(1)
        );

        if (!is_array($product) || empty($product['entity_id'])) {
            return null;
        }

        return $this->buildFromProductRow($product, $storeCode, $enabled);
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function buildFromProductRow(array $product, string $storeCode, ?bool $enabled): array
    {
        $store = $this->storeManager->getStore($storeCode);
        $storeId = (int)$store->getId();
        $websiteCode = (string)$store->getWebsite()->getCode();
        $currencyCode = (string)$store->getCurrentCurrencyCode();
        $productId = (int)$product['entity_id'];
        $sku = (string)$product['sku'];
        $typeId = (string)($product['type_id'] ?? '');
        $linkId = $this->getProductLinkValue($product);

        $price = $this->getProductDecimalValue($linkId, 'price', $storeId);
        $specialPrice = $this->getProductDecimalValue($linkId, 'special_price', $storeId);
        $specialFrom = $this->getProductDatetimeValue($linkId, 'special_from_date', $storeId);
        $specialTo = $this->getProductDatetimeValue($linkId, 'special_to_date', $storeId);
        $oldPrice = $price;
        $currentPrice = $this->offerMath->currentPrice($price, $specialPrice, $specialFrom, $specialTo);
        $stock = $this->buildInventoryState($productId, $sku, $websiteCode);
        $parent = $this->resolveParent($productId);
        $isEnabled = $enabled ?? $this->isProductEnabled($linkId, $storeId);

        return [
            'enabled' => $isEnabled,
            'deleted' => false,
            'offer' => [
                'product_id' => $productId,
                'sku' => $sku,
                'parent_product_id' => $parent['product_id'],
                'parent_sku' => $parent['sku'],
                'magento_type_id' => $typeId,
                'prices' => [
                    'old' => $oldPrice,
                    'current' => $currentPrice,
                    'currency' => $currencyCode,
                    'special_price' => $specialPrice,
                    'special_from_date' => $specialFrom,
                    'special_to_date' => $specialTo,
                    'source' => 'direct_sql_eav',
                ],
                'qty' => $stock['qty'],
                'is_salable' => $stock['is_salable'],
                'manage_stock' => $stock['manage_stock'],
                'backorders' => $stock['backorders'],
                'source' => (string)($stock['source'] ?? 'direct_sql_inventory'),
                'source_updated_at' => (string)($product['updated_at'] ?? ''),
            ],
        ];
    }

    private function isProductEnabled(int $linkId, int $storeId): bool
    {
        $value = $this->getProductIntValue($linkId, 'status', $storeId);
        return $value === null || $value === 1;
    }

    /**
     * @return array{qty: float, is_in_stock: bool, is_salable: bool, manage_stock: bool, backorders: int, stock_status: string}
     */
    private function buildInventoryState(int $productId, string $sku, string $websiteCode): array
    {
        $legacy = $this->fetchLegacyStock($productId);
        $msi = $this->fetchMsiStock($sku, $websiteCode);
        $state = $msi ?? $legacy;

        if ($state === null) {
            $state = [
                'qty' => 0.0,
                'is_in_stock' => false,
                'is_salable' => false,
                'manage_stock' => true,
                'backorders' => 0,
                'min_qty' => 0.0,
                'has_sellable_source' => false,
                'source' => 'direct_sql_inventory_missing',
            ];
        }

        $manageStock = (bool)($legacy['manage_stock'] ?? $state['manage_stock'] ?? true);
        $backorders = (int)($legacy['backorders'] ?? $state['backorders'] ?? 0);
        $minQty = (float)($legacy['min_qty'] ?? $state['min_qty'] ?? 0.0);
        $legacyInStock = (bool)($legacy['is_in_stock'] ?? $state['is_in_stock'] ?? false);
        $hasSellableSource = (bool)($state['has_sellable_source'] ?? $state['is_in_stock'] ?? false);
        $qty = (float)($state['qty'] ?? 0.0);
        $source = (string)($state['source'] ?? 'direct_sql_inventory');

        $state['manage_stock'] = $manageStock;
        $state['backorders'] = $backorders;
        $state['is_salable'] = $this->offerMath->isSqlSalable($qty, $hasSellableSource, $legacyInStock, $manageStock, $backorders, $minQty);
        $state['is_in_stock'] = $legacyInStock || ($hasSellableSource && $qty > $minQty);
        $state['stock_status'] = ((bool)$state['is_in_stock'] || (bool)$state['is_salable']) ? 'in_stock' : 'out_of_stock';
        $state['source'] = $source;
        unset($state['min_qty'], $state['has_sellable_source']);

        return $state;
    }

    /**
     * @return array{qty: float, is_in_stock: bool, is_salable: bool, manage_stock: bool, backorders: int}|null
     */
    private function fetchLegacyStock(int $productId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('cataloginventory_stock_item');
        if (!$this->tableExists($table)) {
            return null;
        }

        $row = $connection->fetchRow(
            $connection->select()
                ->from($table, ['qty', 'is_in_stock', 'manage_stock', 'backorders', 'min_qty'])
                ->where('product_id = ?', $productId)
                ->order('stock_id ASC')
                ->limit(1)
        );

        if (!is_array($row)) {
            return null;
        }

        $qty = (float)($row['qty'] ?? 0);
        $isInStock = (bool)(int)($row['is_in_stock'] ?? 0);
        $backorders = (int)($row['backorders'] ?? 0);
        $manageStock = (bool)(int)($row['manage_stock'] ?? 1);
        $minQty = (float)($row['min_qty'] ?? 0.0);

        return [
            'qty' => $qty,
            'is_in_stock' => $isInStock,
            'is_salable' => $this->offerMath->isSqlSalable($qty, $isInStock, $isInStock, $manageStock, $backorders, $minQty),
            'manage_stock' => $manageStock,
            'backorders' => $backorders,
            'min_qty' => $minQty,
            'has_sellable_source' => $isInStock,
            'source' => 'direct_sql_cataloginventory_stock_item',
        ];
    }

    /**
     * @return array{qty: float, is_in_stock: bool, is_salable: bool, manage_stock: bool, backorders: int}|null
     */
    private function fetchMsiStock(string $sku, string $websiteCode): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $salesChannelTable = $this->resourceConnection->getTableName('inventory_stock_sales_channel');
        $sourceLinkTable = $this->resourceConnection->getTableName('inventory_source_stock_link');
        $sourceItemTable = $this->resourceConnection->getTableName('inventory_source_item');
        if (!$this->tableExists($salesChannelTable) || !$this->tableExists($sourceLinkTable) || !$this->tableExists($sourceItemTable)) {
            return null;
        }

        $stockId = (int)($connection->fetchOne(
            $connection->select()
                ->from($salesChannelTable, 'stock_id')
                ->where('type = ?', 'website')
                ->where('code = ?', $websiteCode)
                ->limit(1)
        ) ?: 0);
        if ($stockId <= 0) {
            return null;
        }

        $sourceCodes = $connection->fetchCol(
            $connection->select()
                ->from($sourceLinkTable, ['source_code'])
                ->where('stock_id = ?', $stockId)
        );
        if ($sourceCodes === []) {
            return null;
        }

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($sourceItemTable, ['quantity', 'status'])
                ->where('sku = ?', $sku)
                ->where('source_code IN (?)', $sourceCodes)
        );
        if ($rows === []) {
            return null;
        }

        $qty = 0.0;
        $hasActiveSource = false;
        foreach ($rows as $row) {
            $sourceQty = (float)($row['quantity'] ?? 0);
            $isActive = (int)($row['status'] ?? 0) === 1;
            if ($isActive) {
                $qty += $sourceQty;
                $hasActiveSource = true;
            }
        }

        $qty += $this->fetchReservationDelta($sku, $stockId);
        $qty = max(0.0, $qty);

        return [
            'qty' => $qty,
            'is_in_stock' => $hasActiveSource && $qty > 0.0,
            'is_salable' => $hasActiveSource && $qty > 0.0,
            'manage_stock' => true,
            'backorders' => 0,
            'min_qty' => 0.0,
            'has_sellable_source' => $hasActiveSource,
            'source' => 'direct_sql_msi_source_item',
        ];
    }

    private function fetchReservationDelta(string $sku, int $stockId): float
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('inventory_reservation');
        if (!$this->tableExists($table)) {
            return 0.0;
        }

        return (float)($connection->fetchOne(
            $connection->select()
                ->from($table, ['qty' => 'SUM(quantity)'])
                ->where('sku = ?', $sku)
                ->where('stock_id = ?', $stockId)
        ) ?: 0.0);
    }

    /**
     * @return array{product_id: int|null, sku: string|null}
     */
    private function resolveParent(int $productId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $relationTable = $this->resourceConnection->getTableName('catalog_product_relation');
        $parentId = null;

        if ($this->tableExists($relationTable)) {
            $parentId = $connection->fetchOne(
                $connection->select()
                    ->from($relationTable, 'parent_id')
                    ->where('child_id = ?', $productId)
                    ->order('parent_id ASC')
                    ->limit(1)
            );
        }

        if (!$parentId) {
            $superLinkTable = $this->resourceConnection->getTableName('catalog_product_super_link');
            if ($this->tableExists($superLinkTable)) {
                $parentId = $connection->fetchOne(
                    $connection->select()
                        ->from($superLinkTable, 'parent_id')
                        ->where('product_id = ?', $productId)
                        ->order('parent_id ASC')
                        ->limit(1)
                );
            }
        }

        $parentId = (int)($parentId ?: 0);
        if ($parentId <= 0) {
            return ['product_id' => null, 'sku' => null];
        }

        $parentSku = $connection->fetchOne(
            $connection->select()
                ->from($productTable, 'sku')
                ->where('entity_id = ?', $parentId)
                ->limit(1)
        );

        return ['product_id' => $parentId, 'sku' => $parentSku ? (string)$parentSku : null];
    }

    private function getProductDecimalValue(int $linkId, string $attributeCode, int $storeId): ?float
    {
        $value = $this->getProductEavValue('catalog_product_entity_decimal', $linkId, $attributeCode, $storeId);
        return $value !== null ? (float)$value : null;
    }

    private function getProductIntValue(int $linkId, string $attributeCode, int $storeId): ?int
    {
        $value = $this->getProductEavValue('catalog_product_entity_int', $linkId, $attributeCode, $storeId);
        return $value !== null ? (int)$value : null;
    }

    private function getProductDatetimeValue(int $linkId, string $attributeCode, int $storeId): ?string
    {
        $value = $this->getProductEavValue('catalog_product_entity_datetime', $linkId, $attributeCode, $storeId);
        $value = $value !== null ? trim((string)$value) : '';
        return $value !== '' ? $value : null;
    }

    private function getProductEavValue(string $tableName, int $linkId, string $attributeCode, int $storeId): mixed
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName($tableName);
        $attributeId = $this->getProductAttributeId($attributeCode);
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
        foreach ($connection->fetchAll($select) as $row) {
            if ((int)$row['store_id'] === 0) {
                $default = $row['value'];
            } elseif ((int)$row['store_id'] === $storeId) {
                $storeValue = $row['value'];
            }
        }

        return $storeValue !== null ? $storeValue : $default;
    }

    private function getProductAttributeId(string $attributeCode): int
    {
        $cacheKey = 'product:' . $attributeCode;
        if (!array_key_exists($cacheKey, $this->attributeIds)) {
            $connection = $this->resourceConnection->getConnection();
            $attributeTable = $this->resourceConnection->getTableName('eav_attribute');
            $entityTypeTable = $this->resourceConnection->getTableName('eav_entity_type');
            try {
                $this->attributeIds[$cacheKey] = (int)($connection->fetchOne(
                    $connection->select()
                        ->from(['a' => $attributeTable], 'attribute_id')
                        ->join(['t' => $entityTypeTable], 'a.entity_type_id = t.entity_type_id', [])
                        ->where('t.entity_type_code = ?', 'catalog_product')
                        ->where('a.attribute_code = ?', $attributeCode)
                        ->limit(1)
                ) ?: 0);
            } catch (\Throwable) {
                $this->attributeIds[$cacheKey] = 0;
            }
        }

        return $this->attributeIds[$cacheKey];
    }

    /**
     * @param array<string, mixed> $product
     */
    private function getProductLinkValue(array $product): int
    {
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $linkField = $this->getLinkField($productTable);
        if (isset($product[$linkField])) {
            return (int)$product[$linkField];
        }
        return (int)$product['entity_id'];
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
