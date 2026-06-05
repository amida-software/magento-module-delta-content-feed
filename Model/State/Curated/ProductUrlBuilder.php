<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\State\Curated;

use Amida\ProductDeltaFeed\Model\Config;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds an absolute product page URL for the curated feed.
 *
 * Mirrors ImageUrlBuilder: the base is the configured feed domain when set (e.g. a production
 * host/CDN), otherwise the store's own link base URL. Using the feed domain avoids depending on
 * the store base URL, which can be misconfigured to "/" on some deploys.
 */
class ProductUrlBuilder
{
    private const XML_PATH_PRODUCT_URL_SUFFIX = 'catalog/seo/product_url_suffix';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Absolute product page URL: {base}/{url_key}{product_url_suffix}. Returns null when the
     * product has no url_key (so a configurable child can inherit the parent's url).
     */
    public function getProductUrl(Product $product, string $storeCode): ?string
    {
        $urlKey = trim((string)($product->getData('url_key') ?? ''));
        if ($urlKey === '') {
            return null;
        }

        $suffix = trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_PRODUCT_URL_SUFFIX,
            ScopeInterface::SCOPE_STORE,
            $storeCode
        ));

        return $this->baseUrl($storeCode) . '/' . ltrim($urlKey, '/') . $suffix;
    }

    private function baseUrl(string $storeCode): string
    {
        $domain = $this->config->getFeedDomain($storeCode);
        if ($domain !== '') {
            return preg_match('#^https?://#i', $domain) === 1
                ? rtrim($domain, '/')
                : 'https://' . rtrim($domain, '/');
        }

        return rtrim((string)$this->storeManager->getStore($storeCode)->getBaseUrl(UrlInterface::URL_TYPE_LINK), '/');
    }
}
