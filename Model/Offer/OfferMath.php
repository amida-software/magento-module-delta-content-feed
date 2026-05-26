<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Offer;

use DateTimeImmutable;

final class OfferMath
{
    public function currentPrice(?float $regularPrice, ?float $specialPrice, ?string $specialFrom, ?string $specialTo, ?DateTimeImmutable $now = null): ?float
    {
        if ($regularPrice === null && $specialPrice === null) {
            return null;
        }

        if ($specialPrice === null) {
            return $regularPrice;
        }

        $now ??= new DateTimeImmutable('now');
        if (!$this->isDateWindowActive($specialFrom, $specialTo, $now)) {
            return $regularPrice ?? $specialPrice;
        }

        if ($regularPrice === null) {
            return $specialPrice;
        }

        return min($regularPrice, $specialPrice);
    }

    public function salableQty(float $sourceQty, float $reservationQty): float
    {
        return $sourceQty + $reservationQty;
    }

    public function isSqlSalable(float $qty, bool $hasSellableSource, bool $legacyInStock, bool $manageStock, int $backorders, float $minQty = 0.0): bool
    {
        if (!$manageStock) {
            return $legacyInStock || $hasSellableSource;
        }

        if ($backorders > 0 && ($legacyInStock || $hasSellableSource)) {
            return true;
        }

        return ($legacyInStock || $hasSellableSource) && $qty > $minQty;
    }

    public function availabilityCode(bool $isSalable, bool $isInStock, int $backorders = 0): string
    {
        if ($isSalable) {
            return $backorders > 0 && !$isInStock ? 'preorder' : 'in_stock';
        }

        return 'out_of_stock';
    }

    private function isDateWindowActive(?string $from, ?string $to, DateTimeImmutable $now): bool
    {
        $nowTs = $now->getTimestamp();
        $from = trim((string)$from);
        $to = trim((string)$to);

        if ($from !== '') {
            try {
                if ((new DateTimeImmutable($from))->getTimestamp() > $nowTs) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        if ($to !== '') {
            try {
                if ((new DateTimeImmutable($to))->getTimestamp() < $nowTs) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }
}
