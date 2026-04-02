<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Change;

use Amida\ProductDeltaFeed\Model\ResourceModel\DirtyQueue;

class DirtyCollector
{
    public function __construct(private readonly DirtyQueue $dirtyQueue)
    {
    }

    public function markDirty(int $productId, int $storeId, int $reasonFlags, ?string $sku = null): void
    {
        if ($productId <= 0 || $reasonFlags <= 0) {
            return;
        }
        $this->dirtyQueue->enqueue($productId, $storeId, $reasonFlags, $sku);
    }
}
