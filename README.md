# Amida_ProductDeltaFeed

Installable Magento 2 module that publishes product deltas over public HTTP endpoints in **Protocol Buffers** with optional **zstd** compression.

## What it does

- Maintains its own append-only product change log.
- Publishes streams: `content`, `seo`, `price`, `availability`, `category`, `offer`, `curated`, `all`, plus `categories` for the category dictionary.
- Adds a consumer-friendly `curated` product stream with full product documents: SKU, old/new price, simple availability, name, description, full image URLs, brand, product type, category IDs, notes and related products.
- Adds direct-SQL `offer` export for price, salability and qty from Magento source DB tables, not price/stock indexes.
- Adds `categories` dictionary export with category tree/content metadata.
- Uses a monotonic `event_id` cursor.
- Stores normalized per-product per-store per-stream state snapshots.
- Applies special lifecycle rules:
  - disable -> publish `STATUS_ONLY`
  - while disabled -> suppress ordinary deltas
  - enable -> publish full replay for all enabled streams
- Handles category changes explicitly.
- Supports batch size by compressed response bytes.
- Can serialize public `changes` / `snapshot` requests through a configurable monopoly-request lock with timeout.
- Auto-bootstrap for the very first snapshot request when state cache is still empty.
- Includes unit tests and controller-level API tests.

## Endpoints

- `GET /amidafeed/v1/changes/key/<KEY>/stream/<STREAM>?after_event_id=<ID>&store=<CODE>`
- `GET /amidafeed/v1/snapshot/key/<KEY>/stream/<STREAM>?after_state_id=<ID>&store=<CODE>`
- `GET /amidafeed/v1/snapshot/key/<KEY>/stream/offer?store=<CODE>&sku=SKU-1,SKU-2`
- `GET /amidafeed/v1/changes/key/<KEY>/stream/offer?store=<CODE>&changed_from=YYYY-MM-DD%20HH:MM:SS&changed_to=YYYY-MM-DD%20HH:MM:SS`
- `GET /amidafeed/v1/snapshot/key/<KEY>/stream/content?store=<CODE>&sku=SKU-1&include_offer=1`
- `GET /amidafeed/v1/snapshot/key/<KEY>/stream/categories?store=<CODE>&category_id=12,15`
- `GET /amidafeed/v1/health/key/<KEY>`
- `GET /amidafeed/v1/stats/key/<KEY>`

## Installation

See [docs/INSTALL.md](docs/INSTALL.md).

## Technical design

See [docs/TECHNICAL.md](docs/TECHNICAL.md).

## Protobuf schema

See [proto/amida_product_delta_feed_v1.proto](proto/amida_product_delta_feed_v1.proto).

## Specification

See [docs/SPEC.md](docs/SPEC.md) and [docs/SPEC_CATEGORIES_OFFERS_DIRECT_SQL.md](docs/SPEC_CATEGORIES_OFFERS_DIRECT_SQL.md).

## Store metadata endpoint

This build adds a compact store passport endpoint for StoreAgent bootstrap:

```http
GET /amidafeed/v1/store/key/<KEY>?store=<STORE>
```

It returns store identity, languages, currency, direct-SQL counts, contacts, addresses, pages, sitemap summary and diagnostics. Data is resolved by priority:

```text
admin_override > site_adapter > auto > fallback/null
```

Source diagnostics are exposed only through `source_map` when both `include_sources=1` and `amida_productdeltafeed/store_metadata/allow_include_sources=1` are set.

Attributes dictionary endpoint:

```http
GET /amidafeed/v1/attributes/key/<KEY>?store=<STORE>&codes=color,size
GET /amidafeed/v1/snapshot/key/<KEY>/stream/attributes?store=<STORE>&codes=color,size
```

See `docs/SPEC_STORE_ENDPOINT.md` and `docs/TESTING_STORE_ENDPOINT.md`.
