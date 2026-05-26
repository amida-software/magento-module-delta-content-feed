<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Store;

interface StoreMetadataAdapterInterface
{
    public function getId(): string;

    public function supports(StoreContext $context): bool;

    /** @return array<string, mixed> */
    public function resolveStore(StoreContext $context): array;

    /** @return array<int, array<string, mixed>> */
    public function resolveLanguages(StoreContext $context): array;

    /** @return array<string, mixed> */
    public function resolveContacts(StoreContext $context): array;

    /** @return array<int, array<string, mixed>> */
    public function resolveAddresses(StoreContext $context): array;

    /** @return array<int, array<string, mixed>> */
    public function resolvePages(StoreContext $context): array;

    /** @return array<string, mixed> */
    public function resolveSitemap(StoreContext $context): array;

    /**
     * @param string[] $attributeCodes
     * @return array<string, array<string, mixed>> keyed by attribute code
     */
    public function resolveAttributeMetadata(StoreContext $context, array $attributeCodes): array;
}
