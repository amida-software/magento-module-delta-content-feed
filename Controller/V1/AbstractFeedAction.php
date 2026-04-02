<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Controller\V1;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\Feed\ApiRequestGate;
use Amida\ProductDeltaFeed\Model\Feed\RequestDroppedException;
use Amida\ProductDeltaFeed\Model\Feed\ZstdCompressor;
use Amida\ProductDeltaFeed\Model\StoreScopeResolver;

abstract class AbstractFeedAction extends Action
{
    public function __construct(
        Context $context,
        protected readonly RawFactory $rawFactory,
        protected readonly JsonFactory $jsonFactory,
        protected readonly Config $config,
        protected readonly StoreScopeResolver $storeScopeResolver,
        protected readonly ZstdCompressor $compressor,
        protected readonly ApiRequestGate $requestGate
    ) {
        parent::__construct($context);
    }

    protected function validateKey(string $key): bool
    {
        if (!$this->config->isEnabled() || !$this->config->isRouteEnabled()) {
            return false;
        }

        $configured = $this->config->getPublicKey();
        if ($configured === '' || $key === '') {
            return false;
        }

        return hash_equals($configured, $key);
    }

    protected function resolveStoreCode(): ?string
    {
        $storeCode = trim((string)$this->getRequest()->getParam('store', $this->storeScopeResolver->getDefaultStoreCode()));
        return $this->storeScopeResolver->isAllowedStoreCode($storeCode) ? $storeCode : null;
    }

    protected function invalidResponse(int $statusCode, string $message): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($statusCode);
        $result->setContents($message);
        return $result;
    }

    protected function guardedRawResponse(callable $callback): Raw
    {
        try {
            /** @var array<string, mixed> $payload */
            $payload = $this->requestGate->execute($callback);
            return $this->rawResponse($payload);
        } catch (RequestDroppedException $exception) {
            $result = $this->invalidResponse(429, $exception->getMessage());
            $result->setHeader('Retry-After', (string)$this->requestGate->getTimeoutSeconds(), true);
            return $result;
        }
    }

    protected function rawResponse(array $payload): Raw
    {
        $result = $this->rawFactory->create();
        foreach ((array)($payload['headers'] ?? []) as $name => $value) {
            $result->setHeader((string)$name, (string)$value, true);
        }
        $result->setHttpResponseCode((int)($payload['status'] ?? 200));
        $result->setContents((string)$payload['body']);
        return $result;
    }

    protected function jsonResponse(array $payload, int $statusCode = 200): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($statusCode);
        return $result->setData($payload);
    }
}
