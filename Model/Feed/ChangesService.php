<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Feed;

use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\ResourceModel\ChangeLog;
use Amida\ProductDeltaFeed\Model\ResourceModel\DeadLetter;
use Amida\ProductDeltaFeed\Model\ResourceModel\StateSnapshot;

class ChangesService
{
    public function __construct(
        private readonly Config $config,
        private readonly ChangeLog $changeLog,
        private readonly DeadLetter $deadLetter,
        private readonly StateSnapshot $stateSnapshot,
        private readonly FeedEncoder $encoder,
        private readonly ZstdCompressor $compressor
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function build(string $stream, string $storeCode, int $afterEventId, array $filters = []): array
    {
        $oldest = $this->changeLog->getOldestRetainedEventId();
        $last = $this->changeLog->getLastEventId();
        $cursorExpired = $afterEventId > 0 && $oldest > 0 && $afterEventId < $oldest;
        if ($cursorExpired) {
            $encoded = $this->encoder->encodeChangesEnvelope([
                'schema_version' => 1,
                'stream' => $stream,
                'store_code' => $storeCode,
                'from_event_id' => $afterEventId,
                'to_event_id' => $afterEventId,
                'has_more' => false,
                'cursor_expired' => true,
            ], [], [[
                'code' => 'cursor_expired',
                'message' => 'Requested cursor is older than retained events.',
            ]]);
            $body = $this->compressor->compress($encoded);
            return $this->response($stream, $storeCode, $afterEventId, $afterEventId, false, $encoded, $body, true);
        }

        $includeOffer = (bool)($filters['include_offer'] ?? false);
        $candidateRows = $this->changeLog->fetchChanges(
            $stream,
            $storeCode,
            $afterEventId,
            $this->config->getCandidateLimit(),
            (string)($filters['changed_from'] ?? ''),
            (string)($filters['changed_to'] ?? ''),
            (array)($filters['skus'] ?? [])
        );
        $offerMap = $includeOffer ? $this->loadOfferMap($candidateRows, $storeCode) : [];
        $accepted = [];
        $diagnostics = [];
        $toEventId = $afterEventId;
        $hasMore = false;
        $encoded = null;
        $compressed = null;
        $maxBytes = $this->config->getMaxBatchSizeBytes();
        $hardSingleLimit = $this->config->getHardSingleItemLimitBytes();

        foreach ($candidateRows as $row) {
            $decodedItem = $this->rowToItem($row, $includeOffer, $offerMap);
            $trialItems = array_merge($accepted, [$decodedItem]);
            $trialEncoded = $this->encoder->encodeChangesEnvelope([
                'schema_version' => 1,
                'stream' => $stream,
                'store_code' => $storeCode,
                'from_event_id' => $afterEventId,
                'to_event_id' => (int)$row['event_id'],
                'has_more' => false,
                'cursor_expired' => false,
            ], $trialItems, $diagnostics);
            $trialCompressed = $this->compressor->compress($trialEncoded);

            if ($accepted === [] && strlen($trialCompressed) > $maxBytes) {
                if (strlen($trialCompressed) <= $hardSingleLimit) {
                    $accepted = [$decodedItem];
                    $encoded = $trialEncoded;
                    $compressed = $trialCompressed;
                    $toEventId = (int)$row['event_id'];
                    break;
                }

                $diagnostics[] = [
                    'code' => 'oversize_item',
                    'message' => 'Skipped item because compressed payload exceeds hard_single_item_limit_bytes.',
                    'event_id' => (int)$row['event_id'],
                ];
                $this->deadLetter->add([
                    'event_id' => (int)$row['event_id'],
                    'entity_id' => (int)$row['entity_id'],
                    'sku' => (string)$row['sku'],
                    'stream_code' => (string)$row['stream_code'],
                    'store_code' => (string)$row['store_code'],
                    'reason_code' => 'oversize_item',
                    'details_json' => json_encode(['limit' => $hardSingleLimit], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $toEventId = (int)$row['event_id'];
                continue;
            }

            if (strlen($trialCompressed) > $maxBytes) {
                $hasMore = true;
                break;
            }

            $accepted = $trialItems;
            $encoded = $trialEncoded;
            $compressed = $trialCompressed;
            $toEventId = (int)$row['event_id'];
        }

        if ($encoded === null || $compressed === null) {
            $encoded = $this->encoder->encodeChangesEnvelope([
                'schema_version' => 1,
                'stream' => $stream,
                'store_code' => $storeCode,
                'from_event_id' => $afterEventId,
                'to_event_id' => $toEventId,
                'has_more' => false,
                'cursor_expired' => false,
            ], [], $diagnostics);
            $compressed = $this->compressor->compress($encoded);
        }

        if (!$hasMore && count($candidateRows) === $this->config->getCandidateLimit() && $toEventId < $last) {
            $hasMore = true;
        }

        return $this->response($stream, $storeCode, $afterEventId, $toEventId, $hasMore, $encoded, $compressed, false);
    }

    private function response(
        string $stream,
        string $storeCode,
        int $fromEventId,
        int $toEventId,
        bool $hasMore,
        string $encoded,
        string $body,
        bool $cursorExpired
    ): array {
        return [
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/x-protobuf',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'X-Amida-Schema-Version' => '1',
                'X-Amida-Stream' => $stream,
                'X-Amida-Store' => $storeCode,
                'X-Amida-From-Event-Id' => (string)$fromEventId,
                'X-Amida-To-Event-Id' => (string)$toEventId,
                'X-Amida-Has-More' => $hasMore ? '1' : '0',
                'X-Amida-Cursor-Expired' => $cursorExpired ? '1' : '0',
                'X-Amida-Uncompressed-Length' => (string)strlen($encoded),
            ] + ($this->compressor->isEnabled() ? ['Content-Encoding' => 'zstd'] : []),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $offerMap
     */
    private function rowToItem(array $row, bool $includeOffer = false, array $offerMap = []): array
    {
        $payload = json_decode((string)$row['payload_json'], true) ?: [];
        $payloadHash = (string)($row['payload_hash'] ?? '');
        if ($includeOffer && (string)$row['stream_code'] !== Config::STREAM_OFFER) {
            $offerState = $offerMap[(string)$row['sku']]['state'] ?? null;
            if (is_array($offerState) && isset($offerState['offer'])) {
                $payload['offer'] = $offerState['offer'];
                $payloadHash = $this->hashPayload($payload);
            }
        }

        return [
            'event_id' => (int)$row['event_id'],
            'stream' => (string)$row['stream_code'],
            'origin_stream' => (string)$row['origin_stream'],
            'product_id' => (int)$row['entity_id'],
            'sku' => (string)$row['sku'],
            'store_code' => (string)$row['store_code'],
            'event_type' => (string)$row['event_type'],
            'changed_fields' => json_decode((string)$row['changed_fields_json'], true) ?: [],
            'source_updated_at' => (string)($row['source_updated_at'] ?? ''),
            'emitted_at' => (string)($row['created_at'] ?? ''),
            'payload_version' => (int)($row['payload_version'] ?? 1),
            'payload_hash' => $payloadHash,
            'payload' => $payload,
        ];
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
        return $this->stateSnapshot->fetchStateMapBySkus('offer', $storeCode, $skus);
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
