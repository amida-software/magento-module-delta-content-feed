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
        private readonly SnapshotService $snapshotService,
        private readonly CategorySnapshotService $categorySnapshotService,
        private readonly AttributeDictionaryService $attributeDictionaryService
    ) {
        parent::__construct($context, $rawFactory, $jsonFactory, $config, $storeScopeResolver, $compressor, $requestGate);
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

        $storeCode = $this->resolveStoreCode();
        if ($storeCode === null) {
            return $this->invalidResponse(400, 'Invalid store code');
        }

        if ($stream === Config::STREAM_ATTRIBUTES) {
            return $this->jsonResponse($this->attributeDictionaryService->build($storeCode, $this->parseCodes()));
        }

        if ($this->compressor->isEnabled() && !$this->compressor->isAvailable()) {
            return $this->invalidResponse(503, 'zstd compression is enabled but ext-zstd is not installed');
        }

        $afterStateId = max(0, (int)$this->getRequest()->getParam('after_state_id', 0));
        $filters = $this->buildQueryFilters();

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
