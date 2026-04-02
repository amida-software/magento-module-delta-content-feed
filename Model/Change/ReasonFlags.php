<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Change;

final class ReasonFlags
{
    public const CONTENT = 1;
    public const SEO = 2;
    public const PRICE = 4;
    public const AVAILABILITY = 8;
    public const CATEGORY = 16;
    public const STATUS = 32;
    public const DELETE = 64;
    public const FORCE_FULL = 128;
    public const FORCE_COMPARE = 256;

    public const ORDINARY_PRODUCT_SAVE = self::CONTENT | self::SEO | self::PRICE | self::STATUS | self::FORCE_COMPARE;
}
