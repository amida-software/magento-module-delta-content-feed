<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Feed;

use Magento\Framework\Lock\LockManagerInterface;
use Amida\ProductDeltaFeed\Model\Config;

class ApiRequestGate
{
    private const LOCK_NAME = 'amida_productdeltafeed_api_request';

    public function __construct(
        private readonly Config $config,
        private readonly LockManagerInterface $lockManager
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isApiRequestMonopolyEnabled();
    }

    public function getTimeoutSeconds(): int
    {
        return $this->config->getApiRequestTimeoutSeconds();
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function execute(callable $callback): mixed
    {
        if (!$this->isEnabled()) {
            return $callback();
        }

        $timeout = $this->getTimeoutSeconds();
        if (!$this->lockManager->lock(self::LOCK_NAME, $timeout)) {
            throw new RequestDroppedException(
                sprintf('Another feed request is still running; wait limit %d second(s) exceeded.', $timeout)
            );
        }

        try {
            return $callback();
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }
}
