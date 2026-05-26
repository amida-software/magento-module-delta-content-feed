<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Change;

use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\ResourceModel\ChangeLog;
use Amida\ProductDeltaFeed\Model\ResourceModel\DeadLetter;
use Amida\ProductDeltaFeed\Model\ResourceModel\DirtyQueue;
use Amida\ProductDeltaFeed\Model\ResourceModel\StateSnapshot;
use Amida\ProductDeltaFeed\Model\State\JsonCanonicalizer;
use Amida\ProductDeltaFeed\Model\State\LifecycleResolver;
use Amida\ProductDeltaFeed\Model\State\ProductStateBuilder;
use Amida\ProductDeltaFeed\Model\State\StateDiffer;
use Amida\ProductDeltaFeed\Model\StoreScopeResolver;

class ChangeProcessor
{
    private const PRODUCT_STREAMS = ['content', 'seo', 'price', 'availability', 'offer', 'category', 'curated'];

    public function __construct(
        private readonly Config $config,
        private readonly DirtyQueue $dirtyQueue,
        private readonly ChangeLog $changeLog,
        private readonly StateSnapshot $stateSnapshot,
        private readonly DeadLetter $deadLetter,
        private readonly ProductStateBuilder $stateBuilder,
        private readonly StateDiffer $stateDiffer,
        private readonly JsonCanonicalizer $canonicalizer,
        private readonly StoreScopeResolver $storeScopeResolver,
        private readonly LifecycleResolver $lifecycleResolver
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
            $key = (int)$row['entity_id'] . ':' . (int)$row['store_id'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'entity_id' => (int)$row['entity_id'],
                    'store_id' => (int)$row['store_id'],
                    'reason_flags' => 0,
                    'dirty_ids' => [],
                    'sku' => (string)($row['sku'] ?? ''),
                ];
            }
            $grouped[$key]['reason_flags'] |= (int)$row['reason_flags'];
            $grouped[$key]['dirty_ids'][] = (int)$row['dirty_id'];
            if ($grouped[$key]['sku'] === '' && !empty($row['sku'])) {
                $grouped[$key]['sku'] = (string)$row['sku'];
            }
        }

        $processed = 0;
        foreach ($grouped as $group) {
            try {
                $this->processOne((int)$group['entity_id'], (int)$group['store_id'], (int)$group['reason_flags'], (string)$group['sku']);
                $this->dirtyQueue->deleteByIds($group['dirty_ids']);
                $processed += count($group['dirty_ids']);
            } catch (\Throwable $exception) {
                $this->dirtyQueue->markFailed($group['dirty_ids'], $exception->getMessage());
                $this->deadLetter->add([
                    'entity_id' => (int)$group['entity_id'],
                    'sku' => (string)$group['sku'],
                    'reason_code' => 'dirty_processing_failed',
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

    private function processOne(int $productId, int $triggerStoreId, int $reasonFlags, string $skuHint = ''): void
    {
        $storeCodes = $this->storeScopeResolver->resolveStoreCodes($triggerStoreId);
        $eventRows = [];
        $snapshotRows = [];

        if (($reasonFlags & ReasonFlags::DELETE) === ReasonFlags::DELETE) {
            foreach ($storeCodes as $storeCode) {
                $previousStates = $this->stateSnapshot->getByProductAndStore($productId, $storeCode);
                if ($previousStates === []) {
                    continue;
                }
                foreach (self::PRODUCT_STREAMS as $stream) {
                    if (!$this->config->isStreamEnabled($stream)) {
                        continue;
                    }
                    $sku = $skuHint !== '' ? $skuHint : (string)($previousStates[$stream]['sku'] ?? '');
                    $payload = $this->emptyPayload($stream, true);
                    $this->appendEvent($eventRows, $stream, $stream, $productId, $sku, $storeCode, 'TOMBSTONE', ['deleted'], $payload, null);
                }
            }
            if ($eventRows !== []) {
                $this->changeLog->insertMany($eventRows);
            }
            $this->stateSnapshot->deleteProduct($productId);
            return;
        }

        foreach ($storeCodes as $storeCode) {
            $previousStates = $this->stateSnapshot->getByProductAndStore($productId, $storeCode);
            $currentStates = $this->stateBuilder->buildStates($productId, $storeCode);

            if ($currentStates === null) {
                if ($this->config->exportDeletedAsTombstone() && $previousStates !== []) {
                    foreach (self::PRODUCT_STREAMS as $stream) {
                        if (!$this->config->isStreamEnabled($stream)) {
                            continue;
                        }
                        $sku = $skuHint !== '' ? $skuHint : (string)($previousStates[$stream]['sku'] ?? '');
                        $payload = $this->emptyPayload($stream, true);
                        $this->appendEvent($eventRows, $stream, $stream, $productId, $sku, $storeCode, 'TOMBSTONE', ['deleted'], $payload, null);
                    }
                    $this->stateSnapshot->deleteProduct($productId);
                }
                continue;
            }

            $hasPrevious = $previousStates !== [];
            $previousEnabled = $this->extractPreviousEnabled($previousStates);
            $currentEnabled = (bool)$currentStates['meta']['enabled'];
            $action = $this->lifecycleResolver->resolve($hasPrevious, $previousEnabled, $currentEnabled);

            if ($action === LifecycleResolver::ACTION_DISABLE) {
                foreach (self::PRODUCT_STREAMS as $stream) {
                    if (!$this->config->isStreamEnabled($stream)) {
                        continue;
                    }
                    $payload = $this->emptyPayload($stream, false);
                    $this->appendEvent(
                        $eventRows,
                        $stream,
                        $stream,
                        $productId,
                        (string)$currentStates['meta']['sku'],
                        $storeCode,
                        'STATUS_ONLY',
                        ['status'],
                        $payload,
                        $currentStates['meta']['source_updated_at']
                    );
                    $snapshotRows[] = $this->snapshotRow($productId, (string)$currentStates['meta']['sku'], $storeCode, $stream, false, $payload);
                }
                continue;
            }

            if ($action === LifecycleResolver::ACTION_SUPPRESSED_DISABLED && $this->config->suppressWhileDisabled()) {
                foreach (self::PRODUCT_STREAMS as $stream) {
                    if (!$this->config->isStreamEnabled($stream)) {
                        continue;
                    }
                    $payload = $this->emptyPayload($stream, false);
                    $snapshotRows[] = $this->snapshotRow($productId, (string)$currentStates['meta']['sku'], $storeCode, $stream, false, $payload);
                }
                continue;
            }

            $forceFull = $action === LifecycleResolver::ACTION_FULL || (($reasonFlags & ReasonFlags::FORCE_FULL) === ReasonFlags::FORCE_FULL);

            foreach (self::PRODUCT_STREAMS as $stream) {
                if (!$this->config->isStreamEnabled($stream)) {
                    continue;
                }
                if (!$forceFull && !$this->shouldEvaluateStream($stream, $reasonFlags)) {
                    continue;
                }

                $currentPayload = $this->preparePayload($stream, $currentStates[$stream], $previousStates[$stream]['state'] ?? null);
                $previousPayload = isset($previousStates[$stream]) ? (array)$previousStates[$stream]['state'] : null;
                $currentHash = $this->stateDiffer->hash($currentStates[$stream]);
                $previousHash = isset($previousStates[$stream]['state_hash']) ? (string)$previousStates[$stream]['state_hash'] : null;
                $changedFields = $forceFull
                    ? $this->stateDiffer->fullChangedFields($currentPayload, $stream)
                    : $this->stateDiffer->changedFields($previousPayload, $currentPayload, $stream);

                if ($forceFull || $previousHash !== $currentHash) {
                    if ($changedFields !== []) {
                        $this->appendEvent(
                            $eventRows,
                            $stream,
                            $stream,
                            $productId,
                            (string)$currentStates['meta']['sku'],
                            $storeCode,
                            $stream === 'category' ? 'CATEGORY_FULL' : ($forceFull ? 'UPSERT_FULL' : 'UPSERT_PARTIAL'),
                            $changedFields,
                            $currentPayload,
                            $currentStates['meta']['source_updated_at']
                        );
                    }
                }

                $snapshotRows[] = $this->snapshotRow(
                    $productId,
                    (string)$currentStates['meta']['sku'],
                    $storeCode,
                    $stream,
                    (bool)$currentStates['meta']['enabled'],
                    $currentStates[$stream]
                );
            }
        }

        if ($eventRows !== []) {
            $this->changeLog->insertMany($eventRows);
        }
        if ($snapshotRows !== []) {
            $this->stateSnapshot->upsertMany($snapshotRows);
        }
    }

    private function shouldEvaluateStream(string $stream, int $reasonFlags): bool
    {
        return match ($stream) {
            'content' => (bool)($reasonFlags & (ReasonFlags::CONTENT | ReasonFlags::FORCE_COMPARE | ReasonFlags::FORCE_FULL | ReasonFlags::STATUS)),
            'seo' => (bool)($reasonFlags & (ReasonFlags::SEO | ReasonFlags::FORCE_COMPARE | ReasonFlags::FORCE_FULL | ReasonFlags::STATUS)),
            'price' => (bool)($reasonFlags & (ReasonFlags::PRICE | ReasonFlags::FORCE_COMPARE | ReasonFlags::FORCE_FULL | ReasonFlags::STATUS)),
            'availability' => (bool)($reasonFlags & (ReasonFlags::AVAILABILITY | ReasonFlags::FORCE_COMPARE | ReasonFlags::FORCE_FULL | ReasonFlags::STATUS)),
            'offer' => (bool)($reasonFlags & (ReasonFlags::PRICE | ReasonFlags::AVAILABILITY | ReasonFlags::FORCE_COMPARE | ReasonFlags::FORCE_FULL | ReasonFlags::STATUS)),
            'category' => (bool)($reasonFlags & (ReasonFlags::CATEGORY | ReasonFlags::FORCE_COMPARE | ReasonFlags::FORCE_FULL | ReasonFlags::STATUS)),
            'curated' => (bool)($reasonFlags & (ReasonFlags::CONTENT | ReasonFlags::SEO | ReasonFlags::PRICE | ReasonFlags::AVAILABILITY | ReasonFlags::CATEGORY | ReasonFlags::STATUS | ReasonFlags::FORCE_COMPARE | ReasonFlags::FORCE_FULL)),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(string $stream, bool $deleted): array
    {
        $payload = [
            'enabled' => false,
            'deleted' => $deleted,
        ];

        if ($stream === 'curated') {
            $payload['curated'] = [];
        } elseif ($stream === 'offer') {
            $payload['offer'] = [];
        } else {
            $payload['attributes'] = [];
        }

        return $payload;
    }

    private function extractPreviousEnabled(array $previousStates): bool
    {
        foreach ($previousStates as $state) {
            return (bool)($state['is_enabled'] ?? false);
        }
        return false;
    }

    private function preparePayload(string $stream, array $currentPayload, ?array $previousPayload): array
    {
        if ($stream !== 'category') {
            return $currentPayload;
        }

        $beforeIds = array_map(static fn (array $item): int => (int)$item['category_id'], (array)($previousPayload['category']['categories'] ?? []));
        $afterIds = array_map(static fn (array $item): int => (int)$item['category_id'], (array)($currentPayload['category']['categories'] ?? []));
        $currentPayload['category']['added_category_ids'] = array_values(array_diff($afterIds, $beforeIds));
        $currentPayload['category']['removed_category_ids'] = array_values(array_diff($beforeIds, $afterIds));
        sort($currentPayload['category']['added_category_ids']);
        sort($currentPayload['category']['removed_category_ids']);

        return $currentPayload;
    }

    private function appendEvent(
        array &$eventRows,
        string $deliveryStream,
        string $originStream,
        int $productId,
        string $sku,
        string $storeCode,
        string $eventType,
        array $changedFields,
        array $payload,
        ?string $sourceUpdatedAt
    ): void {
        $canonical = $this->canonicalizer->encode($payload);
        $payloadHash = hash('sha256', $canonical);
        $row = [
            'stream_code' => $deliveryStream,
            'origin_stream' => $originStream,
            'entity_id' => $productId,
            'sku' => $sku,
            'store_code' => $storeCode,
            'event_type' => $eventType,
            'schema_version' => 1,
            'payload_version' => 1,
            'changed_fields_json' => json_encode(array_values(array_unique($changedFields)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_json' => $canonical,
            'payload_hash' => $payloadHash,
            'source_updated_at' => $sourceUpdatedAt !== '' ? $sourceUpdatedAt : null,
        ];
        $eventRows[] = $row;
        if ($this->config->isStreamEnabled('all')) {
            $row['stream_code'] = 'all';
            $eventRows[] = $row;
        }
    }

    private function snapshotRow(int $productId, string $sku, string $storeCode, string $stream, bool $enabled, array $payload): array
    {
        return [
            'entity_id' => $productId,
            'sku' => $sku,
            'store_code' => $storeCode,
            'stream_code' => $stream,
            'is_enabled' => $enabled ? 1 : 0,
            'state_hash' => $this->stateDiffer->hash($payload),
            'state_json' => $this->canonicalizer->encode($payload),
        ];
    }
}
