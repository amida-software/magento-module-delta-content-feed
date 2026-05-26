<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Category;

use Amida\ProductDeltaFeed\Model\ResourceModel\CategoryDirtyQueue;

class CategoryDirtyCollector
{
    public function __construct(private readonly CategoryDirtyQueue $dirtyQueue)
    {
    }

    public function markDirty(int $categoryId, int $storeId, int $reasonFlags): void
    {
        $this->dirtyQueue->enqueue($categoryId, $storeId, $reasonFlags);
    }
}
