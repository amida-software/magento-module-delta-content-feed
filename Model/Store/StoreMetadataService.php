<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Store;

use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\Store\Normalizer\SpecialNormalizer;
use Amida\ProductDeltaFeed\Model\Store\Normalizer\TextNormalizer;
use Amida\ProductDeltaFeed\Model\Store\Normalizer\UrlNormalizer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store as StoreModel;
use Magento\Store\Model\StoreManagerInterface;

class StoreMetadataService
{
    /** @var array<int, array<string, mixed>> */
    private array $diagnostics = [];

    /** @var array<string, array<string, mixed>> */
    private array $sourceMap = [];

    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreMetadataAdapterPool $adapterPool,
        private readonly TextNormalizer $textNormalizer,
        private readonly UrlNormalizer $urlNormalizer,
        private readonly SpecialNormalizer $specialNormalizer
    ) {
    }

    /** @param array<string, mixed> $options */
    public function build(string $requestedStoreCode, array $options = []): array
    {
        $this->diagnostics = [];
        $this->sourceMap = [];

        $store = $this->storeManager->getStore($requestedStoreCode);
        $storeCode = (string)$store->getCode();
        $context = new StoreContext(
            $store,
            $storeCode,
            (int)$store->getId(),
            (int)$store->getWebsiteId(),
            $this->getStoreGroupId($store),
            $options
        );

        $includePages = $this->boolOption($options, 'include_pages', true);
        $includeCounts = $this->boolOption($options, 'include_counts', $this->config->getStoreMetadataFlag(Config::XML_PATH_STORE_INCLUDE_COUNTS_DEFAULT, $storeCode));
        $includeSitemap = $this->boolOption($options, 'include_sitemap', true);
        $requestedSources = $this->boolOption($options, 'include_sources', false);
        $includeSources = $requestedSources && $this->config->allowStoreIncludeSources($storeCode);
        if ($requestedSources && !$includeSources) {
            $this->diagnostic('source_map_disabled', 'info', 'include_sources=1 was requested but source map exposure is disabled by config.');
        }

        $pages = $includePages ? $this->resolvePages($context) : [];
        $response = [
            'schema_version' => 1,
            'entity' => 'store',
            'generated_at' => gmdate('c'),
            'requested_store_code' => $storeCode,
            'main_store_code' => $this->resolveMainStoreCode($context),
            'store' => $this->resolveStore($context),
            'languages' => $this->resolveLanguages($context),
            'currency' => $this->resolveCurrency($context),
            'counts' => $includeCounts ? $this->resolveCounts($context) : null,
            'contacts' => $this->resolveContacts($context),
            'countries' => $this->resolveCountries($context),
            'addresses' => $this->resolveAddresses($context),
            'pages' => $pages,
            'sitemap' => $includeSitemap ? $this->resolveSitemap($context, $pages) : null,
            'diagnostics' => $this->diagnostics,
        ];

        if (!$includeCounts) {
            $response['counts'] = null;
        }
        if ($includeSources) {
            $response['source_map'] = $this->sourceMap;
        }

        return $response;
    }

    private function resolveStore(StoreContext $context): array
    {
        $store = $context->store;
        $baseUrl = $this->storeBaseUrl($context);
        $secureBaseUrl = $this->storeBaseUrl($context, true);
        $adapterStore = $this->mergeAdapterArray($context, 'resolveStore');

        $name = $this->firstResolved('store.name', [
            $this->adminValue(Config::XML_PATH_STORE_NAME_OVERRIDE, $context, 'store.name'),
            $this->adapterValue($adapterStore['name'] ?? null, 'store.name', 'resolveStore.name'),
            $this->configValue('general/store_information/name', $context, 'store.name'),
            new ResolvedValue($this->textNormalizer->normalize((string)$store->getName(), 160), 'fallback', 'store_model', 'store.name', 0.8),
        ]);

        $description = $this->firstResolved('store.description', [
            $this->adminValue(Config::XML_PATH_STORE_DESCRIPTION_OVERRIDE, $context, 'store.description'),
            $this->adapterValue($adapterStore['description'] ?? null, 'store.description', 'resolveStore.description'),
            $this->homepageDescription($context),
        ], false);
        if ($description === null) {
            $this->diagnostic('store_description_missing', 'warning', 'Store description is not configured and could not be derived from homepage metadata.');
            $this->source('store.description', new ResolvedValue(null, 'missing', 'none', null, 0.0));
        }

        $homeUrl = $this->firstResolved('store.home_url', [
            $this->adminUrlValue(Config::XML_PATH_STORE_HOME_URL_OVERRIDE, $context, 'store.home_url', $baseUrl),
            $this->adapterUrlValue($adapterStore['home_url'] ?? null, 'store.home_url', 'resolveStore.home_url', $baseUrl),
            new ResolvedValue($baseUrl, 'auto', 'magento_store', 'base_url', 0.95),
        ]);

        $logoUrl = $this->firstResolved('store.logo_url', [
            $this->adminUrlValue(Config::XML_PATH_STORE_LOGO_URL_OVERRIDE, $context, 'store.logo_url', $baseUrl),
            $this->adapterUrlValue($adapterStore['logo_url'] ?? null, 'store.logo_url', 'resolveStore.logo_url', $baseUrl),
            $this->autoLogoUrl($context, $baseUrl),
        ], false);

        return [
            'id' => (int)$store->getId(),
            'code' => (string)$store->getCode(),
            'website_id' => (int)$store->getWebsiteId(),
            'website_code' => $this->websiteCode($context),
            'group_id' => $context->groupId,
            'group_code' => $this->groupCode($context),
            'is_active' => (bool)$store->isActive(),
            'is_default' => $this->isDefaultStore($context),
            'name' => $name,
            'description' => $description,
            'base_url' => $baseUrl,
            'secure_base_url' => $secureBaseUrl,
            'home_url' => $homeUrl,
            'logo_url' => $logoUrl,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function resolveLanguages(StoreContext $context): array
    {
        $scope = (string)($context->options['scope'] ?? 'group');
        if (!in_array($scope, ['store', 'group', 'website', 'all'], true)) {
            $scope = 'group';
        }
        $mainStoreCode = $this->resolveMainStoreCode($context);
        $stores = $this->storesForScope($context, $scope);
        $items = [];
        foreach ($stores as $store) {
            if (!$store->isActive()) {
                continue;
            }
            $storeCode = (string)$store->getCode();
            $locale = (string)$this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORES, $storeCode);
            $locale = $locale !== '' ? $locale : 'en_US';
            [$languageCode, $countryCode] = $this->splitLocale($locale);
            $tmpContext = new StoreContext($store, $storeCode, (int)$store->getId(), (int)$store->getWebsiteId(), $this->getStoreGroupId($store), $context->options);
            $items[] = [
                'store_id' => (int)$store->getId(),
                'store_code' => $storeCode,
                'name' => (string)$store->getName(),
                'locale' => $locale,
                'language_code' => $languageCode,
                'country_code' => $countryCode,
                'is_active' => (bool)$store->isActive(),
                'is_default' => $this->isDefaultStore($tmpContext),
                'is_main' => $storeCode === $mainStoreCode,
                'base_url' => $this->storeBaseUrl($tmpContext),
                'home_url' => $this->storeBaseUrl($tmpContext),
                'currency_code' => $this->currentCurrencyCode($tmpContext),
                'sitemap_urls' => $this->sitemapUrls($tmpContext),
            ];
        }
        $this->source('languages', new ResolvedValue('auto', 'auto', 'store_manager', 'getStores/scope:' . $scope, 0.95));
        return $items;
    }

    /** @return array<string, mixed> */
    private function resolveCurrency(StoreContext $context): array
    {
        $allowed = (string)$this->scopeConfig->getValue('currency/options/allow', ScopeInterface::SCOPE_STORES, $context->storeCode);
        $result = [
            'base' => (string)$this->scopeConfig->getValue('currency/options/base', ScopeInterface::SCOPE_STORES, $context->storeCode) ?: $this->currentCurrencyCode($context),
            'default' => (string)$this->scopeConfig->getValue('currency/options/default', ScopeInterface::SCOPE_STORES, $context->storeCode) ?: $this->currentCurrencyCode($context),
            'current' => $this->currentCurrencyCode($context),
            'allowed' => $allowed !== '' ? array_values(array_filter(array_map('trim', explode(',', $allowed)))) : [$this->currentCurrencyCode($context)],
        ];
        $this->source('currency', new ResolvedValue('auto', 'auto', 'magento_config', 'currency/options/*', 0.95));
        return $result;
    }

    /** @return array<string, int> */
    private function resolveCounts(StoreContext $context): array
    {
        $connection = $this->resourceConnection->getConnection();
        try {
            $counts = [
                'products_total' => (int)$connection->fetchOne($connection->select()->from($this->table('catalog_product_entity'), 'COUNT(*)')),
                'products_enabled' => $this->countProductsByEavInt($context, 'status', [1]),
                'products_visible' => $this->countProductsByEavInt($context, 'visibility', [2, 3, 4]),
                'categories_total' => (int)$connection->fetchOne($connection->select()->from($this->table('catalog_category_entity'), 'COUNT(*)')),
                'categories_enabled' => $this->countCategoriesEnabled($context),
            ];
            $this->source('counts', new ResolvedValue('auto', 'auto', 'direct_sql', 'catalog_product_entity/catalog_category_entity/eav', 0.9));
            return $counts;
        } catch (\Throwable $exception) {
            $this->diagnostic('counts_failed', 'warning', 'Counts could not be resolved by direct SQL: ' . $exception->getMessage());
            return [
                'products_total' => 0,
                'products_enabled' => 0,
                'products_visible' => 0,
                'categories_total' => 0,
                'categories_enabled' => 0,
            ];
        }
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function resolveContacts(StoreContext $context): array
    {
        $auto = ['phones' => [], 'emails' => [], 'messengers' => []];
        $phone = $this->textNormalizer->normalize((string)$this->scopeConfig->getValue('general/store_information/phone', ScopeInterface::SCOPE_STORES, $context->storeCode), 80);
        if ($phone !== null) {
            $auto['phones'][] = ['label' => 'Main', 'value' => preg_replace('/\s+/u', '', $phone), 'display' => $phone, 'type' => 'phone'];
        }
        foreach (['general' => 'General', 'sales' => 'Sales', 'support' => 'Support', 'custom1' => 'Custom 1', 'custom2' => 'Custom 2'] as $code => $label) {
            $email = trim((string)$this->scopeConfig->getValue('trans_email/ident_' . $code . '/email', ScopeInterface::SCOPE_STORES, $context->storeCode));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $auto['emails'][] = ['label' => $label, 'value' => $email, 'type' => $code];
            }
        }

        $adapter = $this->mergeAdapterArray($context, 'resolveContacts');
        $admin = $this->jsonConfig(Config::XML_PATH_STORE_CONTACTS_JSON, $context);
        $result = [
            'phones' => $this->mergeContactList($auto['phones'], (array)($adapter['phones'] ?? []), (array)($admin['phones'] ?? [])),
            'emails' => $this->mergeContactList($auto['emails'], (array)($adapter['emails'] ?? []), (array)($admin['emails'] ?? [])),
            'messengers' => $this->mergeContactList($auto['messengers'], (array)($adapter['messengers'] ?? []), (array)($admin['messengers'] ?? [])),
        ];
        $this->source('contacts', new ResolvedValue('mixed', 'mixed', 'magento_config/admin_config/site_adapter', 'contacts', 0.9));
        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function resolveCountries(StoreContext $context): array
    {
        $codes = [];
        foreach (['general/country/default', 'general/store_information/country_id'] as $path) {
            $code = trim((string)$this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORES, $context->storeCode));
            if ($code !== '') {
                $codes[] = strtoupper($code);
            }
        }
        $codes = array_values(array_unique($codes));
        $items = [];
        foreach ($codes as $i => $code) {
            $items[] = ['code' => $code, 'name' => $code, 'is_primary' => $i === 0];
        }
        $this->source('countries', new ResolvedValue('auto', 'auto', 'magento_config', 'general/country/default', 0.8));
        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function resolveAddresses(StoreContext $context): array
    {
        $auto = [];
        $street1 = $this->textNormalizer->normalize((string)$this->scopeConfig->getValue('general/store_information/street_line1', ScopeInterface::SCOPE_STORES, $context->storeCode), 160);
        $street2 = $this->textNormalizer->normalize((string)$this->scopeConfig->getValue('general/store_information/street_line2', ScopeInterface::SCOPE_STORES, $context->storeCode), 160);
        $city = $this->textNormalizer->normalize((string)$this->scopeConfig->getValue('general/store_information/city', ScopeInterface::SCOPE_STORES, $context->storeCode), 120);
        $postcode = $this->textNormalizer->normalize((string)$this->scopeConfig->getValue('general/store_information/postcode', ScopeInterface::SCOPE_STORES, $context->storeCode), 40);
        $country = strtoupper(trim((string)$this->scopeConfig->getValue('general/store_information/country_id', ScopeInterface::SCOPE_STORES, $context->storeCode)));
        if ($street1 !== null || $city !== null || $postcode !== null) {
            $raw = trim(implode(', ', array_filter([$postcode, $city, $street1, $street2])));
            $auto[] = [
                'label' => 'Store address',
                'country_code' => $country !== '' ? $country : null,
                'region' => null,
                'city' => $city,
                'street' => trim(implode(', ', array_filter([$street1, $street2]))),
                'postcode' => $postcode,
                'raw' => $raw !== '' ? $raw : null,
                'geo' => null,
            ];
        }
        $adapter = $this->mergeAdapterList($context, 'resolveAddresses');
        $admin = $this->jsonListConfig(Config::XML_PATH_STORE_ADDRESSES_JSON, $context);
        $result = $this->mergeAddressLists($auto, $adapter, $admin);
        $this->source('addresses', new ResolvedValue($result !== [] ? 'mixed' : 'missing', $result !== [] ? 'mixed' : 'missing', 'magento_config/admin_config/site_adapter', 'addresses', $result !== [] ? 0.9 : 0.0));
        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function resolvePages(StoreContext $context): array
    {
        $baseUrl = $this->storeBaseUrl($context);
        $auto = $this->autoCmsPages($context, $baseUrl);
        $adapter = $this->normalizePages($this->mergeAdapterList($context, 'resolvePages'), $context, $baseUrl, 'site_adapter');
        $admin = $this->normalizePages($this->jsonListConfig(Config::XML_PATH_STORE_PAGES_JSON, $context), $context, $baseUrl, 'admin_override');

        $merged = $auto;
        foreach ($adapter as $page) {
            $merged = $this->upsertPage($merged, $page);
        }
        foreach ($admin as $page) {
            if (($page['is_active'] ?? true) === false) {
                $merged = $this->removePage($merged, (string)($page['id'] ?? ''), (string)($page['url'] ?? ''));
                continue;
            }
            $merged = $this->upsertPage($merged, $page);
        }

        usort($merged, static function (array $a, array $b): int {
            return ((int)($a['sort_order'] ?? 1000) <=> (int)($b['sort_order'] ?? 1000)) ?: strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        });
        foreach ($merged as &$page) {
            unset($page['sort_order'], $page['_source']);
        }
        unset($page);

        $this->source('pages', new ResolvedValue('mixed', 'mixed', 'cms_page/admin_config/site_adapter', 'pages', 0.9));
        return array_values($merged);
    }

    /** @param array<int, array<string, mixed>> $pages */
    private function resolveSitemap(StoreContext $context, array $pages): array
    {
        $mode = (string)($context->options['sitemap_mode'] ?? $this->config->getStoreSitemapMode($context->storeCode));
        $mode = in_array($mode, ['summary', 'full'], true) ? $mode : 'summary';
        $limit = (int)($context->options['sitemap_limit'] ?? $this->config->getStoreSitemapLimit($context->storeCode));
        $limit = max(1, min(10000, $limit));

        $languages = [];
        foreach ($this->storesForScope($context, (string)($context->options['scope'] ?? 'group')) as $store) {
            if (!$store->isActive()) {
                continue;
            }
            $storeCode = (string)$store->getCode();
            $tmpContext = new StoreContext($store, $storeCode, (int)$store->getId(), (int)$store->getWebsiteId(), $this->getStoreGroupId($store), $context->options);
            $baseUrl = $this->storeBaseUrl($tmpContext);
            [$languageCode] = $this->splitLocale((string)$this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORES, $storeCode) ?: 'en_US');

            $entries = [];
            foreach ($pages as $page) {
                if (($page['store_code'] ?? $context->storeCode) !== $storeCode && ($page['store_code'] ?? '') !== '') {
                    continue;
                }
                $entries[] = [
                    'type' => (string)($page['type'] ?? 'page'),
                    'title' => (string)($page['title'] ?? ''),
                    'description' => $page['description'] ?? null,
                    'url' => (string)($page['url'] ?? ''),
                    'special' => array_values((array)($page['special'] ?? [])),
                ];
                if (count($entries) >= $limit) {
                    break;
                }
            }
            if (count($entries) < $limit) {
                foreach ($this->categoryEntries($tmpContext, $baseUrl, $limit - count($entries)) as $entry) {
                    $entries[] = $entry;
                }
            }

            $sitemapUrls = $this->sitemapUrls($tmpContext);
            $languages[] = [
                'code' => $languageCode,
                'store_code' => $storeCode,
                'is_default' => $this->isDefaultStore($tmpContext),
                'base_url' => $baseUrl,
                'sitemap_url' => $sitemapUrls[0] ?? rtrim($baseUrl, '/') . '/sitemap.xml',
                'entries' => $entries,
            ];
        }
        $this->source('sitemap', new ResolvedValue('mixed', 'mixed', 'pages/category_direct_sql/sitemap_table/admin_config', 'sitemap', 0.85));
        return [
            'mode' => $mode,
            'generated_at' => gmdate('c'),
            'languages' => $languages,
        ];
    }

    private function resolveMainStoreCode(StoreContext $context): string
    {
        $admin = trim((string)$this->config->getStoreMetadataValue(Config::XML_PATH_STORE_MAIN_STORE_CODE, $context->storeCode, ''));
        if ($admin !== '' && $this->storeCodeExists($admin)) {
            $this->source('main_store_code', new ResolvedValue($admin, 'admin_override', 'admin_config', Config::XML_PATH_STORE_MAIN_STORE_CODE, 1.0));
            return $admin;
        }
        foreach ($this->adapterPool->getSupported($context) as $adapter) {
            $data = $adapter->resolveStore($context);
            $candidate = trim((string)($data['main_store_code'] ?? ''));
            if ($candidate !== '' && $this->storeCodeExists($candidate)) {
                $this->source('main_store_code', new ResolvedValue($candidate, 'site_adapter', 'adapter:' . $adapter->getId(), 'resolveStore.main_store_code', 0.9));
                return $candidate;
            }
        }
        foreach ($this->storesForScope($context, 'group') as $store) {
            $tmp = new StoreContext($store, (string)$store->getCode(), (int)$store->getId(), (int)$store->getWebsiteId(), $this->getStoreGroupId($store), $context->options);
            if ($this->isDefaultStore($tmp)) {
                $code = (string)$store->getCode();
                $this->source('main_store_code', new ResolvedValue($code, 'auto', 'store_group', 'default_store_id', 0.9));
                return $code;
            }
        }
        return $context->storeCode;
    }

    private function autoLogoUrl(StoreContext $context, string $baseUrl): ?ResolvedValue
    {
        $logo = trim((string)$this->scopeConfig->getValue('design/header/logo_src', ScopeInterface::SCOPE_STORES, $context->storeCode));
        if ($logo === '') {
            return null;
        }
        $mediaBase = $this->storeMediaUrl($context);
        $url = preg_match('#^https?://#i', $logo) ? $logo : rtrim($mediaBase, '/') . '/logo/' . ltrim($logo, '/');
        $normalized = $this->urlNormalizer->normalize($url, $baseUrl, $this->diagnostics, 'store.logo_url');
        return $normalized !== null ? new ResolvedValue($normalized, 'auto', 'magento_config', 'design/header/logo_src', 0.8) : null;
    }

    private function homepageDescription(StoreContext $context): ?ResolvedValue
    {
        $identifier = (string)$this->scopeConfig->getValue('web/default/cms_home_page', ScopeInterface::SCOPE_STORES, $context->storeCode);
        if ($identifier === '') {
            return null;
        }
        $connection = $this->resourceConnection->getConnection();
        try {
            $select = $connection->select()
                ->from(['p' => $this->table('cms_page')], ['meta_description', 'content'])
                ->joinLeft(['ps' => $this->table('cms_page_store')], 'p.page_id = ps.page_id', [])
                ->where('p.identifier = ?', $identifier)
                ->where('p.is_active = ?', 1)
                ->where('ps.store_id IN (?)', [0, $context->storeId])
                ->order('ps.store_id DESC')
                ->limit(1);
            $row = $connection->fetchRow($select);
            if (!is_array($row)) {
                return null;
            }
            $description = $this->textNormalizer->normalize((string)($row['meta_description'] ?? ''), 500)
                ?? $this->textNormalizer->excerpt((string)($row['content'] ?? ''), 500);
            return $description !== null ? new ResolvedValue($description, 'auto', 'cms_page', 'web/default/cms_home_page:' . $identifier, 0.7) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function autoCmsPages(StoreContext $context, string $baseUrl): array
    {
        $connection = $this->resourceConnection->getConnection();
        $items = [];
        try {
            $select = $connection->select()
                ->from(['p' => $this->table('cms_page')], ['page_id', 'identifier', 'title', 'meta_description', 'content', 'is_active'])
                ->joinLeft(['ps' => $this->table('cms_page_store')], 'p.page_id = ps.page_id', ['store_id'])
                ->where('p.is_active = ?', 1)
                ->where('ps.store_id IN (?)', [0, $context->storeId])
                ->order(['ps.store_id DESC', 'p.identifier ASC']);
            $rows = $connection->fetchAll($select);
            $seen = [];
            $homeIdentifier = (string)$this->scopeConfig->getValue('web/default/cms_home_page', ScopeInterface::SCOPE_STORES, $context->storeCode);
            foreach ($rows as $row) {
                $identifier = (string)$row['identifier'];
                if ($identifier === '' || isset($seen[$identifier])) {
                    continue;
                }
                $seen[$identifier] = true;
                $url = $identifier === $homeIdentifier ? $baseUrl : $this->urlNormalizer->normalize($identifier, $baseUrl, $this->diagnostics, 'pages.' . $identifier . '.url');
                if ($url === null) {
                    continue;
                }
                $title = $this->textNormalizer->normalize((string)$row['title'], 160) ?: $identifier;
                $description = $this->textNormalizer->normalize((string)($row['meta_description'] ?? ''), 500)
                    ?? $this->textNormalizer->excerpt((string)($row['content'] ?? ''), 500);
                $special = $identifier === $homeIdentifier ? ['index'] : $this->specialNormalizer->inferFromText($identifier, $title, $url);
                $items[] = [
                    'id' => $identifier,
                    'type' => 'page',
                    'title' => $title,
                    'description' => $description,
                    'url' => $url,
                    'language_code' => $this->languageCode($context),
                    'store_code' => $context->storeCode,
                    'special' => $special,
                    'is_active' => true,
                    'sort_order' => $identifier === $homeIdentifier ? 0 : 100,
                    '_source' => 'auto',
                ];
            }
        } catch (\Throwable $exception) {
            $this->diagnostic('cms_pages_failed', 'warning', 'CMS pages could not be resolved: ' . $exception->getMessage());
        }
        if (!$this->hasSpecial($items, 'index')) {
            $items[] = [
                'id' => 'home',
                'type' => 'page',
                'title' => 'Home',
                'description' => null,
                'url' => $baseUrl,
                'language_code' => $this->languageCode($context),
                'store_code' => $context->storeCode,
                'special' => ['index'],
                'is_active' => true,
                'sort_order' => 0,
                '_source' => 'fallback',
            ];
        }
        return $items;
    }

    /** @param array<int, array<string, mixed>> $pages */
    private function normalizePages(array $pages, StoreContext $context, string $baseUrl, string $source): array
    {
        $items = [];
        foreach ($pages as $i => $page) {
            if (!is_array($page)) {
                continue;
            }
            $id = trim((string)($page['id'] ?? $page['identifier'] ?? 'custom_' . $i));
            $title = $this->textNormalizer->normalize((string)($page['title'] ?? $id), 160) ?: $id;
            $url = $this->urlNormalizer->normalize((string)($page['url'] ?? $id), $baseUrl, $this->diagnostics, 'pages.' . $id . '.url');
            if ($url === null) {
                continue;
            }
            $special = $this->specialNormalizer->normalize($page['special'] ?? [], $this->diagnostics);
            if ($special === []) {
                $special = $this->specialNormalizer->inferFromText($id, $title, $url);
            }
            $items[] = [
                'id' => $id,
                'type' => (string)($page['type'] ?? 'page'),
                'title' => $title,
                'description' => $this->textNormalizer->normalize((string)($page['description'] ?? ''), 500),
                'url' => $url,
                'language_code' => (string)($page['language_code'] ?? $this->languageCode($context)),
                'store_code' => (string)($page['store_code'] ?? $context->storeCode),
                'special' => $special,
                'is_active' => !isset($page['enabled']) || (bool)$page['enabled'],
                'sort_order' => (int)($page['sort_order'] ?? ($source === 'admin_override' ? 10 : 50)),
                '_source' => $source,
            ];
        }
        return $items;
    }

    /** @return array<int, array<string, mixed>> */
    private function categoryEntries(StoreContext $context, string $baseUrl, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $items = [];
        try {
            $categoryEntityType = (int)$connection->fetchOne($connection->select()
                ->from($this->table('eav_entity_type'), 'entity_type_id')
                ->where('entity_type_code = ?', 'catalog_category'));
            if ($categoryEntityType <= 0) {
                return [];
            }
            $attrs = $this->attributeIds($categoryEntityType, ['name', 'url_path', 'url_key', 'description', 'is_active']);
            $select = $connection->select()
                ->from(['c' => $this->table('catalog_category_entity')], ['entity_id', 'parent_id'])
                ->joinLeft(['name0' => $this->table('catalog_category_entity_varchar')], 'name0.entity_id = c.entity_id AND name0.attribute_id = ' . (int)($attrs['name'] ?? 0) . ' AND name0.store_id = 0', [])
                ->joinLeft(['names' => $this->table('catalog_category_entity_varchar')], 'names.entity_id = c.entity_id AND names.attribute_id = ' . (int)($attrs['name'] ?? 0) . ' AND names.store_id = ' . $context->storeId, [])
                ->joinLeft(['url0' => $this->table('catalog_category_entity_varchar')], 'url0.entity_id = c.entity_id AND url0.attribute_id = ' . (int)($attrs['url_path'] ?? $attrs['url_key'] ?? 0) . ' AND url0.store_id = 0', [])
                ->joinLeft(['urls' => $this->table('catalog_category_entity_varchar')], 'urls.entity_id = c.entity_id AND urls.attribute_id = ' . (int)($attrs['url_path'] ?? $attrs['url_key'] ?? 0) . ' AND urls.store_id = ' . $context->storeId, [])
                ->joinLeft(['desc0' => $this->table('catalog_category_entity_text')], 'desc0.entity_id = c.entity_id AND desc0.attribute_id = ' . (int)($attrs['description'] ?? 0) . ' AND desc0.store_id = 0', [])
                ->joinLeft(['descs' => $this->table('catalog_category_entity_text')], 'descs.entity_id = c.entity_id AND descs.attribute_id = ' . (int)($attrs['description'] ?? 0) . ' AND descs.store_id = ' . $context->storeId, [])
                ->joinLeft(['active0' => $this->table('catalog_category_entity_int')], 'active0.entity_id = c.entity_id AND active0.attribute_id = ' . (int)($attrs['is_active'] ?? 0) . ' AND active0.store_id = 0', [])
                ->joinLeft(['actives' => $this->table('catalog_category_entity_int')], 'actives.entity_id = c.entity_id AND actives.attribute_id = ' . (int)($attrs['is_active'] ?? 0) . ' AND actives.store_id = ' . $context->storeId, [])
                ->columns([
                    'name' => new \Zend_Db_Expr('COALESCE(names.value, name0.value)'),
                    'url_path' => new \Zend_Db_Expr('COALESCE(urls.value, url0.value)'),
                    'description' => new \Zend_Db_Expr('COALESCE(descs.value, desc0.value)'),
                    'is_active' => new \Zend_Db_Expr('COALESCE(actives.value, active0.value, 1)'),
                ])
                ->where('c.level > ?', 1)
                ->having('is_active = 1')
                ->order('c.entity_id ASC')
                ->limit($limit);
            foreach ($connection->fetchAll($select) as $row) {
                $name = $this->textNormalizer->normalize((string)($row['name'] ?? ''), 160);
                if ($name === null) {
                    continue;
                }
                $path = trim((string)($row['url_path'] ?? ''));
                $url = $this->urlNormalizer->normalize($path !== '' ? $path : 'catalog/category/view/id/' . (int)$row['entity_id'], $baseUrl, $this->diagnostics, 'category.' . (int)$row['entity_id'] . '.url');
                if ($url === null) {
                    continue;
                }
                $items[] = [
                    'type' => 'category',
                    'title' => $name,
                    'description' => $this->textNormalizer->normalize((string)($row['description'] ?? ''), 500),
                    'url' => $url,
                    'category_id' => (string)(int)$row['entity_id'],
                    'special' => [],
                ];
            }
        } catch (\Throwable $exception) {
            $this->diagnostic('category_sitemap_failed', 'warning', 'Category sitemap entries could not be resolved: ' . $exception->getMessage());
        }
        return $items;
    }

    /** @return string[] */
    private function sitemapUrls(StoreContext $context): array
    {
        $admin = $this->jsonConfig(Config::XML_PATH_STORE_SITEMAP_URLS_JSON, $context);
        if (isset($admin[$context->storeCode]) && is_string($admin[$context->storeCode])) {
            $url = $this->urlNormalizer->normalize($admin[$context->storeCode], $this->storeBaseUrl($context), $this->diagnostics, 'sitemap_url');
            return $url !== null ? [$url] : [];
        }
        if (isset($admin['default']) && is_string($admin['default'])) {
            $url = $this->urlNormalizer->normalize($admin['default'], $this->storeBaseUrl($context), $this->diagnostics, 'sitemap_url');
            return $url !== null ? [$url] : [];
        }
        if (isset($admin[0]) && is_string($admin[0])) {
            $url = $this->urlNormalizer->normalize($admin[0], $this->storeBaseUrl($context), $this->diagnostics, 'sitemap_url');
            return $url !== null ? [$url] : [];
        }

        $adapter = $this->mergeAdapterArray($context, 'resolveSitemap');
        $adapterUrls = $adapter['sitemap_urls'] ?? ($adapter['sitemap_url'] ?? null);
        if (is_string($adapterUrls)) {
            $adapterUrls = [$adapterUrls];
        }
        if (is_array($adapterUrls)) {
            $out = [];
            foreach ($adapterUrls as $candidate) {
                if (!is_scalar($candidate)) {
                    continue;
                }
                $url = $this->urlNormalizer->normalize((string)$candidate, $this->storeBaseUrl($context), $this->diagnostics, 'sitemap_url');
                if ($url !== null) {
                    $out[] = $url;
                }
            }
            if ($out !== []) {
                return array_values(array_unique($out));
            }
        }

        $connection = $this->resourceConnection->getConnection();
        try {
            $table = $this->table('sitemap');
            if (method_exists($connection, 'isTableExists') && !$connection->isTableExists($table)) {
                return [rtrim($this->storeBaseUrl($context), '/') . '/sitemap.xml'];
            }
            $select = $connection->select()
                ->from($table, ['sitemap_path', 'sitemap_filename'])
                ->where('store_id = ?', $context->storeId)
                ->order('sitemap_id DESC')
                ->limit(1);
            $row = $connection->fetchRow($select);
            if (is_array($row) && ($row['sitemap_filename'] ?? '') !== '') {
                $path = trim((string)($row['sitemap_path'] ?? ''), '/');
                $filename = ltrim((string)$row['sitemap_filename'], '/');
                return [rtrim($this->storeBaseUrl($context), '/') . '/' . ($path !== '' ? $path . '/' : '') . $filename];
            }
        } catch (\Throwable) {
            // fallback below
        }
        return [rtrim($this->storeBaseUrl($context), '/') . '/sitemap.xml'];
    }

    /** @return array<int, mixed> */
    private function storesForScope(StoreContext $context, string $scope): array
    {
        if ($scope === 'store') {
            return [$context->store];
        }
        $stores = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            if ($scope === 'group' && $this->getStoreGroupId($store) !== $context->groupId) {
                continue;
            }
            if ($scope === 'website' && (int)$store->getWebsiteId() !== $context->websiteId) {
                continue;
            }
            $stores[] = $store;
        }
        return $stores;
    }

    private function firstResolved(string $path, array $candidates, bool $required = true): mixed
    {
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof ResolvedValue) {
                continue;
            }
            if ($candidate->value !== null && $candidate->value !== '') {
                $this->source($path, $candidate);
                return $candidate->value;
            }
        }
        if ($required) {
            $this->source($path, new ResolvedValue(null, 'missing', 'none', null, 0.0));
        }
        return null;
    }

    private function adminValue(string $configPath, StoreContext $context, string $sourcePath): ?ResolvedValue
    {
        $value = $this->textNormalizer->normalize((string)$this->config->getStoreMetadataValue($configPath, $context->storeCode, ''), $sourcePath === 'store.name' ? 160 : 500);
        return $value !== null ? new ResolvedValue($value, 'admin_override', 'admin_config', $configPath, 1.0) : null;
    }

    private function configValue(string $configPath, StoreContext $context, string $sourcePath): ?ResolvedValue
    {
        $max = $sourcePath === 'store.name' ? 160 : 500;
        $value = $this->textNormalizer->normalize((string)$this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_STORES, $context->storeCode), $max);
        return $value !== null ? new ResolvedValue($value, 'auto', 'magento_config', $configPath, 0.9) : null;
    }

    private function adapterValue(mixed $value, string $path, string $adapterPath): ?ResolvedValue
    {
        $normalized = $this->textNormalizer->normalize(is_scalar($value) ? (string)$value : '', $path === 'store.name' ? 160 : 500);
        return $normalized !== null ? new ResolvedValue($normalized, 'site_adapter', 'adapter', $adapterPath, 0.9) : null;
    }

    private function adminUrlValue(string $configPath, StoreContext $context, string $sourcePath, string $baseUrl): ?ResolvedValue
    {
        $value = trim((string)$this->config->getStoreMetadataValue($configPath, $context->storeCode, ''));
        $url = $this->urlNormalizer->normalize($value, $baseUrl, $this->diagnostics, $sourcePath);
        return $url !== null ? new ResolvedValue($url, 'admin_override', 'admin_config', $configPath, 1.0) : null;
    }

    private function adapterUrlValue(mixed $value, string $sourcePath, string $adapterPath, string $baseUrl): ?ResolvedValue
    {
        if (!is_scalar($value)) {
            return null;
        }
        $url = $this->urlNormalizer->normalize((string)$value, $baseUrl, $this->diagnostics, $sourcePath);
        return $url !== null ? new ResolvedValue($url, 'site_adapter', 'adapter', $adapterPath, 0.9) : null;
    }

    private function source(string $path, ResolvedValue $value): void
    {
        $this->sourceMap[$path] = $value->toSourceMapEntry();
    }

    private function diagnostic(string $code, string $level, string $message): void
    {
        $this->diagnostics[] = ['code' => $code, 'level' => $level, 'message' => $message];
    }

    private function boolOption(array $options, string $name, bool $default): bool
    {
        if (!array_key_exists($name, $options)) {
            return $default;
        }
        $value = $options[$name];
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string, mixed> */
    private function jsonConfig(string $path, StoreContext $context): array
    {
        $raw = trim((string)$this->config->getStoreMetadataValue($path, $context->storeCode, ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->diagnostic('admin_json_invalid', 'warning', sprintf('Invalid JSON config at %s.', $path));
            return [];
        }
        return $decoded;
    }

    /** @return array<int, array<string, mixed>> */
    private function jsonListConfig(string $path, StoreContext $context): array
    {
        $decoded = $this->jsonConfig($path, $context);
        if ($decoded === []) {
            return [];
        }
        if (array_is_list($decoded)) {
            return array_values(array_filter($decoded, 'is_array'));
        }
        if (isset($decoded[$context->storeCode]) && is_array($decoded[$context->storeCode])) {
            return array_is_list($decoded[$context->storeCode]) ? $decoded[$context->storeCode] : [$decoded[$context->storeCode]];
        }
        return [];
    }

    /** @return array<string, mixed> */
    private function mergeAdapterArray(StoreContext $context, string $method): array
    {
        $result = [];
        foreach ($this->adapterPool->getSupported($context) as $adapter) {
            try {
                $data = $adapter->{$method}($context);
                if (is_array($data)) {
                    $result = array_replace_recursive($result, $data);
                }
            } catch (\Throwable $exception) {
                $this->diagnostic('adapter_failed', 'warning', sprintf('Adapter %s failed in %s: %s', $adapter->getId(), $method, $exception->getMessage()));
            }
        }
        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function mergeAdapterList(StoreContext $context, string $method): array
    {
        $items = [];
        foreach ($this->adapterPool->getSupported($context) as $adapter) {
            try {
                $data = $adapter->{$method}($context);
                if (is_array($data)) {
                    foreach ($data as $item) {
                        if (is_array($item)) {
                            $items[] = $item;
                        }
                    }
                }
            } catch (\Throwable $exception) {
                $this->diagnostic('adapter_failed', 'warning', sprintf('Adapter %s failed in %s: %s', $adapter->getId(), $method, $exception->getMessage()));
            }
        }
        return $items;
    }

    private function mergeContactList(array ...$lists): array
    {
        $out = [];
        $seen = [];
        foreach ($lists as $list) {
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $value = trim((string)($item['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $key = strtolower($value);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = array_filter([
                    'label' => $this->textNormalizer->normalize((string)($item['label'] ?? ''), 80),
                    'value' => $value,
                    'display' => isset($item['display']) ? $this->textNormalizer->normalize((string)$item['display'], 80) : null,
                    'type' => $this->textNormalizer->normalize((string)($item['type'] ?? ''), 40) ?: 'custom',
                ], static fn (mixed $v): bool => $v !== null && $v !== '');
            }
        }
        return $out;
    }

    private function mergeAddressLists(array ...$lists): array
    {
        $out = [];
        $seen = [];
        foreach ($lists as $list) {
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $value = trim((string)($item['raw'] ?? json_encode($item)));
                if ($value === '' || isset($seen[$value])) {
                    continue;
                }
                $seen[$value] = true;
                $out[] = $item;
            }
        }
        return $out;
    }

    private function upsertPage(array $pages, array $page): array
    {
        foreach ($pages as $idx => $existing) {
            if (($page['id'] ?? '') !== '' && (string)($existing['id'] ?? '') === (string)$page['id']) {
                $pages[$idx] = array_replace($existing, array_filter($page, static fn (mixed $v): bool => $v !== null));
                return $pages;
            }
            if (($page['url'] ?? '') !== '' && (string)($existing['url'] ?? '') === (string)$page['url']) {
                $pages[$idx] = array_replace($existing, array_filter($page, static fn (mixed $v): bool => $v !== null));
                return $pages;
            }
        }
        $pages[] = $page;
        return $pages;
    }

    private function removePage(array $pages, string $id, string $url): array
    {
        return array_values(array_filter($pages, static function (array $page) use ($id, $url): bool {
            if ($id !== '' && (string)($page['id'] ?? '') === $id) {
                return false;
            }
            if ($url !== '' && (string)($page['url'] ?? '') === $url) {
                return false;
            }
            return true;
        }));
    }

    private function hasSpecial(array $pages, string $special): bool
    {
        foreach ($pages as $page) {
            if (in_array($special, (array)($page['special'] ?? []), true)) {
                return true;
            }
        }
        return false;
    }

    private function storeBaseUrl(StoreContext $context, bool $secure = false): string
    {
        try {
            return (string)$context->store->getBaseUrl(StoreModel::URL_TYPE_LINK, $secure);
        } catch (\Throwable) {
            return '/';
        }
    }

    private function storeMediaUrl(StoreContext $context): string
    {
        try {
            return (string)$context->store->getBaseUrl(StoreModel::URL_TYPE_MEDIA);
        } catch (\Throwable) {
            return $this->storeBaseUrl($context);
        }
    }

    private function currentCurrencyCode(StoreContext $context): string
    {
        try {
            if (method_exists($context->store, 'getCurrentCurrencyCode')) {
                return (string)$context->store->getCurrentCurrencyCode();
            }
            if (method_exists($context->store, 'getDefaultCurrencyCode')) {
                return (string)$context->store->getDefaultCurrencyCode();
            }
        } catch (\Throwable) {
        }
        return (string)$this->scopeConfig->getValue('currency/options/default', ScopeInterface::SCOPE_STORES, $context->storeCode) ?: 'USD';
    }

    private function websiteCode(StoreContext $context): ?string
    {
        try {
            return (string)$this->storeManager->getWebsite($context->websiteId)->getCode();
        } catch (\Throwable) {
            return null;
        }
    }

    private function groupCode(StoreContext $context): ?string
    {
        try {
            $group = $this->storeManager->getGroup($context->groupId);
            return method_exists($group, 'getCode') ? (string)$group->getCode() : (string)$group->getName();
        } catch (\Throwable) {
            return null;
        }
    }

    private function getStoreGroupId(mixed $store): int
    {
        try {
            return method_exists($store, 'getGroupId') ? (int)$store->getGroupId() : (int)$store->getStoreGroupId();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function isDefaultStore(StoreContext $context): bool
    {
        try {
            return (int)$this->storeManager->getGroup($context->groupId)->getDefaultStoreId() === $context->storeId;
        } catch (\Throwable) {
            return false;
        }
    }

    private function languageCode(StoreContext $context): string
    {
        [$language] = $this->splitLocale((string)$this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORES, $context->storeCode) ?: 'en_US');
        return $language;
    }

    /** @return array{0:string,1:string} */
    private function splitLocale(string $locale): array
    {
        $parts = preg_split('/[_-]/', $locale) ?: [];
        return [strtolower((string)($parts[0] ?? 'en')), strtoupper((string)($parts[1] ?? ''))];
    }

    private function storeCodeExists(string $storeCode): bool
    {
        try {
            $this->storeManager->getStore($storeCode);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function table(string $name): string
    {
        return $this->resourceConnection->getTableName($name);
    }

    private function countProductsByEavInt(StoreContext $context, string $attributeCode, array $values): int
    {
        $connection = $this->resourceConnection->getConnection();
        $attributeId = $this->productAttributeId($attributeCode);
        if ($attributeId <= 0) {
            return 0;
        }
        $select = $connection->select()
            ->from(['e' => $this->table('catalog_product_entity')], 'COUNT(DISTINCT e.entity_id)')
            ->joinLeft(['v0' => $this->table('catalog_product_entity_int')], 'v0.entity_id = e.entity_id AND v0.attribute_id = ' . $attributeId . ' AND v0.store_id = 0', [])
            ->joinLeft(['vs' => $this->table('catalog_product_entity_int')], 'vs.entity_id = e.entity_id AND vs.attribute_id = ' . $attributeId . ' AND vs.store_id = ' . $context->storeId, [])
            ->where('COALESCE(vs.value, v0.value) IN (?)', $values);
        return (int)$connection->fetchOne($select);
    }

    private function countCategoriesEnabled(StoreContext $context): int
    {
        $connection = $this->resourceConnection->getConnection();
        $attributeId = $this->categoryAttributeId('is_active');
        if ($attributeId <= 0) {
            return 0;
        }
        $select = $connection->select()
            ->from(['e' => $this->table('catalog_category_entity')], 'COUNT(DISTINCT e.entity_id)')
            ->joinLeft(['v0' => $this->table('catalog_category_entity_int')], 'v0.entity_id = e.entity_id AND v0.attribute_id = ' . $attributeId . ' AND v0.store_id = 0', [])
            ->joinLeft(['vs' => $this->table('catalog_category_entity_int')], 'vs.entity_id = e.entity_id AND vs.attribute_id = ' . $attributeId . ' AND vs.store_id = ' . $context->storeId, [])
            ->where('COALESCE(vs.value, v0.value, 1) = 1');
        return (int)$connection->fetchOne($select);
    }

    private function productAttributeId(string $code): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->table('eav_attribute')], 'a.attribute_id')
            ->join(['t' => $this->table('eav_entity_type')], 'a.entity_type_id = t.entity_type_id', [])
            ->where('t.entity_type_code = ?', 'catalog_product')
            ->where('a.attribute_code = ?', $code)
            ->limit(1);
        return (int)$connection->fetchOne($select);
    }

    private function categoryAttributeId(string $code): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['a' => $this->table('eav_attribute')], 'a.attribute_id')
            ->join(['t' => $this->table('eav_entity_type')], 'a.entity_type_id = t.entity_type_id', [])
            ->where('t.entity_type_code = ?', 'catalog_category')
            ->where('a.attribute_code = ?', $code)
            ->limit(1);
        return (int)$connection->fetchOne($select);
    }

    /** @param string[] $codes @return array<string, int> */
    private function attributeIds(int $entityTypeId, array $codes): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->table('eav_attribute'), ['attribute_code', 'attribute_id'])
            ->where('entity_type_id = ?', $entityTypeId)
            ->where('attribute_code IN (?)', $codes);
        $rows = $connection->fetchPairs($select);
        $out = [];
        foreach ($rows as $code => $id) {
            $out[(string)$code] = (int)$id;
        }
        return $out;
    }
}
