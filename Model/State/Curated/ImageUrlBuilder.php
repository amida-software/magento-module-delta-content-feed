<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\State\Curated;

use Amida\ProductDeltaFeed\Model\Config;
use Magento\Catalog\Model\Product;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class ImageUrlBuilder
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    /**
     * @return string[]
     */
    public function getImageUrls(Product $product, string $storeCode): array
    {
        $files = [];
        $gallery = $product->getData('media_gallery');
        if (is_array($gallery) && isset($gallery['images']) && is_array($gallery['images'])) {
            foreach ($gallery['images'] as $image) {
                if (!is_array($image)) {
                    continue;
                }
                if ((string)($image['disabled'] ?? '0') === '1') {
                    continue;
                }
                $file = $this->normalizeFile((string)($image['file'] ?? ''));
                if ($file !== null) {
                    $files[] = $file;
                }
            }
        }

        foreach (['image', 'small_image', 'thumbnail'] as $attributeCode) {
            $file = $this->normalizeFile((string)($product->getData($attributeCode) ?? ''));
            if ($file !== null) {
                $files[] = $file;
            }
        }

        $files = array_values(array_unique($files));
        if ($files === []) {
            return [];
        }

        $baseMediaUrl = $this->mediaBaseUrl($storeCode);

        return array_map(
            static function (string $file) use ($baseMediaUrl): string {
                if (preg_match('#^https?://#i', $file)) {
                    return $file;
                }

                return $baseMediaUrl . 'catalog/product/' . ltrim($file, '/');
            },
            $files
        );
    }

    /**
     * Media base URL for absolute image links: the configured feed domain when set
     * (e.g. a production CDN/host), otherwise the store's own media base URL.
     */
    private function mediaBaseUrl(string $storeCode): string
    {
        $domain = $this->config->getFeedDomain($storeCode);
        if ($domain !== '') {
            $base = preg_match('#^https?://#i', $domain) === 1 ? rtrim($domain, '/') : 'https://' . rtrim($domain, '/');
            return $base . '/media/';
        }

        return rtrim((string)$this->storeManager->getStore($storeCode)->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/') . '/';
    }

    private function normalizeFile(string $file): ?string
    {
        $file = trim($file);
        if ($file === '' || $file === 'no_selection') {
            return null;
        }

        return $file;
    }
}
