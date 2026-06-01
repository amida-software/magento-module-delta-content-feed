<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Feed;

use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\ResourceModel\ChangeLog;
use Amida\ProductDeltaFeed\Model\ResourceModel\StateSnapshot;
use Amida\ProductDeltaFeed\Model\State\SnapshotRebuilder;

class SnapshotService
{
    public function __construct(
        private readonly Config $config,
        private readonly StateSnapshot $stateSnapshot,
        private readonly ChangeLog $changeLog,
        private readonly FeedEncoder $encoder,
        private readonly ZstdCompressor $compressor,
        private readonly SnapshotRebuilder $snapshotRebuilder,
        private readonly CuratedParentInheritance $parentInheritance
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function build(string $stream, string $storeCode, int $afterStateId, array $filters = []): array
    {
        if ($afterStateId <= 0 && $this->stateSnapshot->count() === 0) {
            $this->snapshotRebuilder->rebuild();
        }

        $formatJson = !empty($filters['_format_json']);
        $skus = $this->normalizeSkus((array)($filters['skus'] ?? []));
        $includeOffer = (bool)($filters['include_offer'] ?? false);
        $candidateLimit = $this->config->getCandidateLimit();
        $isSkuLookup = $skus !== [];

        // SKU lookup is an explicit current-state read. It intentionally ignores after_state_id.
        $rows = $isSkuLookup
            ? $this->stateSnapshot->fetchSnapshotRowsBySkus($stream, $storeCode, $skus, min($candidateLimit, count($skus)))
            : $this->stateSnapshot->fetchSnapshotRows($stream, $storeCode, $afterStateId, $candidateLimit);

        $parentCuratedMap = $stream === Config::STREAM_CURATED
            ? $this->parentInheritance->buildParentCuratedMap($rows, $storeCode)
            : [];
        $offerMap = $includeOffer ? $this->loadOfferMap($rows, $storeCode) : [];
        $diagnostics = $this->buildSkuDiagnostics($isSkuLookup ? $skus : [], $rows, $stream);
        $accepted = [];
        $toStateId = $isSkuLookup ? 0 : $afterStateId;
        $hasMore = false;
        $encoded = null;
        $compressed = null;
        $highwaterEventId = $this->changeLog->getLastEventId();
        $maxBytes = $this->config->getMaxBatchSizeBytes();

        foreach ($rows as $row) {
            $item = $this->rowToItem($row, $stream, $storeCode, $includeOffer, $offerMap, $diagnostics, $parentCuratedMap);

            // Configurable parents are excluded from the curated output; their children carry the data.
            if ($stream === Config::STREAM_CURATED
                && (($item['payload']['curated']['magento_type_id'] ?? null) === 'configurable')) {
                if (!$isSkuLookup) {
                    $toStateId = (int)$row['state_id'];
                }
                continue;
            }

            $trialItems = array_merge($accepted, [$item]);
            $trialMeta = [
                'schema_version' => 1,
                'stream' => $stream,
                'store_code' => $storeCode,
                'from_state_id' => $isSkuLookup ? 0 : $afterStateId,
                'to_state_id' => $isSkuLookup ? 0 : (int)$row['state_id'],
                'has_more' => false,
                'changes_highwater_event_id' => $highwaterEventId,
            ];
            $trialEncoded = $formatJson
                ? $this->encodeJsonEnvelope($trialMeta, $trialItems, $diagnostics)
                : $this->encoder->encodeSnapshotEnvelope($trialMeta, $trialItems, $diagnostics);
            $trialCompressed = $formatJson ? $trialEncoded : $this->compressor->compress($trialEncoded);
            if (strlen($trialCompressed) > $maxBytes) {
                $hasMore = !$isSkuLookup;
                break;
            }

            $accepted = $trialItems;
            $encoded = $trialEncoded;
            $compressed = $trialCompressed;
            if (!$isSkuLookup) {
                $toStateId = (int)$row['state_id'];
            }
        }

        if ($encoded === null || $compressed === null) {
            $meta = [
                'schema_version' => 1,
                'stream' => $stream,
                'store_code' => $storeCode,
                'from_state_id' => $isSkuLookup ? 0 : $afterStateId,
                'to_state_id' => $toStateId,
                'has_more' => false,
                'changes_highwater_event_id' => $highwaterEventId,
            ];
            $encoded = $formatJson
                ? $this->encodeJsonEnvelope($meta, [], $diagnostics)
                : $this->encoder->encodeSnapshotEnvelope($meta, [], $diagnostics);
            $compressed = $formatJson ? $encoded : $this->compressor->compress($encoded);
        }

        if (!$isSkuLookup && !$hasMore && count($rows) === $candidateLimit) {
            $hasMore = true;
        }

        return [
            'body' => $compressed,
            'headers' => [
                'Content-Type' => $formatJson ? 'application/json' : 'application/x-protobuf',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'X-Amida-Schema-Version' => '1',
                'X-Amida-Stream' => $stream,
                'X-Amida-Store' => $storeCode,
                'X-Amida-From-State-Id' => (string)($isSkuLookup ? 0 : $afterStateId),
                'X-Amida-To-State-Id' => (string)$toStateId,
                'X-Amida-Has-More' => $hasMore ? '1' : '0',
                'X-Amida-Changes-Highwater-Event-Id' => (string)$highwaterEventId,
                'X-Amida-Uncompressed-Length' => (string)strlen($encoded),
                'X-Amida-Mode' => $isSkuLookup ? 'sku_lookup' : 'cursor_snapshot',
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

    /**
     * @param array<string, array<string, mixed>> $offerMap
     * @param array<int, array<string, mixed>> $diagnostics
     * @param array<string, array<string, mixed>> $parentCuratedMap child sku => parent curated payload
     */
    private function rowToItem(array $row, string $stream, string $storeCode, bool $includeOffer = false, array $offerMap = [], array &$diagnostics = [], array $parentCuratedMap = []): array
    {
        $payload = json_decode((string)$row['state_json'], true) ?: [];
        $stateHash = (string)($row['state_hash'] ?? '');
        if ($includeOffer && $stream !== Config::STREAM_OFFER) {
            $sku = (string)$row['sku'];
            $offerState = $offerMap[$sku]['state'] ?? null;
            if (is_array($offerState) && isset($offerState['offer'])) {
                $payload['offer'] = $offerState['offer'];
                $stateHash = $this->hashPayload($payload);
            } else {
                $diagnostics[] = [
                    'code' => 'offer_state_missing',
                    'message' => 'include_offer=1 requested, but offer state was not found for this SKU.',
                    'sku' => $sku,
                ];
            }
        }

        if ($stream === Config::STREAM_CURATED && isset($payload['curated']) && is_array($payload['curated'])) {
            // Configurable child inheritance: fill empty fields from the parent. Done before
            // stripping so inherited description/short_description are also stripped. state_hash is
            // left as the canonical source-state version and is intentionally not recomputed here.
            $sku = (string)($row['sku'] ?? '');
            if ($sku !== '' && isset($parentCuratedMap[$sku]) && is_array($parentCuratedMap[$sku])) {
                $payload['curated'] = $this->parentInheritance->inherit($payload['curated'], $parentCuratedMap[$sku]);
            }

            // Presentational HTML stripping for curated description fields. Applied at read time so
            // it also covers already-stored snapshots without a full state rebuild.
            foreach (['description', 'short_description'] as $htmlField) {
                if (isset($payload['curated'][$htmlField]) && is_string($payload['curated'][$htmlField])) {
                    $payload['curated'][$htmlField] = $this->stripHtmlText($payload['curated'][$htmlField]);
                }
            }
        }

        return [
            'state_id' => (int)$row['state_id'],
            'product_id' => (int)$row['entity_id'],
            'sku' => (string)$row['sku'],
            'stream' => $stream,
            'store_code' => $storeCode,
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'state_hash' => $stateHash,
            'payload' => $payload,
        ];
    }

    /** Strip HTML tags/entities to plain text (no truncation). Returns null when empty. */
    private function stripHtmlText(string $value): ?string
    {
        $value = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $value) ?? $value;
        $value = preg_replace('#<[^>]+>#', ' ', $value) ?? $value;
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function loadOfferMap(array $rows, string $storeCode): array
    {
        $skus = [];
        foreach ($rows as $row) {
            $sku = trim((string)($row['sku'] ?? ''));
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }
        return $this->stateSnapshot->fetchStateMapBySkus(Config::STREAM_OFFER, $storeCode, $skus);
    }

    /** @param string[] $skus */
    private function buildSkuDiagnostics(array $skus, array $rows, string $stream): array
    {
        if ($skus === []) {
            return [];
        }

        $found = [];
        foreach ($rows as $row) {
            $found[(string)$row['sku']] = true;
        }

        $diagnostics = [];
        foreach ($skus as $sku) {
            if (!isset($found[$sku])) {
                $diagnostics[] = [
                    'code' => 'sku_state_missing',
                    'message' => 'Requested SKU has no current state for stream ' . $stream . '.',
                    'sku' => $sku,
                ];
            }
        }
        return $diagnostics;
    }

    /** @param string[] $skus */
    private function normalizeSkus(array $skus): array
    {
        return array_values(array_filter(array_unique(array_map(static fn (mixed $sku): string => trim((string)$sku), $skus)), static fn (string $sku): bool => $sku !== ''));
    }

    /** @param mixed $payload */
    private function hashPayload(mixed $payload): string
    {
        $payload = $this->sortRecursively($payload);
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->sortRecursively($child);
        }
        return $value;
    }
}
