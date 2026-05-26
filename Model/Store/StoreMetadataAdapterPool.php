<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Store;

class StoreMetadataAdapterPool
{
    /** @param array<string, StoreMetadataAdapterInterface> $adapters */
    public function __construct(private readonly array $adapters = [])
    {
    }

    /** @return StoreMetadataAdapterInterface[] */
    public function getSupported(StoreContext $context): array
    {
        $supported = [];
        foreach ($this->adapters as $adapter) {
            if ($adapter instanceof StoreMetadataAdapterInterface && $adapter->supports($context)) {
                $supported[] = $adapter;
            }
        }
        return $supported;
    }
}
