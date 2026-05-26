<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Controller\V1;

use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\Feed\ApiRequestGate;
use Amida\ProductDeltaFeed\Model\Feed\ZstdCompressor;
use Amida\ProductDeltaFeed\Model\Store\StoreMetadataService;
use Amida\ProductDeltaFeed\Model\StoreScopeResolver;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;

class Store extends AbstractFeedAction
{
    public function __construct(
        Context $context,
        RawFactory $rawFactory,
        JsonFactory $jsonFactory,
        Config $config,
        StoreScopeResolver $storeScopeResolver,
        ZstdCompressor $compressor,
        ApiRequestGate $requestGate,
        private readonly StoreMetadataService $storeMetadataService
    ) {
        parent::__construct($context, $rawFactory, $jsonFactory, $config, $storeScopeResolver, $compressor, $requestGate);
    }

    public function execute(): ResultInterface
    {
        $key = (string)$this->getRequest()->getParam('key');
        if (!$this->validateKey($key)) {
            return $this->invalidResponse(404, 'Not found');
        }

        $storeCode = $this->resolveStoreCode();
        if ($storeCode === null) {
            return $this->invalidResponse(400, 'Invalid store code');
        }
        if (!$this->config->isStoreEndpointEnabled($storeCode)) {
            return $this->invalidResponse(404, 'Store endpoint is disabled');
        }

        $options = [
            'scope' => (string)$this->getRequest()->getParam('scope', 'group'),
            'include_pages' => $this->boolParam('include_pages', true),
            'include_counts' => $this->boolParam('include_counts', true),
            'include_sitemap' => $this->boolParam('include_sitemap', true),
            'sitemap_mode' => (string)$this->getRequest()->getParam('sitemap_mode', $this->config->getStoreSitemapMode($storeCode)),
            'sitemap_limit' => max(1, min(10000, (int)$this->getRequest()->getParam('sitemap_limit', $this->config->getStoreSitemapLimit($storeCode)))),
            'include_sources' => $this->boolParam('include_sources', false),
        ];

        return $this->jsonResponse($this->storeMetadataService->build($storeCode, $options));
    }

    private function boolParam(string $name, bool $default): bool
    {
        $value = $this->getRequest()->getParam($name, $default ? '1' : '0');
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }
}
