# Testing: Store endpoint and attributes dictionary

## 1. Static checks

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tools/source_contract_check_store.php
php tools/mock_store_metadata_contract.php
```

`source_contract_check_store.php` verifies that the store metadata path does not use product/category collections, repositories, external HTTP calls or LLM generation.

`mock_store_metadata_contract.php` verifies deterministic normalizers:

- `devilery -> delivery`;
- `loyality -> loyalty`;
- HTML/script stripping;
- relative URL normalization;
- unsafe URL rejection.

## 2. PHPUnit unit tests

Added unit tests:

```text
Test/Unit/Model/Store/Normalizer/SpecialNormalizerTest.php
Test/Unit/Model/Store/Normalizer/TextNormalizerTest.php
Test/Unit/Model/Store/Normalizer/UrlNormalizerTest.php
```

Run in Magento module test environment:

```bash
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Amida/ProductDeltaFeed/Test/Unit
```

or from package root, depending on installation layout.

## 3. Magento integration tests

Added integration tests:

```text
Test/Integration/Controller/StoreControllerTest.php
Test/Integration/Controller/AttributesControllerTest.php
```

Expected checks:

- `/amidafeed/v1/store/key/<KEY>` returns JSON passport;
- admin description override wins and strips HTML;
- `source_map` appears only when allowed and requested;
- `/amidafeed/v1/attributes/key/<KEY>` returns JSON dictionary.

Run in Magento integration environment:

```bash
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml.dist app/code/Amida/ProductDeltaFeed/Test/Integration
```

## 4. Manual API smoke checks

After installing the module and configuring the key:

```bash
curl 'https://example.com/amidafeed/v1/store/key/<KEY>?store=default&include_counts=0&include_sitemap=1'
```

Expected:

- HTTP 200;
- JSON body;
- `entity=store`;
- `store.code=default`;
- `languages[]` present;
- `sitemap.languages[].entries[]` present when sitemap is enabled.

Attributes:

```bash
curl -sS -w '\nHTTP %{http_code} time_total=%{time_total}s size=%{size_download}B\n' \
  'https://example.com/amidafeed/v1/attributes/key/<KEY>?store=default&codes=name,color'
```

Compatibility route:

```bash
curl -sS -w '\nHTTP %{http_code} time_total=%{time_total}s size=%{size_download}B\n' \
  'https://example.com/amidafeed/v1/snapshot/key/<KEY>/stream/attributes?store=default&codes=name,color'
```

Expected attributes checks:

- Default schema v2 exposes a top-level `attributes` object keyed by stringified attribute id; `items[]` is present only when `schema=v1` is explicitly requested.
- `product_types[]` lists only product type codes used by products on the requested store website, with v2 `attribute_ids` derived from `catalog_eav_attribute.apply_to`; v1 keeps `attribute_codes`/`product_count`.
- `attribute_sets[]` is a tree of used product attribute sets, groups, and included v2 `attribute_ids`; v1 keeps `attribute_codes`/`product_count`.
- Schema v2 relation nodes contain IDs only: no embedded attribute objects and no `product_count`.
- Attribute and option `labels` maps are keyed by store code for every filled localized label; `admin_label` appears separately only when it differs.
- Attributes that are not assigned to a used product attribute set/group, or have no non-empty product value in admin/active store views, are excluded.

Railway before/after timing plan for `/amidafeed/v1/attributes` changes:

1. Before deployment, run the attributes `curl -w` command against production three times for an uncached broad dictionary request and three times for a representative `codes=name,color` request; record `time_total`, HTTP status, response size, and item counts in the work log.
2. Deploy to a Railway branch/preview when available and repeat the same commands with the preview URL; confirm `product_types`, `attribute_sets`, store-code `labels`, and the value-filtered v2 `attributes` contract and explicit `schema=v1` legacy `items[]` contract.
3. After the `jan2` production auto-deploy completes, repeat the production measurements and compare median timing with the pre-deploy baseline.
4. If timing regresses materially, inspect Railway logs for slow SQL and test a narrow `codes=` request to separate broad dictionary cost from endpoint overhead.

## 5. Important Magento runtime checks for agent

Run these in real Magento, not just sandbox:

```bash
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Then verify:

1. store endpoint works with default config;
2. `include_sources=1` does not return `source_map` until `allow_include_sources=1`;
3. admin `description_override` is reflected;
4. admin `pages_json` can add a page with `special=["delivery"]`;
5. unsafe adapter/admin URL is rejected with diagnostic;
6. attributes endpoint does not load products;
7. counts can be disabled with `include_counts=0`.

Additional schema v2 acceptance checks:

- Request `/amidafeed/v1/attributes/key/<KEY>?store=default&load_options=0` and verify the default response has `schema_version: 2`, top-level `attributes`, no top-level `items`, no nested `options` keys, and at least one selectable attribute with `options_count > 0` when the catalog has selectable attributes with options.
- Repeat with a JSON POST body containing `{"load_options": false}` to verify robust boolean parsing. The values `0`, `"0"`, `"false"`, `"no"`, and `"off"` must behave the same way.
- Verify every `product_types[].attribute_ids[]` and `attribute_sets[].groups[].attribute_ids[]` value is a JSON number and resolves to an existing key in the top-level `attributes` object.
- Request `schema=v1` explicitly to confirm legacy `items[]` remains available only for v1 consumers.
- Request `format=json` on product snapshot/changes and category snapshot/changes streams, including empty and cursor-expired cases where possible, and verify `Content-Type: application/json`, `json_decode` success, and no protobuf bytes.
