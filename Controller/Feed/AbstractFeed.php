<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Controller\Feed;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\Feed\ApiRequestGate;
use Amida\ProductDeltaFeed\Model\Feed\RequestDroppedException;
use Amida\ProductDeltaFeed\Model\FeedExporter;

abstract class AbstractFeed extends Action
{
    public function __construct(
        Context $context,
        protected readonly RawFactory $rawFactory,
        protected readonly Config $config,
        protected readonly FeedExporter $feedExporter,
        protected readonly ApiRequestGate $requestGate
    ) {
        parent::__construct($context);
    }

    abstract protected function export(string $streamCode, int $cursor): array;

    public function execute(): Raw
    {
        $raw = $this->rawFactory->create();

        if (!$this->config->isEnabled()) {
            return $raw->setHttpResponseCode(404)->setContents('Not Found');
        }

        $token = (string)$this->getRequest()->getParam('token', '');
        if ($token === '' || !hash_equals($this->config->getPublicToken(), $token)) {
            return $raw->setHttpResponseCode(404)->setContents('Not Found');
        }

        $stream = (string)$this->getRequest()->getParam('stream', Config::STREAM_CONTENT);
        if (!in_array($stream, $this->config->getActiveStreams(), true)) {
            return $raw->setHttpResponseCode(404)->setContents('Not Found');
        }

        $cursor = max(0, (int)$this->getRequest()->getParam('cursor', 0));

        try {
            $result = $this->requestGate->execute(fn (): array => $this->export($stream, $cursor));
        } catch (RequestDroppedException $exception) {
            $this->getResponse()->setHeader('Retry-After', (string)$this->requestGate->getTimeoutSeconds(), true);
            return $raw->setHttpResponseCode(429)->setContents($exception->getMessage());
        }

        $this->getResponse()->setHeader('Content-Type', 'application/x-protobuf', true);
        $this->getResponse()->setHeader('Content-Encoding', 'zstd', true);
        $this->getResponse()->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $this->getResponse()->setHeader('Pragma', 'no-cache', true);
        $this->getResponse()->setHeader('X-AMIDAFEED-Stream', $stream, true);
        $this->getResponse()->setHeader('X-AMIDAFEED-Next-Cursor', (string)$result['next_cursor'], true);
        $this->getResponse()->setHeader('X-AMIDAFEED-Has-More', $result['has_more'] ? '1' : '0', true);
        $this->getResponse()->setHeader('X-AMIDAFEED-Item-Count', (string)$result['item_count'], true);

        return $raw->setContents((string)$result['body']);
    }
}
