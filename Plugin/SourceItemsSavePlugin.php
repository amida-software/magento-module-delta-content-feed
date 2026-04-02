<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Plugin;

use Amida\ProductDeltaFeed\Model\Change\DirtyCollector;
use Amida\ProductDeltaFeed\Model\Change\ReasonFlags;
use Amida\ProductDeltaFeed\Model\ProductLocator;

class SourceItemsSavePlugin
{
    public function __construct(
        private readonly ProductLocator $productLocator,
        private readonly DirtyCollector $dirtyCollector
    ) {
    }

    public function afterExecute(mixed $subject, mixed $result, array $sourceItems): mixed
    {
        foreach ($sourceItems as $sourceItem) {
            $sku = method_exists($sourceItem, 'getSku') ? (string)$sourceItem->getSku() : '';
            if ($sku === '') {
                continue;
            }
            $productId = $this->productLocator->getIdBySku($sku);
            if ($productId !== null) {
                $this->dirtyCollector->markDirty($productId, 0, ReasonFlags::AVAILABILITY | ReasonFlags::FORCE_COMPARE, $sku);
            }
        }
        return $result;
    }
}
