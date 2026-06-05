<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\State;

use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\ResourceModel\StateSnapshot;
use Amida\ProductDeltaFeed\Model\State\JsonCanonicalizer;
use Amida\ProductDeltaFeed\Model\State\ProductStateBuilder;
use Amida\ProductDeltaFeed\Model\State\SnapshotRebuilder;
use Amida\ProductDeltaFeed\Model\State\StateDiffer;
use Amida\ProductDeltaFeed\Model\StoreScopeResolver;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class SnapshotRebuilderTest extends TestCase
{
    /**
     * The online-safe rebuild must never wipe the feed up front (no truncate), must release its
     * per-run caches (memory bound), and must sweep rows for products that no longer exist so a
     * rebuild stays a full reconcile.
     */
    public function testRebuildNeverTruncatesAndSweepsOrphanRows(): void
    {
        $snapshot = $this->createMock(StateSnapshot::class);
        $snapshot->expects($this->never())->method('truncate');
        $snapshot->expects($this->once())
            ->method('deleteProductsNotIn')
            ->with([1, 2])
            ->willReturn(0);

        $stateBuilder = $this->stateBuilderMock();
        $stateBuilder->expects($this->atLeastOnce())->method('resetCaches');

        $rebuilder = $this->newRebuilder($snapshot, $stateBuilder);

        self::assertSame(2, $rebuilder->rebuild());
    }

    /**
     * Rows are upserted in place, and configurable products (curated_excluded) get every stream
     * except curated -- the exact gap that previously fed a null curated state into hash() and
     * crash-looped the rebuild.
     */
    public function testRebuildUpsertsInPlaceAndOmitsCuratedForConfigurables(): void
    {
        $captured = [];
        $snapshot = $this->createMock(StateSnapshot::class);
        $snapshot->method('upsertMany')->willReturnCallback(
            static function (array $rows) use (&$captured): void {
                foreach ($rows as $row) {
                    $captured[] = $row;
                }
            }
        );
        $snapshot->method('deleteProductsNotIn')->willReturn(0);

        $rebuilder = $this->newRebuilder($snapshot, $this->stateBuilderMock());
        $rebuilder->rebuild();

        // 4 rows for the enabled simple product + 3 for the disabled configurable (no curated) = 7.
        self::assertCount(7, $captured);

        $curatedForConfigurable = array_filter(
            $captured,
            static fn (array $row): bool => $row['entity_id'] === 2 && $row['stream_code'] === 'curated'
        );
        self::assertSame([], $curatedForConfigurable, 'configurable product must not get a curated row');

        $curatedForSimple = array_filter(
            $captured,
            static fn (array $row): bool => $row['entity_id'] === 1 && $row['stream_code'] === 'curated'
        );
        self::assertCount(1, $curatedForSimple, 'simple product must get a curated row');
    }

    /**
     * Product 1: enabled simple -> 4 stream rows incl. curated.
     * Product 2: disabled configurable -> 3 rows (content/offer/category), curated omitted.
     */
    private function stateBuilderMock(): ProductStateBuilder
    {
        $stateBuilder = $this->createMock(ProductStateBuilder::class);
        $stateBuilder->method('buildStates')->willReturnCallback(
            static function (int $productId, string $storeCode): array {
                $configurable = $productId === 2;
                $enabled = !$configurable;

                return [
                    'meta' => [
                        'product_id' => $productId,
                        'sku' => 'SKU-' . $productId,
                        'enabled' => $enabled,
                        'source_updated_at' => '',
                        'store_code' => $storeCode,
                        'curated_excluded' => $configurable,
                    ],
                    'content' => ['enabled' => $enabled, 'attributes' => [], 'deleted' => false],
                    'offer' => ['enabled' => $enabled, 'deleted' => false, 'offer' => []],
                    'category' => ['enabled' => $enabled, 'category' => [], 'deleted' => false],
                    'curated' => $configurable
                        ? null
                        : ['enabled' => true, 'deleted' => false, 'curated' => ['sku' => 'SKU-' . $productId]],
                ];
            }
        );

        return $stateBuilder;
    }

    private function resourceMock(): ResourceConnection
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('select')->willReturn($select);
        $adapter->method('fetchCol')->willReturn([1, 2]);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnArgument(0);

        return $resource;
    }

    private function newRebuilder(StateSnapshot $snapshot, ProductStateBuilder $stateBuilder): SnapshotRebuilder
    {
        $config = $this->createMock(Config::class);
        $config->method('isStreamEnabled')->willReturn(true);

        $stateDiffer = $this->createMock(StateDiffer::class);
        $stateDiffer->method('hash')->willReturn('hash');

        $canonicalizer = $this->createMock(JsonCanonicalizer::class);
        $canonicalizer->method('encode')->willReturn('{}');

        $storeScopeResolver = $this->createMock(StoreScopeResolver::class);
        $storeScopeResolver->method('resolveStoreCodes')->willReturn(['default']);

        return new SnapshotRebuilder(
            $config,
            $stateBuilder,
            $stateDiffer,
            $snapshot,
            $storeScopeResolver,
            $canonicalizer,
            $this->resourceMock()
        );
    }
}
