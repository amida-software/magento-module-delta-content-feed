<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Feed;

use Amida\ProductDeltaFeed\Model\Category\CategorySnapshotRebuilder;
use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\ResourceModel\CategoryChangeLog;
use Amida\ProductDeltaFeed\Model\ResourceModel\CategoryStateSnapshot;

class CategorySnapshotService
{
    public function __construct(
        private readonly Config $config,
        private readonly CategoryStateSnapshot $stateSnapshot,
        private readonly CategoryChangeLog $changeLog,
        private readonly FeedEncoder $encoder,
        private readonly ZstdCompressor $compressor,
        private readonly CategorySnapshotRebuilder $snapshotRebuilder
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function build(string $storeCode, int $afterStateId, array $filters = []): array
    {
        $formatJson = !empty($filters['_format_json']);
        if ($afterStateId <= 0 && $this->stateSnapshot->count() === 0) {
            $this->snapshotRebuilder->rebuild();
        }

        $categoryIds = $this->normalizeIds((array)($filters['category_ids'] ?? []));
        $isCategoryLookup = $categoryIds !== [];
        $candidateLimit = $this->config->getCandidateLimit();
        $rows = $this->stateSnapshot->fetchSnapshotRows(
            $storeCode,
            $isCategoryLookup ? 0 : $afterStateId,
            $candidateLimit,
            $categoryIds
        );

        $accepted = [];
        $diagnostics = $this->buildCategoryDiagnostics($isCategoryLookup ? $categoryIds : [], $rows);
        $toStateId = $isCategoryLookup ? 0 : $afterStateId;
        $hasMore = false;
        $encoded = null;
        $compressed = null;
        $highwaterEventId = $this->changeLog->getLastEventId();
        $maxBytes = $this->config->getMaxBatchSizeBytes();

        foreach ($rows as $row) {
            $item = $this->rowToItem($row, $storeCode);
            $trialItems = array_merge($accepted, [$item]);
            $trialMeta = [
                'schema_version' => 1,
                'stream' => Config::STREAM_CATEGORIES,
                'store_code' => $storeCode,
                'from_state_id' => $isCategoryLookup ? 0 : $afterStateId,
                'to_state_id' => $isCategoryLookup ? 0 : (int)$row['state_id'],
                'has_more' => false,
                'changes_highwater_event_id' => $highwaterEventId,
            ];
            $trialEncoded = $formatJson
                ? $this->encodeJsonEnvelope($trialMeta, $trialItems, $diagnostics)
                : $this->encoder->encodeCategorySnapshotEnvelope($trialMeta, $trialItems, $diagnostics);
            $trialCompressed = $formatJson ? $trialEncoded : $this->compressor->compress($trialEncoded);
            if (strlen($trialCompressed) > $maxBytes) {
                $hasMore = !$isCategoryLookup;
                break;
            }
            $accepted = $trialItems;
            $encoded = $trialEncoded;
            $compressed = $trialCompressed;
            if (!$isCategoryLookup) {
                $toStateId = (int)$row['state_id'];
            }
        }

        if ($encoded === null || $compressed === null) {
            $meta = [
                'schema_version' => 1,
                'stream' => Config::STREAM_CATEGORIES,
                'store_code' => $storeCode,
                'from_state_id' => $isCategoryLookup ? 0 : $afterStateId,
                'to_state_id' => $toStateId,
                'has_more' => false,
                'changes_highwater_event_id' => $highwaterEventId,
            ];
            $encoded = $formatJson
                ? $this->encodeJsonEnvelope($meta, [], $diagnostics)
                : $this->encoder->encodeCategorySnapshotEnvelope($meta, [], $diagnostics);
            $compressed = $formatJson ? $encoded : $this->compressor->compress($encoded);
        }

        if (!$isCategoryLookup && !$hasMore && count($rows) === $candidateLimit) {
            $hasMore = true;
        }

        return $this->response(
            $storeCode,
            $isCategoryLookup ? 0 : $afterStateId,
            $toStateId,
            $hasMore,
            $isCategoryLookup,
            $highwaterEventId,
            $encoded,
            $compressed,
            $formatJson
        );
    }

    private function response(
        string $storeCode,
        int $fromStateId,
        int $toStateId,
        bool $hasMore,
        bool $isCategoryLookup,
        int $highwaterEventId,
        string $encoded,
        string $body,
        bool $formatJson = false
    ): array {
        return [
            'body' => $body,
            'headers' => [
                'Content-Type' => $formatJson ? 'application/json' : 'application/x-protobuf',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'X-Amida-Schema-Version' => '1',
                'X-Amida-Stream' => Config::STREAM_CATEGORIES,
                'X-Amida-Store' => $storeCode,
                'X-Amida-From-State-Id' => (string)$fromStateId,
                'X-Amida-To-State-Id' => (string)$toStateId,
                'X-Amida-Has-More' => ($isCategoryLookup ? false : $hasMore) ? '1' : '0',
                'X-Amida-Mode' => $isCategoryLookup ? 'category_lookup' : 'cursor_snapshot',
                'X-Amida-Changes-Highwater-Event-Id' => (string)$highwaterEventId,
                'X-Amida-Uncompressed-Length' => (string)strlen($encoded),
            ] + (!$formatJson && $this->compressor->isEnabled() ? ['Content-Encoding' => 'zstd'] : []),
        ];
    }

    /** @param array<string, mixed> $meta @param array<int, array<string, mixed>> $items @param array<int, array<string, mixed>> $diagnostics */
    private function encodeJsonEnvelope(array $meta, array $items, array $diagnostics = []): string
    {
        return (string)json_encode($meta + [
            'items' => $items,
            'diagnostics' => $diagnostics,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function rowToItem(array $row, string $storeCode): array
    {
        return [
            'state_id' => (int)$row['state_id'],
            'category_id' => (int)$row['category_id'],
            'stream' => Config::STREAM_CATEGORIES,
            'store_code' => $storeCode,
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'state_hash' => (string)($row['state_hash'] ?? ''),
            'payload' => json_decode((string)$row['state_json'], true) ?: [],
        ];
    }

    /** @param int[] $ids */
    private function buildCategoryDiagnostics(array $ids, array $rows): array
    {
        if ($ids === []) {
            return [];
        }
        $found = [];
        foreach ($rows as $row) {
            $found[(int)$row['category_id']] = true;
        }
        $diagnostics = [];
        foreach ($ids as $id) {
            if (!isset($found[$id])) {
                $diagnostics[] = [
                    'code' => 'category_state_missing',
                    'message' => 'Requested category_id has no current category state.',
                    'category_id' => $id,
                ];
            }
        }
        return $diagnostics;
    }

    /** @param mixed[] $ids */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_filter(array_unique(array_map('intval', $ids)), static fn (int $id): bool => $id > 0));
    }
}
