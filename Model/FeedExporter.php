<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model;

use Amida\ProductDeltaFeed\Api\CompressorInterface;
use Amida\ProductDeltaFeed\Model\Proto\FeedEncoder;
use Amida\ProductDeltaFeed\Model\ResourceModel\EventResource;
use Amida\ProductDeltaFeed\Model\ResourceModel\SnapshotResource;

class FeedExporter
{
    private const FETCH_LIMIT = 1000;

    public function __construct(
        private readonly Config $config,
        private readonly EventResource $eventResource,
        private readonly SnapshotResource $snapshotResource,
        private readonly FeedEncoder $feedEncoder,
        private readonly CompressorInterface $compressor
    ) {
    }

    public function exportChanges(string $streamCode, int $cursor): array
    {
        $rows = $this->eventResource->fetchBatch($streamCode, $cursor, self::FETCH_LIMIT);
        return $this->buildResponse('changes', $streamCode, $cursor, $rows, 'event_id');
    }

    public function exportSnapshot(string $streamCode, int $cursor): array
    {
        $rows = $this->snapshotResource->fetchSnapshotBatch($streamCode, $cursor, self::FETCH_LIMIT);
        return $this->buildResponse('snapshot', $streamCode, $cursor, $rows, 'row_id');
    }

    private function buildResponse(string $mode, string $streamCode, int $cursor, array $rows, string $cursorKey): array
    {
        $items = [];
        $nextCursor = $cursor;
        $hasMore = false;
        $limit = $this->config->getMaxBatchSizeBytes();

        foreach ($rows as $row) {
            $payload = json_decode((string)$row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
            $payload['op'] = $mode === 'changes' ? (string)$row['op'] : 'snapshot';
            $payload['event_id'] = $mode === 'changes' ? (int)$row['event_id'] : 0;
            $candidateItems = $items;
            $candidateItems[] = $payload;
            $candidateEnvelope = $this->feedEncoder->encodeEnvelope([
                'module_version' => '1.0.0',
                'mode' => $mode,
                'stream' => $streamCode,
                'next_cursor' => (int)$row[$cursorKey],
                'has_more' => false,
                'item_count' => count($candidateItems),
                'items' => $candidateItems,
                'generated_at_unix' => time(),
            ]);

            if ($items !== [] && strlen($candidateEnvelope) > $limit) {
                $hasMore = true;
                break;
            }

            $items = $candidateItems;
            $nextCursor = (int)$row[$cursorKey];
        }

        if (!$hasMore && count($rows) === self::FETCH_LIMIT) {
            $hasMore = true;
        }

        $envelope = $this->feedEncoder->encodeEnvelope([
            'module_version' => '1.0.0',
            'mode' => $mode,
            'stream' => $streamCode,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'item_count' => count($items),
            'items' => $items,
            'generated_at_unix' => time(),
        ]);

        return [
            'body' => $this->compressor->compress($envelope, $this->config->getZstdLevel()),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'item_count' => count($items),
        ];
    }
}
