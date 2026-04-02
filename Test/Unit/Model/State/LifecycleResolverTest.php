<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\State;

use Amida\ProductDeltaFeed\Model\State\LifecycleResolver;
use PHPUnit\Framework\TestCase;

class LifecycleResolverTest extends TestCase
{
    public function testDisableTransition(): void
    {
        $resolver = new LifecycleResolver();
        self::assertSame(LifecycleResolver::ACTION_DISABLE, $resolver->resolve(true, true, false));
    }

    public function testSuppressedDisabledTransition(): void
    {
        $resolver = new LifecycleResolver();
        self::assertSame(LifecycleResolver::ACTION_SUPPRESSED_DISABLED, $resolver->resolve(true, false, false));
    }

    public function testReenableGivesFullReplay(): void
    {
        $resolver = new LifecycleResolver();
        self::assertSame(LifecycleResolver::ACTION_FULL, $resolver->resolve(true, false, true));
    }
}
