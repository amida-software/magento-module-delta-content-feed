<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\Policy;

use Amida\ProductDeltaFeed\Model\Policy\EventDecision;
use PHPUnit\Framework\TestCase;

class EventDecisionTest extends TestCase
{
    public function testDisableTransitionProducesStatusOnly(): void
    {
        $policy = new EventDecision();
        self::assertSame(EventDecision::OP_DISABLE_STATUS_ONLY, $policy->decide(true, true, false, true));
    }

    public function testEnableTransitionProducesFullEvent(): void
    {
        $policy = new EventDecision();
        self::assertSame(EventDecision::OP_ENABLE_FULL, $policy->decide(true, false, true, true));
    }

    public function testDisabledToDisabledProducesNoEvent(): void
    {
        $policy = new EventDecision();
        self::assertNull($policy->decide(true, false, false, true));
    }
}
