<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Test\Unit\Controller\Feed;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Amida\ProductDeltaFeed\Controller\Feed\Changes;
use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\Feed\ApiRequestGate;
use Amida\ProductDeltaFeed\Model\FeedExporter;
use PHPUnit\Framework\TestCase;

class ChangesTest extends TestCase
{
    public function testControllerReturnsNotFoundForBadToken(): void
    {
        $request = $this->createMock(Http::class);
        $request->method('getParam')->willReturnMap([
            ['token', '', 'bad'],
            ['stream', 'content', 'content'],
            ['cursor', 0, 0],
        ]);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $raw = $this->createMock(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnSelf();
        $raw->method('setContents')->willReturnSelf();

        $rawFactory = $this->createConfiguredMock(RawFactory::class, ['create' => $raw]);
        $config = $this->createConfiguredMock(Config::class, ['isEnabled' => true, 'getPublicToken' => 'good']);
        $exporter = $this->createMock(FeedExporter::class);

        $requestGate = $this->createMock(ApiRequestGate::class);

        $controller = new Changes($context, $rawFactory, $config, $exporter, $requestGate);
        $result = $controller->execute();

        self::assertSame($raw, $result);
    }
}
