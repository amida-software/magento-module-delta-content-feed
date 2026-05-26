<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Store;

use Magento\Store\Api\Data\StoreInterface;

final class StoreContext
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly StoreInterface $store,
        public readonly string $storeCode,
        public readonly int $storeId,
        public readonly int $websiteId,
        public readonly int $groupId,
        public readonly array $options = []
    ) {
    }
}
