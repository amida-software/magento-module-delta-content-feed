<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\Feed;

use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\Feed\FeedEncoder;
use Amida\ProductDeltaFeed\Model\Feed\SnapshotService;
use Amida\ProductDeltaFeed\Model\Feed\ZstdCompressor;
use Amida\ProductDeltaFeed\Model\ResourceModel\ChangeLog;
use Amida\ProductDeltaFeed\Model\ResourceModel\StateSnapshot;
use Amida\ProductDeltaFeed\Model\State\SnapshotRebuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SnapshotServiceTest extends TestCase
{
    private Config&MockObject $config;
    private StateSnapshot&MockObject $stateSnapshot;
    private ChangeLog&MockObject $changeLog;
    private FeedEncoder&MockObject $encoder;
    private ZstdCompressor&MockObject $compressor;
    private SnapshotRebuilder&MockObject $snapshotRebuilder;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->stateSnapshot = $this->createMock(StateSnapshot::class);
        $this->changeLog = $this->createMock(ChangeLog::class);
        $this->encoder = $this->createMock(FeedEncoder::class);
        $this->compressor = $this->createMock(ZstdCompressor::class);
        $this->snapshotRebuilder = $this->createMock(SnapshotRebuilder::class);
    }

    public function testFirstRequestRebuildsSnapshotAndReturnsOrderedRows(): void
    {
        $this->config->method('getCandidateLimit')->willReturn(50);
        $this->config->method('getMaxBatchSizeBytes')->willReturn(4096);
        $this->stateSnapshot->expects(self::once())->method('count')->willReturn(0);
        $this->snapshotRebuilder->expects(self::once())->method('rebuild');
        $this->stateSnapshot->expects(self::once())
            ->method('fetchSnapshotRows')
            ->with('content', 'default', 0, 50)
            ->willReturn([
                [
                    'state_id' => 10,
                    'entity_id' => 100,
                    'sku' => 'sku-100',
                    'updated_at' => '2026-03-31 10:00:00',
                    'state_hash' => 'hash-100',
                    'state_json' => '{"enabled":true,"attributes":[],"deleted":false}',
                ],
                [
                    'state_id' => 11,
                    'entity_id' => 101,
                    'sku' => 'sku-101',
                    'updated_at' => '2026-03-31 10:01:00',
                    'state_hash' => 'hash-101',
                    'state_json' => '{"enabled":true,"attributes":[],"deleted":false}',
                ],
            ]);
        $this->changeLog->method('getLastEventId')->willReturn(77);
        $this->encoder->method('encodeSnapshotEnvelope')->willReturnOnConsecutiveCalls('pb-10', 'pb-11');
        $this->compressor->method('compress')->willReturnCallback(static fn (string $payload): string => $payload);
        $this->compressor->method('isEnabled')->willReturn(false);

        $service = new SnapshotService(
            $this->config,
            $this->stateSnapshot,
            $this->changeLog,
            $this->encoder,
            $this->compressor,
            $this->snapshotRebuilder
        );

        $result = $service->build('content', 'default', 0);

        self::assertSame('pb-11', $result['body']);
        self::assertSame('11', $result['headers']['X-Amida-To-State-Id']);
        self::assertSame('0', $result['headers']['X-Amida-From-State-Id']);
        self::assertSame('77', $result['headers']['X-Amida-Changes-Highwater-Event-Id']);
    }

    public function testNonInitialCursorDoesNotTriggerRebuild(): void
    {
        $this->config->method('getCandidateLimit')->willReturn(50);
        $this->config->method('getMaxBatchSizeBytes')->willReturn(4096);
        $this->stateSnapshot->expects(self::never())->method('count');
        $this->snapshotRebuilder->expects(self::never())->method('rebuild');
        $this->stateSnapshot->expects(self::once())
            ->method('fetchSnapshotRows')
            ->with('content', 'default', 15, 50)
            ->willReturn([]);
        $this->changeLog->method('getLastEventId')->willReturn(88);
        $this->encoder->method('encodeSnapshotEnvelope')->willReturn('pb-empty');
        $this->compressor->method('compress')->willReturn('pb-empty');
        $this->compressor->method('isEnabled')->willReturn(false);

        $service = new SnapshotService(
            $this->config,
            $this->stateSnapshot,
            $this->changeLog,
            $this->encoder,
            $this->compressor,
            $this->snapshotRebuilder
        );

        $result = $service->build('content', 'default', 15);

        self::assertSame('pb-empty', $result['body']);
        self::assertSame('15', $result['headers']['X-Amida-To-State-Id']);
    }
    public function testSkuSnapshotCanEmbedCurrentOfferState(): void
    {
        $this->config->method('getCandidateLimit')->willReturn(50);
        $this->config->method('getMaxBatchSizeBytes')->willReturn(4096);
        $this->stateSnapshot->expects(self::once())->method('count')->willReturn(1);
        $this->snapshotRebuilder->expects(self::never())->method('rebuild');
        $this->stateSnapshot->expects(self::once())
            ->method('fetchSnapshotRowsBySkus')
            ->with('content', 'default', ['SKU-1'], 1)
            ->willReturn([[
                'state_id' => 10,
                'entity_id' => 100,
                'sku' => 'SKU-1',
                'updated_at' => '2026-05-25 12:00:00',
                'state_hash' => 'content-hash',
                'state_json' => '{"enabled":true,"attributes":[],"deleted":false}',
            ]]);
        $this->stateSnapshot->expects(self::once())
            ->method('fetchStateMapBySkus')
            ->with('offer', 'default', ['SKU-1'])
            ->willReturn([
                'SKU-1' => [
                    'state' => [
                        'offer' => [
                            'sku' => 'SKU-1',
                            'prices' => ['old' => 100.0, 'current' => 80.0, 'currency' => 'EUR'],
                            'availability' => 'in_stock',
                            'qty' => 5.0,
                        ],
                    ],
                ],
            ]);
        $this->changeLog->method('getLastEventId')->willReturn(99);
        $this->encoder->expects(self::once())
            ->method('encodeSnapshotEnvelope')
            ->willReturnCallback(function (array $meta, array $items, array $diagnostics): string {
                self::assertSame('content', $meta['stream']);
                self::assertSame([], $diagnostics);
                self::assertSame('SKU-1', $items[0]['payload']['offer']['sku'] ?? null);
                self::assertSame(80.0, $items[0]['payload']['offer']['prices']['current'] ?? null);
                return 'pb-with-offer';
            });
        $this->compressor->method('compress')->willReturnCallback(static fn (string $payload): string => $payload);
        $this->compressor->method('isEnabled')->willReturn(false);

        $service = new SnapshotService(
            $this->config,
            $this->stateSnapshot,
            $this->changeLog,
            $this->encoder,
            $this->compressor,
            $this->snapshotRebuilder
        );

        $result = $service->build('content', 'default', 0, [
            'skus' => ['SKU-1'],
            'include_offer' => true,
        ]);

        self::assertSame('pb-with-offer', $result['body']);
        self::assertSame('sku_lookup', $result['headers']['X-Amida-Mode']);
    }

}
