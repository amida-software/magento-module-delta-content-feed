<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Cron;

use Psr\Log\LoggerInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Amida\ProductDeltaFeed\Model\Category\CategoryChangeProcessor;
use Amida\ProductDeltaFeed\Model\Change\ChangeProcessor;
use Amida\ProductDeltaFeed\Model\Config;

class ProcessDirtyQueue
{
    private const LOCK_NAME = 'amida_productdeltafeed_process_dirty';

    public function __construct(
        private readonly ChangeProcessor $changeProcessor,
        private readonly CategoryChangeProcessor $categoryChangeProcessor,
        private readonly Config $config,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 300)) {
            return;
        }

        try {
            $this->changeProcessor->processBatch($this->config->getDirtyBatchSize());
            $this->categoryChangeProcessor->processBatch($this->config->getDirtyBatchSize());
        } catch (\Throwable $exception) {
            $this->logger->error('Product/category delta dirty processing failed', ['exception' => $exception]);
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }
}
