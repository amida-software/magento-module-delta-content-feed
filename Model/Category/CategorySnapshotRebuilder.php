<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Category;

use Amida\ProductDeltaFeed\Model\ResourceModel\CategoryStateSnapshot;
use Amida\ProductDeltaFeed\Model\State\JsonCanonicalizer;
use Amida\ProductDeltaFeed\Model\State\StateDiffer;
use Amida\ProductDeltaFeed\Model\StoreScopeResolver;

class CategorySnapshotRebuilder
{
    public function __construct(
        private readonly CategoryStateBuilder $stateBuilder,
        private readonly CategoryStateSnapshot $stateSnapshot,
        private readonly StoreScopeResolver $storeScopeResolver,
        private readonly StateDiffer $stateDiffer,
        private readonly JsonCanonicalizer $canonicalizer
    ) {
    }

    public function rebuild(): int
    {
        $this->stateSnapshot->truncate();
        $rows = [];
        $processed = 0;
        foreach ($this->storeScopeResolver->resolveStoreCodes(0) as $storeCode) {
            foreach ($this->stateBuilder->getVisibleCategoryIdsForStore($storeCode) as $categoryId) {
                $state = $this->stateBuilder->buildState($categoryId, $storeCode);
                if ($state === null) {
                    continue;
                }
                $rows[] = $this->snapshotRow($categoryId, $storeCode, $state);
                $processed++;
                if (count($rows) >= 500) {
                    $this->stateSnapshot->upsertMany($rows);
                    $rows = [];
                }
            }
        }
        if ($rows !== []) {
            $this->stateSnapshot->upsertMany($rows);
        }
        return $processed;
    }

    /** @param array<string, mixed> $state */
    private function snapshotRow(int $categoryId, string $storeCode, array $state): array
    {
        return [
            'category_id' => $categoryId,
            'store_code' => $storeCode,
            'parent_id' => $state['category']['parent_id'] ?? null,
            'is_enabled' => (int)($state['enabled'] ?? false),
            'state_hash' => $this->stateDiffer->hash($state),
            'state_json' => $this->canonicalizer->encode($state),
        ];
    }
}
