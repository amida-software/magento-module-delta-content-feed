# Implementation plan: direct-SQL `offer` stream and `categories` dictionary

**Module:** `Amida_ProductDeltaFeed`
**Branch:** `codex/offer-categories-sql`
**Source package:** `magento-product-delta-feed-offer-categories-sql.zip`
**Instruction:** `AGENT_INSTRUCTION_OFFER_CATEGORIES_SQL.md`

## Goal

Integrate the supplied direct-SQL offer/categories patch into the current module branch without regressing existing product streams, especially the recently added `curated` stream.

## Non-negotiable invariants

1. Existing product v1 endpoints stay compatible.
2. `offer` price/stock hot path must not read Magento price/stock index tables:
   - `catalog_product_index_price`
   - `cataloginventory_stock_status`
   - dynamic `inventory_stock_<id>` tables
3. `offer` hot path must not use Magento inventory service abstractions or `Product::getFinalPrice()`.
4. Product stream `category` remains product-category assignments; new `categories` stream is a category dictionary.
5. `include_offer=1` enriches the response payload only; it must not mutate stored product event history.
6. SKU lookup mode is explicit current-state lookup and intentionally ignores `after_state_id`.

## Implementation steps

### 1. Intake and diff review

- Unpack the supplied zip outside the repo.
- Compare the package against `C:\Data\Repo\magento-module-delta-content-feed`.
- Copy code selectively, not blindly:
  - keep current curated stream behavior;
  - do not add duplicate/agent-only docs unless useful;
  - preserve existing module package structure.

### 2. Core offer stream

Files:

- `Model/Offer/DirectSqlOfferProvider.php`
- `Model/Offer/OfferMath.php`
- `Model/State/ProductStateBuilder.php`
- `Model/State/CuratedProductBuilder.php`
- `Model/Change/ChangeProcessor.php`
- `Model/State/SnapshotRebuilder.php`
- `Model/Feed/FeedEncoder.php`
- `proto/amida_product_delta_feed_v1.proto`

Implement:

- `Config::STREAM_OFFER`.
- Per-product state key `offer`.
- Direct SQL baseline prices and availability.
- Existing `price` and `availability` streams derived from offer baseline state.
- `OfferState` and `OfferPriceState` protobuf messages.

Tests/checks:

- `Test/Unit/Model/Offer/OfferMathTest.php`
- `Test/Unit/Model/Offer/DirectSqlOfferProviderSourceTest.php`
- `tools/source_contract_check.php`
- `tools/mock_offer_math_test.php`

### 3. SKU and date filters

Files:

- `Controller/V1/Changes.php`
- `Controller/V1/Snapshot.php`
- `Model/Feed/ChangesService.php`
- `Model/Feed/SnapshotService.php`
- `Model/ResourceModel/ChangeLog.php`
- `Model/ResourceModel/StateSnapshot.php`
- `Model/Config.php`
- `etc/adminhtml/system.xml`
- `etc/config.xml`

Implement:

- `sku` / `skus` GET filters.
- POST JSON body support for larger exact SKU lists.
- `changed_from` / `changed_to` filters for changes while retaining monotonic `event_id` cursor semantics.
- Response diagnostics for missing SKU state.
- `X-Amida-Mode: sku_lookup` for SKU snapshot lookup.

Tests/checks:

- Extend `ChangesServiceTest` and `SnapshotServiceTest`.
- Endpoint smoke with `stream/offer?sku=...`.
- Endpoint smoke with `include_offer=1` on product stream.

### 4. Categories dictionary stream

Files:

- `Model/Category/CategoryStateBuilder.php`
- `Model/Category/CategoryDirtyCollector.php`
- `Model/Category/CategoryChangeProcessor.php`
- `Model/Category/CategorySnapshotRebuilder.php`
- `Model/ResourceModel/CategoryDirtyQueue.php`
- `Model/ResourceModel/CategoryChangeLog.php`
- `Model/ResourceModel/CategoryStateSnapshot.php`
- `Model/Feed/CategoryChangesService.php`
- `Model/Feed/CategorySnapshotService.php`
- `Observer/CategorySaveAfterObserver.php`
- `Observer/CategoryDeleteAfterObserver.php`
- `etc/db_schema.xml`
- `etc/events.xml`

Implement:

- Category dirty queue, event log and state snapshot tables.
- Direct SQL category dictionary builder with store-scoped EAV fallback.
- Category snapshot and changes services.
- Save/delete observers.
- Category protobuf envelopes and payload messages.

Tests/checks:

- `tools/mock_offer_category_smoke.php`
- `tools/source_contract_check.php`
- Endpoint smoke for `stream/categories` snapshot and changes.
- Local DB check that `amida_product_delta_category_state.parent_id` exists and category snapshot rebuild persists rows.

### 5. Ops integration

Files:

- `Console/Command/ProcessDirtyCommand.php`
- `Console/Command/SnapshotRebuildCommand.php`
- `Model/Cron/ProcessDirtyQueue.php`
- `Model/Cron/Cleanup.php`
- `Model/Feed/HealthService.php`

Implement:

- `amidafeed:process-dirty` processes product + category dirty queues.
- `amidafeed:snapshot:rebuild` rebuilds product + category snapshots.
- Cleanup, health and stats include category state.

Tests/checks:

- CLI list shows existing `amidafeed:*` commands.
- `amidafeed:process-dirty` exits successfully for empty and seeded queues.
- Local endpoint highwater/counters update as expected.

### 6. Documentation

Files:

- `README.md`
- `docs/SPEC.md`
- `docs/TECHNICAL.md`
- `docs/PROTOBUF_SCHEMA.md`
- `docs/SPEC_OFFER_CATEGORIES_SQL.md`
- `docs/TESTING_OFFER_CATEGORIES_SQL.md`
- `docs/VALIDATION_REPORT_OFFER_CATEGORIES_SQL.md`

Document:

- New streams and endpoint parameters.
- Direct-SQL source tables and forbidden sources.
- MVP price/stock limitations.
- SKU lookup semantics.
- Category stream contract.
- Verification commands and local validation results.

## Verification checklist

1. PHP lint for every PHP file.
2. Source contract checks.
3. Mock offer math checks.
4. Mock offer/category smoke checks.
5. Existing module smoke check.
6. PHPUnit unit suite.
7. Magento `setup:upgrade --keep-generated` on local DB.
8. Magento CLI command discovery for `amidafeed:*`.
9. Local endpoint smoke:
   - `snapshot stream=categories`
   - `snapshot stream=offer&sku=...`
   - `changes stream=offer&sku=...`
   - `changes stream=categories&category_id=...`
   - `snapshot stream=content&sku=...&include_offer=1`
10. Check table/state counts in local MariaDB.

## Known local-environment note

Full `setup:di:compile` on this Windows bind-mounted Magento tree is extremely slow and previously timed out in the local environment. Do not use a timed-out DI compile as proof of failure of the module; validate generated-code cleanup, `setup:upgrade`, CLI instantiation, unit tests and endpoint execution. For release/staging, run DI compile on a normal Linux filesystem or CI runner.
