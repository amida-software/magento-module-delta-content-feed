<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\Offer;

use PHPUnit\Framework\TestCase;

class DirectSqlOfferProviderSourceTest extends TestCase
{
    public function testOfferProviderUsesSourceTablesAndDoesNotUseIndexesOrInventoryApis(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../../../Model/Offer/DirectSqlOfferProvider.php');

        self::assertStringContainsString('catalog_product_entity_decimal', $source);
        self::assertStringContainsString('cataloginventory_stock_item', $source);
        self::assertStringContainsString('inventory_source_item', $source);
        self::assertStringContainsString('inventory_reservation', $source);

        self::assertDoesNotMatchRegularExpression('/inventory_stock_\\d+/', $source);
        self::assertStringNotContainsString('cataloginventory_stock_status', $source);
        self::assertStringNotContainsString('catalog_product_index_price', $source);
        self::assertStringNotContainsString('StockRegistry', $source);
        self::assertStringNotContainsString('GetProductSalableQty', $source);
        self::assertStringNotContainsString('IsProductSalable', $source);
        self::assertStringNotContainsString('getFinalPrice', $source);
    }

    public function testOfferPayloadDoesNotExposeRedundantStockStatusFields(): void
    {
        $offerProvider = (string)file_get_contents(__DIR__ . '/../../../../Model/Offer/DirectSqlOfferProvider.php');
        $stateBuilder = (string)file_get_contents(__DIR__ . '/../../../../Model/State/ProductStateBuilder.php');

        self::assertStringNotContainsString("'availability' => \$availability", $offerProvider);
        self::assertStringNotContainsString("'is_in_stock' => \$stock['is_in_stock']", $offerProvider);
        self::assertStringNotContainsString("'stock_status' => \$stock['stock_status']", $offerProvider);
        self::assertStringNotContainsString("'availability' => \$enabled", $stateBuilder);
        self::assertStringNotContainsString("'is_in_stock' => false", $stateBuilder);
        self::assertStringNotContainsString("'stock_status' => 'out_of_stock'", $stateBuilder);
    }
}
