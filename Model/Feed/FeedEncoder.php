<?php
declare(strict_types=1);

namespace Amida\ProductDeltaFeed\Model\Feed;

class FeedEncoder
{
    public function __construct(private readonly ProtoWriter $writer)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $diagnostics
     */
    public function encodeChangesEnvelope(array $meta, array $items, array $diagnostics = []): string
    {
        $payload = '';
        $payload .= $this->writer->int32(1, (int)($meta['schema_version'] ?? 1));
        $payload .= $this->writer->string(2, (string)($meta['stream'] ?? ''));
        $payload .= $this->writer->string(3, (string)($meta['store_code'] ?? ''));
        $payload .= $this->writer->uint64(4, (int)($meta['from_event_id'] ?? 0));
        $payload .= $this->writer->uint64(5, (int)($meta['to_event_id'] ?? 0));
        $payload .= $this->writer->bool(6, (bool)($meta['has_more'] ?? false));
        $payload .= $this->writer->bool(7, (bool)($meta['cursor_expired'] ?? false));
        foreach ($items as $item) {
            $payload .= $this->writer->message(8, $this->encodeFeedItem($item));
        }
        foreach ($diagnostics as $diagnostic) {
            $payload .= $this->writer->message(9, $this->encodeDiagnostic($diagnostic));
        }
        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $diagnostics
     */
    public function encodeSnapshotEnvelope(array $meta, array $items, array $diagnostics = []): string
    {
        $payload = '';
        $payload .= $this->writer->int32(1, (int)($meta['schema_version'] ?? 1));
        $payload .= $this->writer->string(2, (string)($meta['stream'] ?? ''));
        $payload .= $this->writer->string(3, (string)($meta['store_code'] ?? ''));
        $payload .= $this->writer->uint64(4, (int)($meta['from_state_id'] ?? 0));
        $payload .= $this->writer->uint64(5, (int)($meta['to_state_id'] ?? 0));
        $payload .= $this->writer->bool(6, (bool)($meta['has_more'] ?? false));
        $payload .= $this->writer->uint64(7, (int)($meta['changes_highwater_event_id'] ?? 0));
        foreach ($items as $item) {
            $payload .= $this->writer->message(8, $this->encodeSnapshotItem($item));
        }
        foreach ($diagnostics as $diagnostic) {
            $payload .= $this->writer->message(9, $this->encodeDiagnostic($diagnostic));
        }
        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $diagnostics
     */
    public function encodeCategoryChangesEnvelope(array $meta, array $items, array $diagnostics = []): string
    {
        $payload = '';
        $payload .= $this->writer->int32(1, (int)($meta['schema_version'] ?? 1));
        $payload .= $this->writer->string(2, (string)($meta['stream'] ?? 'categories'));
        $payload .= $this->writer->string(3, (string)($meta['store_code'] ?? ''));
        $payload .= $this->writer->uint64(4, (int)($meta['from_event_id'] ?? 0));
        $payload .= $this->writer->uint64(5, (int)($meta['to_event_id'] ?? 0));
        $payload .= $this->writer->bool(6, (bool)($meta['has_more'] ?? false));
        $payload .= $this->writer->bool(7, (bool)($meta['cursor_expired'] ?? false));
        foreach ($items as $item) {
            $payload .= $this->writer->message(8, $this->encodeCategoryFeedItem($item));
        }
        foreach ($diagnostics as $diagnostic) {
            $payload .= $this->writer->message(9, $this->encodeDiagnostic($diagnostic));
        }
        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $diagnostics
     */
    public function encodeCategorySnapshotEnvelope(array $meta, array $items, array $diagnostics = []): string
    {
        $payload = '';
        $payload .= $this->writer->int32(1, (int)($meta['schema_version'] ?? 1));
        $payload .= $this->writer->string(2, (string)($meta['stream'] ?? 'categories'));
        $payload .= $this->writer->string(3, (string)($meta['store_code'] ?? ''));
        $payload .= $this->writer->uint64(4, (int)($meta['from_state_id'] ?? 0));
        $payload .= $this->writer->uint64(5, (int)($meta['to_state_id'] ?? 0));
        $payload .= $this->writer->bool(6, (bool)($meta['has_more'] ?? false));
        $payload .= $this->writer->uint64(7, (int)($meta['changes_highwater_event_id'] ?? 0));
        foreach ($items as $item) {
            $payload .= $this->writer->message(8, $this->encodeCategorySnapshotItem($item));
        }
        foreach ($diagnostics as $diagnostic) {
            $payload .= $this->writer->message(9, $this->encodeDiagnostic($diagnostic));
        }
        return $payload;
    }

    private function encodeFeedItem(array $item): string
    {
        $payload = '';
        $payload .= $this->writer->uint64(1, (int)($item['event_id'] ?? 0));
        $payload .= $this->writer->string(2, (string)($item['stream'] ?? ''));
        $payload .= $this->writer->string(3, (string)($item['origin_stream'] ?? ''));
        $payload .= $this->writer->uint64(4, (int)($item['product_id'] ?? 0));
        $payload .= $this->writer->string(5, (string)($item['sku'] ?? ''));
        $payload .= $this->writer->string(6, (string)($item['store_code'] ?? ''));
        $payload .= $this->writer->int32(7, $this->mapEventType((string)($item['event_type'] ?? '')));
        foreach ((array)($item['changed_fields'] ?? []) as $field) {
            $payload .= $this->writer->string(8, (string)$field);
        }
        if (($item['source_updated_at'] ?? '') !== '') {
            $payload .= $this->writer->string(9, (string)$item['source_updated_at']);
        }
        if (($item['emitted_at'] ?? '') !== '') {
            $payload .= $this->writer->string(10, (string)$item['emitted_at']);
        }
        $payload .= $this->writer->int32(11, (int)($item['payload_version'] ?? 1));
        $payload .= $this->writer->string(12, (string)($item['payload_hash'] ?? ''));
        $payload .= $this->writer->message(13, $this->encodeProductState((array)($item['payload'] ?? [])));
        return $payload;
    }

    private function encodeSnapshotItem(array $item): string
    {
        $payload = '';
        $payload .= $this->writer->uint64(1, (int)($item['state_id'] ?? 0));
        $payload .= $this->writer->uint64(2, (int)($item['product_id'] ?? 0));
        $payload .= $this->writer->string(3, (string)($item['sku'] ?? ''));
        $payload .= $this->writer->string(4, (string)($item['stream'] ?? ''));
        $payload .= $this->writer->string(5, (string)($item['store_code'] ?? ''));
        if (($item['updated_at'] ?? '') !== '') {
            $payload .= $this->writer->string(6, (string)$item['updated_at']);
        }
        $payload .= $this->writer->string(7, (string)($item['state_hash'] ?? ''));
        $payload .= $this->writer->message(8, $this->encodeProductState((array)($item['payload'] ?? [])));
        return $payload;
    }

    private function encodeProductState(array $state): string
    {
        $payload = '';
        $payload .= $this->writer->bool(1, (bool)($state['enabled'] ?? false));
        foreach ((array)($state['attributes'] ?? []) as $attribute) {
            $payload .= $this->writer->message(2, $this->encodeAttributeValue((array)$attribute));
        }
        if (!empty($state['category'])) {
            $payload .= $this->writer->message(3, $this->encodeCategoryState((array)$state['category']));
        }
        if (!empty($state['price'])) {
            $payload .= $this->writer->message(4, $this->encodePriceState((array)$state['price']));
        }
        if (!empty($state['availability'])) {
            $payload .= $this->writer->message(5, $this->encodeAvailabilityState((array)$state['availability']));
        }
        $payload .= $this->writer->bool(6, (bool)($state['deleted'] ?? false));
        if (!empty($state['curated'])) {
            $payload .= $this->writer->message(7, $this->encodeCuratedProduct((array)$state['curated']));
        }
        if (!empty($state['offer'])) {
            $payload .= $this->writer->message(8, $this->encodeOfferState((array)$state['offer']));
        }
        return $payload;
    }

    private function encodeAttributeValue(array $attribute): string
    {
        $payload = '';
        $payload .= $this->writer->string(1, (string)($attribute['code'] ?? ''));
        $payload .= $this->writer->int32(2, $this->mapValueKind((string)($attribute['kind'] ?? '')));
        $payload .= $this->writer->bool(3, (bool)($attribute['is_null'] ?? false));
        if (isset($attribute['string_value'])) {
            $payload .= $this->writer->string(4, (string)$attribute['string_value']);
        }
        if (isset($attribute['int_value'])) {
            $payload .= $this->writer->uint64(5, (int)$attribute['int_value']);
        }
        if (isset($attribute['float_value'])) {
            $payload .= $this->writer->double(6, (float)$attribute['float_value']);
        }
        if (isset($attribute['bool_value'])) {
            $payload .= $this->writer->bool(7, (bool)$attribute['bool_value']);
        }
        foreach ((array)($attribute['list_values'] ?? []) as $value) {
            $payload .= $this->writer->string(8, (string)$value);
        }
        foreach ((array)($attribute['labels'] ?? []) as $label) {
            $payload .= $this->writer->string(9, (string)$label);
        }
        return $payload;
    }

    private function encodeCategoryState(array $state): string
    {
        $payload = '';
        foreach ((array)($state['categories'] ?? []) as $category) {
            $payload .= $this->writer->message(1, $this->encodeCategoryAssignment((array)$category));
        }
        foreach ((array)($state['added_category_ids'] ?? []) as $categoryId) {
            $payload .= $this->writer->uint64(2, (int)$categoryId);
        }
        foreach ((array)($state['removed_category_ids'] ?? []) as $categoryId) {
            $payload .= $this->writer->uint64(3, (int)$categoryId);
        }
        return $payload;
    }

    private function encodeCategoryAssignment(array $category): string
    {
        $payload = '';
        $payload .= $this->writer->uint64(1, (int)($category['category_id'] ?? 0));
        $payload .= $this->writer->sint32(2, (int)($category['position'] ?? 0));
        return $payload;
    }

    private function encodePriceState(array $state): string
    {
        $payload = '';
        if (isset($state['price']) && $state['price'] !== null) {
            $payload .= $this->writer->double(1, (float)$state['price']);
        }
        if (isset($state['special_price']) && $state['special_price'] !== null) {
            $payload .= $this->writer->double(2, (float)$state['special_price']);
        }
        if (($state['special_from_date'] ?? '') !== '') {
            $payload .= $this->writer->string(3, (string)$state['special_from_date']);
        }
        if (($state['special_to_date'] ?? '') !== '') {
            $payload .= $this->writer->string(4, (string)$state['special_to_date']);
        }
        foreach ((array)($state['tier_prices'] ?? []) as $tierPrice) {
            $payload .= $this->writer->message(5, $this->encodeTierPrice((array)$tierPrice));
        }
        foreach ((array)($state['group_prices'] ?? []) as $groupPrice) {
            $payload .= $this->writer->message(6, $this->encodeGroupPrice((array)$groupPrice));
        }
        if (($state['currency_code'] ?? '') !== '') {
            $payload .= $this->writer->string(7, (string)$state['currency_code']);
        }
        if (isset($state['current']) && $state['current'] !== null) {
            $payload .= $this->writer->double(8, (float)$state['current']);
        }
        if (($state['source'] ?? '') !== '') {
            $payload .= $this->writer->string(9, (string)$state['source']);
        }
        return $payload;
    }

    private function encodeTierPrice(array $price): string
    {
        $payload = '';
        if (($price['customer_group'] ?? '') !== '') {
            $payload .= $this->writer->string(1, (string)$price['customer_group']);
        }
        if (isset($price['qty'])) {
            $payload .= $this->writer->double(2, (float)$price['qty']);
        }
        if (isset($price['value'])) {
            $payload .= $this->writer->double(3, (float)$price['value']);
        }
        return $payload;
    }

    private function encodeGroupPrice(array $price): string
    {
        $payload = '';
        if (($price['customer_group'] ?? '') !== '') {
            $payload .= $this->writer->string(1, (string)$price['customer_group']);
        }
        if (isset($price['value'])) {
            $payload .= $this->writer->double(2, (float)$price['value']);
        }
        return $payload;
    }

    private function encodeAvailabilityState(array $state): string
    {
        $payload = '';
        if (isset($state['is_in_stock'])) {
            $payload .= $this->writer->bool(1, (bool)$state['is_in_stock']);
        }
        if (isset($state['is_salable'])) {
            $payload .= $this->writer->bool(2, (bool)$state['is_salable']);
        }
        if (isset($state['qty'])) {
            $payload .= $this->writer->double(3, (float)$state['qty']);
        }
        if (isset($state['manage_stock'])) {
            $payload .= $this->writer->bool(4, (bool)$state['manage_stock']);
        }
        if (isset($state['backorders'])) {
            $payload .= $this->writer->int32(5, (int)$state['backorders']);
        }
        if (($state['stock_status'] ?? '') !== '') {
            $payload .= $this->writer->string(6, (string)$state['stock_status']);
        }
        if (($state['availability'] ?? '') !== '') {
            $payload .= $this->writer->string(7, (string)$state['availability']);
        }
        if (($state['source'] ?? '') !== '') {
            $payload .= $this->writer->string(8, (string)$state['source']);
        }
        return $payload;
    }

    private function encodeCuratedProduct(array $state): string
    {
        $payload = '';
        if (($state['sku'] ?? '') !== '') {
            $payload .= $this->writer->string(1, (string)$state['sku']);
        }
        if (!empty($state['prices'])) {
            $payload .= $this->writer->message(2, $this->encodeCuratedPrices((array)$state['prices']));
        }
        if (!empty($state['availability'])) {
            $payload .= $this->writer->message(3, $this->encodeCuratedAvailability((array)$state['availability']));
        }
        if (($state['name'] ?? '') !== '') {
            $payload .= $this->writer->string(4, (string)$state['name']);
        }
        if (($state['description'] ?? '') !== '') {
            $payload .= $this->writer->string(5, (string)$state['description']);
        }
        if (($state['short_description'] ?? '') !== '') {
            $payload .= $this->writer->string(6, (string)$state['short_description']);
        }
        if (($state['url_key'] ?? '') !== '') {
            $payload .= $this->writer->string(7, (string)$state['url_key']);
        }
        foreach ((array)($state['images'] ?? []) as $imageUrl) {
            $payload .= $this->writer->string(8, (string)$imageUrl);
        }
        if (($state['brand'] ?? '') !== '') {
            $payload .= $this->writer->string(9, (string)$state['brand']);
        }
        if (($state['product_type'] ?? '') !== '') {
            $payload .= $this->writer->string(10, (string)$state['product_type']);
        }
        if (($state['magento_type_id'] ?? '') !== '') {
            $payload .= $this->writer->string(11, (string)$state['magento_type_id']);
        }
        foreach ((array)($state['category_ids'] ?? []) as $categoryId) {
            $payload .= $this->writer->uint64(12, (int)$categoryId);
        }
        foreach ((array)($state['notes'] ?? []) as $note) {
            $payload .= $this->writer->string(13, (string)$note);
        }
        foreach ((array)($state['related_products'] ?? []) as $relatedProduct) {
            $payload .= $this->writer->message(14, $this->encodeRelatedProduct((array)$relatedProduct));
        }
        return $payload;
    }

    private function encodeCuratedPrices(array $prices): string
    {
        $payload = '';
        if (array_key_exists('old', $prices) && $prices['old'] !== null) {
            $payload .= $this->writer->double(1, (float)$prices['old']);
        }
        if (array_key_exists('new', $prices) && $prices['new'] !== null) {
            $payload .= $this->writer->double(2, (float)$prices['new']);
        }
        return $payload;
    }

    private function encodeCuratedAvailability(array $availability): string
    {
        $payload = '';
        if (isset($availability['is_available'])) {
            $payload .= $this->writer->bool(1, (bool)$availability['is_available']);
        }
        if (isset($availability['qty'])) {
            $payload .= $this->writer->double(2, (float)$availability['qty']);
        }
        return $payload;
    }

    private function encodeRelatedProduct(array $relatedProduct): string
    {
        $payload = '';
        if (($relatedProduct['relation'] ?? '') !== '') {
            $payload .= $this->writer->string(1, (string)$relatedProduct['relation']);
        }
        $payload .= $this->writer->uint64(2, (int)($relatedProduct['product_id'] ?? 0));
        if (($relatedProduct['sku'] ?? '') !== '') {
            $payload .= $this->writer->string(3, (string)$relatedProduct['sku']);
        }
        if (($relatedProduct['type_id'] ?? '') !== '') {
            $payload .= $this->writer->string(4, (string)$relatedProduct['type_id']);
        }
        if (isset($relatedProduct['position'])) {
            $payload .= $this->writer->sint32(5, (int)$relatedProduct['position']);
        }
        return $payload;
    }

    private function encodeOfferState(array $offer): string
    {
        $payload = '';
        $payload .= $this->writer->uint64(1, (int)($offer['product_id'] ?? 0));
        if (($offer['sku'] ?? '') !== '') {
            $payload .= $this->writer->string(2, (string)$offer['sku']);
        }
        if (!empty($offer['parent_product_id'])) {
            $payload .= $this->writer->uint64(3, (int)$offer['parent_product_id']);
        }
        if (($offer['parent_sku'] ?? '') !== '') {
            $payload .= $this->writer->string(4, (string)$offer['parent_sku']);
        }
        if (($offer['magento_type_id'] ?? '') !== '') {
            $payload .= $this->writer->string(5, (string)$offer['magento_type_id']);
        }
        if (!empty($offer['prices'])) {
            $payload .= $this->writer->message(6, $this->encodeOfferPrices((array)$offer['prices']));
        }
        if (isset($offer['qty'])) {
            $payload .= $this->writer->double(8, (float)$offer['qty']);
        }
        if (isset($offer['is_salable'])) {
            $payload .= $this->writer->bool(9, (bool)$offer['is_salable']);
        }
        if (isset($offer['manage_stock'])) {
            $payload .= $this->writer->bool(12, (bool)$offer['manage_stock']);
        }
        if (isset($offer['backorders'])) {
            $payload .= $this->writer->int32(13, (int)$offer['backorders']);
        }
        if (($offer['source_updated_at'] ?? '') !== '') {
            $payload .= $this->writer->string(14, (string)$offer['source_updated_at']);
        }
        if (($offer['source'] ?? '') !== '') {
            $payload .= $this->writer->string(15, (string)$offer['source']);
        }
        return $payload;
    }

    private function encodeOfferPrices(array $prices): string
    {
        $payload = '';
        if (array_key_exists('old', $prices) && $prices['old'] !== null) {
            $payload .= $this->writer->double(1, (float)$prices['old']);
        }
        if (array_key_exists('current', $prices) && $prices['current'] !== null) {
            $payload .= $this->writer->double(2, (float)$prices['current']);
        }
        if (($prices['currency'] ?? '') !== '') {
            $payload .= $this->writer->string(3, (string)$prices['currency']);
        }
        if (array_key_exists('special_price', $prices) && $prices['special_price'] !== null) {
            $payload .= $this->writer->double(4, (float)$prices['special_price']);
        }
        if (($prices['special_from_date'] ?? '') !== '') {
            $payload .= $this->writer->string(5, (string)$prices['special_from_date']);
        }
        if (($prices['special_to_date'] ?? '') !== '') {
            $payload .= $this->writer->string(6, (string)$prices['special_to_date']);
        }
        if (($prices['source'] ?? '') !== '') {
            $payload .= $this->writer->string(7, (string)$prices['source']);
        }
        return $payload;
    }

    private function encodeCategoryFeedItem(array $item): string
    {
        $payload = '';
        $payload .= $this->writer->uint64(1, (int)($item['event_id'] ?? 0));
        $payload .= $this->writer->string(2, (string)($item['stream'] ?? 'categories'));
        $payload .= $this->writer->uint64(3, (int)($item['category_id'] ?? 0));
        $payload .= $this->writer->string(4, (string)($item['store_code'] ?? ''));
        $payload .= $this->writer->int32(5, $this->mapEventType((string)($item['event_type'] ?? '')));
        foreach ((array)($item['changed_fields'] ?? []) as $field) {
            $payload .= $this->writer->string(6, (string)$field);
        }
        if (($item['source_updated_at'] ?? '') !== '') {
            $payload .= $this->writer->string(7, (string)$item['source_updated_at']);
        }
        if (($item['emitted_at'] ?? '') !== '') {
            $payload .= $this->writer->string(8, (string)$item['emitted_at']);
        }
        $payload .= $this->writer->int32(9, (int)($item['payload_version'] ?? 1));
        $payload .= $this->writer->string(10, (string)($item['payload_hash'] ?? ''));
        $payload .= $this->writer->message(11, $this->encodeCategoryPayload((array)($item['payload'] ?? [])));
        return $payload;
    }

    private function encodeCategorySnapshotItem(array $item): string
    {
        $payload = '';
        $payload .= $this->writer->uint64(1, (int)($item['state_id'] ?? 0));
        $payload .= $this->writer->uint64(2, (int)($item['category_id'] ?? 0));
        $payload .= $this->writer->string(3, (string)($item['stream'] ?? 'categories'));
        $payload .= $this->writer->string(4, (string)($item['store_code'] ?? ''));
        if (($item['updated_at'] ?? '') !== '') {
            $payload .= $this->writer->string(5, (string)$item['updated_at']);
        }
        $payload .= $this->writer->string(6, (string)($item['state_hash'] ?? ''));
        $payload .= $this->writer->message(7, $this->encodeCategoryPayload((array)($item['payload'] ?? [])));
        return $payload;
    }

    private function encodeCategoryPayload(array $state): string
    {
        $payload = '';
        $payload .= $this->writer->bool(1, (bool)($state['enabled'] ?? false));
        $payload .= $this->writer->bool(2, (bool)($state['deleted'] ?? false));
        if (!empty($state['category'])) {
            $payload .= $this->writer->message(3, $this->encodeCategoryDocument((array)$state['category']));
        }
        return $payload;
    }

    private function encodeCategoryDocument(array $category): string
    {
        $payload = '';
        $payload .= $this->writer->uint64(1, (int)($category['category_id'] ?? 0));
        if (($category['external_id'] ?? '') !== '') {
            $payload .= $this->writer->string(2, (string)$category['external_id']);
        }
        if (isset($category['enabled'])) {
            $payload .= $this->writer->bool(3, (bool)$category['enabled']);
        }
        if (($category['store_code'] ?? '') !== '') {
            $payload .= $this->writer->string(4, (string)$category['store_code']);
        }
        if (!empty($category['parent_id'])) {
            $payload .= $this->writer->uint64(5, (int)$category['parent_id']);
        }
        if (($category['path'] ?? '') !== '') {
            $payload .= $this->writer->string(6, (string)$category['path']);
        }
        if (isset($category['level'])) {
            $payload .= $this->writer->uint64(7, (int)$category['level']);
        }
        if (isset($category['position'])) {
            $payload .= $this->writer->sint32(8, (int)$category['position']);
        }
        if (($category['url_key'] ?? '') !== '') {
            $payload .= $this->writer->string(9, (string)$category['url_key']);
        }
        if (($category['url_path'] ?? '') !== '') {
            $payload .= $this->writer->string(10, (string)$category['url_path']);
        }
        if (($category['url'] ?? '') !== '') {
            $payload .= $this->writer->string(11, (string)$category['url']);
        }
        if (($category['name'] ?? '') !== '') {
            $payload .= $this->writer->string(12, (string)$category['name']);
        }
        if (($category['title'] ?? '') !== '') {
            $payload .= $this->writer->string(13, (string)$category['title']);
        }
        if (($category['description'] ?? '') !== '') {
            $payload .= $this->writer->string(14, (string)$category['description']);
        }
        if (($category['meta_title'] ?? '') !== '') {
            $payload .= $this->writer->string(15, (string)$category['meta_title']);
        }
        if (($category['meta_description'] ?? '') !== '') {
            $payload .= $this->writer->string(16, (string)$category['meta_description']);
        }
        if (isset($category['include_in_menu'])) {
            $payload .= $this->writer->bool(17, (bool)$category['include_in_menu']);
        }
        if (($category['source_updated_at'] ?? '') !== '') {
            $payload .= $this->writer->string(18, (string)$category['source_updated_at']);
        }
        return $payload;
    }

    private function encodeDiagnostic(array $diagnostic): string
    {
        $payload = '';
        $payload .= $this->writer->string(1, (string)($diagnostic['code'] ?? ''));
        $payload .= $this->writer->string(2, (string)($diagnostic['message'] ?? ''));
        if (isset($diagnostic['event_id'])) {
            $payload .= $this->writer->uint64(3, (int)$diagnostic['event_id']);
        }
        if (isset($diagnostic['sku'])) {
            $payload .= $this->writer->string(4, (string)$diagnostic['sku']);
        }
        if (isset($diagnostic['category_id'])) {
            $payload .= $this->writer->uint64(5, (int)$diagnostic['category_id']);
        }
        return $payload;
    }

    private function mapEventType(string $eventType): int
    {
        return match ($eventType) {
            'UPSERT_PARTIAL' => 1,
            'UPSERT_FULL' => 2,
            'STATUS_ONLY' => 3,
            'CATEGORY_FULL' => 4,
            'TOMBSTONE' => 5,
            'SNAPSHOT_ITEM' => 6,
            default => 0,
        };
    }

    private function mapValueKind(string $kind): int
    {
        return match ($kind) {
            'string' => 1,
            'int' => 2,
            'float', 'decimal' => 3,
            'bool', 'boolean' => 4,
            'date' => 5,
            'datetime' => 6,
            'select' => 7,
            'multiselect' => 8,
            'text' => 9,
            default => 0,
        };
    }
}
