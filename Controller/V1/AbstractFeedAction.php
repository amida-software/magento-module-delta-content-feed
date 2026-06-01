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
use Amida\ProductDeltaFeed\Model\Feed\OpenApiDocumentEncoder;
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
        protected readonly ApiRequestGate $requestGate,
        protected readonly OpenApiDocumentEncoder $openApiDocumentEncoder
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
        return $this->resolveRequestedStoreCode();
    }

    protected function requestedStoreCode(): ?string
    {
        $value = $this->getRequest()->getParam('store', null);
        if ($value === null) {
            return null;
        }
        $storeCode = trim((string)$value);
        return $storeCode !== '' ? $storeCode : null;
    }

    protected function resolveRequestedStoreCode(): ?string
    {
        $storeCode = $this->requestedStoreCode();
        if ($storeCode === null) {
            return null;
        }
        return $this->storeScopeResolver->isAllowedStoreCode($storeCode) ? $storeCode : null;
    }

    protected function invalidRequestedStoreResponse(): ?Raw
    {
        $requested = $this->requestedStoreCode();
        if ($requested !== null && !$this->storeScopeResolver->isAllowedStoreCode($requested)) {
            return $this->invalidResponse(400, 'Invalid store code');
        }
        return null;
    }

    protected function responseFormat(bool $defaultJson = false): string
    {
        $format = strtolower(trim((string)$this->getRequest()->getParam('format', '')));
        if (in_array($format, ['json'], true)) {
            return 'json';
        }
        if (in_array($format, ['protobuf', 'proto', 'pb'], true)) {
            return 'protobuf';
        }
        $accept = strtolower((string)$this->getRequest()->getHeader('Accept'));
        if (str_contains($accept, 'application/x-protobuf') || str_contains($accept, 'application/protobuf')) {
            return 'protobuf';
        }
        if (str_contains($accept, 'application/json')) {
            return 'json';
        }
        return $defaultJson ? 'json' : 'protobuf';
    }

    /** @param array<string, mixed> $payload */
    protected function openApiDocumentResponse(
        array $payload,
        string $entity,
        string $openApiPath,
        string $schemaRef,
        bool $defaultJson = true,
        int $statusCode = 200
    ): Raw {
        $format = $this->responseFormat($defaultJson);
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($statusCode);
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('X-Amida-OpenAPI-Path', $openApiPath, true);
        $result->setHeader('X-Amida-Schema-Ref', $schemaRef, true);
        $result->setHeader('X-Amida-Entity', $entity, true);
        if ($format === 'json') {
            $result->setHeader('Content-Type', 'application/json', true);
            $result->setContents((string)json_encode(
                $payload,
                ($this->parseTruthyRequestFlag('pretty') ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
            return $result;
        }
        $encoded = $this->openApiDocumentEncoder->encode($payload, $entity, $openApiPath, $schemaRef);
        $result->setHeader('Content-Type', 'application/x-protobuf', true);
        $result->setHeader('X-Amida-Protobuf-Message', 'amida.productdelta.v1.OpenApiDocument', true);
        $result->setHeader('X-Amida-Uncompressed-Length', (string)strlen($encoded), true);
        $result->setContents($encoded);
        return $result;
    }

    protected function parseTruthyRequestFlag(string $name, bool $default = false): bool
    {
        $value = $this->getRequest()->getParam($name, $default ? '1' : '0');
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
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

    protected function prettyJsonResponse(array $payload, int $statusCode = 200): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'application/json', true);
        $result->setHttpResponseCode($statusCode);
        $result->setContents(
            (string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        return $result;
    }
}
