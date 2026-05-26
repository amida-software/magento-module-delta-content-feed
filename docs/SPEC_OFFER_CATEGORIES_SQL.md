# Спецификация доработки: `offer`, `categories`, SKU/date-фильтры и direct SQL price/stock

**Module:** `Amida_ProductDeltaFeed`
**Status:** implemented draft
**Scope:** Magento 2 product delta feed extension for StoreAgent/Banter ingestion.
**Main invariant:** offer price/stock export reads source-of-truth Magento DB tables directly, not index tables and not Magento product/inventory abstraction APIs.

---

## 1. Новые stream-ы

### 1.1. `offer`

`offer` — отдельная продаваемая позиция по SKU. Он экспортирует только то, что нужно для продажи и фильтрации наличия:

- `sku`
- `product_id`
- `parent_product_id` / `parent_sku` для variant/simple under configurable
- `magento_type_id`
- `prices.old`
- `prices.current`
- `prices.currency`
- `prices.special_price`
- `prices.special_from_date`
- `prices.special_to_date`
- `prices.source`
- `availability`
- `qty`
- `is_salable`
- `is_in_stock`
- `stock_status`
- `manage_stock`
- `backorders`
- `source_updated_at`

`offer` не содержит контент товара, бренд, отзывы, описание, характеристики или категории.

### 1.2. `categories`

`categories` — справочник дерева категорий магазина. Это не старый stream `category`.

- `category` = assignments товара к категориям.
- `categories` = dictionary/tree категорий.

Payload `categories`:

- `category_id`
- `external_id`
- `enabled`
- `store_code`
- `parent_id`
- `path`
- `level`
- `position`
- `url_key`
- `url_path`
- `url`
- `name`
- `title`
- `description`
- `meta_title`
- `meta_description`
- `include_in_menu`
- `source_updated_at`

---

## 2. Direct SQL invariant for offer

### 2.1. Запрещено

Для `offer` price/stock hot path запрещено использовать:

- `catalog_product_index_price`
- `cataloginventory_stock_status`
- `inventory_stock_<id>`
- `ProductRepository` для цены/остатков
- `StockRegistry` для построения offer
- `GetProductSalableQtyInterface`
- `IsProductSalableInterface`
- `Product::getFinalPrice()`

### 2.2. Разрешенные source tables

Price source tables:

- `catalog_product_entity`
- `catalog_product_entity_decimal`
- `catalog_product_entity_datetime`
- `catalog_product_entity_int`
- `eav_attribute`
- `eav_entity_type`

Legacy stock source tables:

- `cataloginventory_stock_item`

MSI stock source tables:

- `inventory_stock_sales_channel`
- `inventory_source_stock_link`
- `inventory_source_item`
- `inventory_reservation`

Parent/variant relation source tables:

- `catalog_product_relation`
- `catalog_product_super_link`
- `catalog_product_entity`

### 2.3. Price calculation MVP

`prices.old` = direct EAV `price`.
`prices.current` = active `special_price` when date window is active and special price is lower; otherwise regular price.
`prices.source` = `direct_sql_eav`.

This is deliberately customer-group agnostic. It does not claim to reproduce every possible custom price modifier, catalog rule, bundle option, configurable option, custom option, coupon, tax, or customer group price. The goal is the stable exported offer baseline from DB source rows without index tables.

### 2.4. Stock calculation MVP

Legacy mode:

- source row: `cataloginventory_stock_item`
- `qty`
- `is_in_stock`
- `manage_stock`
- `backorders`

MSI mode:

- resolve stock by website through `inventory_stock_sales_channel`
- resolve assigned sources through `inventory_source_stock_link`
- sum active source quantities from `inventory_source_item`
- apply reservation delta from `inventory_reservation`
- clamp exported qty to `>= 0`

`availability` mapping:

- disabled product → `hidden`
- salable with positive qty → `in_stock`
- salable through backorders with zero qty → `preorder`
- otherwise → `out_of_stock`

---

## 3. Product export extensions

### 3.1. `include_offer=1`

For product streams (`content`, `seo`, `price`, `availability`, `category`, `curated`, `all`) snapshot/changes may include current offer state inline:

```http
GET /amidafeed/v1/snapshot/key/<KEY>/stream/content?store=default&sku=SKU-1&include_offer=1
GET /amidafeed/v1/changes/key/<KEY>/stream/content?store=default&after_event_id=10&include_offer=1
```

Implementation detail:

- product event history is not mutated;
- service reads current `offer` state from `amida_product_delta_state` stream `offer` by SKU;
- inline offer is added only to response payload;
- if offer state is missing, response diagnostics include `offer_state_missing` with `sku`.

### 3.2. SKU filter for product snapshot/current state

SKU lookup is current-state lookup and intentionally ignores `after_state_id`:

```http
GET /amidafeed/v1/snapshot/key/<KEY>/stream/content?store=default&sku=SKU-1,SKU-2
```

Headers include:

```http
X-Amida-Mode: sku_lookup
X-Amida-From-State-Id: 0
X-Amida-To-State-Id: 0
```

Missing SKU state rows are reported as diagnostics:

```json
{"code":"sku_state_missing","sku":"SKU-404"}
```

---

## 4. Offer export modes

### 4.1. Full/current snapshot

```http
GET /amidafeed/v1/snapshot/key/<KEY>/stream/offer?store=default&after_state_id=0
```

Uses cursor pagination by `state_id` unless `sku` is supplied.

### 4.2. By SKU set

```http
GET /amidafeed/v1/snapshot/key/<KEY>/stream/offer?store=default&sku=SKU-1,SKU-2
```

This returns current offer states for exact SKU set.

### 4.3. By date with pagination

```http
GET /amidafeed/v1/changes/key/<KEY>/stream/offer?store=default&changed_from=2026-05-01%2000:00:00&changed_to=2026-05-02%2000:00:00&after_event_id=0
```

Date filtering uses event `created_at` and still paginates by monotonic `event_id`.

### 4.4. By SKU set in changes

```http
GET /amidafeed/v1/changes/key/<KEY>/stream/offer?store=default&sku=SKU-1,SKU-2&after_event_id=0
```

Uses composite index `(stream_code, store_code, sku, event_id)`.

---

## 5. Category export modes

### 5.1. Snapshot

```http
GET /amidafeed/v1/snapshot/key/<KEY>/stream/categories?store=default&after_state_id=0
```

### 5.2. Snapshot by category IDs

```http
GET /amidafeed/v1/snapshot/key/<KEY>/stream/categories?store=default&category_id=12,15
```

### 5.3. Changes by date

```http
GET /amidafeed/v1/changes/key/<KEY>/stream/categories?store=default&changed_from=2026-05-01%2000:00:00&changed_to=2026-05-02%2000:00:00&after_event_id=0
```

---

## 6. Storage changes

### 6.1. Product state/event indexes

Added for efficient SKU and date reads:

- `AMIDA_PRODUCT_DELTA_STATE_STREAM_STORE_SKU`
- `AMIDA_PRODUCT_DELTA_EVENT_STREAM_STORE_SKU_EVENT`
- `AMIDA_PRODUCT_DELTA_EVENT_STREAM_STORE_CREATED_EVENT`

### 6.2. Category tables

- `amida_product_delta_category_dirty`
- `amida_product_delta_category_event`
- `amida_product_delta_category_state`

---

## 7. Admin config additions

Streams:

- `offer_enabled`
- `categories_enabled`

Runtime:

- `sku_filter_get_limit` default `200`
- `sku_filter_post_limit` default `5000`
- `date_filter_max_days` default `31`

---

## 8. Processing lifecycle

### 8.1. Product dirty processing

Product dirty processing now builds states for:

```text
content, seo, price, availability, offer, category, curated
```

`all` receives fan-out events from all enabled origin streams.

### 8.2. Category dirty processing

Category save/delete events write to `amida_product_delta_category_dirty` and are processed by `CategoryChangeProcessor`.

CLI and cron process both product and category dirty queues:

```bash
bin/magento amidafeed:process-dirty
```

Snapshot rebuild rebuilds both product and category state:

```bash
bin/magento amidafeed:snapshot:rebuild
```

---

## 9. Testing contract

Must pass on a Magento project with dev dependencies:

```bash
vendor/bin/phpunit Test/Unit
vendor/bin/phpunit Test/Integration
bin/magento setup:di:compile
bin/magento setup:upgrade --dry-run=1
```

Sandbox checks included in this archive:

```bash
php -l $(find . -name '*.php')
php tools/source_contract_check.php
php tools/mock_offer_math_test.php
php tools/mock_offer_category_smoke.php
```

---

## 10. Known limits / next hardening

1. Price is direct SQL baseline price + active special price. It is not full per-customer cart quote simulation.
2. MSI stock is computed from source items + reservations, not from `inventory_stock_<id>` salability indexes.
3. SKU GET filter is intentionally capped; use POST support or smaller batches for large exact SKU reads.
4. For very large stores, direct SQL offer export can be further optimized with a batch preloader instead of per-product state-builder reads.
