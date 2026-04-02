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

Magento saves, category assignments and stock updates enqueue dirty rows. A cron/CLI processor rebuilds canonical state from live Magento data, diffs it against `amida_product_delta_state` and appends new rows to `amida_product_delta_event`.

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
- `all`: duplicates individual events in one ordered stream and preserves `origin_stream`.

## Tests

- Unit tests cover canonicalization, diffs, lifecycle, batching, monopoly-request locking and first-run snapshot bootstrap.
- Integration/controller tests dispatch real Magento routes and assert headers/body/health JSON.
