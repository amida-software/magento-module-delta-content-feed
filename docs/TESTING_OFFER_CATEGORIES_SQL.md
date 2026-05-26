# Тестирование доработки `offer/categories/direct SQL`

## 1. Что проверяем

1. `offer` строится из direct SQL source tables, без Magento index tables и без inventory API hot path.
2. `offer` доступен как отдельный stream.
3. `offer` можно выгружать:
   - полным/current snapshot;
   - date-filtered changes + cursor pagination;
   - exact SKU set lookup.
4. Product streams умеют `include_offer=1`.
5. Product snapshot умеет exact SKU lookup вместо обычного cursor-only snapshot.
6. `categories` доступен как отдельный category dictionary stream.
7. Dirty processing и snapshot rebuild обрабатывают product и category queues.
8. Protobuf schema и encoder совпадают по номерам полей.

---

## 2. Sandbox checks, которые можно выполнить без Magento

```bash
cd app/code/Amida/ProductDeltaFeed

php -l $(find . -name '*.php')
php tools/source_contract_check.php
php tools/mock_offer_math_test.php
php tools/mock_offer_category_smoke.php
```

Что проверяют scripts:

- `source_contract_check.php`
  - `DirectSqlOfferProvider` содержит source tables;
  - не содержит price/stock indexes и запрещенные API markers;
  - `SnapshotService` содержит SKU lookup + `include_offer` diagnostics;
  - `.proto` содержит `OfferState`, `CategoryPayload`, diagnostic entity hints.

- `mock_offer_math_test.php`
  - active/future special price;
  - reservation delta;
  - salable/backorder mapping.

- `mock_offer_category_smoke.php`
  - protobuf encoder пишет mock offer payload;
  - protobuf encoder пишет mock category dictionary payload;
  - direct SQL source markers присутствуют;
  - forbidden index/API markers отсутствуют.

---

## 3. PHPUnit unit tests

Run inside Magento project with PHPUnit/dev dependencies:

```bash
vendor/bin/phpunit app/code/Amida/ProductDeltaFeed/Test/Unit
```

Important tests:

- `Test/Unit/Model/Feed/FeedEncoderTest.php`
  - deterministic binary envelope;
  - curated stream encoding;
  - offer encoding;
  - category dictionary encoding.

- `Test/Unit/Model/Feed/ChangesServiceTest.php`
  - headers/body;
  - date + SKU filters passed to `ChangeLog::fetchChanges()`;
  - `include_offer=1` inlines current offer state from `StateSnapshot`.

- `Test/Unit/Model/Feed/SnapshotServiceTest.php`
  - first snapshot auto-rebuild;
  - cursor snapshot;
  - SKU lookup mode;
  - `include_offer=1` inlines current offer state.

- `Test/Unit/Model/Offer/DirectSqlOfferProviderSourceTest.php`
  - source-level contract: required direct tables present, forbidden index/API calls absent.

---

## 4. Magento integration/API tests

Run inside Magento integration-test environment:

```bash
vendor/bin/phpunit app/code/Amida/ProductDeltaFeed/Test/Integration
```

Existing integration tests cover base controller behavior for:

- changes endpoint;
- snapshot endpoint;
- health endpoint.

Recommended added manual/integration scenarios:

1. Insert state row for `stream_code = offer`, call:

```http
GET /amidafeed/v1/snapshot/key/integration-key/stream/offer?store=default&sku=SKU-1
```

Expected:

- HTTP 200;
- `Content-Type: application/x-protobuf`;
- `X-Amida-Mode: sku_lookup`.

2. Insert product content state + offer state for same SKU, call:

```http
GET /amidafeed/v1/snapshot/key/integration-key/stream/content?store=default&sku=SKU-1&include_offer=1
```

Expected:

- content payload contains inline `ProductState.offer`.

3. Insert category state row, call:

```http
GET /amidafeed/v1/snapshot/key/integration-key/stream/categories?store=default&category_id=12
```

Expected:

- HTTP 200;
- category dictionary payload encoded.

---

## 5. Magento compile/setup checks

```bash
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
bin/magento amidafeed:process-dirty
bin/magento amidafeed:snapshot:rebuild
```

Check DB tables exist:

```sql
SHOW TABLES LIKE 'amida_product_delta_category_%';
SHOW INDEX FROM amida_product_delta_event;
SHOW INDEX FROM amida_product_delta_state;
```

Expected important indexes:

- `AMIDA_PRODUCT_DELTA_EVENT_STREAM_STORE_SKU_EVENT`
- `AMIDA_PRODUCT_DELTA_EVENT_STREAM_STORE_CREATED_EVENT`
- `AMIDA_PRODUCT_DELTA_STATE_STREAM_STORE_SKU`

---

## 6. Direct SQL verification on real Magento DB

Use `EXPLAIN` on generated query patterns:

```sql
EXPLAIN SELECT *
FROM amida_product_delta_event
WHERE stream_code = 'offer'
  AND store_code = 'default'
  AND sku IN ('SKU-1','SKU-2')
  AND event_id > 0
ORDER BY event_id ASC
LIMIT 250;
```

```sql
EXPLAIN SELECT *
FROM amida_product_delta_event
WHERE stream_code = 'offer'
  AND store_code = 'default'
  AND created_at >= '2026-05-01 00:00:00'
  AND created_at < '2026-05-02 00:00:00'
  AND event_id > 0
ORDER BY event_id ASC
LIMIT 250;
```

Expected: composite indexes are used or at minimum considered by optimizer.

---

## 7. Known sandbox limitation

This archive was syntax-checked and smoke-tested without a full Magento runtime. PHPUnit and Magento integration tests require a Magento project with Composer dependencies, database and Magento TestFramework.
