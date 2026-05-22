<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\State;

use Amida\ProductDeltaFeed\Model\State\Curated\CategoryProvider;
use Amida\ProductDeltaFeed\Model\State\Curated\ImageUrlBuilder;
use Amida\ProductDeltaFeed\Model\State\Curated\RelatedProductProvider;
use Amida\ProductDeltaFeed\Model\State\CuratedProductBuilder;
use Magento\Catalog\Model\Product;
use PHPUnit\Framework\TestCase;

class CuratedProductBuilderTest extends TestCase
{
    public function testBuildsConsumerFriendlyFullProductDocument(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(10);
        $product->method('getSku')->willReturn('SKU-10');
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPrice')->willReturn(1200.0);
        $product->method('getFinalPrice')->willReturn(950.0);
        $product->method('getData')->willReturnCallback(static function (string $code): mixed {
            return [
                'name' => 'Idole',
                'description' => 'Long perfume description',
                'short_description' => 'Short perfume description',
                'url_key' => 'idole',
                'manufacturer' => '482',
                'notes' => '8,20',
                'status' => 1,
            ][$code] ?? null;
        });
        $product->method('getAttributeText')->willReturnCallback(static function (string $code): mixed {
            return match ($code) {
                'manufacturer' => 'Lubin',
                'notes' => ['cumin', 'sandal'],
                default => null,
            };
        });

        $categoryProvider = $this->createMock(CategoryProvider::class);
        $categoryProvider->expects(self::once())
            ->method('getCategories')
            ->with(10, 'ua')
            ->willReturn([[
                'category_id' => 3,
                'name' => 'Perfume',
                'url_key' => 'perfume',
                'path' => ['Catalog', 'Perfume'],
                'position' => 7,
            ]]);

        $imageUrlBuilder = $this->createMock(ImageUrlBuilder::class);
        $imageUrlBuilder->expects(self::once())
            ->method('getImageUrls')
            ->with($product, 'ua')
            ->willReturn([
                'https://www.jan.com.ua/media/catalog/product/i/d/idole.jpg',
            ]);

        $relatedProductProvider = $this->createMock(RelatedProductProvider::class);
        $relatedProductProvider->expects(self::once())
            ->method('getRelatedProducts')
            ->with(10)
            ->willReturn([[
                'relation' => 'related',
                'product_id' => 11,
                'sku' => 'REL-11',
            ]]);

        $builder = new CuratedProductBuilder($categoryProvider, $imageUrlBuilder, $relatedProductProvider);
        $payload = $builder->build($product, 'ua', [
            'is_in_stock' => false,
            'is_salable' => true,
            'qty' => 5.0,
            'manage_stock' => true,
            'backorders' => 0,
            'stock_status' => 'in_stock',
        ]);

        self::assertSame([
            'enabled' => true,
            'deleted' => false,
            'curated' => [
                'sku' => 'SKU-10',
                'prices' => [
                    'old' => 1200.0,
                    'new' => 950.0,
                ],
                'availability' => [
                    'is_available' => true,
                    'qty' => 5.0,
                ],
                'name' => 'Idole',
                'description' => 'Long perfume description',
                'short_description' => 'Short perfume description',
                'url_key' => 'idole',
                'images' => [
                    'https://www.jan.com.ua/media/catalog/product/i/d/idole.jpg',
                ],
                'brand' => 'Lubin',
                'product_type' => 'Perfume',
                'magento_type_id' => 'simple',
                'category_ids' => [3],
                'notes' => ['cumin', 'sandal'],
                'related_products' => [[
                    'relation' => 'related',
                    'product_id' => 11,
                    'sku' => 'REL-11',
                ]],
            ],
        ], $payload);
    }
}
