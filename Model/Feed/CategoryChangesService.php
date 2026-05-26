<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Feed;

use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\ResourceModel\CategoryChangeLog;

class CategoryChangesService
{
    public function __construct(
        private readonly Config $config,
        private readonly CategoryChangeLog $changeLog,
        private readonly FeedEncoder $encoder,
        private readonly ZstdCompressor $compressor
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function build(string $storeCode, int $afterEventId, array $filters = []): array
    {
        $oldest = $this->changeLog->getOldestRetainedEventId();
        $cursorExpired = $afterEventId > 0 && $oldest > 0 && $afterEventId < $oldest;
        if ($cursorExpired) {
            $encoded = $this->encoder->encodeCategoryChangesEnvelope([
                'schema_version' => 1,
                'stream' => Config::STREAM_CATEGORIES,
                'store_code' => $storeCode,
                'from_event_id' => $afterEventId,
                'to_event_id' => $afterEventId,
                'has_more' => false,
                'cursor_expired' => true,
            ], [], [[
                'code' => 'cursor_expired',
                'message' => 'Requested cursor is older than retained category events.',
            ]]);
            $body = $this->compressor->compress($encoded);
            return $this->response($storeCode, $afterEventId, $afterEventId, false, $encoded, $body, true);
        }

        $rows = $this->changeLog->fetchChanges(
            $storeCode,
            $afterEventId,
            $this->config->getCandidateLimit(),
            (string)($filters['changed_from'] ?? ''),
            (string)($filters['changed_to'] ?? ''),
            array_map('intval', (array)($filters['category_ids'] ?? []))
        );
        $accepted = [];
        $diagnostics = [];
        $toEventId = $afterEventId;
        $hasMore = false;
        $encoded = null;
        $compressed = null;
        $maxBytes = $this->config->getMaxBatchSizeBytes();

        foreach ($rows as $row) {
            $item = $this->rowToItem($row, $storeCode);
            $trialItems = array_merge($accepted, [$item]);
            $trialEncoded = $this->encoder->encodeCategoryChangesEnvelope([
                'schema_version' => 1,
                'stream' => Config::STREAM_CATEGORIES,
                'store_code' => $storeCode,
                'from_event_id' => $afterEventId,
                'to_event_id' => (int)$row['event_id'],
                'has_more' => false,
                'cursor_expired' => false,
            ], $trialItems, $diagnostics);
            $trialCompressed = $this->compressor->compress($trialEncoded);
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
            $encoded = $this->encoder->encodeCategoryChangesEnvelope([
                'schema_version' => 1,
                'stream' => Config::STREAM_CATEGORIES,
                'store_code' => $storeCode,
                'from_event_id' => $afterEventId,
                'to_event_id' => $toEventId,
                'has_more' => false,
                'cursor_expired' => false,
            ], [], $diagnostics);
            $compressed = $this->compressor->compress($encoded);
        }

        if (!$hasMore && count($rows) === $this->config->getCandidateLimit()) {
            $hasMore = true;
        }

        return $this->response($storeCode, $afterEventId, $toEventId, $hasMore, $encoded, $compressed, false);
    }

    private function response(string $storeCode, int $fromEventId, int $toEventId, bool $hasMore, string $encoded, string $body, bool $cursorExpired): array
    {
        return [
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/x-protobuf',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'X-Amida-Schema-Version' => '1',
                'X-Amida-Stream' => Config::STREAM_CATEGORIES,
                'X-Amida-Store' => $storeCode,
                'X-Amida-From-Event-Id' => (string)$fromEventId,
                'X-Amida-To-Event-Id' => (string)$toEventId,
                'X-Amida-Has-More' => $hasMore ? '1' : '0',
                'X-Amida-Cursor-Expired' => $cursorExpired ? '1' : '0',
                'X-Amida-Uncompressed-Length' => (string)strlen($encoded),
            ] + ($this->compressor->isEnabled() ? ['Content-Encoding' => 'zstd'] : []),
        ];
    }

    private function rowToItem(array $row, string $storeCode): array
    {
        return [
            'event_id' => (int)$row['event_id'],
            'stream' => Config::STREAM_CATEGORIES,
            'category_id' => (int)$row['category_id'],
            'store_code' => $storeCode,
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
