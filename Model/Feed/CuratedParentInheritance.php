<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Feed;

/**
 * Fills empty curated fields on a configurable child from its parent's curated payload.
 *
 * Identity / commercial fields (sku, name, prices, availability) and the product type are
 * intentionally never inherited. Applied at STATE BUILD time so the stored state, its hash,
 * the snapshot stream and the changes stream all stay consistent.
 */
class CuratedParentInheritance
{
    /**
     * Curated fields a child inherits from its configurable parent when the child value is empty.
     * Everything except sku, name, prices, availability and magento_type_id.
     *
     * @var string[]
     */
    public const INHERITABLE_FIELDS = [
        'category_ids',
        'images',
        'description',
        'short_description',
        'url_key',
        'url',
        'brand',
        'product_type',
        'notes',
        'related_products',
    ];

    /**
     * Fill empty inheritable fields on a child curated payload from its configurable parent.
     *
     * @param array<string, mixed> $childCurated
     * @param array<string, mixed> $parentCurated
     * @return array<string, mixed>
     */
    public function inherit(array $childCurated, array $parentCurated): array
    {
        foreach (self::INHERITABLE_FIELDS as $field) {
            if ($this->isEmptyValue($childCurated[$field] ?? null) && !$this->isEmptyValue($parentCurated[$field] ?? null)) {
                $childCurated[$field] = $parentCurated[$field];
            }
        }

        return $childCurated;
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
