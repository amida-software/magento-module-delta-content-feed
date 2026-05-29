<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Controller\V1;

use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\Feed\ApiRequestGate;
use Amida\ProductDeltaFeed\Model\Feed\CategoryChangesService;
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
        private readonly ChangesService $changesService,
        private readonly CategoryChangesService $categoryChangesService
    ) {
        parent::__construct($context, $rawFactory, $jsonFactory, $config, $storeScopeResolver, $compressor, $requestGate);
    }

    public function execute(): ResultInterface
    {
        $key = (string)$this->getRequest()->getParam('key');
        if (!$this->validateKey($key)) {
            return $this->invalidResponse(404, 'Not found');
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
        $filters = $this->buildQueryFilters();
        if (empty($filters['_format_json']) && $this->compressor->isEnabled() && !$this->compressor->isAvailable()) {
            return $this->invalidResponse(503, 'zstd compression is enabled but ext-zstd is not installed');
        }
        $dateError = $this->validateDateWindow($filters);
        if ($dateError !== null) {
            return $this->invalidResponse(400, $dateError);
        }

        if ($stream === Config::STREAM_CATEGORIES) {
            return $this->guardedRawResponse(
                fn (): array => $this->categoryChangesService->build($storeCode, $afterEventId, $filters)
            );
        }

        return $this->guardedRawResponse(
            fn (): array => $this->changesService->build($stream, $storeCode, $afterEventId, $filters)
        );
    }

    /** @return array<string, mixed> */
    private function buildQueryFilters(): array
    {
        $body = $this->readJsonBody();
        return [
            'skus' => $this->parseStringList('skus', $body) ?: $this->parseStringList('sku', $body),
            'category_ids' => array_map('intval', $this->parseStringList('category_ids', $body) ?: $this->parseStringList('category_id', $body)),
            'changed_from' => trim((string)($body['changed_from'] ?? $this->getRequest()->getParam('changed_from', ''))),
            'changed_to' => trim((string)($body['changed_to'] ?? $this->getRequest()->getParam('changed_to', ''))),
            'include_offer' => (bool)(int)($body['include_offer'] ?? $this->getRequest()->getParam('include_offer', 0)),
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

    /** @param array<string, mixed> $body */
    private function parseFormatJson(array $body): bool
    {
        $value = array_key_exists('format', $body) ? $body['format'] : $this->getRequest()->getParam('format', '');
        return strtolower(trim((string)$value)) === 'json';
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

    /** @param array<string, mixed> $filters */
    private function validateDateWindow(array $filters): ?string
    {
        $from = trim((string)($filters['changed_from'] ?? ''));
        $to = trim((string)($filters['changed_to'] ?? ''));
        if ($from === '' || $to === '') {
            return null;
        }
        $fromTs = strtotime($from);
        $toTs = strtotime($to);
        if ($fromTs === false || $toTs === false || $toTs <= $fromTs) {
            return 'Invalid changed_from/changed_to range';
        }
        if (($toTs - $fromTs) > $this->config->getDateFilterMaxDays() * 86400) {
            return 'Date filter window is too large';
        }
        return null;
    }
}
