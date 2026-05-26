<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Feed;

use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\ResourceModel\ChangeLog;
use Amida\ProductDeltaFeed\Model\ResourceModel\CategoryChangeLog;
use Amida\ProductDeltaFeed\Model\ResourceModel\CategoryDirtyQueue;
use Amida\ProductDeltaFeed\Model\ResourceModel\CategoryStateSnapshot;
use Amida\ProductDeltaFeed\Model\ResourceModel\DeadLetter;
use Amida\ProductDeltaFeed\Model\ResourceModel\DirtyQueue;
use Amida\ProductDeltaFeed\Model\ResourceModel\StateSnapshot;

class HealthService
{
    public function __construct(
        private readonly Config $config,
        private readonly ChangeLog $changeLog,
        private readonly CategoryChangeLog $categoryChangeLog,
        private readonly DeadLetter $deadLetter,
        private readonly DirtyQueue $dirtyQueue,
        private readonly CategoryDirtyQueue $categoryDirtyQueue,
        private readonly StateSnapshot $stateSnapshot,
        private readonly CategoryStateSnapshot $categoryStateSnapshot,
        private readonly ZstdCompressor $compressor
    ) {
    }

    public function getHealth(): array
    {
        return [
            'module_enabled' => $this->config->isEnabled(),
            'route_enabled' => $this->config->isRouteEnabled(),
            'compression_enabled' => !$this->compressor->isEnabled() || $this->compressor->isAvailable(),
            'last_event_id' => $this->changeLog->getLastEventId(),
            'last_category_event_id' => $this->categoryChangeLog->getLastEventId(),
            'oldest_retained_event_id' => $this->changeLog->getOldestRetainedEventId(),
            'dead_letter_count' => $this->deadLetter->count(),
            'dirty_queue_count' => $this->dirtyQueue->count(),
            'category_dirty_queue_count' => $this->categoryDirtyQueue->count(),
            'snapshot_state_ok' => $this->stateSnapshot->count() >= 0,
            'category_snapshot_state_ok' => $this->categoryStateSnapshot->count() >= 0,
            'reconciliation_lag' => $this->config->getLastReconcileProductId(),
            'last_reconcile_run_at' => $this->config->getLastReconcileRunAt(),
        ];
    }

    public function getStats(): array
    {
        return [
            'events_by_stream' => $this->changeLog->countByStream(),
            'category_event_count' => $this->categoryChangeLog->count(),
            'dirty_queue_count' => $this->dirtyQueue->count(),
            'category_dirty_queue_count' => $this->categoryDirtyQueue->count(),
            'state_rows' => $this->stateSnapshot->count(),
            'category_state_rows' => $this->categoryStateSnapshot->count(),
            'dead_letter_count' => $this->deadLetter->count(),
        ];
    }
}
