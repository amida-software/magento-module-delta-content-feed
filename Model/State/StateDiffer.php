<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\State;

class StateDiffer
{
    public function __construct(private readonly JsonCanonicalizer $canonicalizer)
    {
    }

    public function hash(array $state): string
    {
        return hash('sha256', $this->canonicalizer->encode($state));
    }

    /**
     * @return string[]
     */
    public function changedFields(?array $previous, array $current, string $stream): array
    {
        if ($previous === null) {
            return $this->fullChangedFields($current, $stream);
        }

        return match ($stream) {
            'content', 'seo' => $this->diffAttributeCodes($previous, $current),
            'price' => $this->diffAssocByPrefix($previous['price'] ?? [], $current['price'] ?? [], 'price.'),
            'availability' => $this->diffAssocByPrefix($previous['availability'] ?? [], $current['availability'] ?? [], 'availability.'),
            'category' => $this->diffCategory($previous['category'] ?? [], $current['category'] ?? []),
            'curated' => $this->diffAssocByPrefix($previous['curated'] ?? [], $current['curated'] ?? [], 'curated.'),
            default => $this->diffAssocByPrefix($previous, $current, ''),
        };
    }

    /**
     * @return string[]
     */
    public function fullChangedFields(array $current, string $stream): array
    {
        return match ($stream) {
            'content', 'seo' => array_keys($this->attributesToMap($current['attributes'] ?? [])),
            'price' => array_map(static fn (string $key): string => 'price.' . $key, array_keys((array)($current['price'] ?? []))),
            'availability' => array_map(static fn (string $key): string => 'availability.' . $key, array_keys((array)($current['availability'] ?? []))),
            'category' => ['category_ids', 'category_positions'],
            'curated' => array_map(static fn (string $key): string => 'curated.' . $key, array_keys((array)($current['curated'] ?? []))),
            default => array_keys($current),
        };
    }

    /**
     * @return string[]
     */
    private function diffAttributeCodes(array $previous, array $current): array
    {
        $before = $this->attributesToMap($previous['attributes'] ?? []);
        $after = $this->attributesToMap($current['attributes'] ?? []);
        $keys = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));
        sort($keys);

        $changed = [];
        foreach ($keys as $code) {
            $left = $before[$code] ?? null;
            $right = $after[$code] ?? null;
            if ($this->hashValue($left) !== $this->hashValue($right)) {
                $changed[] = $code;
            }
        }

        return $changed;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function attributesToMap(array $attributes): array
    {
        $result = [];
        foreach ($attributes as $attribute) {
            if (!is_array($attribute) || !isset($attribute['code'])) {
                continue;
            }
            $code = (string)$attribute['code'];
            $result[$code] = $attribute;
        }
        ksort($result);
        return $result;
    }

    /**
     * @return string[]
     */
    private function diffAssocByPrefix(array $previous, array $current, string $prefix): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($previous), array_keys($current))));
        sort($keys);
        $changed = [];
        foreach ($keys as $key) {
            $left = $previous[$key] ?? null;
            $right = $current[$key] ?? null;
            if ($this->hashValue($left) !== $this->hashValue($right)) {
                $changed[] = $prefix . $key;
            }
        }
        return $changed;
    }

    /**
     * @return string[]
     */
    private function diffCategory(array $previous, array $current): array
    {
        $beforeCategories = $previous['categories'] ?? [];
        $afterCategories = $current['categories'] ?? [];
        $changed = [];
        if ($this->hashValue($beforeCategories) !== $this->hashValue($afterCategories)) {
            $changed[] = 'category_ids';
            $changed[] = 'category_positions';
        }
        return array_values(array_unique($changed));
    }

    private function hashValue(mixed $value): string
    {
        if (is_array($value)) {
            return hash('sha256', $this->canonicalizer->encode($value));
        }
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
