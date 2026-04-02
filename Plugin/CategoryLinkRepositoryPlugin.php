<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Plugin;

use Magento\Catalog\Api\Data\CategoryProductLinkInterface;
use Amida\ProductDeltaFeed\Model\Change\DirtyCollector;
use Amida\ProductDeltaFeed\Model\Change\ReasonFlags;
use Amida\ProductDeltaFeed\Model\ProductLocator;

class CategoryLinkRepositoryPlugin
{
    public function __construct(
        private readonly ProductLocator $productLocator,
        private readonly DirtyCollector $dirtyCollector
    ) {
    }

    public function afterSave(mixed $subject, mixed $result, CategoryProductLinkInterface $productLink): mixed
    {
        $sku = (string)$productLink->getSku();
        $productId = $this->productLocator->getIdBySku($sku);
        if ($productId !== null) {
            $this->dirtyCollector->markDirty($productId, 0, ReasonFlags::CATEGORY | ReasonFlags::FORCE_COMPARE, $sku);
        }
        return $result;
    }

    public function afterDeleteByIds(mixed $subject, mixed $result, int $categoryId, string $sku): mixed
    {
        $productId = $this->productLocator->getIdBySku($sku);
        if ($productId !== null) {
            $this->dirtyCollector->markDirty($productId, 0, ReasonFlags::CATEGORY | ReasonFlags::FORCE_COMPARE, $sku);
        }
        return $result;
    }
}
