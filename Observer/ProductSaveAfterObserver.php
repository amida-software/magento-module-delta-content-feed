<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Amida\ProductDeltaFeed\Model\Change\DirtyCollector;
use Amida\ProductDeltaFeed\Model\Change\ReasonFlags;

class ProductSaveAfterObserver implements ObserverInterface
{
    private const PRICE_FIELDS = ['price', 'special_price', 'special_from_date', 'special_to_date'];
    private const SEO_FIELDS = ['name', 'url_key', 'description', 'short_description', 'meta_title', 'meta_description', 'meta_keyword'];

    public function __construct(private readonly DirtyCollector $dirtyCollector)
    {
    }

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getProduct();
        if (!$product || !(int)$product->getId()) {
            return;
        }

        $flags = ReasonFlags::CONTENT | ReasonFlags::FORCE_COMPARE;
        foreach (self::PRICE_FIELDS as $field) {
            if ($product->dataHasChangedFor($field)) {
                $flags |= ReasonFlags::PRICE;
                break;
            }
        }
        foreach (self::SEO_FIELDS as $field) {
            if ($product->dataHasChangedFor($field)) {
                $flags |= ReasonFlags::SEO;
                break;
            }
        }
        if ($product->dataHasChangedFor('status')) {
            $flags |= ReasonFlags::STATUS;
        }
        if ($product->hasData('stock_data') || $product->hasData('quantity_and_stock_status')) {
            $flags |= ReasonFlags::AVAILABILITY;
        }

        $this->dirtyCollector->markDirty(
            (int)$product->getId(),
            (int)$product->getStoreId(),
            $flags,
            (string)$product->getSku()
        );
    }
}
