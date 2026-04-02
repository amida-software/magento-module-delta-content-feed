<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Controller\V1;

use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\Feed\ApiRequestGate;
use Amida\ProductDeltaFeed\Model\Feed\ChangesService;
use Amida\ProductDeltaFeed\Model\Feed\ZstdCompressor;
use Amida\ProductDeltaFeed\Model\StoreScopeResolver;

class Changes extends AbstractFeedAction
{
    public function __construct(
        Context $context,
        RawFactory $rawFactory,
        JsonFactory $jsonFactory,
        Config $config,
        StoreScopeResolver $storeScopeResolver,
        ZstdCompressor $compressor,
        ApiRequestGate $requestGate,
        private readonly ChangesService $changesService
    ) {
        parent::__construct($context, $rawFactory, $jsonFactory, $config, $storeScopeResolver, $compressor, $requestGate);
    }

    public function execute(): ResultInterface
    {
        $key = (string)$this->getRequest()->getParam('key');
        if (!$this->validateKey($key)) {
            return $this->invalidResponse(404, 'Not found');
        }

        if ($this->compressor->isEnabled() && !$this->compressor->isAvailable()) {
            return $this->invalidResponse(503, 'zstd compression is enabled but ext-zstd is not installed');
        }

        $stream = (string)$this->getRequest()->getParam('stream', Config::STREAM_ALL);
        if (!$this->config->isStreamEnabled($stream)) {
            return $this->invalidResponse(404, 'Unknown or disabled stream');
        }

        $storeCode = $this->resolveStoreCode();
        if ($storeCode === null) {
            return $this->invalidResponse(400, 'Invalid store code');
        }

        $afterEventId = max(0, (int)$this->getRequest()->getParam('after_event_id', 0));

        return $this->guardedRawResponse(
            fn (): array => $this->changesService->build($stream, $storeCode, $afterEventId)
        );
    }
}
