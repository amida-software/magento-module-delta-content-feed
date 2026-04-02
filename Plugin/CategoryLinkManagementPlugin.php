<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Plugin;

use Amida\ProductDeltaFeed\Model\Change\DirtyCollector;
use Amida\ProductDeltaFeed\Model\Change\ReasonFlags;
use Amida\ProductDeltaFeed\Model\ProductLocator;

class CategoryLinkManagementPlugin
{
    public function __construct(
        private readonly ProductLocator $productLocator,
        private readonly DirtyCollector $dirtyCollector
    ) {
    }

    public function afterAssignProductToCategories(mixed $subject, mixed $result, string $productSku, array $categoryIds): mixed
    {
        $productId = $this->productLocator->getIdBySku($productSku);
        if ($productId !== null) {
            $this->dirtyCollector->markDirty($productId, 0, ReasonFlags::CATEGORY | ReasonFlags::FORCE_COMPARE, $productSku);
        }
        return $result;
    }
}
