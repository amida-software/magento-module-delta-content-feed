<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\State;

class LifecycleResolver
{
    public const ACTION_DISABLE = 'disable';
    public const ACTION_SUPPRESSED_DISABLED = 'suppressed_disabled';
    public const ACTION_FULL = 'full';
    public const ACTION_NORMAL = 'normal';

    public function resolve(bool $hasPrevious, bool $previousEnabled, bool $currentEnabled): string
    {
        if ((!$hasPrevious && !$currentEnabled) || ($hasPrevious && $previousEnabled && !$currentEnabled)) {
            return self::ACTION_DISABLE;
        }

        if ($hasPrevious && !$previousEnabled && !$currentEnabled) {
            return self::ACTION_SUPPRESSED_DISABLED;
        }

        if ((!$hasPrevious && $currentEnabled) || ($hasPrevious && !$previousEnabled && $currentEnabled)) {
            return self::ACTION_FULL;
        }

        return self::ACTION_NORMAL;
    }
}
