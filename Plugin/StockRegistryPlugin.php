<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Plugin;

use Amida\ProductDeltaFeed\Model\Change\DirtyCollector;
use Amida\ProductDeltaFeed\Model\Change\ReasonFlags;
use Amida\ProductDeltaFeed\Model\ProductLocator;

class StockRegistryPlugin
{
    public function __construct(
        private readonly ProductLocator $productLocator,
        private readonly DirtyCollector $dirtyCollector
    ) {
    }

    public function afterUpdateStockItemBySku(mixed $subject, mixed $result, string $productSku, mixed $stockItem): mixed
    {
        $productId = $this->productLocator->getIdBySku($productSku);
        if ($productId !== null) {
            $this->dirtyCollector->markDirty($productId, 0, ReasonFlags::AVAILABILITY | ReasonFlags::FORCE_COMPARE, $productSku);
        }
        return $result;
    }
}
