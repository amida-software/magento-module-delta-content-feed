<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\Store\Normalizer;

use Amida\ProductDeltaFeed\Model\Store\Normalizer\UrlNormalizer;
use PHPUnit\Framework\TestCase;

class UrlNormalizerTest extends TestCase
{
    public function testRelativeUrlBecomesAbsolute(): void
    {
        $diagnostics = [];
        $normalizer = new UrlNormalizer();
        self::assertSame('https://example.com/delivery', $normalizer->normalize('delivery', 'https://example.com/', $diagnostics));
    }

    public function testUnsupportedSchemeRejected(): void
    {
        $diagnostics = [];
        $normalizer = new UrlNormalizer();
        self::assertNull($normalizer->normalize('javascript:alert(1)', 'https://example.com/', $diagnostics));
        self::assertSame('unsupported_url_scheme', $diagnostics[0]['code']);
    }
}
