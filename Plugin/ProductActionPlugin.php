<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Plugin;

use Amida\ProductDeltaFeed\Model\Change\DirtyCollector;
use Amida\ProductDeltaFeed\Model\Change\ReasonFlags;
use Magento\Catalog\Model\Product\Action;

class ProductActionPlugin
{
    private const PRICE_FIELDS = ['price', 'special_price', 'special_from_date', 'special_to_date'];
    private const SEO_FIELDS = ['name', 'url_key', 'description', 'short_description', 'meta_title', 'meta_description', 'meta_keyword'];

    public function __construct(private readonly DirtyCollector $dirtyCollector)
    {
    }

    public function afterUpdateAttributes(Action $subject, Action $result, array $productIds, array $attrData, int $storeId): Action
    {
        $flags = $this->resolveReasonFlags($attrData);

        foreach ($this->normalizeProductIds($productIds) as $productId) {
            $this->dirtyCollector->markDirty($productId, $storeId, $flags);
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $attrData
     */
    private function resolveReasonFlags(array $attrData): int
    {
        $flags = ReasonFlags::CONTENT | ReasonFlags::FORCE_COMPARE;
        $attributeCodes = array_map('strval', array_keys($attrData));

        if (array_intersect($attributeCodes, self::PRICE_FIELDS) !== []) {
            $flags |= ReasonFlags::PRICE;
        }

        if (array_intersect($attributeCodes, self::SEO_FIELDS) !== []) {
            $flags |= ReasonFlags::SEO;
        }

        if (in_array('status', $attributeCodes, true)) {
            $flags |= ReasonFlags::STATUS;
        }

        return $flags;
    }

    /**
     * @param array<int|string, mixed> $productIds
     * @return int[]
     */
    private function normalizeProductIds(array $productIds): array
    {
        $normalized = [];
        foreach ($productIds as $productId) {
            $productId = (int)$productId;
            if ($productId > 0) {
                $normalized[$productId] = $productId;
            }
        }

        return array_values($normalized);
    }
}
