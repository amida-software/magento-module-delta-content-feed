<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Observer;

use Amida\ProductDeltaFeed\Model\Category\CategoryDirtyCollector;
use Amida\ProductDeltaFeed\Model\Change\ReasonFlags;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CategoryDeleteAfterObserver implements ObserverInterface
{
    public function __construct(private readonly CategoryDirtyCollector $dirtyCollector)
    {
    }

    public function execute(Observer $observer): void
    {
        $category = $observer->getEvent()->getCategory();
        if (!is_object($category) || !method_exists($category, 'getId') || !(int)$category->getId()) {
            return;
        }

        $this->dirtyCollector->markDirty((int)$category->getId(), 0, ReasonFlags::DELETE);
    }
}
