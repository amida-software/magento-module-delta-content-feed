<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Plugin;

use Amida\ProductDeltaFeed\Model\Change\DirtyCollector;
use Amida\ProductDeltaFeed\Model\Change\ReasonFlags;
use Amida\ProductDeltaFeed\Plugin\ProductActionPlugin;
use Magento\Catalog\Model\Product\Action;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductActionPluginTest extends TestCase
{
    private DirtyCollector&MockObject $dirtyCollector;
    private Action&MockObject $action;

    protected function setUp(): void
    {
        $this->dirtyCollector = $this->createMock(DirtyCollector::class);
        $this->action = $this->createMock(Action::class);
    }

    public function testEnqueuesUniqueValidProductIdsWithMappedReasonFlags(): void
    {
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

        $plugin = new ProductActionPlugin($this->dirtyCollector);
        $result = $plugin->afterUpdateAttributes(
            $this->action,
            $this->action,
            [10, '10', 0, -5, 11],
            [
                'name' => 'Updated name',
                'price' => 12.34,
                'status' => 1,
            ],
            2
        );

        $expectedFlags = ReasonFlags::CONTENT
            | ReasonFlags::SEO
            | ReasonFlags::PRICE
            | ReasonFlags::STATUS
            | ReasonFlags::FORCE_COMPARE;

        self::assertSame($this->action, $result);
        self::assertSame(
            [
                [10, 2, $expectedFlags, null],
                [11, 2, $expectedFlags, null],
            ],
            $calls
        );
    }

    public function testUsesContentAndForceCompareForUnknownAttributes(): void
    {
        $this->dirtyCollector
            ->expects(self::once())
            ->method('markDirty')
            ->with(
                42,
                0,
                ReasonFlags::CONTENT | ReasonFlags::FORCE_COMPARE,
                null
            );

        $plugin = new ProductActionPlugin($this->dirtyCollector);
        $plugin->afterUpdateAttributes(
            $this->action,
            $this->action,
            [42],
            ['custom_attribute' => 'value'],
            0
        );
    }

    public function testSkipsEmptyProductIds(): void
    {
        $this->dirtyCollector
            ->expects(self::never())
            ->method('markDirty');

        $plugin = new ProductActionPlugin($this->dirtyCollector);
        $plugin->afterUpdateAttributes(
            $this->action,
            $this->action,
            [0, -1, '0'],
            ['status' => 1],
            1
        );
    }
}
