<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Cron;

use DateInterval;
use DateTimeImmutable;
use Magento\Framework\Lock\LockManagerInterface;
use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\ResourceModel\ChangeLog;
use Amida\ProductDeltaFeed\Model\ResourceModel\CategoryChangeLog;
use Amida\ProductDeltaFeed\Model\ResourceModel\DeadLetter;
use Psr\Log\LoggerInterface;

class Cleanup
{
    private const LOCK_NAME = 'amida_productdeltafeed_cleanup';

    public function __construct(
        private readonly Config $config,
        private readonly ChangeLog $changeLog,
        private readonly CategoryChangeLog $categoryChangeLog,
        private readonly DeadLetter $deadLetter,
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
            $cutoff = (new DateTimeImmutable())->sub(new DateInterval('P' . $this->config->getRetentionDays() . 'D'));
            $this->changeLog->deleteOlderThan($cutoff);
            $this->categoryChangeLog->deleteOlderThan($cutoff);
            $this->deadLetter->deleteOlderThan($cutoff);
        } catch (\Throwable $exception) {
            $this->logger->error('Product/category delta cleanup failed', ['exception' => $exception]);
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }
}
