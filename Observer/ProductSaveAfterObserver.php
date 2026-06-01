<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Observer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Amida\ProductDeltaFeed\Model\Change\DirtyCollector;
use Amida\ProductDeltaFeed\Model\Change\ReasonFlags;

class ProductSaveAfterObserver implements ObserverInterface
{
    private const PRICE_FIELDS = ['price', 'special_price', 'special_from_date', 'special_to_date'];
    private const SEO_FIELDS = ['name', 'url_key', 'description', 'short_description', 'meta_title', 'meta_description', 'meta_keyword'];

    public function __construct(
        private readonly DirtyCollector $dirtyCollector,
        private readonly ResourceConnection $resourceConnection
    ) {
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

        // When a configurable parent changes, re-emit its children so the fields they inherit
        // from the parent (category/images/description/…) stay current in the changes stream.
        if ((string)$product->getTypeId() === 'configurable') {
            foreach ($this->configurableChildIds((int)$product->getId()) as $childId) {
                $this->dirtyCollector->markDirty(
                    $childId,
                    0,
                    ReasonFlags::CONTENT | ReasonFlags::CATEGORY | ReasonFlags::FORCE_COMPARE
                );
            }
        }
    }

    /**
     * @return int[]
     */
    private function configurableChildIds(int $parentId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_super_link');
        $ids = $connection->fetchCol(
            $connection->select()->from($table, 'product_id')->where('parent_id = ?', $parentId)
        );

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
