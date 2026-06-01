<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Controller\V1;

use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\Feed\ApiRequestGate;
use Amida\ProductDeltaFeed\Model\Feed\ZstdCompressor;
use Amida\ProductDeltaFeed\Model\Feed\OpenApiDocumentEncoder;
use Amida\ProductDeltaFeed\Model\Store\AttributeDictionaryService;
use Amida\ProductDeltaFeed\Model\StoreScopeResolver;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;

class Attributes extends AbstractFeedAction
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
        private readonly AttributeDictionaryService $attributeDictionaryService
    ) {
        parent::__construct($context, $rawFactory, $jsonFactory, $config, $storeScopeResolver, $compressor, $requestGate, $openApiDocumentEncoder);
    }

    public function execute(): ResultInterface
    {
        $key = (string)$this->getRequest()->getParam('key');
        if (!$this->validateKey($key)) {
            return $this->invalidResponse(404, 'Not found');
        }
        if (!$this->config->isStreamEnabled(Config::STREAM_ATTRIBUTES)) {
            return $this->invalidResponse(404, 'Attributes stream is disabled');
        }

        $invalidStore = $this->invalidRequestedStoreResponse();
        if ($invalidStore !== null) {
            return $invalidStore;
        }

        $storeCode = $this->resolveRequestedStoreCode();
        $effectiveStoreCode = $storeCode ?? $this->storeScopeResolver->getDefaultStoreCode();
        $payload = $this->attributeDictionaryService->build($effectiveStoreCode, $this->parseCodes(), $this->parseLoadOptions(), $this->parseSchemaVersion(), $this->parseAll());
        if ($storeCode === null) {
            $payload['store_scope'] = 'all';
            $payload['requested_store_code'] = null;
        } else {
            $payload['store_scope'] = 'single';
            $payload['requested_store_code'] = $storeCode;
        }

        return $this->openApiDocumentResponse(
            $payload,
            'attributes',
            '/amidafeed/v1/attributes/key/{key}',
            $this->parseSchemaVersion() === 1 ? '#/components/schemas/AttributesDictionaryV1' : '#/components/schemas/AttributesDictionaryV2',
            true
        );
    }

    private function parsePretty(): bool
    {
        return $this->parseBoolFlag('pretty');
    }

    private function parseAll(): bool
    {
        return $this->parseBoolFlag('all');
    }

    /** Truthy query/body flag, default false. */
    private function parseBoolFlag(string $name): bool
    {
        $body = $this->readJsonBody();
        $value = array_key_exists($name, $body) ? $body[$name] : $this->getRequest()->getParam($name, false);
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /** @return string[] */
    private function parseCodes(): array
    {
        $body = $this->readJsonBody();
        $value = $body['codes'] ?? $this->getRequest()->getParam('codes', '');
        $parts = is_array($value) ? $value : explode(',', (string)$value);
        $parts = array_values(array_filter(array_unique(array_map(static fn (mixed $item): string => trim((string)$item), $parts)), static fn (string $item): bool => $item !== ''));
        return array_slice($parts, 0, $this->getRequest()->isPost() ? $this->config->getSkuFilterPostLimit() : $this->config->getSkuFilterGetLimit());
    }

    private function parseLoadOptions(): bool
    {
        // Default false: options are only loaded when explicitly requested (load_options=1).
        return $this->parseBoolFlag('load_options');
    }

    private function parseSchemaVersion(): int
    {
        $body = $this->readJsonBody();
        $value = array_key_exists('schema', $body) ? $body['schema'] : $this->getRequest()->getParam('schema', '');
        return strtolower(trim((string)$value)) === 'v1' || (string)$value === '1' ? 1 : 2;
    }

    /** @return array<string, mixed> */
    private function readJsonBody(): array
    {
        if (!$this->getRequest()->isPost()) {
            return [];
        }
        $raw = trim((string)$this->getRequest()->getContent());
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
