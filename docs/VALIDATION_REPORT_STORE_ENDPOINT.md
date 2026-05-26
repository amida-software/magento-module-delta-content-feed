# Report: Magento module store endpoint implementation

**Module:** `Amida_ProductDeltaFeed`  
**Artifact:** `magento-product-delta-feed-store-endpoint.zip`  
**Date:** 2026-05-26

## 1. What was implemented

### Store metadata endpoint

Added dedicated JSON endpoint:

```http
GET /amidafeed/v1/store/key/<KEY>?store=<STORE>
```

Implemented controller:

```text
Controller/V1/Store.php
```

The endpoint requires the existing public feed key and checks a new config flag:

```text
amida_productdeltafeed/store_metadata/endpoint_enabled
```

### Store metadata resolver

Added resolver stack:

```text
Model/Store/StoreMetadataService.php
Model/Store/StoreContext.php
Model/Store/ResolvedValue.php
Model/Store/StoreMetadataAdapterInterface.php
Model/Store/StoreMetadataAdapterPool.php
```

Effective priority:

```text
admin_override > site_adapter > auto > fallback/null
```

The resolver returns:

- `store` identity and public URLs;
- `languages` for selected scope;
- `currency`;
- direct-SQL `counts`;
- `contacts`;
- `countries`;
- `addresses`;
- `pages` with optional `description`;
- compact `sitemap` with optional `description` in entries;
- `diagnostics`;
- optional `source_map` only when `include_sources=1` and config allows it.

### Admin configuration

Added config group:

```text
amida_productdeltafeed/store_metadata/*
```

Fields added:

- `endpoint_enabled`
- `allow_include_sources`
- `main_store_code`
- `name_override`
- `description_override`
- `home_url_override`
- `logo_url_override`
- `include_counts_default`
- `sitemap_mode`
- `sitemap_limit`
- `contacts_json`
- `pages_json`
- `addresses_json`
- `sitemap_urls_json`
- `attribute_metadata_json`

Added source model:

```text
Model/Config/Source/SitemapMode.php
```

### Site adapter extension point

Added adapter interface and pool:

```text
Model/Store/StoreMetadataAdapterInterface.php
Model/Store/StoreMetadataAdapterPool.php
```

Adapters can contribute store data, contacts, addresses, pages, sitemap URL(s), and attribute metadata. Adapter output is normalized before response.

### Normalizers

Added:

```text
Model/Store/Normalizer/TextNormalizer.php
Model/Store/Normalizer/UrlNormalizer.php
Model/Store/Normalizer/SpecialNormalizer.php
```

They handle:

- HTML/script/style stripping;
- whitespace collapse;
- bounded plain-text descriptions;
- relative URL normalization;
- unsafe scheme rejection;
- `special[]` normalization and aliases like `devilery -> delivery`, `loyality -> loyalty`.

### Attributes dictionary endpoint

Added JSON endpoint:

```http
GET /amidafeed/v1/attributes/key/<KEY>?store=<STORE>&codes=color,size
POST /amidafeed/v1/attributes/key/<KEY>/by-code?store=<STORE>
```

Added compatibility route through existing snapshot controller:

```http
GET /amidafeed/v1/snapshot/key/<KEY>/stream/attributes?store=<STORE>&codes=color,size
```

Implemented:

```text
Controller/V1/Attributes.php
Model/Store/AttributeDictionaryService.php
```

The service reads EAV dictionary data through direct SQL, not products:

- `eav_entity_type`
- `eav_attribute`
- `catalog_eav_attribute`
- `eav_attribute_label`
- `eav_attribute_option`
- `eav_attribute_option_value`

### Config streams

Added stream constant:

```text
Config::STREAM_ATTRIBUTES = attributes
```

Added config/default stream flag:

```text
amida_productdeltafeed/streams/attributes_enabled
```

Updated stream source model to include `Attributes dictionary`.

## 2. Documentation added

```text
docs/SPEC_STORE_ENDPOINT.md
docs/TESTING_STORE_ENDPOINT.md
README.md appended with endpoint summary
```

## 3. Tests added

### Unit tests

```text
Test/Unit/Model/Store/Normalizer/SpecialNormalizerTest.php
Test/Unit/Model/Store/Normalizer/TextNormalizerTest.php
Test/Unit/Model/Store/Normalizer/UrlNormalizerTest.php
```

### Integration tests

```text
Test/Integration/Controller/StoreControllerTest.php
Test/Integration/Controller/AttributesControllerTest.php
```

### Smoke/static tools

```text
tools/source_contract_check_store.php
tools/mock_store_metadata_contract.php
```

## 4. Checks run in sandbox

Passed:

```bash
php -l Controller/V1/Store.php
php -l Controller/V1/Attributes.php
php -l Controller/V1/Snapshot.php
php -l Model/Store/StoreMetadataService.php
php -l Model/Store/AttributeDictionaryService.php
php -l Model/Store/Normalizer/TextNormalizer.php
php -l Model/Store/Normalizer/SpecialNormalizer.php
php -l Model/Store/Normalizer/UrlNormalizer.php
php -l Model/Config/Source/SitemapMode.php
php -l Test/Integration/Controller/StoreControllerTest.php
php -l Test/Integration/Controller/AttributesControllerTest.php
php -l Test/Unit/Model/Store/Normalizer/SpecialNormalizerTest.php
php -l Test/Unit/Model/Store/Normalizer/TextNormalizerTest.php
php -l Test/Unit/Model/Store/Normalizer/UrlNormalizerTest.php
php tools/source_contract_check.php
php tools/source_contract_check_store.php
php tools/mock_offer_math_test.php
php tools/mock_offer_category_smoke.php
php tools/mock_store_metadata_contract.php
```

XML parsed successfully with Python `xml.etree.ElementTree`:

```text
etc/adminhtml/system.xml OK
etc/config.xml OK
etc/di.xml OK
etc/frontend/routes.xml OK
etc/module.xml OK
```

## 5. Sandbox limitations

Not run here because this environment does not include a full Magento runtime, Composer vendor tree, DB, or PHPUnit runner:

```bash
composer install
vendor/bin/phpunit
bin/magento setup:upgrade
bin/magento setup:di:compile
```

The module includes integration tests and docs for a real Magento agent to run them.

## 6. What the implementation agent must verify in real Magento

Run:

```bash
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Amida/ProductDeltaFeed/Test/Unit
vendor/bin/phpunit -c dev/tests/integration/phpunit.xml.dist app/code/Amida/ProductDeltaFeed/Test/Integration
```

Manual API checks:

```bash
curl 'https://example.com/amidafeed/v1/store/key/<KEY>?store=default&include_counts=0&include_sitemap=1'
curl 'https://example.com/amidafeed/v1/store/key/<KEY>?store=default&include_sources=1'
curl 'https://example.com/amidafeed/v1/attributes/key/<KEY>?store=default&codes=name,color'
curl 'https://example.com/amidafeed/v1/snapshot/key/<KEY>/stream/attributes?store=default&codes=name,color'
```

Check specifically:

1. `source_map` is absent by default.
2. `source_map` appears only after `allow_include_sources=1`.
3. `description` is `string|null` and HTML is stripped.
4. `sitemap.entries[]` does not contain `source`.
5. `sitemap.entries[].description` exists as `string|null` when available.
6. Admin `pages_json` can add/override/hide pages.
7. Adapter pages are accepted only after URL/text normalization.
8. Counts do not use search indexes or product collections.
9. Attributes endpoint returns EAV dictionary metadata without loading products.

## 7. Main changed/added files

```text
Controller/V1/Store.php
Controller/V1/Attributes.php
Controller/V1/Snapshot.php
Model/Config.php
Model/Config/Source/SitemapMode.php
Model/Config/Source/Streams.php
Model/Store/AttributeDictionaryService.php
Model/Store/Normalizer/SpecialNormalizer.php
Model/Store/Normalizer/TextNormalizer.php
Model/Store/Normalizer/UrlNormalizer.php
Model/Store/ResolvedValue.php
Model/Store/StoreContext.php
Model/Store/StoreMetadataAdapterInterface.php
Model/Store/StoreMetadataAdapterPool.php
Model/Store/StoreMetadataService.php
etc/adminhtml/system.xml
etc/config.xml
Test/Integration/Controller/AbstractFeedControllerTest.php
Test/Integration/Controller/StoreControllerTest.php
Test/Integration/Controller/AttributesControllerTest.php
Test/Unit/Model/Store/Normalizer/*
tools/source_contract_check_store.php
tools/mock_store_metadata_contract.php
docs/SPEC_STORE_ENDPOINT.md
docs/TESTING_STORE_ENDPOINT.md
README.md
```
