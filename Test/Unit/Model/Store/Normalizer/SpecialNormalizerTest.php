<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\Store\Normalizer;

use Amida\ProductDeltaFeed\Model\Store\Normalizer\SpecialNormalizer;
use PHPUnit\Framework\TestCase;

class SpecialNormalizerTest extends TestCase
{
    public function testAliasNormalization(): void
    {
        $diagnostics = [];
        $normalizer = new SpecialNormalizer();

        self::assertSame(['delivery', 'loyalty', 'privacy_policy'], $normalizer->normalize(['devilery', 'loyality', 'privacy'], $diagnostics));
        self::assertSame([], $diagnostics);
    }

    public function testUnknownSpecialBecomesCustomWithDiagnostic(): void
    {
        $diagnostics = [];
        $normalizer = new SpecialNormalizer();

        self::assertSame(['custom'], $normalizer->normalize(['strange-page'], $diagnostics));
        self::assertSame('page_special_unknown', $diagnostics[0]['code']);
    }

    public function testInferFromText(): void
    {
        $normalizer = new SpecialNormalizer();
        self::assertContains('delivery', $normalizer->inferFromText('delivery', 'Delivery', 'https://example.com/delivery'));
        self::assertContains('contacts', $normalizer->inferFromText('contacts', 'Contacts', 'https://example.com/contacts'));
    }
}
