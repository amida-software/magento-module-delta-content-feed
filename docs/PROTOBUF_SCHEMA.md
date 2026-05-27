# Protobuf Schema Notes

The canonical schema is stored in `proto/amida_product_delta_feed_v1.proto`.

The module implements its own tiny protobuf encoder so the hot feed path does not depend on a runtime code generator. The schema is still published as `.proto` so downstream consumers can generate strongly typed clients in Python, Go, Rust, Java, Node.js or PHP.

## Product streams

`FeedEnvelope` and `SnapshotEnvelope` carry product-stream events/states:

- `content`
- `seo`
- `price`
- `availability`
- `category` — product/category assignments
- `offer` — direct-SQL price + qty + salability by SKU
- `curated`
- `all`

`ProductState.offer` is field `8` and is encoded for the `offer` stream and optionally inlined into other product streams when `include_offer=1` is requested.

`PriceState` includes direct-SQL current price metadata:

- `current_price = 8`
- `source = 9`

`AvailabilityState` includes normalized availability metadata:

- `availability = 7`
- `source = 8`

`OfferState` intentionally does not duplicate string stock status fields. Field numbers `7`, `10` and `11`
are reserved for the removed `availability`, `is_in_stock` and `stock_status` offer fields; use the
dedicated `availability` stream for normalized stock-status text.

## Category dictionary stream

`CategoryFeedEnvelope` and `CategorySnapshotEnvelope` carry the `categories` dictionary stream.

`categories` is intentionally separate from product-stream `category`:

- `category` = category assignments for a product;
- `categories` = category tree/content dictionary.

Category payload is wrapped as `CategoryPayload`:

```proto
message CategoryPayload {
  bool enabled = 1;
  bool deleted = 2;
  CategoryEntityState category = 3;
}
```

## Diagnostics

`Diagnostic` includes optional entity hints:

- `event_id = 3`
- `sku = 4`
- `category_id = 5`

These are used for missing SKU state, missing inline offer state, cursor expiration and oversize item diagnostics.
