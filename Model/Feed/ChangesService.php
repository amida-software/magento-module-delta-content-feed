<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Feed;

use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\ResourceModel\ChangeLog;
use Amida\ProductDeltaFeed\Model\ResourceModel\DeadLetter;

class ChangesService
{
    public function __construct(
        private readonly Config $config,
        private readonly ChangeLog $changeLog,
        private readonly DeadLetter $deadLetter,
        private readonly FeedEncoder $encoder,
        private readonly ZstdCompressor $compressor
    ) {
    }

    public function build(string $stream, string $storeCode, int $afterEventId): array
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

        $candidateRows = $this->changeLog->fetchChanges($stream, $storeCode, $afterEventId, $this->config->getCandidateLimit());
        $accepted = [];
        $diagnostics = [];
        $toEventId = $afterEventId;
        $hasMore = false;
        $encoded = null;
        $compressed = null;
        $maxBytes = $this->config->getMaxBatchSizeBytes();
        $hardSingleLimit = $this->config->getHardSingleItemLimitBytes();

        foreach ($candidateRows as $row) {
            $decodedItem = $this->rowToItem($row);
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

    private function rowToItem(array $row): array
    {
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
            'payload_hash' => (string)($row['payload_hash'] ?? ''),
            'payload' => json_decode((string)$row['payload_json'], true) ?: [],
        ];
    }
}
