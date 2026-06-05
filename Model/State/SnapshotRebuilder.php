<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\State;

use Magento\Framework\App\ResourceConnection;
use Amida\ProductDeltaFeed\Model\Config;
use Amida\ProductDeltaFeed\Model\StoreScopeResolver;
use Amida\ProductDeltaFeed\Model\ResourceModel\StateSnapshot;

class SnapshotRebuilder
{
    private const PRODUCT_STREAMS = ['content', 'offer', 'category', 'curated'];

    public function __construct(
        private readonly Config $config,
        private readonly ProductStateBuilder $stateBuilder,
        private readonly StateDiffer $stateDiffer,
        private readonly StateSnapshot $stateSnapshot,
        private readonly StoreScopeResolver $storeScopeResolver,
        private readonly JsonCanonicalizer $canonicalizer,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function rebuild(): int
    {
        $this->stateSnapshot->truncate();
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_entity');
        $productIds = array_map('intval', $connection->fetchCol($connection->select()->from($table, ['entity_id'])->order('entity_id ASC')));

        $rows = [];
        foreach ($this->storeScopeResolver->resolveStoreCodes(0) as $storeCode) {
            foreach ($productIds as $productId) {
                $states = $this->stateBuilder->buildStates($productId, $storeCode);
                if ($states === null) {
                    continue;
                }
                foreach (self::PRODUCT_STREAMS as $stream) {
                    if (!$this->config->isStreamEnabled($stream)) {
                        continue;
                    }
                    if ($stream === 'curated' && ($states['meta']['curated_excluded'] ?? false)) {
                        // Configurable products are excluded from the curated stream entirely
                        // (whether enabled or disabled), matching ChangeProcessor's curated_excluded
                        // handling. Without this, disabled configurables hit emptyPayload('curated')
                        // (non-null) and a curated row would be written for them.
                        continue;
                    }
                    $payload = (bool)$states['meta']['enabled']
                        ? $states[$stream]
                        : $this->emptyPayload($stream);
                    if ($payload === null) {
                        // Defensive: never hash(null) or write a null state row.
                        continue;
                    }
                    $rows[] = [
                        'entity_id' => $productId,
                        'sku' => (string)$states['meta']['sku'],
                        'store_code' => $storeCode,
                        'stream_code' => $stream,
                        'is_enabled' => (int)$states['meta']['enabled'],
                        'state_hash' => $this->stateDiffer->hash($payload),
                        'state_json' => $this->canonicalizer->encode($payload),
                    ];
                    if (count($rows) >= 500) {
                        $this->stateSnapshot->upsertMany($rows);
                        $rows = [];
                    }
                }
            }
        }
        if ($rows !== []) {
            $this->stateSnapshot->upsertMany($rows);
        }

        return count($productIds);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(string $stream): array
    {
        $payload = [
            'enabled' => false,
            'deleted' => false,
        ];

        if ($stream === 'curated') {
            $payload['curated'] = [];
        } elseif ($stream === 'offer') {
            $payload['offer'] = [];
        } else {
            $payload['attributes'] = [];
        }

        return $payload;
    }
}
