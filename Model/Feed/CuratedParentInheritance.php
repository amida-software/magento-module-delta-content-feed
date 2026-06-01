<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Feed;

use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\ResourceModel\StateSnapshot;
use Magento\Framework\App\ResourceConnection;

/**
 * Resolves configurable parents for curated child products and fills empty child fields from the
 * parent's curated state. Identity / commercial fields (sku, name, prices, availability) and the
 * product type are intentionally never inherited.
 *
 * Applied at snapshot read time so it covers already-stored states without a full rebuild.
 */
class CuratedParentInheritance
{
    /**
     * Curated fields a child inherits from its configurable parent when the child value is empty.
     * Everything except sku, name, prices, availability and magento_type_id.
     *
     * @var string[]
     */
    private const INHERITABLE_FIELDS = [
        'category_ids',
        'images',
        'description',
        'short_description',
        'url_key',
        'brand',
        'product_type',
        'notes',
        'related_products',
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StateSnapshot $stateSnapshot
    ) {
    }

    /**
     * Build a map of child SKU => parent curated payload, for children whose parent is configurable.
     *
     * @param array<int, array<string, mixed>> $rows snapshot rows (must contain entity_id and sku)
     * @return array<string, array<string, mixed>>
     */
    public function buildParentCuratedMap(array $rows, string $storeCode): array
    {
        $childIdToSku = [];
        foreach ($rows as $row) {
            $childId = (int)($row['entity_id'] ?? 0);
            $childSku = trim((string)($row['sku'] ?? ''));
            if ($childId > 0 && $childSku !== '') {
                $childIdToSku[$childId] = $childSku;
            }
        }
        if ($childIdToSku === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        // child product_id => configurable parent_id (catalog_product_super_link is configurable-specific)
        $superLinkTable = $this->resourceConnection->getTableName('catalog_product_super_link');
        $childIdToParentId = [];
        $parentIds = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($superLinkTable, ['product_id', 'parent_id'])
                ->where('product_id IN (?)', array_keys($childIdToSku))
        ) as $link) {
            $childId = (int)$link['product_id'];
            $parentId = (int)$link['parent_id'];
            if ($childId > 0 && $parentId > 0 && !isset($childIdToParentId[$childId])) {
                $childIdToParentId[$childId] = $parentId;
                $parentIds[$parentId] = $parentId;
            }
        }
        if ($parentIds === []) {
            return [];
        }

        // parent_id => parent_sku, restricted to configurable products
        $entityTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $parentIdToSku = [];
        $parentSkus = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($entityTable, ['entity_id', 'sku'])
                ->where('entity_id IN (?)', array_values($parentIds))
                ->where('type_id = ?', 'configurable')
        ) as $parentRow) {
            $parentId = (int)$parentRow['entity_id'];
            $parentSku = trim((string)$parentRow['sku']);
            if ($parentId > 0 && $parentSku !== '') {
                $parentIdToSku[$parentId] = $parentSku;
                $parentSkus[] = $parentSku;
            }
        }
        if ($parentSkus === []) {
            return [];
        }

        $parentStates = $this->stateSnapshot->fetchStateMapBySkus(Config::STREAM_CURATED, $storeCode, $parentSkus);

        $map = [];
        foreach ($childIdToSku as $childId => $childSku) {
            $parentId = $childIdToParentId[$childId] ?? null;
            $parentSku = $parentId !== null ? ($parentIdToSku[$parentId] ?? null) : null;
            if ($parentSku === null) {
                continue;
            }
            $parentState = $parentStates[$parentSku]['state'] ?? null;
            $parentCurated = is_array($parentState) ? ($parentState['curated'] ?? null) : null;
            if (is_array($parentCurated)) {
                $map[$childSku] = $parentCurated;
            }
        }

        return $map;
    }

    /**
     * Fill empty inheritable fields on a child curated payload from its configurable parent.
     *
     * @param array<string, mixed> $childCurated
     * @param array<string, mixed> $parentCurated
     * @return array<string, mixed>
     */
    public function inherit(array $childCurated, array $parentCurated): array
    {
        foreach (self::INHERITABLE_FIELDS as $field) {
            if ($this->isEmptyValue($childCurated[$field] ?? null) && !$this->isEmptyValue($parentCurated[$field] ?? null)) {
                $childCurated[$field] = $parentCurated[$field];
            }
        }

        return $childCurated;
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
