<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Plugin;

use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\CategoryLinkRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryProductLinkInterface;
use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\ProductProjectionService;

class CategoryLinkPlugin
{
    public function __construct(private readonly ProductProjectionService $projectionService)
    {
    }

    public function afterAssignProductToCategories(
        CategoryLinkManagementInterface $subject,
        bool $result,
        string $productSku,
        array $categoryIds
    ): bool {
        $this->projectionService->projectSku($productSku, [Config::STREAM_CATEGORY]);
        return $result;
    }

    public function afterSave(
        CategoryLinkRepositoryInterface $subject,
        bool $result,
        CategoryProductLinkInterface $productLink
    ): bool {
        $this->projectionService->projectSku((string)$productLink->getSku(), [Config::STREAM_CATEGORY]);
        return $result;
    }

    public function afterDelete(
        CategoryLinkRepositoryInterface $subject,
        bool $result,
        CategoryProductLinkInterface $productLink
    ): bool {
        $this->projectionService->projectSku((string)$productLink->getSku(), [Config::STREAM_CATEGORY]);
        return $result;
    }

    public function afterDeleteByIds(
        CategoryLinkRepositoryInterface $subject,
        bool $result,
        int $categoryId,
        string $sku
    ): bool {
        $this->projectionService->projectSku($sku, [Config::STREAM_CATEGORY]);
        return $result;
    }
}
