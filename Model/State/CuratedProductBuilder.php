<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\State;

use Amida\ProductDeltaFeed\Model\State\Curated\CategoryProvider;
use Amida\ProductDeltaFeed\Model\State\Curated\ImageUrlBuilder;
use Amida\ProductDeltaFeed\Model\State\Curated\RelatedProductProvider;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;

class CuratedProductBuilder
{
    public function __construct(
        private readonly CategoryProvider $categoryProvider,
        private readonly ImageUrlBuilder $imageUrlBuilder,
        private readonly RelatedProductProvider $relatedProductProvider
    ) {
    }

    /**
     * @param array<string, mixed> $offerContext Direct-SQL offer context, or legacy availability state for BC tests.
     * @return array<string, mixed>
     */
    public function build(Product $product, string $storeCode, array $offerContext): array
    {
        $categories = $this->categoryProvider->getCategories((int)$product->getId(), $storeCode);
        $offer = (array)($offerContext['offer'] ?? []);
        $availability = (array)($offerContext['availability'] ?? $offerContext);
        $prices = $this->resolvePrices($product, $offer);

        return [
            'enabled' => (int)$product->getData('status') === Status::STATUS_ENABLED,
            'deleted' => false,
            'curated' => [
                'sku' => (string)$product->getSku(),
                'prices' => $prices,
                'availability' => $this->normalizeAvailability($availability),
                'name' => $this->stringOrNull($product->getData('name')),
                'description' => $this->stringOrNull($product->getData('description')),
                'short_description' => $this->stringOrNull($product->getData('short_description')),
                'url_key' => $this->stringOrNull($product->getData('url_key')),
                'images' => $this->imageUrlBuilder->getImageUrls($product, $storeCode),
                'brand' => $this->firstLabel($this->safeAttributeText($product, 'manufacturer')) ?? $this->stringOrNull($product->getData('manufacturer')),
                'product_type' => $this->resolveProductType($categories),
                'magento_type_id' => (string)$product->getTypeId(),
                'category_ids' => $this->extractCategoryIds($categories),
                'notes' => $this->labels($this->safeAttributeText($product, 'notes'), $product->getData('notes')),
                'related_products' => $this->relatedProductProvider->getRelatedProducts((int)$product->getId()),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $offer
     * @return array{old: ?float, new: ?float}
     */
    private function resolvePrices(Product $product, array $offer): array
    {
        $offerPrices = (array)($offer['prices'] ?? []);
        if ($offerPrices !== []) {
            return [
                'old' => $this->floatOrNull($offerPrices['old'] ?? null),
                'new' => $this->floatOrNull($offerPrices['current'] ?? $offerPrices['new'] ?? null),
            ];
        }

        $legacyPrice = $this->floatOrNull($product->getData('price') ?? $this->safeCall($product, 'getPrice'));

        return [
            'old' => $legacyPrice,
            'new' => $legacyPrice,
        ];
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private function normalizeAvailability(array $availability): array
    {
        $result = [
            'is_available' => $this->resolveAvailabilityFlag($availability),
        ];

        if (array_key_exists('qty', $availability)) {
            $result['qty'] = (float)$availability['qty'];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $offer
     */
    private function resolveAvailabilityFlag(array $availability): bool
    {
        if (array_key_exists('is_salable', $availability)) {
            return (bool)$availability['is_salable'];
        }

        if (($availability['stock_status'] ?? '') !== '') {
            return (string)$availability['stock_status'] === 'in_stock';
        }

        return (bool)($availability['is_in_stock'] ?? false);
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     */
    private function resolveProductType(array $categories): ?string
    {
        for ($index = count($categories) - 1; $index >= 0; $index--) {
            $name = trim((string)($categories[$index]['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     * @return int[]
     */
    private function extractCategoryIds(array $categories): array
    {
        $ids = [];
        foreach ($categories as $category) {
            $id = (int)($category['category_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return string[]
     */
    private function labels(mixed $text, mixed $rawValue): array
    {
        $labels = $this->normalizeLabels($text);
        if ($labels !== []) {
            return $labels;
        }

        if ($rawValue === null || $rawValue === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', (string)$rawValue)),
            static fn (string $value): bool => $value !== ''
        ));
    }

    private function firstLabel(mixed $text): ?string
    {
        $labels = $this->normalizeLabels($text);
        return $labels[0] ?? null;
    }

    /**
     * @return string[]
     */
    private function normalizeLabels(mixed $text): array
    {
        if ($text === null || $text === false || $text === '') {
            return [];
        }
        if (is_array($text)) {
            return array_values(array_filter(
                array_map(static fn (mixed $item): string => trim((string)$item), $text),
                static fn (string $item): bool => $item !== ''
            ));
        }

        return [trim((string)$text)];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        return (string)$value;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float)$value;
    }

    private function safeCall(Product $product, string $method): mixed
    {
        try {
            return $product->{$method}();
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeAttributeText(Product $product, string $attributeCode): mixed
    {
        try {
            return $product->getAttributeText($attributeCode);
        } catch (\Throwable) {
            return null;
        }
    }
}
