<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Cron;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use Amida\ProductDeltaFeed\Model\Change\DirtyCollector;
use Amida\ProductDeltaFeed\Model\Change\ReasonFlags;
use Amida\ProductDeltaFeed\Model\Config;

class ReconcileState
{
    private const LOCK_NAME = 'amida_productdeltafeed_reconcile';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DirtyCollector $dirtyCollector,
        private readonly Config $config,
        private readonly WriterInterface $configWriter,
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
            $batchSize = $this->config->getRepairScanBatchSize();
            $afterId = $this->config->getLastReconcileProductId();
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('catalog_product_entity');
            $select = $connection->select()
                ->from($table, ['entity_id'])
                ->where('entity_id > ?', $afterId)
                ->order('entity_id ASC')
                ->limit($batchSize);
            $productIds = array_map('intval', $connection->fetchCol($select));
            if ($productIds === []) {
                $this->configWriter->save(Config::XML_PATH_LAST_RECONCILE_PRODUCT_ID, 0);
                $this->configWriter->save(Config::XML_PATH_LAST_RECONCILE_RUN_AT, (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
                return;
            }

            foreach ($productIds as $productId) {
                $this->dirtyCollector->markDirty($productId, 0, ReasonFlags::FORCE_COMPARE);
            }

            $this->configWriter->save(Config::XML_PATH_LAST_RECONCILE_PRODUCT_ID, (string)max($productIds));
            $this->configWriter->save(Config::XML_PATH_LAST_RECONCILE_RUN_AT, (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        } catch (\Throwable $exception) {
            $this->logger->error('Product delta reconcile failed', ['exception' => $exception]);
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }
}
