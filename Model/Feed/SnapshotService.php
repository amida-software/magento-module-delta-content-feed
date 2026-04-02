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
        private readonly SnapshotRebuilder $snapshotRebuilder
    ) {
    }

    public function build(string $stream, string $storeCode, int $afterStateId): array
    {
        if ($afterStateId <= 0 && $this->stateSnapshot->count() === 0) {
            $this->snapshotRebuilder->rebuild();
        }

        $rows = $this->stateSnapshot->fetchSnapshotRows($stream, $storeCode, $afterStateId, $this->config->getCandidateLimit());
        $accepted = [];
        $diagnostics = [];
        $toStateId = $afterStateId;
        $hasMore = false;
        $encoded = null;
        $compressed = null;
        $highwaterEventId = $this->changeLog->getLastEventId();
        $maxBytes = $this->config->getMaxBatchSizeBytes();

        foreach ($rows as $row) {
            $item = $this->rowToItem($row, $stream, $storeCode);
            $trialItems = array_merge($accepted, [$item]);
            $trialEncoded = $this->encoder->encodeSnapshotEnvelope([
                'schema_version' => 1,
                'stream' => $stream,
                'store_code' => $storeCode,
                'from_state_id' => $afterStateId,
                'to_state_id' => (int)$row['state_id'],
                'has_more' => false,
                'changes_highwater_event_id' => $highwaterEventId,
            ], $trialItems, $diagnostics);
            $trialCompressed = $this->compressor->compress($trialEncoded);
            if (strlen($trialCompressed) > $maxBytes) {
                $hasMore = true;
                break;
            }

            $accepted = $trialItems;
            $encoded = $trialEncoded;
            $compressed = $trialCompressed;
            $toStateId = (int)$row['state_id'];
        }

        if ($encoded === null || $compressed === null) {
            $encoded = $this->encoder->encodeSnapshotEnvelope([
                'schema_version' => 1,
                'stream' => $stream,
                'store_code' => $storeCode,
                'from_state_id' => $afterStateId,
                'to_state_id' => $toStateId,
                'has_more' => false,
                'changes_highwater_event_id' => $highwaterEventId,
            ], [], $diagnostics);
            $compressed = $this->compressor->compress($encoded);
        }

        if (!$hasMore && count($rows) === $this->config->getCandidateLimit()) {
            $hasMore = true;
        }

        return [
            'body' => $compressed,
            'headers' => [
                'Content-Type' => 'application/x-protobuf',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'X-Amida-Schema-Version' => '1',
                'X-Amida-Stream' => $stream,
                'X-Amida-Store' => $storeCode,
                'X-Amida-From-State-Id' => (string)$afterStateId,
                'X-Amida-To-State-Id' => (string)$toStateId,
                'X-Amida-Has-More' => $hasMore ? '1' : '0',
                'X-Amida-Changes-Highwater-Event-Id' => (string)$highwaterEventId,
                'X-Amida-Uncompressed-Length' => (string)strlen($encoded),
            ] + ($this->compressor->isEnabled() ? ['Content-Encoding' => 'zstd'] : []),
        ];
    }

    private function rowToItem(array $row, string $stream, string $storeCode): array
    {
        return [
            'state_id' => (int)$row['state_id'],
            'product_id' => (int)$row['entity_id'],
            'sku' => (string)$row['sku'],
            'stream' => $stream,
            'store_code' => $storeCode,
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'state_hash' => (string)($row['state_hash'] ?? ''),
            'payload' => json_decode((string)$row['state_json'], true) ?: [],
        ];
    }
}
