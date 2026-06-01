<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Controller\V1;

use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\Feed\ApiRequestGate;
use Amida\ProductDeltaFeed\Model\Feed\CategorySnapshotService;
use Amida\ProductDeltaFeed\Model\Feed\SnapshotService;
use Amida\ProductDeltaFeed\Model\Feed\ZstdCompressor;
use Amida\ProductDeltaFeed\Model\Feed\OpenApiDocumentEncoder;
use Amida\ProductDeltaFeed\Model\Store\AttributeDictionaryService;
use Amida\ProductDeltaFeed\Model\StoreScopeResolver;

class Snapshot extends AbstractFeedAction
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
        private readonly SnapshotService $snapshotService,
        private readonly CategorySnapshotService $categorySnapshotService,
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

        $stream = (string)$this->getRequest()->getParam('stream', Config::STREAM_CONTENT);
        if (!$this->config->isStreamEnabled($stream)) {
            return $this->invalidResponse(404, 'Unknown or disabled stream');
        }

        $invalidStore = $this->invalidRequestedStoreResponse();
        if ($invalidStore !== null) {
            return $invalidStore;
        }
        $storeCode = $this->resolveRequestedStoreCode();

        if ($stream === Config::STREAM_ATTRIBUTES) {
            return $this->openApiDocumentResponse($this->attributeDictionaryService->build($storeCode ?? $this->storeScopeResolver->getDefaultStoreCode(), $this->parseCodes(), $this->parseLoadOptions(), $this->parseSchemaVersion(), $this->parseAll()), 'attributes', '/amidafeed/v1/snapshot/key/{key}/stream/attributes', $this->parseSchemaVersion() === 1 ? '#/components/schemas/AttributesDictionaryV1' : '#/components/schemas/AttributesDictionaryV2', true);
        }

        $afterStateId = max(0, (int)$this->getRequest()->getParam('after_state_id', 0));
        $filters = $this->buildQueryFilters();

        if (empty($filters['_format_json']) && $this->compressor->isEnabled() && !$this->compressor->isAvailable()) {
            return $this->invalidResponse(503, 'zstd compression is enabled but ext-zstd is not installed');
        }

        if ($stream === Config::STREAM_CATEGORIES) {
            return $this->guardedRawResponse(
                fn (): array => $this->categorySnapshotService->build($storeCode, $afterStateId, $filters)
            );
        }

        return $this->guardedRawResponse(
            fn (): array => $this->snapshotService->build($stream, $storeCode, $afterStateId, $filters)
        );
    }

    /** @return array<string, mixed> */
    private function buildQueryFilters(): array
    {
        $body = $this->readJsonBody();
        return [
            'skus' => $this->parseStringList('skus', $body) ?: $this->parseStringList('sku', $body),
            'category_ids' => array_map('intval', $this->parseStringList('category_ids', $body) ?: $this->parseStringList('category_id', $body)),
            'include_offer' => (bool)(int)($body['include_offer'] ?? $this->getRequest()->getParam('include_offer', 0)),
            'offer_parts' => $this->parseOfferParts($body),
            'changes_highwater_event_id' => max(0, (int)($body['changes_highwater_event_id'] ?? $body['snapshot_highwater_event_id'] ?? $this->getRequest()->getParam('changes_highwater_event_id', $this->getRequest()->getParam('snapshot_highwater_event_id', 0)))),
            '_format_json' => $this->parseFormatJson($body),
        ];
    }

    /** @return string[] */
    private function parseStringList(string $name, array $body = []): array
    {
        $fromBody = array_key_exists($name, $body);
        $value = $fromBody ? $body[$name] : $this->getRequest()->getParam($name, '');
        $parts = is_array($value) ? $value : explode(',', (string)$value);
        $parts = array_values(array_filter(
            array_unique(array_map(static fn (mixed $item): string => trim((string)$item), $parts)),
            static fn (string $item): bool => $item !== ''
        ));
        return array_slice($parts, 0, $fromBody ? $this->config->getSkuFilterPostLimit() : $this->config->getSkuFilterGetLimit());
    }

    /** @return string[] */
    private function parseCodes(): array
    {
        $body = $this->readJsonBody();
        $value = $body['codes'] ?? $this->getRequest()->getParam('codes', '');
        $parts = is_array($value) ? $value : explode(',', (string)$value);
        $parts = array_values(array_filter(
            array_unique(array_map(static fn (mixed $item): string => trim((string)$item), $parts)),
            static fn (string $item): bool => $item !== ''
        ));
        return array_slice($parts, 0, $this->getRequest()->isPost() ? $this->config->getSkuFilterPostLimit() : $this->config->getSkuFilterGetLimit());
    }

    private function parseLoadOptions(): bool
    {
        // Default false: options are only loaded when explicitly requested (load_options=1).
        return $this->parseBoolFlag('load_options');
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

    private function parseSchemaVersion(): int
    {
        $body = $this->readJsonBody();
        $value = array_key_exists('schema', $body) ? $body['schema'] : $this->getRequest()->getParam('schema', '');
        return strtolower(trim((string)$value)) === 'v1' || (string)$value === '1' ? 1 : 2;
    }

    /** @param array<string, mixed> $body */
    private function parseOfferParts(array $body): array
    {
        $value = $body['offer_parts'] ?? $body['offer_part'] ?? $this->getRequest()->getParam('offer_parts', $this->getRequest()->getParam('offer_part', ''));
        $parts = is_array($value) ? $value : explode(',', (string)$value);
        $allowed = ['price', 'availability'];
        $parts = array_values(array_intersect($allowed, array_values(array_unique(array_filter(array_map(static fn (mixed $p): string => strtolower(trim((string)$p)), $parts))))));
        return $parts;
    }

    /** @param array<string, mixed> $body */
    private function parseFormatJson(array $body): bool
    {
        $value = array_key_exists('format', $body) ? $body['format'] : $this->getRequest()->getParam('format', '');
        if (strtolower(trim((string)$value)) === 'json') {
            return true;
        }
        return $this->responseFormat(false) === 'json';
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
