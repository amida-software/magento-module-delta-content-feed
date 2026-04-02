<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\Feed;

use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\Feed\ChangesService;
use Amida\ProductDeltaFeed\Model\Feed\FeedEncoder;
use Amida\ProductDeltaFeed\Model\Feed\ZstdCompressor;
use Amida\ProductDeltaFeed\Model\ResourceModel\ChangeLog;
use Amida\ProductDeltaFeed\Model\ResourceModel\DeadLetter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChangesServiceTest extends TestCase
{
    private Config&MockObject $config;
    private ChangeLog&MockObject $changeLog;
    private DeadLetter&MockObject $deadLetter;
    private FeedEncoder&MockObject $encoder;
    private ZstdCompressor&MockObject $compressor;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->changeLog = $this->createMock(ChangeLog::class);
        $this->deadLetter = $this->createMock(DeadLetter::class);
        $this->encoder = $this->createMock(FeedEncoder::class);
        $this->compressor = $this->createMock(ZstdCompressor::class);
    }

    public function testReturnsHeadersAndBody(): void
    {
        $this->config->method('getCandidateLimit')->willReturn(10);
        $this->config->method('getMaxBatchSizeBytes')->willReturn(1000);
        $this->config->method('getHardSingleItemLimitBytes')->willReturn(2000);
        $this->changeLog->method('getOldestRetainedEventId')->willReturn(1);
        $this->changeLog->method('getLastEventId')->willReturn(1);
        $this->changeLog->method('fetchChanges')->willReturn([[
            'event_id' => 1,
            'stream_code' => 'all',
            'origin_stream' => 'content',
            'entity_id' => 10,
            'sku' => 'sku-10',
            'store_code' => 'default',
            'event_type' => 'UPSERT_FULL',
            'payload_version' => 1,
            'payload_hash' => 'hash',
            'changed_fields_json' => '["name"]',
            'payload_json' => '{"enabled":true,"attributes":[],"deleted":false}',
            'created_at' => '2026-03-30 12:00:01',
            'source_updated_at' => '2026-03-30 12:00:00',
        ]]);
        $this->encoder->method('encodeChangesEnvelope')->willReturn('protobuf-body');
        $this->compressor->method('compress')->willReturnCallback(static fn (string $payload): string => $payload);
        $this->compressor->method('isEnabled')->willReturn(false);

        $service = new ChangesService($this->config, $this->changeLog, $this->deadLetter, $this->encoder, $this->compressor);
        $result = $service->build('all', 'default', 0);

        self::assertArrayHasKey('body', $result);
        self::assertSame('protobuf-body', $result['body']);
        self::assertSame('application/x-protobuf', $result['headers']['Content-Type']);
    }
}
