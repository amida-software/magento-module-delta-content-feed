<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\State\Curated;

use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\State\Curated\ImageUrlBuilder;
use Magento\Catalog\Model\Product;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ImageUrlBuilderTest extends TestCase
{
    public function testBuildsUniqueFullMediaUrlsFromGalleryAndFallbackImages(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->willReturnCallback(static function (string $code): mixed {
            return [
                'media_gallery' => [
                    'images' => [
                        ['file' => '/a/b/first.jpg', 'disabled' => '0'],
                        ['file' => '/a/b/disabled.jpg', 'disabled' => '1'],
                        ['file' => '/a/b/first.jpg', 'disabled' => '0'],
                    ],
                ],
                'image' => '/a/b/first.jpg',
                'small_image' => '/a/b/second.jpg',
                'thumbnail' => 'no_selection',
            ][$code] ?? null;
        });

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->with('media')->willReturn('https://www.jan.com.ua/media/');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->with('ua')->willReturn($store);

        $config = $this->createMock(Config::class);
        $config->method('getFeedDomain')->willReturn('');

        $builder = new ImageUrlBuilder($storeManager, $config);

        self::assertSame([
            'https://www.jan.com.ua/media/catalog/product/a/b/first.jpg',
            'https://www.jan.com.ua/media/catalog/product/a/b/second.jpg',
        ], $builder->getImageUrls($product, 'ua'));
    }

    public function testFeedDomainOverrideReplacesTheStoreHost(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->willReturnCallback(
            static fn (string $code): mixed => ['image' => '/a/b/first.jpg'][$code] ?? null
        );

        // When a feed domain is configured the store base URL is not consulted.
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects(self::never())->method('getStore');

        $config = $this->createMock(Config::class);
        $config->method('getFeedDomain')->willReturn('jan2-production.up.railway.app');

        $builder = new ImageUrlBuilder($storeManager, $config);

        self::assertSame(
            ['https://jan2-production.up.railway.app/media/catalog/product/a/b/first.jpg'],
            $builder->getImageUrls($product, 'ua')
        );
    }
}
