<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Inventory;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\Store\Model\StoreManagerInterface;

class InventoryProvider
{
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly IsProductSalableInterface $isProductSalable,
        private readonly GetProductSalableQtyInterface $getProductSalableQty,
        private readonly StockResolverInterface $stockResolver,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return array<string, scalar|null>
     */
    public function build(string $sku, string $storeCode): array
    {
        $state = [
            'is_in_stock' => false,
            'is_salable' => false,
            'qty' => 0.0,
            'manage_stock' => false,
            'backorders' => 0,
            'stock_status' => 'out_of_stock',
        ];

        try {
            $stockItem = $this->stockRegistry->getStockItemBySku($sku);
            if ($stockItem) {
                $state['is_in_stock'] = (bool)$stockItem->getIsInStock();
                $state['qty'] = (float)$stockItem->getQty();
                $state['manage_stock'] = (bool)$stockItem->getManageStock();
                $state['backorders'] = (int)$stockItem->getBackorders();
            }
        } catch (\Throwable) {
            // keep defaults and continue with salability check
        }

        try {
            $store = $this->storeManager->getStore($storeCode);
            $stock = $this->stockResolver->execute(SalesChannelInterface::TYPE_WEBSITE, (string)$store->getWebsite()->getCode());
            $stockId = (int)$stock->getStockId();
            $state['is_salable'] = (bool)$this->isProductSalable->execute($sku, $stockId);
            $state['qty'] = (float)$this->getProductSalableQty->execute($sku, $stockId);
        } catch (\Throwable) {
            $state['is_salable'] = (bool)$state['is_in_stock'];
        }

        $state['stock_status'] = ($state['is_in_stock'] || $state['is_salable']) ? 'in_stock' : 'out_of_stock';

        return $state;
    }
}
