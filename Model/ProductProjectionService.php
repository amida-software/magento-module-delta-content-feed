<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Amida\ProductDeltaFeed\Model\Policy\EventDecision;
use Amida\ProductDeltaFeed\Model\ResourceModel\EventResource;
use Amida\ProductDeltaFeed\Model\ResourceModel\SnapshotResource;

class ProductProjectionService
{
    public function __construct(
        private readonly Config $config,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly SnapshotResource $snapshotResource,
        private readonly EventResource $eventResource,
        private readonly EventDecision $decision,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function projectProduct(int $productId, ?array $streams = null, bool $emitEvents = true): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $streams = $this->resolveStreams($streams);
        foreach ($this->config->getExportStoreIds() as $storeId) {
            foreach ($streams as $streamCode) {
                try {
                    $payload = $this->payloadBuilder->build($productId, $storeId, $streamCode);
                } catch (NoSuchEntityException) {
                    return;
                }

                $snapshot = $this->snapshotResource->upsert(
                    $streamCode,
                    (int)$payload['product_id'],
                    (int)$payload['store_id'],
                    (string)$payload['sku'],
                    (bool)$payload['is_enabled'],
                    $payload
                );

                if (!$emitEvents) {
                    continue;
                }

                $op = $this->decision->decide(
                    (bool)$snapshot['exists'],
                    $snapshot['old_enabled'] === null ? null : (bool)$snapshot['old_enabled'],
                    (bool)$payload['is_enabled'],
                    (bool)$snapshot['changed']
                );

                if ($op === null) {
                    continue;
                }

                $eventPayload = $op === EventDecision::OP_DISABLE_STATUS_ONLY
                    ? $this->statusOnlyPayload($payload)
                    : $payload;

                $this->eventResource->append(
                    $streamCode,
                    $op,
                    (int)$payload['product_id'],
                    (int)$payload['store_id'],
                    (string)$payload['sku'],
                    (bool)$payload['is_enabled'],
                    $eventPayload
                );
            }
        }
    }

    public function projectProducts(array $productIds, ?array $streams = null, bool $emitEvents = true): void
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        foreach ($productIds as $productId) {
            if ($productId > 0) {
                $this->projectProduct($productId, $streams, $emitEvents);
            }
        }
    }

    public function projectSku(string $sku, ?array $streams = null, bool $emitEvents = true): void
    {
        if ($sku === '') {
            return;
        }
        try {
            $product = $this->productRepository->get($sku, false, null, true);
            $this->projectProduct((int)$product->getId(), $streams, $emitEvents);
        } catch (NoSuchEntityException) {
            // no-op
        }
    }

    public function projectSkus(array $skus, ?array $streams = null, bool $emitEvents = true): void
    {
        foreach (array_values(array_unique(array_filter(array_map('strval', $skus)))) as $sku) {
            $this->projectSku($sku, $streams, $emitEvents);
        }
    }

    public function deleteByProductId(int $productId, ?string $sku = null): void
    {
        $rows = $this->snapshotResource->fetchByProductId($productId);
        if ($rows === []) {
            return;
        }

        foreach ($rows as $row) {
            $payload = [
                'product_id' => (int)$row['product_id'],
                'store_id' => (int)$row['store_id'],
                'sku' => (string)$row['sku'],
                'is_enabled' => false,
                'updated_at' => gmdate('c'),
                'attributes' => [],
                'categories' => [],
            ];
            $this->eventResource->append(
                (string)$row['stream_code'],
                EventDecision::OP_DELETE,
                (int)$row['product_id'],
                (int)$row['store_id'],
                (string)$row['sku'],
                false,
                $payload
            );
        }

        $this->snapshotResource->deleteByProductId($productId);
    }

    public function deleteBySku(string $sku): void
    {
        foreach ($this->snapshotResource->fetchBySku($sku) as $row) {
            $this->deleteByProductId((int)$row['product_id'], $sku);
            return;
        }
    }

    public function rebuild(?array $productIds = null, ?array $streams = null, bool $emitEvents = false): void
    {
        $productIds = $productIds ?: $this->fetchAllProductIds();
        $this->projectProducts($productIds, $streams, $emitEvents);
    }

    private function resolveStreams(?array $streams): array
    {
        $active = $this->config->getActiveStreams();
        if ($streams === null || $streams === []) {
            return $active;
        }
        return array_values(array_intersect($active, array_map('strval', $streams)));
    }

    private function statusOnlyPayload(array $payload): array
    {
        return [
            'product_id' => (int)$payload['product_id'],
            'store_id' => (int)$payload['store_id'],
            'sku' => (string)$payload['sku'],
            'is_enabled' => false,
            'updated_at' => (string)$payload['updated_at'],
            'attributes' => [],
            'categories' => [],
        ];
    }

    /** @return int[] */
    private function fetchAllProductIds(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_entity');
        $select = $connection->select()->from($table, ['entity_id'])->order('entity_id ASC');
        return array_map('intval', $connection->fetchCol($select));
    }
}
