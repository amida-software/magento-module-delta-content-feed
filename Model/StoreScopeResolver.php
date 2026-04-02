<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model;

use Magento\Store\Model\StoreManagerInterface;

class StoreScopeResolver
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return string[]
     */
    public function resolveStoreCodes(int $triggerStoreId = 0): array
    {
        $configured = $this->config->getConfiguredStoreCodes();
        if ($triggerStoreId <= 0) {
            return $configured;
        }

        try {
            $store = $this->storeManager->getStore($triggerStoreId);
            $code = (string)$store->getCode();
            if ($store->isActive() && in_array($code, $configured, true)) {
                return [$code];
            }
        } catch (\Throwable) {
            // fallback to all configured storefront stores
        }

        return $configured;
    }

    public function getDefaultStoreCode(): string
    {
        $codes = $this->config->getConfiguredStoreCodes();
        return $codes[0] ?? (string)$this->storeManager->getDefaultStoreView()->getCode();
    }

    public function isAllowedStoreCode(string $storeCode): bool
    {
        return in_array($storeCode, $this->config->getConfiguredStoreCodes(), true);
    }
}
