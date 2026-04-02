<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Policy;

class EventDecision
{
    public const OP_UPSERT = 'upsert';
    public const OP_ENABLE_FULL = 'enable_full';
    public const OP_DISABLE_STATUS_ONLY = 'disable_status_only';
    public const OP_DELETE = 'delete';

    public function decide(bool $snapshotExists, ?bool $oldEnabled, bool $newEnabled, bool $changed): ?string
    {
        if (!$snapshotExists) {
            return $newEnabled ? self::OP_UPSERT : null;
        }

        if ($oldEnabled === true && $newEnabled === false) {
            return self::OP_DISABLE_STATUS_ONLY;
        }

        if ($oldEnabled === false && $newEnabled === true) {
            return self::OP_ENABLE_FULL;
        }

        if ($oldEnabled === false && $newEnabled === false) {
            return null;
        }

        return $changed ? self::OP_UPSERT : null;
    }
}
