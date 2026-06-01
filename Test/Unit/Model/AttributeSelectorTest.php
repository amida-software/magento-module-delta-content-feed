<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model;

use Amida\ProductDeltaFeed\Model\AttributeSelector;
use Amida\ProductDeltaFeed\Model\Config;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use PHPUnit\Framework\TestCase;

class AttributeSelectorTest extends TestCase
{
    /** Lightweight attribute stub exposing only the getters AttributeSelector uses. */
    private function attribute(string $code, string $frontendInput, int $isFilterable): object
    {
        return new class ($code, $frontendInput, $isFilterable) {
            public function __construct(
                private readonly string $code,
                private readonly string $frontendInput,
                private readonly int $isFilterable
            ) {
            }

            public function getAttributeCode(): string
            {
                return $this->code;
            }

            public function getFrontendInput(): string
            {
                return $this->frontendInput;
            }

            public function getIsFilterable(): int
            {
                return $this->isFilterable;
            }
        };
    }

    private function selector(Config $config, array $attributes): AttributeSelector
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($attributes));

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new AttributeSelector($config, $factory);
    }

    public function testExplicitIncludeListIsReturnedPlusBaseCodes(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getContentIncludeAttributes')->willReturn(['color', 'volume']);

        $codes = $this->selector($config, [])->getContentAttributeCodes();

        self::assertContains('color', $codes);
        self::assertContains('volume', $codes);
        // Base codes are always present.
        foreach (AttributeSelector::BASE_ATTRIBUTE_CODES as $base) {
            self::assertContains($base, $codes, "missing base code: $base");
        }
        // Sorted and unique.
        $expected = $codes;
        sort($expected);
        self::assertSame($expected, $codes);
        self::assertSame(array_values(array_unique($codes)), $codes);
    }

    public function testEmptyIncludeDefaultsToFilterableAttributesPlusBaseCodes(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getContentIncludeAttributes')->willReturn([]);
        $config->method('getContentExcludeAttributes')->willReturn([]);
        $config->method('getSeoAttributeCodes')->willReturn([]);
        $config->method('getPriceAttributeCodes')->willReturn([]);

        $codes = $this->selector($config, [
            $this->attribute('color', 'select', 1),       // filterable -> kept
            $this->attribute('manufacturer', 'select', 1), // filterable -> kept
            $this->attribute('weight', 'text', 0),         // not filterable -> dropped
            $this->attribute('image', 'media_image', 1),   // media_image -> dropped (but re-added as base)
        ])->getContentAttributeCodes();

        self::assertContains('color', $codes);
        self::assertContains('manufacturer', $codes);
        self::assertNotContains('weight', $codes);
        // Base codes always present (incl. 'image' even though media_image was skipped in selection).
        foreach (AttributeSelector::BASE_ATTRIBUTE_CODES as $base) {
            self::assertContains($base, $codes, "missing base code: $base");
        }
    }
}
