<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Store;

use Amida\ProductDeltaFeed\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

class AttributeDictionaryService
{
    /** @var array<string, string> */
    private array $tableColumns = [];

    /** @var array<int, string>|null */
    private ?array $storeCodesById = null;

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly StoreMetadataAdapterPool $adapterPool
    ) {
    }

    /** @param string[] $codes */
    public function build(string $storeCode, array $codes = [], bool $loadOptions = true, int $schemaVersion = 2): array
    {
        $store = $this->storeManager->getStore($storeCode);
        $context = new StoreContext(
            $store,
            (string)$store->getCode(),
            (int)$store->getId(),
            (int)$store->getWebsiteId(),
            method_exists($store, 'getGroupId') ? (int)$store->getGroupId() : 0,
            []
        );
        $codes = $this->normalizeCodes($codes);
        $entityTypeId = $this->productEntityTypeId();
        $usedAttributeSets = $entityTypeId > 0 ? $this->loadUsedAttributeSets($context, $entityTypeId) : [];
        $productTypes = $entityTypeId > 0 ? $this->loadProductTypes($context) : [];
        $items = $entityTypeId > 0 && $usedAttributeSets !== []
            ? $this->loadAttributes($context, $entityTypeId, array_keys($usedAttributeSets), array_column($productTypes, 'code'), $codes, $loadOptions)
            : [];
        $productTypes = $this->attachProductTypeAttributeCodes($productTypes, $items);
        $admin = $this->adminMetadata($storeCode);
        $adapterMetadata = $this->adapterMetadata($context, array_keys($items));

        foreach ($items as $code => &$item) {
            if (isset($adapterMetadata[$code]) && is_array($adapterMetadata[$code])) {
                $item = array_replace($item, $this->sanitizeMetadata($adapterMetadata[$code]));
            }
            if (isset($admin[$code]) && is_array($admin[$code])) {
                $item = array_replace($item, $this->sanitizeMetadata($admin[$code]));
            }
        }
        unset($item);

        if ($schemaVersion === 1) {
            return [
                'schema_version' => 1,
                'entity' => 'attributes',
                'store_code' => $storeCode,
                'product_types' => $productTypes,
                'attribute_sets' => $this->buildAttributeSetTree($usedAttributeSets, $items, false),
                'items' => array_values($items),
                'diagnostics' => [],
            ];
        }

        return [
            'schema_version' => 2,
            'entity' => 'attributes',
            'store_code' => $storeCode,
            'attributes' => $this->attributesById($items),
            'attribute_sets' => $this->buildAttributeSetTree($usedAttributeSets, $items, true),
            'product_types' => $this->attachProductTypeAttributeIds($productTypes, $items),
            'diagnostics' => [],
        ];
    }

    /** @param int[] $usedAttributeSetIds @param string[] $productTypeCodes @param string[] $codes @return array<string, array<string, mixed>> */
    private function loadAttributes(StoreContext $context, int $entityTypeId, array $usedAttributeSetIds, array $productTypeCodes, array $codes, bool $loadOptions): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->table('eav_attribute')], [
                'attribute_id', 'attribute_code', 'frontend_label', 'frontend_input', 'backend_type', 'is_required', 'default_value'
            ])
            ->joinInner(['eea' => $this->table('eav_entity_attribute')], 'a.attribute_id = eea.attribute_id', [
                'attribute_set_id', 'attribute_group_id', 'attribute_sort_order' => 'sort_order'
            ])
            ->joinInner(['ag' => $this->table('eav_attribute_group')], 'eea.attribute_group_id = ag.attribute_group_id', [
                'attribute_group_name', 'attribute_group_sort_order' => 'sort_order'
            ])
            ->joinLeft(['ca' => $this->table('catalog_eav_attribute')], 'a.attribute_id = ca.attribute_id', [
                'is_filterable', 'is_searchable', 'is_visible', 'is_visible_on_front', 'used_for_sort_by', 'apply_to'
            ])
            ->where('a.entity_type_id = ?', $entityTypeId)
            ->where('eea.attribute_set_id IN (?)', $usedAttributeSetIds)
            ->order(['a.attribute_code ASC', 'eea.attribute_set_id ASC', 'ag.sort_order ASC', 'eea.sort_order ASC']);
        if ($codes !== []) {
            $select->where('a.attribute_code IN (?)', $codes);
        }

        $rows = $connection->fetchAll($select);
        $candidates = [];
        foreach ($rows as $row) {
            $attributeId = (int)$row['attribute_id'];
            $code = (string)$row['attribute_code'];
            if ($attributeId <= 0 || $code === '') {
                continue;
            }
            if (!isset($candidates[$attributeId])) {
                $candidates[$attributeId] = [
                    'row' => $row,
                    'sets' => [],
                    'groups' => [],
                ];
            }
            $setId = (int)$row['attribute_set_id'];
            $groupId = (int)$row['attribute_group_id'];
            $candidates[$attributeId]['sets'][$setId] = $setId;
            $candidates[$attributeId]['groups'][$setId . ':' . $groupId] = [
                'attribute_set_id' => $setId,
                'group_id' => $groupId,
                'group' => (string)$row['attribute_group_name'],
                'sort_order' => (int)$row['attribute_sort_order'],
            ];
        }
        if ($candidates === []) {
            return [];
        }

        $valuedAttributeIds = $this->loadValuedAttributeIds($context, $candidates);
        $attributeLabels = $this->loadAttributeLabels(array_keys($candidates));
        $optionCounts = $loadOptions ? [] : $this->loadOptionCounts(array_keys($candidates));
        $items = [];
        foreach ($candidates as $attributeId => $candidate) {
            if (!isset($valuedAttributeIds[$attributeId])) {
                continue;
            }
            $row = $candidate['row'];
            $code = (string)$row['attribute_code'];
            $kind = $this->kind((string)($row['frontend_input'] ?? ''), (string)($row['backend_type'] ?? ''));
            $adminLabel = (string)($attributeLabels[$attributeId]['admin_label'] ?? trim((string)($row['frontend_label'] ?? '')));
            $labels = (array)($attributeLabels[$attributeId]['labels'] ?? []);
            $label = (string)($labels[$context->storeCode] ?? ($adminLabel !== '' ? $adminLabel : $code));
            $attributeProductTypes = $this->productTypesForAttribute((string)($row['apply_to'] ?? ''), $productTypeCodes);
            $options = [];
            $optionsCount = 0;
            if (in_array($kind, ['select', 'multiselect', 'boolean'], true)) {
                if ($loadOptions) {
                    $options = $this->loadOptions((int)$row['attribute_id'], $context->storeCode);
                    if ($kind === 'boolean' && $options === []) {
                        $options = $this->booleanOptions($context->storeCode);
                    }
                    $optionsCount = count($options);
                } else {
                    $optionsCount = (int)($optionCounts[$attributeId] ?? 0);
                    if ($kind === 'boolean' && $optionsCount === 0) {
                        $optionsCount = 2;
                    }
                }
            }
            $item = [
                'id' => $attributeId,
                'code' => $code,
                'label' => $label,
                'labels' => $labels,
                'kind' => $kind,
                'unit' => null,
                'is_filterable' => (bool)(int)($row['is_filterable'] ?? 0),
                'is_searchable' => (bool)(int)($row['is_searchable'] ?? 0),
                'is_visible' => (bool)(int)($row['is_visible'] ?? 1),
                'is_visible_on_front' => (bool)(int)($row['is_visible_on_front'] ?? 0),
                'is_required' => (bool)(int)($row['is_required'] ?? 0),
                'product_types' => $attributeProductTypes,
                'attribute_set_ids' => array_values($candidate['sets']),
                'attribute_groups' => array_values($candidate['groups']),
            ];
            if ($loadOptions) {
                if ($options !== []) {
                    $item['options'] = $options;
                }
            } elseif ($optionsCount > 0) {
                $item['options_count'] = $optionsCount;
            }
            if ($this->adminLabelDiffers($adminLabel, $labels)) {
                $item['admin_label'] = $adminLabel;
            }
            $items[$code] = $item;
        }
        ksort($items);
        return $items;
    }

    private function productEntityTypeId(): int
    {
        $connection = $this->resourceConnection->getConnection();
        return (int)$connection->fetchOne($connection->select()
            ->from($this->table('eav_entity_type'), 'entity_type_id')
            ->where('entity_type_code = ?', 'catalog_product')
            ->limit(1));
    }

    /** @return array<int, array<string, mixed>> */
    private function loadUsedAttributeSets(StoreContext $context, int $entityTypeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['p' => $this->table('catalog_product_entity')], [
                'attribute_set_id',
                'product_count' => new \Zend_Db_Expr('COUNT(*)'),
            ])
            ->joinInner(['s' => $this->table('eav_attribute_set')], 'p.attribute_set_id = s.attribute_set_id', ['attribute_set_name'])
            ->where('s.entity_type_id = ?', $entityTypeId)
            ->group(['p.attribute_set_id', 's.attribute_set_name'])
            ->order('s.attribute_set_name ASC');
        $this->joinWebsiteProducts($select, 'p', $context);

        $sets = [];
        foreach ($connection->fetchAll($select) as $row) {
            $id = (int)$row['attribute_set_id'];
            if ($id <= 0) {
                continue;
            }
            $sets[$id] = [
                'id' => $id,
                'name' => (string)$row['attribute_set_name'],
                'product_count' => (int)$row['product_count'],
                'groups' => [],
            ];
        }
        return $sets;
    }

    /** @return array<int, array<string, mixed>> */
    private function loadProductTypes(StoreContext $context): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['p' => $this->table('catalog_product_entity')], [
                'code' => 'type_id',
                'product_count' => new \Zend_Db_Expr('COUNT(*)'),
            ])
            ->where('p.type_id IS NOT NULL')
            ->where("p.type_id <> ''")
            ->group('p.type_id')
            ->order('p.type_id ASC');
        $this->joinWebsiteProducts($select, 'p', $context);

        $types = [];
        foreach ($connection->fetchAll($select) as $row) {
            $code = (string)$row['code'];
            if ($code === '') {
                continue;
            }
            $types[] = [
                'code' => $code,
                'label' => $this->productTypeLabel($code),
                'product_count' => (int)$row['product_count'],
            ];
        }
        return $types;
    }

    /** @param array<int, array<string, mixed>> $candidates @return array<int, bool> */
    private function loadValuedAttributeIds(StoreContext $context, array $candidates): array
    {
        $connection = $this->resourceConnection->getConnection();
        $valued = [];
        $byBackendType = [];
        foreach ($candidates as $attributeId => $candidate) {
            $backendType = (string)($candidate['row']['backend_type'] ?? '');
            $byBackendType[$backendType][$attributeId] = (string)$candidate['row']['attribute_code'];
        }

        foreach ($byBackendType['static'] ?? [] as $attributeId => $code) {
            if (!$this->tableHasColumn($this->table('catalog_product_entity'), $code)) {
                continue;
            }
            $select = $connection->select()
                ->from(['p' => $this->table('catalog_product_entity')], [new \Zend_Db_Expr('1')])
                ->where($this->nonEmptyColumnExpression('p', $code))
                ->limit(1);
            $this->joinWebsiteProducts($select, 'p', $context);
            if ((int)$connection->fetchOne($select) === 1) {
                $valued[$attributeId] = true;
            }
        }

        foreach ($byBackendType as $backendType => $attributeCodes) {
            if ($backendType === 'static' || $attributeCodes === [] || !in_array($backendType, ['varchar', 'int', 'text', 'decimal', 'datetime'], true)) {
                continue;
            }
            $table = $this->table('catalog_product_entity_' . $backendType);
            if (!$this->tableHasColumn($table, 'attribute_id')) {
                continue;
            }
            $linkField = $this->productLinkField($table);
            $select = $connection->select()
                ->distinct(true)
                ->from(['v' => $table], ['attribute_id'])
                ->joinInner(['p' => $this->table('catalog_product_entity')], 'p.' . $this->productEntityLinkField() . ' = v.' . $linkField, [])
                ->where('v.attribute_id IN (?)', array_keys($attributeCodes))
                ->where('v.store_id IN (?)', $this->valueStoreIds())
                ->where($this->nonEmptyValueExpression($backendType));
            $this->joinWebsiteProducts($select, 'p', $context);
            foreach ($connection->fetchCol($select) as $attributeId) {
                $valued[(int)$attributeId] = true;
            }
        }

        return $valued;
    }

    /** @param int[] $attributeIds @return array<int, int> */
    private function loadOptionCounts(array $attributeIds): array
    {
        $attributeIds = array_values(array_unique(array_map('intval', $attributeIds)));
        $attributeIds = array_values(array_filter($attributeIds, static fn (int $attributeId): bool => $attributeId > 0));
        if ($attributeIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['o' => $this->table('eav_attribute_option')], [
                'attribute_id',
                'options_count' => new \Zend_Db_Expr('COUNT(*)'),
            ])
            ->where('o.attribute_id IN (?)', $attributeIds)
            ->group('o.attribute_id');

        $counts = [];
        foreach ($connection->fetchAll($select) as $row) {
            $attributeId = (int)$row['attribute_id'];
            if ($attributeId > 0) {
                $counts[$attributeId] = (int)$row['options_count'];
            }
        }

        return $counts;
    }

    /** @return array<int, array<string, mixed>> */
    private function loadOptions(int $attributeId, string $storeCode): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['o' => $this->table('eav_attribute_option')], ['option_id', 'sort_order'])
            ->joinLeft(['v' => $this->table('eav_attribute_option_value')], 'o.option_id = v.option_id', ['store_id', 'value'])
            ->where('o.attribute_id = ?', $attributeId)
            ->where('v.store_id IS NULL OR v.store_id IN (?)', $this->valueStoreIds())
            ->order(['o.sort_order ASC', 'o.option_id ASC', 'v.store_id ASC']);
        $rowsByOption = [];
        foreach ($connection->fetchAll($select) as $row) {
            $optionId = (int)$row['option_id'];
            if ($optionId <= 0) {
                continue;
            }
            $rowsByOption[$optionId] ??= ['admin_label' => '', 'labels' => []];
            $label = trim((string)($row['value'] ?? ''));
            if ($label === '') {
                continue;
            }
            $storeId = (int)($row['store_id'] ?? 0);
            if ($storeId === 0) {
                $rowsByOption[$optionId]['admin_label'] = $label;
                continue;
            }
            $storeCodes = $this->storeCodesById();
            if (isset($storeCodes[$storeId])) {
                $rowsByOption[$optionId]['labels'][$storeCodes[$storeId]] = $label;
            }
        }

        $options = [];
        foreach ($rowsByOption as $optionId => $data) {
            $labels = $this->normalizeLabelMap((array)$data['labels']);
            $adminLabel = (string)$data['admin_label'];
            $label = (string)($labels[$storeCode] ?? ($adminLabel !== '' ? $adminLabel : ''));
            if ($label === '') {
                continue;
            }
            $option = [
                'value' => (string)$optionId,
                'label' => $label,
                'labels' => $labels,
            ];
            if ($this->adminLabelDiffers($adminLabel, $labels)) {
                $option['admin_label'] = $adminLabel;
            }
            $options[] = $option;
        }
        return $options;
    }

    /** @return array<int, array<string, mixed>> */
    private function booleanOptions(string $storeCode): array
    {
        return [
            ['value' => '0', 'label' => 'No', 'labels' => [$storeCode => 'No']],
            ['value' => '1', 'label' => 'Yes', 'labels' => [$storeCode => 'Yes']],
        ];
    }

    /** @param array<int, array<string, mixed>> $sets @param array<string, array<string, mixed>> $items @return array<int, array<string, mixed>> */
    private function buildAttributeSetTree(array $sets, array $items, bool $useAttributeIds = false): array
    {
        foreach ($items as $item) {
            foreach ($item['attribute_groups'] ?? [] as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $setId = (int)($group['attribute_set_id'] ?? 0);
                $groupId = (int)($group['group_id'] ?? 0);
                if (!isset($sets[$setId]) || $groupId <= 0) {
                    continue;
                }
                if (!isset($sets[$setId]['groups'][$groupId])) {
                    $sets[$setId]['groups'][$groupId] = [
                        'id' => $groupId,
                        'name' => (string)($group['group'] ?? ''),
                        'attribute_codes' => [],
                        'attribute_ids' => [],
                    ];
                }
                $sets[$setId]['groups'][$groupId]['attribute_codes'][] = (string)$item['code'];
                $sets[$setId]['groups'][$groupId]['attribute_ids'][] = (int)$item['id'];
            }
        }

        $tree = [];
        foreach ($sets as $set) {
            $groups = array_values($set['groups']);
            if ($groups === []) {
                continue;
            }
            foreach ($groups as &$group) {
                $group['attribute_codes'] = array_values(array_unique($group['attribute_codes']));
                sort($group['attribute_codes']);
                $group['attribute_ids'] = array_values(array_unique(array_map('intval', $group['attribute_ids'])));
                sort($group['attribute_ids']);
                if ($useAttributeIds) {
                    unset($group['attribute_codes']);
                } else {
                    unset($group['attribute_ids']);
                    // Compatibility alias for clients that consumed the first draft of this tree.
                    $group['attributes'] = $group['attribute_codes'];
                }
            }
            unset($group);
            $set['groups'] = $groups;
            if ($useAttributeIds) {
                unset($set['product_count']);
            }
            $tree[] = $set;
        }
        return $tree;
    }

    /** @param int[] $attributeIds @return array<int, array{admin_label:string, labels:array<string, string>}> */
    private function loadAttributeLabels(array $attributeIds): array
    {
        if ($attributeIds === []) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $out = [];
        $select = $connection->select()
            ->from(['a' => $this->table('eav_attribute')], ['attribute_id', 'admin_label' => 'frontend_label'])
            ->where('a.attribute_id IN (?)', $attributeIds);
        foreach ($connection->fetchAll($select) as $row) {
            $attributeId = (int)$row['attribute_id'];
            $out[$attributeId] = [
                'admin_label' => trim((string)($row['admin_label'] ?? '')),
                'labels' => [],
            ];
        }

        $storeCodes = $this->storeCodesById();
        if ($storeCodes === []) {
            return $out;
        }
        $labelSelect = $connection->select()
            ->from(['l' => $this->table('eav_attribute_label')], ['attribute_id', 'store_id', 'value'])
            ->where('l.attribute_id IN (?)', $attributeIds)
            ->where('l.store_id IN (?)', array_keys($storeCodes))
            ->where("l.value IS NOT NULL AND TRIM(l.value) <> ''");
        foreach ($connection->fetchAll($labelSelect) as $row) {
            $attributeId = (int)$row['attribute_id'];
            $storeId = (int)$row['store_id'];
            if (!isset($out[$attributeId], $storeCodes[$storeId])) {
                continue;
            }
            $out[$attributeId]['labels'][$storeCodes[$storeId]] = trim((string)$row['value']);
        }
        foreach ($out as &$data) {
            $data['labels'] = $this->normalizeLabelMap($data['labels']);
        }
        unset($data);
        return $out;
    }

    /** @return array<int, string> */
    private function storeCodesById(): array
    {
        if ($this->storeCodesById !== null) {
            return $this->storeCodesById;
        }
        $out = [];
        try {
            foreach ($this->storeManager->getStores(false) as $store) {
                if (method_exists($store, 'isActive') && !$store->isActive()) {
                    continue;
                }
                $storeId = (int)$store->getId();
                $storeCode = trim((string)$store->getCode());
                if ($storeId > 0 && $storeCode !== '') {
                    $out[$storeId] = $storeCode;
                }
            }
        } catch (\Throwable) {
            $out = [];
        }
        ksort($out);
        return $this->storeCodesById = $out;
    }

    /** @return int[] */
    private function valueStoreIds(): array
    {
        return array_values(array_unique(array_merge([0], array_keys($this->storeCodesById()))));
    }

    /** @param array<string, string> $labels @return array<string, string> */
    private function normalizeLabelMap(array $labels): array
    {
        $out = [];
        foreach ($labels as $storeCode => $label) {
            $storeCode = trim((string)$storeCode);
            $label = trim((string)$label);
            if ($storeCode !== '' && $label !== '') {
                $out[$storeCode] = $label;
            }
        }
        ksort($out);
        return $out;
    }

    /** @param array<string, string> $labels */
    private function adminLabelDiffers(string $adminLabel, array $labels): bool
    {
        $adminLabel = trim($adminLabel);
        return $adminLabel !== '' && $labels !== [] && !in_array($adminLabel, array_values($labels), true);
    }

    /** @param string[] $productTypeCodes @return string[] */
    private function productTypesForAttribute(string $applyTo, array $productTypeCodes): array
    {
        $productTypeCodes = array_values(array_unique(array_filter(array_map('strval', $productTypeCodes))));
        sort($productTypeCodes);
        $applyTo = trim($applyTo);
        if ($applyTo === '') {
            return $productTypeCodes;
        }
        $allowed = array_values(array_unique(array_filter(array_map('trim', explode(',', $applyTo)), static fn (string $type): bool => $type !== '')));
        $types = array_values(array_intersect($productTypeCodes, $allowed));
        sort($types);
        return $types;
    }

    /** @param array<int, array<string, mixed>> $productTypes @param array<string, array<string, mixed>> $items @return array<int, array<string, mixed>> */
    private function attachProductTypeAttributeCodes(array $productTypes, array $items): array
    {
        foreach ($productTypes as &$type) {
            $type['attribute_codes'] = [];
        }
        unset($type);
        $indexByCode = [];
        foreach ($productTypes as $idx => $type) {
            $indexByCode[(string)$type['code']] = $idx;
        }
        foreach ($items as $item) {
            $code = (string)($item['code'] ?? '');
            if ($code === '') {
                continue;
            }
            foreach ((array)($item['product_types'] ?? []) as $typeCode) {
                $typeCode = (string)$typeCode;
                if (isset($indexByCode[$typeCode])) {
                    $productTypes[$indexByCode[$typeCode]]['attribute_codes'][] = $code;
                }
            }
        }
        foreach ($productTypes as &$type) {
            $type['attribute_codes'] = array_values(array_unique((array)$type['attribute_codes']));
            sort($type['attribute_codes']);
        }
        unset($type);
        return $productTypes;
    }

    /** @param array<int, array<string, mixed>> $productTypes @param array<string, array<string, mixed>> $items @return array<int, array<string, mixed>> */
    private function attachProductTypeAttributeIds(array $productTypes, array $items): array
    {
        foreach ($productTypes as &$type) {
            $codes = (array)($type['attribute_codes'] ?? []);
            $type['attribute_ids'] = [];
            foreach ($items as $item) {
                if (in_array((string)($item['code'] ?? ''), $codes, true)) {
                    $type['attribute_ids'][] = (int)$item['id'];
                }
            }
            $type['attribute_ids'] = array_values(array_unique($type['attribute_ids']));
            sort($type['attribute_ids']);
            unset($type['attribute_codes'], $type['product_count']);
        }
        unset($type);
        return $productTypes;
    }

    /** @param array<string, array<string, mixed>> $items @return array<string, array<string, mixed>> */
    private function attributesById(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id > 0) {
                unset($item['product_types'], $item['attribute_set_ids'], $item['attribute_groups']);
                $out[(string)$id] = $item;
            }
        }
        ksort($out, SORT_NATURAL);
        return $out;
    }

    private function joinWebsiteProducts(\Zend_Db_Select $select, string $productAlias, StoreContext $context): void
    {
        if ($context->websiteId <= 0) {
            return;
        }
        $select->joinInner(
            ['cpw' => $this->table('catalog_product_website')],
            $productAlias . '.entity_id = cpw.product_id AND cpw.website_id = ' . $context->websiteId,
            []
        );
    }

    private function productEntityLinkField(): string
    {
        return $this->tableHasColumn($this->table('catalog_product_entity'), 'row_id') ? 'row_id' : 'entity_id';
    }

    private function productLinkField(string $valueTable): string
    {
        return $this->tableHasColumn($valueTable, 'row_id') ? 'row_id' : 'entity_id';
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        $key = $table . ':' . $column;
        if (array_key_exists($key, $this->tableColumns)) {
            return $this->tableColumns[$key] === $column;
        }
        $columns = $this->resourceConnection->getConnection()->describeTable($table);
        $this->tableColumns[$key] = isset($columns[$column]) ? $column : '';
        return $this->tableColumns[$key] === $column;
    }

    private function nonEmptyColumnExpression(string $alias, string $column): string
    {
        $quoted = $this->resourceConnection->getConnection()->quoteIdentifier($alias . '.' . $column);
        return $quoted . ' IS NOT NULL AND TRIM(CAST(' . $quoted . ' AS CHAR)) <> \'\'';
    }

    private function nonEmptyValueExpression(string $backendType): string
    {
        if (in_array($backendType, ['int', 'decimal'], true)) {
            return 'v.value IS NOT NULL';
        }
        return "v.value IS NOT NULL AND TRIM(v.value) <> ''";
    }

    private function productTypeLabel(string $code): string
    {
        return match ($code) {
            'simple' => 'Simple Product',
            'configurable' => 'Configurable Product',
            'virtual' => 'Virtual Product',
            'downloadable' => 'Downloadable Product',
            'bundle' => 'Bundle Product',
            'grouped' => 'Grouped Product',
            default => ucwords(str_replace(['_', '-'], ' ', $code)),
        };
    }

    /** @return array<string, array<string, mixed>> */
    private function adminMetadata(string $storeCode): array
    {
        $raw = trim((string)$this->config->getStoreMetadataValue(Config::XML_PATH_STORE_ATTRIBUTE_METADATA_JSON, $storeCode, ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param string[] $codes @return array<string, array<string, mixed>> */
    private function adapterMetadata(StoreContext $context, array $codes): array
    {
        $out = [];
        foreach ($this->adapterPool->getSupported($context) as $adapter) {
            try {
                $data = $adapter->resolveAttributeMetadata($context, $codes);
                if (is_array($data)) {
                    $out = array_replace_recursive($out, $data);
                }
            } catch (\Throwable) {
                // Attribute dictionary must remain available even if an optional adapter fails.
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function sanitizeMetadata(array $metadata): array
    {
        $allowed = ['label', 'labels', 'kind', 'unit', 'semantic_group', 'facet_priority', 'export_enabled', 'aliases', 'is_filterable', 'is_searchable', 'is_visible'];
        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $metadata)) {
                $out[$key] = $metadata[$key];
            }
        }
        return $out;
    }

    private function kind(string $frontendInput, string $backendType): string
    {
        return match ($frontendInput) {
            'boolean' => 'boolean',
            'select' => 'select',
            'multiselect' => 'multiselect',
            'price' => 'decimal',
            'date' => 'datetime',
            'textarea' => 'text',
            default => match ($backendType) {
                'int' => 'integer',
                'decimal' => 'decimal',
                'datetime' => 'datetime',
                'text' => 'text',
                default => 'string',
            },
        };
    }

    /** @param string[] $codes @return string[] */
    private function normalizeCodes(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $code = trim((string)$code);
            if ($code !== '') {
                $out[] = $code;
            }
        }
        return array_values(array_unique($out));
    }

    private function table(string $name): string
    {
        return $this->resourceConnection->getTableName($name);
    }
}
