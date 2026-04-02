<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Model\Feed;

use Magento\Framework\Lock\LockManagerInterface;
use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\Feed\ApiRequestGate;
use Amida\ProductDeltaFeed\Model\Feed\RequestDroppedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ApiRequestGateTest extends TestCase
{
    private Config&MockObject $config;
    private LockManagerInterface&MockObject $lockManager;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->lockManager = $this->createMock(LockManagerInterface::class);
    }

    public function testDisabledModeSkipsLocking(): void
    {
        $this->config->method('isApiRequestMonopolyEnabled')->willReturn(false);
        $this->lockManager->expects(self::never())->method('lock');
        $this->lockManager->expects(self::never())->method('unlock');

        $gate = new ApiRequestGate($this->config, $this->lockManager);

        self::assertSame('ok', $gate->execute(static fn (): string => 'ok'));
    }

    public function testEnabledModeLocksAroundCallback(): void
    {
        $this->config->method('isApiRequestMonopolyEnabled')->willReturn(true);
        $this->config->method('getApiRequestTimeoutSeconds')->willReturn(7);
        $this->lockManager->expects(self::once())
            ->method('lock')
            ->with('amida_productdeltafeed_api_request', 7)
            ->willReturn(true);
        $this->lockManager->expects(self::once())
            ->method('unlock')
            ->with('amida_productdeltafeed_api_request');

        $gate = new ApiRequestGate($this->config, $this->lockManager);

        self::assertSame(['done' => true], $gate->execute(static fn (): array => ['done' => true]));
    }

    public function testDropsRequestWhenLockIsNotAcquiredInTime(): void
    {
        $this->config->method('isApiRequestMonopolyEnabled')->willReturn(true);
        $this->config->method('getApiRequestTimeoutSeconds')->willReturn(3);
        $this->lockManager->expects(self::once())
            ->method('lock')
            ->with('amida_productdeltafeed_api_request', 3)
            ->willReturn(false);
        $this->lockManager->expects(self::never())->method('unlock');

        $gate = new ApiRequestGate($this->config, $this->lockManager);

        $this->expectException(RequestDroppedException::class);
        $this->expectExceptionMessage('3 second');
        $gate->execute(static fn (): string => 'never');
    }

    public function testUnlocksWhenCallbackThrows(): void
    {
        $this->config->method('isApiRequestMonopolyEnabled')->willReturn(true);
        $this->config->method('getApiRequestTimeoutSeconds')->willReturn(2);
        $this->lockManager->expects(self::once())
            ->method('lock')
            ->with('amida_productdeltafeed_api_request', 2)
            ->willReturn(true);
        $this->lockManager->expects(self::once())
            ->method('unlock')
            ->with('amida_productdeltafeed_api_request');

        $gate = new ApiRequestGate($this->config, $this->lockManager);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $gate->execute(static function (): void {
            throw new \RuntimeException('boom');
        });
    }
}
