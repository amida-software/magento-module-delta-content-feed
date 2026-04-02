<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Amida\ProductDeltaFeed\Model\Change\DirtyCollector;
use Amida\ProductDeltaFeed\Model\Change\ReasonFlags;

class CategoryChangeProductsObserver implements ObserverInterface
{
    public function __construct(private readonly DirtyCollector $dirtyCollector)
    {
    }

    public function execute(Observer $observer): void
    {
        $productIds = (array)($observer->getEvent()->getProductIds() ?? []);
        foreach ($productIds as $productId) {
            $this->dirtyCollector->markDirty((int)$productId, 0, ReasonFlags::CATEGORY | ReasonFlags::FORCE_COMPARE);
        }
    }
}
