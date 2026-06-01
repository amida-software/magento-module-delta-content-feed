# Technical design

## Hot path

The steady-state request path never loads live EAV product state. It only:

1. validates the public feed key,
2. reads candidate rows from `amida_product_delta_event` or `amida_product_delta_state`,
3. encodes the response as protobuf,
4. compresses with zstd,
5. returns the batch.

That keeps the public API cheap and predictable.

Exception: when the snapshot state cache is completely empty and the client asks for the initial snapshot (`after_state_id=0`), the module rebuilds the snapshot cache first and then returns the same ordered, unfiltered product list from the freshly rebuilt state table.

## Write path

Magento saves, mass attribute updates, category assignments and stock updates enqueue dirty rows. A cron/CLI processor rebuilds canonical state from live Magento data, diffs it against `amida_product_delta_state` and appends new rows to `amida_product_delta_event`.

## Reliability rules

- Cursor is `event_id`, not `updated_at`.
- Category changes are tracked separately and emitted to `category` and `all`.
- Disable emits `STATUS_ONLY` across enabled streams and stores disabled marker snapshots.
- Re-enable emits full replay for enabled streams.
- Oversize items go to dead letter instead of blocking the feed forever.
- Snapshot and changes endpoints can run in monopoly-request mode: while one export request holds the API lock, the next ones wait up to the configured timeout and are dropped with HTTP 429 if the lock is still busy.
- A reconcile cron walks catalog IDs in batches and enqueues forced compare rows to repair missed events.

## Tables

- `amida_product_delta_dirty`
- `amida_product_delta_event`
- `amida_product_delta_state`
- `amida_product_delta_dead_letter`

## Stream payload rules

- `content`: configured product attributes except reserved streams.
- `seo`: `name`, `url_key`, `description`, `short_description`, `meta_title`, `meta_description`, `meta_keyword`.
- `price`: catalog price fields.
- `availability`: stock/salability state.
- `category`: full current category assignments plus `added_category_ids` / `removed_category_ids`.
- `curated`: full consumer-friendly product document. It intentionally duplicates selected data from the lower-level streams so downstream importers do not have to join `seo + price + availability + category + content` just to build a storefront product card. Configurable parents are excluded and their child variations inherit empty parent fields; `description`/`short_description` are HTML-stripped (see [Curated product payload](#curated-product-payload)).
- `all`: duplicates individual events in one ordered stream and preserves `origin_stream`.

### Curated product payload

`curated` is emitted as a complete product document whenever any relevant product area changes. The payload always contains:

- `sku`
- `prices.old` and `prices.new` (currency is intentionally not repeated per product)
- `availability.is_available` and `availability.qty`
- `name`, `description`, `short_description`, `url_key`
- `images[]` as absolute media URLs
- `brand`
- `product_type` and `magento_type_id`
- `category_ids[]`; category dictionaries are intentionally fetched separately by consumers when an unknown ID appears
- `notes[]`
- `related_products[]` for Magento linked products such as related/up-sell/cross-sell

#### Configurable products and read-time normalization

The `curated` stream is a flat list of sellable simple/child products:

- **Configurable parents are excluded** from the output (matched by `magento_type_id == 'configurable'`); their child variations carry the product data instead.
- **Children inherit from their configurable parent** every field below that is empty on the child: `category_ids`, `images`, `description`, `short_description`, `url_key`, `brand`, `product_type`, `notes`, `related_products`. Never inherited (always the child's own values): `sku`, `name`, `prices`, `availability`, `magento_type_id`. The parent is resolved via `catalog_product_super_link`, reusing the parent's stored curated state.
- `description` and `short_description` are **HTML-stripped** to plain text.

These three normalizations are applied at **state-build time** (`Model/State/ProductStateBuilder` + `Model/State/CuratedProductBuilder` + `Model/Feed/CuratedParentInheritance`), so the stored `curated` state, its `state_hash`, the `snapshot` stream and the `changes` stream are all consistent. Because inheritance is baked into the child's stored state, a configurable parent change must re-emit its children: `Observer/ProductSaveAfterObserver` enqueues every child of a saved configurable with `ReasonFlags::FORCE_COMPARE` so the inherited fields stay current in the delta stream. Switching these rules on requires one `snapshot:rebuild` to re-normalize already-stored states.

### Attribute selection and feed URLs

- The `content` stream and the attributes dictionary share one selection (`Model/AttributeSelector`): the admin include list (`content/include_attributes`) when set, otherwise **filterable attributes** (`catalog_eav_attribute.is_filterable = 1`) minus the excluded/reserved/seo/price codes. A fixed set of **base codes** (`sku, image, created_at, updated_at, name, description, short_description, url_key`) is always added on top — everywhere.
- Per-deploy values are committed in `app/etc/config.php` (version-controlled), e.g. jan pins its `content/include_attributes` there rather than only in the DB.
- Absolute URLs in the feed (images, category URLs) use the optional `general/feed_domain` override when set, otherwise the store base URL. jan sets it to its production host in `app/etc/config.php`.

The JSON HTTP surface is described by `docs/openapi.yaml`; the binary streams by `proto/amida_product_delta_feed_v1.proto` (kept in sync with `Model/Feed/FeedEncoder` by `tools/proto_encoder_parity.php`). Module distribution / drift is discussed in `docs/DISTRIBUTION.md`.

## Tests

- Unit tests cover canonicalization, diffs, lifecycle, batching, monopoly-request locking and first-run snapshot bootstrap.
- Integration/controller tests dispatch real Magento routes and assert headers/body/health JSON.


## Offer/category SQL extension

See `docs/OFFER_CATEGORIES_SQL_SPEC.md` and `docs/AGENT_CHECKLIST_SQL_OFFER_CATEGORIES.md`. Price and stock for `offer` are built from Magento source tables during dirty processing/snapshot rebuild; public endpoints continue reading module event/state tables.
