<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Plugin;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Amida\ProductDeltaFeed\Model\ProductProjectionService;

class ProductRepositoryPlugin
{
    public function __construct(private readonly ProductProjectionService $projectionService)
    {
    }

    public function afterSave(ProductRepositoryInterface $subject, ProductInterface $result, ProductInterface $product, bool $saveOptions = false): ProductInterface
    {
        $this->projectionService->projectProduct((int)$result->getId());
        return $result;
    }

    public function afterDelete(ProductRepositoryInterface $subject, bool $result, ProductInterface $product): bool
    {
        if ($result) {
            $this->projectionService->deleteByProductId((int)$product->getId(), (string)$product->getSku());
        }
        return $result;
    }

    public function afterDeleteById(ProductRepositoryInterface $subject, bool $result, string $sku): bool
    {
        if ($result) {
            $this->projectionService->deleteBySku($sku);
        }
        return $result;
    }
}
