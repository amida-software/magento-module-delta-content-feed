<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\State;

class JsonCanonicalizer
{
    public function encode(array $payload): string
    {
        return (string)json_encode($this->normalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($this->isList($value)) {
            $normalized = array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
            usort($normalized, [$this, 'compare']);
            return $normalized;
        }

        $normalized = [];
        ksort($value);
        foreach ($value as $key => $item) {
            $normalized[(string)$key] = $this->normalize($item);
        }

        return $normalized;
    }

    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function compare(mixed $left, mixed $right): int
    {
        return strcmp((string)json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string)json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
