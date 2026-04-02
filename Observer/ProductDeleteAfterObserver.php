<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Amida\ProductDeltaFeed\Model\Change\DirtyCollector;
use Amida\ProductDeltaFeed\Model\Change\ReasonFlags;

class ProductDeleteAfterObserver implements ObserverInterface
{
    public function __construct(private readonly DirtyCollector $dirtyCollector)
    {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getProduct();
        if (!$product || !(int)$product->getId()) {
            return;
        }

        $this->dirtyCollector->markDirty(
            (int)$product->getId(),
            0,
            ReasonFlags::DELETE,
            (string)$product->getSku()
        );
    }
}
