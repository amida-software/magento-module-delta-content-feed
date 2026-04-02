<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Plugin;

use Magento\Catalog\Model\Product\Action;
use Amida\ProductDeltaFeed\Model\ProductProjectionService;

class ProductActionPlugin
{
    public function __construct(private readonly ProductProjectionService $projectionService)
    {
    }

    public function afterUpdateAttributes(Action $subject, Action $result, array $productIds, array $attrData, int $storeId): Action
    {
        $this->projectionService->projectProducts($productIds);
        return $result;
    }
}
