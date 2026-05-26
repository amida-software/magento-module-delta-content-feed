<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\State;

use Amida\ProductDeltaFeed\Model\State\JsonCanonicalizer;
use Amida\ProductDeltaFeed\Model\State\StateDiffer;
use PHPUnit\Framework\TestCase;

class StateDifferTest extends TestCase
{
    public function testDetectsChangedContentAttributeCodes(): void
    {
        $differ = new StateDiffer(new JsonCanonicalizer());
        $previous = [
            'attributes' => [
                ['code' => 'name', 'kind' => 'string', 'is_null' => false, 'string_value' => 'Old'],
                ['code' => 'material', 'kind' => 'string', 'is_null' => false, 'string_value' => 'Cotton'],
            ],
        ];
        $current = [
            'attributes' => [
                ['code' => 'name', 'kind' => 'string', 'is_null' => false, 'string_value' => 'New'],
                ['code' => 'material', 'kind' => 'string', 'is_null' => false, 'string_value' => 'Cotton'],
            ],
        ];

        self::assertSame(['name'], $differ->changedFields($previous, $current, 'content'));
    }

    public function testDetectsCategoryChanges(): void
    {
        $differ = new StateDiffer(new JsonCanonicalizer());
        $previous = [
            'category' => [
                'categories' => [
                    ['category_id' => 10, 'position' => 1],
                ],
            ],
        ];
        $current = [
            'category' => [
                'categories' => [
                    ['category_id' => 12, 'position' => 1],
                ],
            ],
        ];

        self::assertSame(['category_ids', 'category_positions'], $differ->changedFields($previous, $current, 'category'));
    }

    public function testDetectsCuratedFieldChanges(): void
    {
        $differ = new StateDiffer(new JsonCanonicalizer());
        $previous = [
            'curated' => [
                'sku' => 'SKU-1',
                'name' => 'Old name',
                'prices' => ['old' => 100.0, 'new' => 90.0],
            ],
        ];
        $current = [
            'curated' => [
                'sku' => 'SKU-1',
                'name' => 'New name',
                'prices' => ['old' => 100.0, 'new' => 80.0],
            ],
        ];

        self::assertSame(['curated.name', 'curated.prices'], $differ->changedFields($previous, $current, 'curated'));
    }
    public function testDetectsOfferFieldChanges(): void
    {
        $differ = new StateDiffer(new JsonCanonicalizer());
        $previous = [
            'offer' => [
                'sku' => 'SKU-1',
                'prices' => ['old' => 100.0, 'current' => 90.0, 'currency' => 'EUR'],
                'availability' => 'in_stock',
                'qty' => 5.0,
            ],
        ];
        $current = [
            'offer' => [
                'sku' => 'SKU-1',
                'prices' => ['old' => 100.0, 'current' => 80.0, 'currency' => 'EUR'],
                'availability' => 'in_stock',
                'qty' => 5.0,
            ],
        ];

        self::assertSame(['offer.prices'], $differ->changedFields($previous, $current, 'offer'));
    }

}
