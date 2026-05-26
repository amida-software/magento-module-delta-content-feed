<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Store;

use Amida\ProductDeltaFeed\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

class AttributeDictionaryService
{
    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly StoreMetadataAdapterPool $adapterPool
    ) {
    }

    /** @param string[] $codes */
    public function build(string $storeCode, array $codes = []): array
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
        $items = $this->loadAttributes($context, $codes);
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

        return [
            'schema_version' => 1,
            'entity' => 'attributes',
            'store_code' => $storeCode,
            'items' => array_values($items),
            'diagnostics' => [],
        ];
    }

    /** @param string[] $codes @return array<string, array<string, mixed>> */
    private function loadAttributes(StoreContext $context, array $codes): array
    {
        $connection = $this->resourceConnection->getConnection();
        $entityTypeId = (int)$connection->fetchOne($connection->select()
            ->from($this->table('eav_entity_type'), 'entity_type_id')
            ->where('entity_type_code = ?', 'catalog_product')
            ->limit(1));
        if ($entityTypeId <= 0) {
            return [];
        }

        $select = $connection->select()
            ->from(['a' => $this->table('eav_attribute')], [
                'attribute_id', 'attribute_code', 'frontend_label', 'frontend_input', 'backend_type', 'is_required', 'default_value'
            ])
            ->joinLeft(['ca' => $this->table('catalog_eav_attribute')], 'a.attribute_id = ca.attribute_id', [
                'is_filterable', 'is_searchable', 'is_visible', 'is_visible_on_front', 'used_for_sort_by'
            ])
            ->joinLeft(['asl' => $this->table('eav_attribute_label')], 'a.attribute_id = asl.attribute_id AND asl.store_id = ' . $context->storeId, ['store_label' => 'value'])
            ->where('a.entity_type_id = ?', $entityTypeId)
            ->order('a.attribute_code ASC');
        if ($codes !== []) {
            $select->where('a.attribute_code IN (?)', $codes);
        }

        $rows = $connection->fetchAll($select);
        $items = [];
        foreach ($rows as $row) {
            $code = (string)$row['attribute_code'];
            if ($code === '') {
                continue;
            }
            $kind = $this->kind((string)($row['frontend_input'] ?? ''), (string)($row['backend_type'] ?? ''));
            $items[$code] = [
                'code' => $code,
                'label' => (string)($row['store_label'] ?: $row['frontend_label'] ?: $code),
                'kind' => $kind,
                'unit' => null,
                'is_filterable' => (bool)(int)($row['is_filterable'] ?? 0),
                'is_searchable' => (bool)(int)($row['is_searchable'] ?? 0),
                'is_visible' => (bool)(int)($row['is_visible'] ?? 1),
                'is_visible_on_front' => (bool)(int)($row['is_visible_on_front'] ?? 0),
                'is_required' => (bool)(int)($row['is_required'] ?? 0),
                'options' => in_array($kind, ['select', 'multiselect', 'boolean'], true) ? $this->loadOptions((int)$row['attribute_id'], $context->storeId) : [],
            ];
        }
        return $items;
    }

    /** @return array<int, array<string, string>> */
    private function loadOptions(int $attributeId, int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['o' => $this->table('eav_attribute_option')], ['option_id'])
            ->joinLeft(['v0' => $this->table('eav_attribute_option_value')], 'o.option_id = v0.option_id AND v0.store_id = 0', [])
            ->joinLeft(['vs' => $this->table('eav_attribute_option_value')], 'o.option_id = vs.option_id AND vs.store_id = ' . $storeId, [])
            ->columns(['label' => new \Zend_Db_Expr('COALESCE(vs.value, v0.value)')])
            ->where('o.attribute_id = ?', $attributeId)
            ->order('o.sort_order ASC');
        $options = [];
        foreach ($connection->fetchAll($select) as $row) {
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $options[] = ['value' => (string)(int)$row['option_id'], 'label' => $label];
        }
        return $options;
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
        $allowed = ['label', 'kind', 'unit', 'semantic_group', 'facet_priority', 'export_enabled', 'aliases', 'is_filterable', 'is_searchable', 'is_visible'];
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
