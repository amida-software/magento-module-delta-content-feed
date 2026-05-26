# Validation report: direct-SQL `offer` and `categories`

**Date:** 2026-05-26
**Local Magento:** `C:\Data\Sites\jan.local`
**Module repo:** `C:\Data\Repo\magento-module-delta-content-feed`
**Branch:** `codex/offer-categories-sql`

## Commands executed

### Static/source checks

```bash
docker run --rm -v C:\Data\Repo\magento-module-delta-content-feed:/work -w /work jan-local-php:latest \
  sh -lc "find . -path './.git' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l"

docker run --rm -v C:\Data\Repo\magento-module-delta-content-feed:/work -w /work jan-local-php:latest \
  sh -lc "php tools/source_contract_check.php && php tools/mock_offer_math_test.php && php tools/mock_offer_category_smoke.php && php tools/smoke.php"
```

Result:

```text
PHP syntax: OK for all module PHP files
Source contract OK
OfferMath mock OK
OK: mock offer/category smoke checks passed
Smoke OK
```

### PHPUnit unit suite

```bash
docker compose --env-file .env.docker exec -T php \
  vendor/bin/phpunit vendor/amida/module-product-delta-feed/Test/Unit --colors=never
```

Result:

```text
OK (32 tests, 94 assertions)
```

### Magento setup / local DB

```bash
docker compose --env-file .env.docker exec -T php \
  php -d memory_limit=2G bin/magento setup:upgrade --keep-generated
```

Result:

```text
setup:upgrade completed successfully
```

Also verified:

```text
amida_product_delta_category_state.parent_id exists: yes
```

### CLI smoke

```bash
docker compose --env-file .env.docker exec -T php \
  php -d memory_limit=2G bin/magento list amidafeed --no-ansi
```

Result includes:

```text
amidafeed:key:rotate
amidafeed:process-dirty
amidafeed:snapshot:rebuild
```

```bash
docker compose --env-file .env.docker exec -T php \
  php -d memory_limit=2G bin/magento amidafeed:process-dirty --no-ansi
```

Result after seeded dirty rows:

```text
Processed product dirty rows: 1
Processed category dirty rows: 1
```

### Endpoint smoke tests

All requests were executed against local nginx with `Host: jan.local` and a DB-stored feed key; the key is not recorded here.

#### Category snapshot

```http
GET /amidafeed/v1/snapshot/key/<KEY>/stream/categories?store=ua&after_state_id=0
```

Result headers:

```text
HTTP/1.1 200 OK
Content-Type: application/x-protobuf
X-Amida-Stream: categories
X-Amida-Store: ua
X-Amida-Mode: cursor_snapshot
Content-Encoding: zstd
```

Local DB state after bootstrap:

```text
amida_product_delta_category_state: 68 rows
```

#### Offer snapshot by SKU

```http
GET /amidafeed/v1/snapshot/key/<KEY>/stream/offer?store=ua&sku=46510
```

Result headers:

```text
HTTP/1.1 200 OK
Content-Type: application/x-protobuf
X-Amida-Stream: offer
X-Amida-Store: ua
X-Amida-Mode: sku_lookup
Content-Encoding: zstd
```

#### Product snapshot with embedded current offer

```http
GET /amidafeed/v1/snapshot/key/<KEY>/stream/content?store=ua&sku=46510&include_offer=1
```

Result headers:

```text
HTTP/1.1 200 OK
Content-Type: application/x-protobuf
X-Amida-Stream: content
X-Amida-Mode: sku_lookup
Content-Encoding: zstd
```

#### Offer changes by SKU

```http
GET /amidafeed/v1/changes/key/<KEY>/stream/offer?store=ua&after_event_id=0&sku=46510
```

Result headers:

```text
HTTP/1.1 200 OK
Content-Type: application/x-protobuf
X-Amida-Stream: offer
X-Amida-Cursor-Expired: 0
Content-Encoding: zstd
```

#### Category changes by ID

```http
GET /amidafeed/v1/changes/key/<KEY>/stream/categories?store=ua&after_event_id=0&category_id=2
```

Result headers:

```text
HTTP/1.1 200 OK
Content-Type: application/x-protobuf
X-Amida-Stream: categories
X-Amida-To-Event-Id: 1
Content-Encoding: zstd
```

## Important issue found and fixed during validation

Initial category snapshot failed with:

```text
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'parent_id' in 'INSERT INTO'
```

Fix:

- added `parent_id` column and index to `amida_product_delta_category_state` in `etc/db_schema.xml`;
- added `parent_id` to `CategoryStateSnapshot::upsertMany()` duplicate update columns;
- added source contract assertions so this mismatch is caught before runtime.

## Integration test note

The module has Magento TestFramework-style integration tests under `Test/Integration`, but this local Composer install does not include `Magento\TestFramework\TestCase\AbstractController`; running those tests directly with project PHPUnit fails before executing module assertions. Endpoint smoke tests above were used as the local runtime/controller verification.

## DI compile note

`setup:di:compile` was attempted on the local Windows bind-mounted Magento tree and timed out. This environment is known to be very slow for generated code. The module was instead verified through fresh generated-code cleanup, `setup:upgrade`, CLI command instantiation, unit tests, source checks and real HTTP endpoints. Run full DI compile on Linux/CI/staging before release packaging.
