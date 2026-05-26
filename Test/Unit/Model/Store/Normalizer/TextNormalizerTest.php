<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\Store\Normalizer;

use Amida\ProductDeltaFeed\Model\Store\Normalizer\TextNormalizer;
use PHPUnit\Framework\TestCase;

class TextNormalizerTest extends TestCase
{
    public function testHtmlIsStrippedAndWhitespaceCollapsed(): void
    {
        $normalizer = new TextNormalizer();
        self::assertSame('Hello world', $normalizer->normalize('<p>Hello <b>world</b></p>'));
    }

    public function testScriptIsRemoved(): void
    {
        $normalizer = new TextNormalizer();
        self::assertSame('Safe', $normalizer->normalize('<script>alert(1)</script><p>Safe</p>'));
    }

    public function testEmptyBecomesNull(): void
    {
        $normalizer = new TextNormalizer();
        self::assertNull($normalizer->normalize(' <br> '));
    }
}
