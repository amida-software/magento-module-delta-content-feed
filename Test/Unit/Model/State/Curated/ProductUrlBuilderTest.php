<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\State\Curated;

use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\State\Curated\ProductUrlBuilder;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ProductUrlBuilderTest extends TestCase
{
    public function testBuildsAbsoluteUrlFromFeedDomainAndSuffix(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->willReturnCallback(
            static fn (string $code): mixed => ['url_key' => 'idole'][$code] ?? null
        );

        // Feed domain is configured -> store base URL must not be consulted.
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects(self::never())->method('getStore');

        $config = $this->createMock(Config::class);
        $config->method('getFeedDomain')->willReturn('jan2-production.up.railway.app');

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('.html');

        $builder = new ProductUrlBuilder($storeManager, $config, $scopeConfig);

        self::assertSame(
            'https://jan2-production.up.railway.app/idole.html',
            $builder->getProductUrl($product, 'ua')
        );
    }

    public function testFallsBackToStoreBaseUrlWhenNoFeedDomain(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->willReturnCallback(
            static fn (string $code): mixed => ['url_key' => 'idole'][$code] ?? null
        );

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://www.jan.com.ua/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->with('ua')->willReturn($store);

        $config = $this->createMock(Config::class);
        $config->method('getFeedDomain')->willReturn('');

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('.html');

        $builder = new ProductUrlBuilder($storeManager, $config, $scopeConfig);

        self::assertSame('https://www.jan.com.ua/idole.html', $builder->getProductUrl($product, 'ua'));
    }

    public function testReturnsNullWhenUrlKeyEmpty(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->willReturn(null);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects(self::never())->method('getStore');
        $config = $this->createMock(Config::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);

        $builder = new ProductUrlBuilder($storeManager, $config, $scopeConfig);

        self::assertNull($builder->getProductUrl($product, 'ua'));
    }
}
