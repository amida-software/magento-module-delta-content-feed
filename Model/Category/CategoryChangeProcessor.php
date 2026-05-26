<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Category;

use Amida\ProductDeltaFeed\Model\Change\ReasonFlags;
use Amida\ProductDeltaFeed\Model\ResourceModel\CategoryChangeLog;
use Amida\ProductDeltaFeed\Model\ResourceModel\CategoryDirtyQueue;
use Amida\ProductDeltaFeed\Model\ResourceModel\CategoryStateSnapshot;
use Amida\ProductDeltaFeed\Model\ResourceModel\DeadLetter;
use Amida\ProductDeltaFeed\Model\State\JsonCanonicalizer;
use Amida\ProductDeltaFeed\Model\State\StateDiffer;
use Amida\ProductDeltaFeed\Model\StoreScopeResolver;

class CategoryChangeProcessor
{
    public function __construct(
        private readonly CategoryDirtyQueue $dirtyQueue,
        private readonly CategoryChangeLog $changeLog,
        private readonly CategoryStateSnapshot $stateSnapshot,
        private readonly DeadLetter $deadLetter,
        private readonly CategoryStateBuilder $stateBuilder,
        private readonly StateDiffer $stateDiffer,
        private readonly JsonCanonicalizer $canonicalizer,
        private readonly StoreScopeResolver $storeScopeResolver
    ) {
    }

    public function processBatch(int $limit): int
    {
        $rows = $this->dirtyQueue->fetchBatch($limit);
        if ($rows === []) {
            return 0;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $key = (int)$row['category_id'] . ':' . (int)$row['store_id'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'category_id' => (int)$row['category_id'],
                    'store_id' => (int)$row['store_id'],
                    'reason_flags' => 0,
                    'dirty_ids' => [],
                ];
            }
            $grouped[$key]['reason_flags'] |= (int)$row['reason_flags'];
            $grouped[$key]['dirty_ids'][] = (int)$row['dirty_id'];
        }

        $processed = 0;
        foreach ($grouped as $group) {
            try {
                $this->processOne((int)$group['category_id'], (int)$group['store_id'], (int)$group['reason_flags']);
                $this->dirtyQueue->deleteByIds($group['dirty_ids']);
                $processed += count($group['dirty_ids']);
            } catch (\Throwable $exception) {
                $this->dirtyQueue->markFailed($group['dirty_ids'], $exception->getMessage());
                $this->deadLetter->add([
                    'entity_id' => (int)$group['category_id'],
                    'stream_code' => 'categories',
                    'reason_code' => 'category_dirty_processing_failed',
                    'details_json' => json_encode([
                        'store_id' => (int)$group['store_id'],
                        'reason_flags' => (int)$group['reason_flags'],
                        'message' => $exception->getMessage(),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }
        }
        return $processed;
    }

    private function processOne(int $categoryId, int $triggerStoreId, int $reasonFlags): void
    {
        $eventRows = [];
        $snapshotRows = [];
        foreach ($this->storeScopeResolver->resolveStoreCodes($triggerStoreId) as $storeCode) {
            $previous = $this->stateSnapshot->getByCategoryAndStore($categoryId, $storeCode);

            if (($reasonFlags & ReasonFlags::DELETE) === ReasonFlags::DELETE) {
                if ($previous !== null) {
                    $payload = ['enabled' => false, 'deleted' => true, 'category' => ['category_id' => $categoryId]];
                    $this->appendEvent($eventRows, $categoryId, $storeCode, 'TOMBSTONE', ['deleted'], $payload, null);
                }
                continue;
            }

            $current = $this->stateBuilder->buildState($categoryId, $storeCode);
            if ($current === null) {
                if ($previous !== null) {
                    $payload = ['enabled' => false, 'deleted' => true, 'category' => ['category_id' => $categoryId]];
                    $this->appendEvent($eventRows, $categoryId, $storeCode, 'TOMBSTONE', ['deleted'], $payload, null);
                    $this->stateSnapshot->deleteCategory($categoryId);
                }
                continue;
            }

            $currentHash = $this->stateDiffer->hash($current);
            $previousHash = $previous['state_hash'] ?? null;
            $forceFull = ($reasonFlags & ReasonFlags::FORCE_FULL) === ReasonFlags::FORCE_FULL || $previous === null;
            $changedFields = $forceFull
                ? $this->fullChangedFields($current)
                : $this->changedFields((array)($previous['state'] ?? []), $current);

            if ($forceFull || $previousHash !== $currentHash) {
                if ($changedFields !== []) {
                    $this->appendEvent(
                        $eventRows,
                        $categoryId,
                        $storeCode,
                        (bool)$current['enabled'] ? ($forceFull ? 'UPSERT_FULL' : 'UPSERT_PARTIAL') : 'STATUS_ONLY',
                        $changedFields,
                        $current,
                        (string)($current['category']['source_updated_at'] ?? '') ?: null
                    );
                }
            }

            $snapshotRows[] = [
                'category_id' => $categoryId,
                'store_code' => $storeCode,
                'parent_id' => $current['category']['parent_id'] ?? null,
                'is_enabled' => (int)($current['enabled'] ?? false),
                'state_hash' => $currentHash,
                'state_json' => $this->canonicalizer->encode($current),
            ];
        }

        if ($eventRows !== []) {
            $this->changeLog->insertMany($eventRows);
        }
        if ($snapshotRows !== []) {
            $this->stateSnapshot->upsertMany($snapshotRows);
        }
    }

    /** @return string[] */
    private function fullChangedFields(array $current): array
    {
        return array_map(static fn (string $key): string => 'category.' . $key, array_keys((array)($current['category'] ?? [])));
    }

    /** @return string[] */
    private function changedFields(array $previous, array $current): array
    {
        $before = (array)($previous['category'] ?? []);
        $after = (array)($current['category'] ?? []);
        $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
        sort($keys);
        $changed = [];
        foreach ($keys as $key) {
            if ($this->stateDiffer->hash([$before[$key] ?? null]) !== $this->stateDiffer->hash([$after[$key] ?? null])) {
                $changed[] = 'category.' . $key;
            }
        }
        return $changed;
    }

    private function appendEvent(
        array &$eventRows,
        int $categoryId,
        string $storeCode,
        string $eventType,
        array $changedFields,
        array $payload,
        ?string $sourceUpdatedAt
    ): void {
        $canonical = $this->canonicalizer->encode($payload);
        $eventRows[] = [
            'category_id' => $categoryId,
            'store_code' => $storeCode,
            'event_type' => $eventType,
            'schema_version' => 1,
            'payload_version' => 1,
            'changed_fields_json' => json_encode(array_values(array_unique($changedFields)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_json' => $canonical,
            'payload_hash' => hash('sha256', $canonical),
            'source_updated_at' => $sourceUpdatedAt !== '' ? $sourceUpdatedAt : null,
        ];
    }
}
