<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\Feed;

use Amida\ProductDeltaFeed\Model\Feed\CuratedParentInheritance;
use PHPUnit\Framework\TestCase;

class CuratedParentInheritanceTest extends TestCase
{
    private function service(): CuratedParentInheritance
    {
        return new CuratedParentInheritance();
    }

    public function testInheritFillsOnlyEmptyInheritableFields(): void
    {
        $child = [
            'sku' => 'CHILD-1',
            'name' => 'Child name',
            'prices' => ['old' => null, 'new' => 10.0],
            'availability' => ['is_available' => true],
            'magento_type_id' => 'simple',
            'description' => '',                 // empty -> inherit
            'short_description' => 'own short',  // present -> keep
            'url_key' => null,                   // empty -> inherit
            'url' => null,                       // empty -> inherit
            'images' => [],                      // empty -> inherit
            'category_ids' => [5],               // present -> keep
            'brand' => null,                     // empty -> inherit
            'notes' => [],                       // empty -> inherit
            'related_products' => [],            // empty -> inherit
            'product_type' => '',                // empty -> inherit
        ];
        $parent = [
            'sku' => 'PARENT-1',
            'name' => 'Parent name',
            'prices' => ['old' => 1.0, 'new' => 1.0],
            'availability' => ['is_available' => false],
            'magento_type_id' => 'configurable',
            'description' => 'Parent description',
            'short_description' => 'Parent short',
            'url_key' => 'parent-url',
            'url' => 'https://shop/parent-url.html',
            'images' => ['http://example.com/img.jpg'],
            'category_ids' => [1, 2],
            'brand' => 'ParentBrand',
            'notes' => ['note1'],
            'related_products' => [['sku' => 'R1']],
            'product_type' => 'Parfum',
        ];

        $result = $this->service()->inherit($child, $parent);

        // inherited (child fields were empty)
        self::assertSame('Parent description', $result['description']);
        self::assertSame('parent-url', $result['url_key']);
        self::assertSame('https://shop/parent-url.html', $result['url']);
        self::assertSame(['http://example.com/img.jpg'], $result['images']);
        self::assertSame('ParentBrand', $result['brand']);
        self::assertSame(['note1'], $result['notes']);
        self::assertSame([['sku' => 'R1']], $result['related_products']);
        self::assertSame('Parfum', $result['product_type']);

        // kept (child had its own value)
        self::assertSame('own short', $result['short_description']);
        self::assertSame([5], $result['category_ids']);

        // never inherited (identity / commercial fields and product type)
        self::assertSame('CHILD-1', $result['sku']);
        self::assertSame('Child name', $result['name']);
        self::assertSame(['old' => null, 'new' => 10.0], $result['prices']);
        self::assertSame(['is_available' => true], $result['availability']);
        self::assertSame('simple', $result['magento_type_id']);
    }

    public function testInheritDoesNotOverrideWhenParentValueAlsoEmpty(): void
    {
        $result = $this->service()->inherit(
            ['description' => '', 'images' => []],
            ['description' => '', 'images' => []]
        );

        self::assertSame('', $result['description']);
        self::assertSame([], $result['images']);
    }
}
