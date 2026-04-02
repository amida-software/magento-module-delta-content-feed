<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Plugin;

use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Amida\ProductDeltaFeed\Model\Change\DirtyCollector;
use Amida\ProductDeltaFeed\Model\Change\ReasonFlags;
use Amida\ProductDeltaFeed\Model\ProductLocator;

class StockItemRepositoryPlugin
{
    public function __construct(
        private readonly ProductLocator $productLocator,
        private readonly DirtyCollector $dirtyCollector
    ) {
    }

    public function afterSave(mixed $subject, StockItemInterface $result): StockItemInterface
    {
        $productId = (int)$result->getProductId();
        if ($productId > 0) {
            $sku = $this->productLocator->getSkuById($productId) ?? '';
            $this->dirtyCollector->markDirty($productId, 0, ReasonFlags::AVAILABILITY | ReasonFlags::FORCE_COMPARE, $sku !== '' ? $sku : null);
        }
        return $result;
    }
}
