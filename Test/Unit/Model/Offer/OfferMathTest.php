<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\Offer;

use Amida\ProductDeltaFeed\Model\Offer\OfferMath;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class OfferMathTest extends TestCase
{
    public function testActiveSpecialPriceBecomesCurrentPrice(): void
    {
        $math = new OfferMath();
        self::assertSame(
            80.0,
            $math->currentPrice(100.0, 80.0, '2026-05-01 00:00:00', '2026-06-01 00:00:00', new DateTimeImmutable('2026-05-25 12:00:00'))
        );
    }

    public function testFutureSpecialPriceIsIgnored(): void
    {
        $math = new OfferMath();
        self::assertSame(
            100.0,
            $math->currentPrice(100.0, 80.0, '2026-06-01 00:00:00', null, new DateTimeImmutable('2026-05-25 12:00:00'))
        );
    }

    public function testReservationDeltaAndBackordersAffectSalability(): void
    {
        $math = new OfferMath();
        self::assertSame(5.0, $math->salableQty(7.0, -2.0));
        self::assertTrue($math->isSqlSalable(0.0, true, true, true, 1));
        self::assertSame('preorder', $math->availabilityCode(true, false, 1));
    }
}
