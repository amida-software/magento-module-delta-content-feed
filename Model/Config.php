<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Config
{
    public const STREAM_CONTENT = 'content';
    public const STREAM_SEO = 'seo';
    public const STREAM_PRICE = 'price';
    public const STREAM_AVAILABILITY = 'availability';
    public const STREAM_CATEGORY = 'category';
    public const STREAM_CURATED = 'curated';
    public const STREAM_ALL = 'all';

    public const XML_PATH_ENABLED = 'amida_productdeltafeed/general/enabled';
    public const XML_PATH_ROUTE_ENABLED = 'amida_productdeltafeed/general/route_enabled';
    public const XML_PATH_API_REQUEST_MONOPOLY_ENABLED = 'amida_productdeltafeed/general/api_request_monopoly_enabled';
    public const XML_PATH_API_REQUEST_TIMEOUT_SECONDS = 'amida_productdeltafeed/general/api_request_timeout_seconds';
    public const XML_PATH_PUBLIC_KEY = 'amida_productdeltafeed/general/public_key';
    public const XML_PATH_ZSTD_ENABLED = 'amida_productdeltafeed/general/zstd_enabled';
    public const XML_PATH_ZSTD_LEVEL = 'amida_productdeltafeed/general/zstd_level';
    public const XML_PATH_MAX_BATCH_SIZE = 'amida_productdeltafeed/general/max_batch_size_bytes';
    public const XML_PATH_HARD_SINGLE_LIMIT = 'amida_productdeltafeed/general/hard_single_item_limit_bytes';
    public const XML_PATH_CANDIDATE_LIMIT = 'amida_productdeltafeed/general/candidate_limit';
    public const XML_PATH_DIRTY_BATCH_SIZE = 'amida_productdeltafeed/general/dirty_batch_size';
    public const XML_PATH_RECONCILE_BATCH_SIZE = 'amida_productdeltafeed/general/reconcile_batch_size';
    public const XML_PATH_REPAIR_SCAN_BATCH_SIZE = 'amida_productdeltafeed/general/repair_scan_batch_size';
    public const XML_PATH_RETENTION_DAYS = 'amida_productdeltafeed/general/retention_days';
    public const XML_PATH_LAST_RECONCILE_PRODUCT_ID = 'amida_productdeltafeed/general/last_reconcile_product_id';
    public const XML_PATH_LAST_RECONCILE_RUN_AT = 'amida_productdeltafeed/general/last_reconcile_run_at';
    public const XML_PATH_STORES = 'amida_productdeltafeed/stores/codes';
    public const XML_PATH_CONTENT_INCLUDE = 'amida_productdeltafeed/content/include_attributes';
    public const XML_PATH_CONTENT_EXCLUDE = 'amida_productdeltafeed/content/exclude_attributes';
    public const XML_PATH_SUPPRESS_WHILE_DISABLED = 'amida_productdeltafeed/runtime/suppress_while_disabled';
    public const XML_PATH_REEMIT_FULL_ON_ENABLE = 'amida_productdeltafeed/runtime/reemit_full_on_enable';
    public const XML_PATH_EXPORT_TOMBSTONE = 'amida_productdeltafeed/runtime/export_deleted_as_tombstone';

    private const STREAM_PATHS = [
        self::STREAM_CONTENT => 'amida_productdeltafeed/streams/content_enabled',
        self::STREAM_SEO => 'amida_productdeltafeed/streams/seo_enabled',
        self::STREAM_PRICE => 'amida_productdeltafeed/streams/price_enabled',
        self::STREAM_AVAILABILITY => 'amida_productdeltafeed/streams/availability_enabled',
        self::STREAM_CATEGORY => 'amida_productdeltafeed/streams/category_enabled',
        self::STREAM_CURATED => 'amida_productdeltafeed/streams/curated_enabled',
        self::STREAM_ALL => 'amida_productdeltafeed/streams/all_enabled',
    ];

    private const DEFAULT_SEO_ATTRIBUTES = [
        'name',
        'url_key',
        'description',
        'short_description',
        'meta_title',
        'meta_description',
        'meta_keyword',
    ];

    private const DEFAULT_PRICE_ATTRIBUTES = [
        'price',
        'special_price',
        'special_from_date',
        'special_to_date',
    ];

    private const DEFAULT_AVAILABILITY_FIELDS = [
        'is_in_stock',
        'is_salable',
        'qty',
        'manage_stock',
        'backorders',
        'stock_status',
    ];

    private const DEFAULT_CATEGORY_FIELDS = [
        'category_ids',
        'category_positions',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isEnabled(?string $scopeCode = null): bool
    {
        return $this->isFlag(self::XML_PATH_ENABLED, $scopeCode);
    }

    public function isRouteEnabled(?string $scopeCode = null): bool
    {
        return $this->isFlag(self::XML_PATH_ROUTE_ENABLED, $scopeCode);
    }

    public function isApiRequestMonopolyEnabled(?string $scopeCode = null): bool
    {
        return $this->isFlag(self::XML_PATH_API_REQUEST_MONOPOLY_ENABLED, $scopeCode);
    }

    public function getApiRequestTimeoutSeconds(?string $scopeCode = null): int
    {
        return max(1, (int)$this->getValue(self::XML_PATH_API_REQUEST_TIMEOUT_SECONDS, $scopeCode, 5));
    }

    public function isZstdEnabled(?string $scopeCode = null): bool
    {
        return $this->isFlag(self::XML_PATH_ZSTD_ENABLED, $scopeCode);
    }

    public function getZstdLevel(?string $scopeCode = null): int
    {
        return max(1, (int)$this->getValue(self::XML_PATH_ZSTD_LEVEL, $scopeCode, 3));
    }

    public function getMaxBatchSizeBytes(?string $scopeCode = null): int
    {
        return max(1024, (int)$this->getValue(self::XML_PATH_MAX_BATCH_SIZE, $scopeCode, 2097152));
    }

    public function getHardSingleItemLimitBytes(?string $scopeCode = null): int
    {
        return max(
            $this->getMaxBatchSizeBytes($scopeCode),
            (int)$this->getValue(self::XML_PATH_HARD_SINGLE_LIMIT, $scopeCode, 4194304)
        );
    }

    public function getCandidateLimit(?string $scopeCode = null): int
    {
        return max(10, (int)$this->getValue(self::XML_PATH_CANDIDATE_LIMIT, $scopeCode, 250));
    }

    public function getDirtyBatchSize(?string $scopeCode = null): int
    {
        return max(1, (int)$this->getValue(self::XML_PATH_DIRTY_BATCH_SIZE, $scopeCode, 100));
    }

    public function getReconcileBatchSize(?string $scopeCode = null): int
    {
        return max(1, (int)$this->getValue(self::XML_PATH_RECONCILE_BATCH_SIZE, $scopeCode, 100));
    }

    public function getRepairScanBatchSize(?string $scopeCode = null): int
    {
        return max(1, (int)$this->getValue(self::XML_PATH_REPAIR_SCAN_BATCH_SIZE, $scopeCode, 100));
    }

    public function getRetentionDays(?string $scopeCode = null): int
    {
        return max(1, (int)$this->getValue(self::XML_PATH_RETENTION_DAYS, $scopeCode, 30));
    }

    public function getLastReconcileProductId(?string $scopeCode = null): int
    {
        return max(0, (int)$this->getValue(self::XML_PATH_LAST_RECONCILE_PRODUCT_ID, $scopeCode, 0));
    }

    public function getLastReconcileRunAt(?string $scopeCode = null): string
    {
        return (string)$this->getValue(self::XML_PATH_LAST_RECONCILE_RUN_AT, $scopeCode, '');
    }

    public function getPublicKey(?string $scopeCode = null): string
    {
        return trim((string)$this->getValue(self::XML_PATH_PUBLIC_KEY, $scopeCode, ''));
    }

    public function getPublicToken(?string $scopeCode = null): string
    {
        return $this->getPublicKey($scopeCode);
    }

    public function isStreamEnabled(string $stream, ?string $scopeCode = null): bool
    {
        if (!isset(self::STREAM_PATHS[$stream])) {
            return false;
        }

        return $this->isFlag(self::STREAM_PATHS[$stream], $scopeCode);
    }

    /**
     * @return string[]
     */
    public function getStreamCodes(): array
    {
        return array_keys(self::STREAM_PATHS);
    }

    /**
     * @return string[]
     */
    public function getActiveStreams(?string $scopeCode = null): array
    {
        return array_values(array_filter(
            $this->getStreamCodes(),
            fn (string $stream): bool => $this->isStreamEnabled($stream, $scopeCode)
        ));
    }

    /**
     * @return string[]
     */
    public function getSeoAttributeCodes(): array
    {
        return self::DEFAULT_SEO_ATTRIBUTES;
    }

    /**
     * @return string[]
     */
    public function getPriceAttributeCodes(): array
    {
        return self::DEFAULT_PRICE_ATTRIBUTES;
    }

    /**
     * @return string[]
     */
    public function getAvailabilityFields(): array
    {
        return self::DEFAULT_AVAILABILITY_FIELDS;
    }

    /**
     * @return string[]
     */
    public function getCategoryFields(): array
    {
        return self::DEFAULT_CATEGORY_FIELDS;
    }

    /**
     * @return string[]
     */
    public function getContentIncludeAttributes(): array
    {
        return $this->csvToArray((string)$this->getValue(self::XML_PATH_CONTENT_INCLUDE, null, ''));
    }

    /**
     * @return string[]
     */
    public function getContentAttributeCodes(): array
    {
        return $this->getContentIncludeAttributes();
    }

    /**
     * @return string[]
     */
    public function getContentExcludeAttributes(): array
    {
        return $this->csvToArray((string)$this->getValue(self::XML_PATH_CONTENT_EXCLUDE, null, ''));
    }

    /**
     * @return string[]
     */
    public function getContentExcludedAttributeCodes(): array
    {
        return $this->getContentExcludeAttributes();
    }

    public function getContentAttributeMode(): string
    {
        return $this->getContentIncludeAttributes() !== [] ? 'whitelist' : 'auto_all_minus_excluded';
    }

    public function suppressWhileDisabled(): bool
    {
        return $this->isFlag(self::XML_PATH_SUPPRESS_WHILE_DISABLED);
    }

    public function reemitFullOnEnable(): bool
    {
        return $this->isFlag(self::XML_PATH_REEMIT_FULL_ON_ENABLE);
    }

    public function exportDeletedAsTombstone(): bool
    {
        return $this->isFlag(self::XML_PATH_EXPORT_TOMBSTONE);
    }

    public function includeCategoryNames(): bool
    {
        return false;
    }

    public function includeCategoryPaths(): bool
    {
        return false;
    }

    /**
     * @return string[]
     */
    public function getConfiguredStoreCodes(): array
    {
        $configured = $this->csvToArray((string)$this->getValue(self::XML_PATH_STORES, null, ''));
        $allowedIds = array_map('intval', $configured);
        $codes = [];

        foreach ($this->storeManager->getStores(false) as $store) {
            if (!$store->isActive()) {
                continue;
            }
            if ($allowedIds !== [] && !in_array((int)$store->getId(), $allowedIds, true)) {
                continue;
            }
            $codes[] = (string)$store->getCode();
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return int[]
     */
    public function getExportStoreIds(): array
    {
        $configured = $this->csvToArray((string)$this->getValue(self::XML_PATH_STORES, null, ''));
        $allowedIds = array_map('intval', $configured);
        $ids = [];

        foreach ($this->storeManager->getStores(false) as $store) {
            if (!$store->isActive()) {
                continue;
            }
            if ($allowedIds !== [] && !in_array((int)$store->getId(), $allowedIds, true)) {
                continue;
            }
            $ids[] = (int)$store->getId();
        }

        return array_values(array_unique($ids));
    }

    private function isFlag(string $path, ?string $scopeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_WEBSITES, $scopeCode);
    }

    private function getValue(string $path, ?string $scopeCode = null, mixed $default = null): mixed
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_WEBSITES, $scopeCode);

        return $value !== null && $value !== '' ? $value : $default;
    }

    /**
     * @return string[]
     */
    private function csvToArray(string $value): array
    {
        $parts = array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== '');
        return array_values(array_unique($parts));
    }
}
