<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Proto;

class FeedEncoder
{
    public function __construct(private readonly WireEncoder $wire)
    {
    }

    public function encodeEnvelope(array $envelope): string
    {
        $bytes = '';
        $bytes .= $this->wire->stringField(1, (string)($envelope['module_version'] ?? '1.0.0'));
        $bytes .= $this->wire->stringField(2, (string)($envelope['mode'] ?? 'changes'));
        $bytes .= $this->wire->stringField(3, (string)($envelope['stream'] ?? 'content'));
        $bytes .= $this->wire->uintField(4, (int)($envelope['next_cursor'] ?? 0));
        $bytes .= $this->wire->boolField(5, (bool)($envelope['has_more'] ?? false));
        $bytes .= $this->wire->uintField(6, (int)($envelope['item_count'] ?? 0));
        foreach (($envelope['items'] ?? []) as $item) {
            $bytes .= $this->wire->messageField(7, $this->encodeRecord($item));
        }
        $bytes .= $this->wire->uintField(8, (int)($envelope['generated_at_unix'] ?? time()));
        return $bytes;
    }

    public function encodeRecord(array $record): string
    {
        $bytes = '';
        $bytes .= $this->wire->uintField(1, (int)($record['product_id'] ?? 0));
        $bytes .= $this->wire->uintField(2, (int)($record['store_id'] ?? 0));
        $bytes .= $this->wire->stringField(3, (string)($record['sku'] ?? ''));
        $bytes .= $this->wire->stringField(4, (string)($record['op'] ?? 'upsert'));
        $bytes .= $this->wire->boolField(5, (bool)($record['is_enabled'] ?? false));
        foreach (($record['attributes'] ?? []) as $attribute) {
            $bytes .= $this->wire->messageField(6, $this->encodeAttribute($attribute));
        }
        foreach (($record['categories'] ?? []) as $category) {
            $bytes .= $this->wire->messageField(7, $this->encodeCategory($category));
        }
        $bytes .= $this->wire->uintField(8, (int)($record['event_id'] ?? 0));
        $bytes .= $this->wire->stringField(9, (string)($record['updated_at'] ?? ''));
        return $bytes;
    }

    private function encodeAttribute(array $attribute): string
    {
        $bytes = '';
        $bytes .= $this->wire->stringField(1, (string)($attribute['code'] ?? ''));
        $bytes .= $this->wire->stringField(2, (string)($attribute['value'] ?? ''));
        return $bytes;
    }

    private function encodeCategory(array $category): string
    {
        $bytes = '';
        $bytes .= $this->wire->uintField(1, (int)($category['category_id'] ?? 0));
        $bytes .= $this->wire->stringField(2, (string)($category['name'] ?? ''));
        $bytes .= $this->wire->stringField(3, (string)($category['path'] ?? ''));
        $bytes .= $this->wire->uintField(4, (int)($category['position'] ?? 0));
        return $bytes;
    }
}
