<?php
declare(strict_types=1);

require __DIR__ . '/../Model/Offer/OfferMath.php';

use Amida\ProductDeltaFeed\Model\Offer\OfferMath;

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$math = new OfferMath();
$now = new DateTimeImmutable('2026-05-25 12:00:00');

assert_true($math->currentPrice(100.0, 80.0, '2026-05-01 00:00:00', '2026-06-01 00:00:00', $now) === 80.0, 'active special price wins');
assert_true($math->currentPrice(100.0, 80.0, '2026-06-01 00:00:00', '2026-07-01 00:00:00', $now) === 100.0, 'future special is ignored');
assert_true($math->currentPrice(100.0, 120.0, null, null, $now) === 100.0, 'special cannot increase current price');
assert_true($math->salableQty(7.0, -2.0) === 5.0, 'reservation delta is applied');
assert_true($math->isSqlSalable(5.0, true, true, true, 0, 0.0) === true, 'positive stock is salable');
assert_true($math->isSqlSalable(0.0, true, true, true, 1, 0.0) === true, 'backorder stock is salable');
assert_true($math->availabilityCode(true, false, 1) === 'preorder', 'backorder without stock maps to preorder');
assert_true($math->availabilityCode(false, false, 0) === 'out_of_stock', 'not salable maps to out_of_stock');

echo "OfferMath mock OK\n";
