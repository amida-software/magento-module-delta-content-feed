<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class PayloadBuilder
{
    public function __construct(
        private readonly Config $config,
        private readonly AttributeResolver $attributeResolver,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryFactory $categoryFactory,
        private readonly StockRegistryInterface $stockRegistry
    ) {
    }

    /** @throws NoSuchEntityException */
    public function build(int $productId, int $storeId, string $streamCode): array
    {
        $product = $this->productRepository->getById($productId, false, $storeId, true);
        $sku = (string)$product->getSku();
        $isEnabled = (int)$product->getStatus() === Status::STATUS_ENABLED;
        $record = [
            'product_id' => (int)$product->getId(),
            'store_id' => $storeId,
            'sku' => $sku,
            'is_enabled' => $isEnabled,
            'updated_at' => (string)($product->getUpdatedAt() ?: gmdate('c')),
            'attributes' => [],
            'categories' => [],
        ];

        if ($streamCode === Config::STREAM_AVAILABILITY) {
            $record['attributes'] = $this->buildAvailabilityAttributes($product->getId(), $sku);
            return $record;
        }

        if ($streamCode === Config::STREAM_CATEGORY) {
            $record['categories'] = $this->buildCategories($product->getCategoryIds(), $storeId);
            return $record;
        }

        $record['attributes'] = $this->buildAttributes($product, $this->attributeResolver->resolveForStream($streamCode));
        return $record;
    }

    private function buildAttributes(object $product, array $codes): array
    {
        $attributes = [];
        foreach ($codes as $code) {
            $value = $product->getData($code);
            if ($value === null || $value === '') {
                continue;
            }
            $attributes[] = [
                'code' => (string)$code,
                'value' => $this->stringify($value),
            ];
        }

        return $attributes;
    }

    private function buildAvailabilityAttributes(int $productId, string $sku): array
    {
        $stockItem = $this->stockRegistry->getStockItemBySku($sku);
        $status = $this->stockRegistry->getStockStatusBySku($sku);
        $pairs = [
            'qty' => $stockItem->getQty(),
            'is_in_stock' => $stockItem->getIsInStock(),
            'manage_stock' => $stockItem->getManageStock(),
            'backorders' => $stockItem->getBackorders(),
            'min_qty' => $stockItem->getMinQty(),
            'min_sale_qty' => $stockItem->getMinSaleQty(),
            'max_sale_qty' => $stockItem->getMaxSaleQty(),
            'stock_status' => method_exists($status, 'getStockStatus') ? $status->getStockStatus() : $this->stockRegistry->getProductStockStatus($productId),
        ];

        $attributes = [];
        foreach ($pairs as $code => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $attributes[] = ['code' => (string)$code, 'value' => $this->stringify($value)];
        }
        return $attributes;
    }

    private function buildCategories(array $categoryIds, int $storeId): array
    {
        $categories = [];
        foreach ($categoryIds as $categoryId) {
            try {
                $category = $this->categoryFactory->create();
                $category->setStoreId($storeId);
                $category->load((int)$categoryId);
                if (!$category->getId()) {
                    continue;
                }
                $categories[] = [
                    'category_id' => (int)$category->getId(),
                    'name' => $this->config->includeCategoryNames() ? (string)$category->getName() : '',
                    'path' => $this->config->includeCategoryPaths() ? (string)$category->getPath() : '',
                    'position' => 0,
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        usort($categories, static fn (array $a, array $b): int => ($a['category_id'] <=> $b['category_id']));
        return $categories;
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
