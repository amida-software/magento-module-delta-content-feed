<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\ResourceModel;

use Amida\ProductDeltaFeed\Model\ResourceModel\StateSnapshot;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

class StateSnapshotTest extends TestCase
{
    /**
     * The sweep must be a no-op on an empty id set -- otherwise "delete rows not in ()" would wipe
     * the entire feed state table.
     */
    public function testDeleteProductsNotInIgnoresEmptySet(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects($this->never())->method('delete');

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnArgument(0);

        $snapshot = new StateSnapshot($resource);

        self::assertSame(0, $snapshot->deleteProductsNotIn([]));
    }

    public function testDeleteProductsNotInDeletesByDedupedIdSet(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects($this->once())
            ->method('delete')
            ->with('amida_product_delta_state', ['entity_id NOT IN (?)' => [1, 2, 3]])
            ->willReturn(5);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnArgument(0);

        $snapshot = new StateSnapshot($resource);

        self::assertSame(5, $snapshot->deleteProductsNotIn([1, 2, 2, 3]));
    }
}
