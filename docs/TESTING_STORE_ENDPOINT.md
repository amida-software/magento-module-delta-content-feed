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
curl 'https://example.com/amidafeed/v1/attributes/key/<KEY>?store=default&codes=name,color'
```

Compatibility route:

```bash
curl 'https://example.com/amidafeed/v1/snapshot/key/<KEY>/stream/attributes?store=default&codes=name,color'
```

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
