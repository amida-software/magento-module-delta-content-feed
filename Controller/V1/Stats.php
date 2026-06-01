<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Controller\V1;

use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\Feed\ApiRequestGate;
use Amida\ProductDeltaFeed\Model\Feed\HealthService;
use Amida\ProductDeltaFeed\Model\Feed\ZstdCompressor;
use Amida\ProductDeltaFeed\Model\Feed\OpenApiDocumentEncoder;
use Amida\ProductDeltaFeed\Model\StoreScopeResolver;

class Stats extends AbstractFeedAction
{
    public function __construct(
        Context $context,
        RawFactory $rawFactory,
        JsonFactory $jsonFactory,
        Config $config,
        StoreScopeResolver $storeScopeResolver,
        ZstdCompressor $compressor,
        ApiRequestGate $requestGate,
        OpenApiDocumentEncoder $openApiDocumentEncoder,
        private readonly HealthService $healthService
    ) {
        parent::__construct($context, $rawFactory, $jsonFactory, $config, $storeScopeResolver, $compressor, $requestGate, $openApiDocumentEncoder);
    }

    public function execute(): ResultInterface
    {
        $key = (string)$this->getRequest()->getParam('key');
        if (!$this->validateKey($key)) {
            return $this->invalidResponse(404, 'Not found');
        }

        return $this->openApiDocumentResponse(
            $this->healthService->getStats(),
            'stats',
            '/amidafeed/v1/stats/key/{key}',
            '#/components/schemas/Stats',
            true
        );
    }
}
