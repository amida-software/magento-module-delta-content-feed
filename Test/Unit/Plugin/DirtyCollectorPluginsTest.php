<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Plugin;

use Amida\ProductDeltaFeed\Model\Change\DirtyCollector;
use Amida\ProductDeltaFeed\Model\Change\ReasonFlags;
use Amida\ProductDeltaFeed\Model\ProductLocator;
use Amida\ProductDeltaFeed\Plugin\CategoryLinkManagementPlugin;
use Amida\ProductDeltaFeed\Plugin\CategoryLinkRepositoryPlugin;
use Amida\ProductDeltaFeed\Plugin\SourceItemsSavePlugin;
use Amida\ProductDeltaFeed\Plugin\StockRegistryPlugin;
use Magento\Catalog\Api\Data\CategoryProductLinkInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DirtyCollectorPluginsTest extends TestCase
{
    private ProductLocator&MockObject $productLocator;
    private DirtyCollector&MockObject $dirtyCollector;

    protected function setUp(): void
    {
        $this->productLocator = $this->createMock(ProductLocator::class);
        $this->dirtyCollector = $this->createMock(DirtyCollector::class);
    }

    public function testCategoryManagementPluginEnqueuesCategoryDirtyRow(): void
    {
        $this->productLocator->method('getIdBySku')->with('sku-10')->willReturn(10);
        $this->dirtyCollector
            ->expects(self::once())
            ->method('markDirty')
            ->with(10, 0, ReasonFlags::CATEGORY | ReasonFlags::FORCE_COMPARE, 'sku-10');

        $plugin = new CategoryLinkManagementPlugin($this->productLocator, $this->dirtyCollector);
        self::assertTrue($plugin->afterAssignProductToCategories(new \stdClass(), true, 'sku-10', [2, 3]));
    }

    public function testCategoryRepositoryPluginEnqueuesCategoryDirtyRows(): void
    {
        $productLink = $this->createMock(CategoryProductLinkInterface::class);
        $productLink->method('getSku')->willReturn('sku-11');
        $this->productLocator->method('getIdBySku')->with('sku-11')->willReturn(11);

        $calls = [];
        $this->dirtyCollector
            ->expects(self::exactly(2))
            ->method('markDirty')
            ->willReturnCallback(static function (
                int $productId,
                int $storeId,
                int $reasonFlags,
                ?string $sku = null
            ) use (&$calls): void {
                $calls[] = [$productId, $storeId, $reasonFlags, $sku];
            });

        $plugin = new CategoryLinkRepositoryPlugin($this->productLocator, $this->dirtyCollector);
        self::assertTrue($plugin->afterSave(new \stdClass(), true, $productLink));
        self::assertTrue($plugin->afterDeleteByIds(new \stdClass(), true, 3, 'sku-11'));

        self::assertSame(
            [
                [11, 0, ReasonFlags::CATEGORY | ReasonFlags::FORCE_COMPARE, 'sku-11'],
                [11, 0, ReasonFlags::CATEGORY | ReasonFlags::FORCE_COMPARE, 'sku-11'],
            ],
            $calls
        );
    }

    public function testStockRegistryPluginEnqueuesAvailabilityDirtyRow(): void
    {
        $this->productLocator->method('getIdBySku')->with('sku-12')->willReturn(12);
        $this->dirtyCollector
            ->expects(self::once())
            ->method('markDirty')
            ->with(12, 0, ReasonFlags::AVAILABILITY | ReasonFlags::FORCE_COMPARE, 'sku-12');

        $plugin = new StockRegistryPlugin($this->productLocator, $this->dirtyCollector);
        self::assertSame('result', $plugin->afterUpdateStockItemBySku(new \stdClass(), 'result', 'sku-12', new \stdClass()));
    }

    public function testSourceItemsSavePluginEnqueuesAvailabilityDirtyRowsAndSkipsEmptySku(): void
    {
        $this->productLocator->method('getIdBySku')->with('sku-13')->willReturn(13);
        $this->dirtyCollector
            ->expects(self::once())
            ->method('markDirty')
            ->with(13, 0, ReasonFlags::AVAILABILITY | ReasonFlags::FORCE_COMPARE, 'sku-13');

        $plugin = new SourceItemsSavePlugin($this->productLocator, $this->dirtyCollector);
        self::assertNull($plugin->afterExecute(new \stdClass(), null, [
            new class {
                public function getSku(): string
                {
                    return 'sku-13';
                }
            },
            new class {
                public function getSku(): string
                {
                    return '';
                }
            },
            new \stdClass(),
        ]));
    }
}
