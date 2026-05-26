# Store metadata endpoint and attributes dictionary

**Module:** `Amida_ProductDeltaFeed`  
**Status:** implemented draft  
**Schema version:** `1`  
**Added endpoints:**

- `GET /amidafeed/v1/store/key/<KEY>?store=<STORE>`
- `GET /amidafeed/v1/attributes/key/<KEY>?store=<STORE>&codes=color,size`
- `POST /amidafeed/v1/attributes/key/<KEY>/by-code?store=<STORE>` with JSON body `{"codes":[...]}`
- compatibility: `GET /amidafeed/v1/snapshot/key/<KEY>/stream/attributes?store=<STORE>&codes=color`

## 1. Store endpoint purpose

The `store` endpoint returns a compact operational passport for StoreAgent: store identity, languages, currency, counts, contacts, countries, addresses, pages, sitemap summary and diagnostics.

It is not a product stream and does not replace full XML sitemap export.

## 2. Resolution priority

```text
AutoResolver -> SiteAdapter -> AdminOverride -> Validator/Normalizer -> Response
```

Effective priority for overrideable fields:

```text
admin_override > site_adapter > auto > fallback/null
```

Runtime truth fields are not overrideable: `store.id`, `store.code`, `website_id`, `group_id`, real currency and counts.

## 3. Admin configuration

Config group:

```text
amida_productdeltafeed/store_metadata/*
```

Important fields:

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

JSON fields are intentionally simple in this implementation. They can later be replaced by admin grids/repeaters without changing the public API.

## 4. Query parameters

| Parameter | Default | Notes |
|---|---:|---|
| `store` | default store | requested store code |
| `scope` | `group` | `store`, `group`, `website`, `all` |
| `include_pages` | `1` | include `pages[]` |
| `include_counts` | `1` | direct SQL counts; can be disabled |
| `include_sitemap` | `1` | include compact sitemap summary |
| `sitemap_mode` | config/default `summary` | `summary`, `full`; product entries are not emitted by default |
| `sitemap_limit` | config/default `1000` | maximum entries per language |
| `include_sources` | `0` | returns `source_map` only when config allows it |

## 5. Response shape

```json
{
  "schema_version": 1,
  "entity": "store",
  "generated_at": "2026-05-26T12:30:00+00:00",
  "requested_store_code": "default",
  "main_store_code": "default",
  "store": {},
  "languages": [],
  "currency": {},
  "counts": {},
  "contacts": {},
  "countries": [],
  "addresses": [],
  "pages": [],
  "sitemap": {},
  "diagnostics": []
}
```

When `include_sources=1` and `allow_include_sources=1`, the response also contains:

```json
{
  "source_map": {
    "store.description": {
      "source": "admin_override",
      "provider": "admin_config",
      "path": "amida_productdeltafeed/store_metadata/description_override",
      "confidence": 1
    }
  }
}
```

`source` is deliberately not embedded in public `pages[]` or `sitemap.entries[]`.

## 6. Sitemap entry

```json
{
  "type": "page",
  "title": "Delivery",
  "description": "Delivery terms and shipping options.",
  "url": "https://example.com/delivery",
  "special": ["delivery"]
}
```

Allowed `special[]` values include:

```text
index, blog, news, faq, offline_stores, contacts, public_offer, policy,
privacy_policy, terms, about, brands, catalog, sitemap, vacancy, delivery,
payment, promos, loyalty, landing, claims, returns, warranty, checkout,
cart, account, login, register, compare, wishlist, custom
```

Aliases are normalized, including:

```text
devilery -> delivery
loyality -> loyalty
privacy -> privacy_policy
offer/oferta -> public_offer
stores/shops -> offline_stores
```

## 7. Descriptions

`description` is optional and is always `string|null`.

The module never generates descriptions with an LLM. Sources are:

1. admin override;
2. site adapter;
3. CMS meta description;
4. plain-text CMS content excerpt;
5. category description;
6. `null` plus diagnostic when missing.

HTML, scripts and styles are stripped. Whitespace is collapsed. Descriptions are bounded.

## 8. Auto-generated data

The default resolver uses Magento data only:

- active store views;
- locale and language code;
- base/home URLs;
- currency config;
- logo config;
- CMS pages;
- sitemap table fallback;
- category EAV data;
- direct SQL product/category counts;
- store information config and transactional email identities.

No product collections are used for counts. No search index is used for counts. No external network calls are made in the default path.

## 9. Site adapters

A custom site may register adapters through DI:

```xml
<type name="Amida\ProductDeltaFeed\Model\Store\StoreMetadataAdapterPool">
    <arguments>
        <argument name="adapters" xsi:type="array">
            <item name="demo_site" xsi:type="object">Vendor\Module\Model\Adapter\DemoSiteStoreAdapter</item>
        </argument>
    </arguments>
</type>
```

Adapter interface:

```php
interface StoreMetadataAdapterInterface
{
    public function getId(): string;
    public function supports(StoreContext $context): bool;
    public function resolveStore(StoreContext $context): array;
    public function resolveLanguages(StoreContext $context): array;
    public function resolveContacts(StoreContext $context): array;
    public function resolveAddresses(StoreContext $context): array;
    public function resolvePages(StoreContext $context): array;
    public function resolveSitemap(StoreContext $context): array;
    public function resolveAttributeMetadata(StoreContext $context, array $attributeCodes): array;
}
```

Adapter output is treated as untrusted input and normalized by the core module.

## 10. Attributes dictionary endpoint

The endpoint returns attribute metadata, not product values.

```json
{
  "schema_version": 1,
  "entity": "attributes",
  "store_code": "default",
  "items": [
    {
      "code": "color",
      "label": "Color",
      "kind": "select",
      "unit": null,
      "is_filterable": true,
      "is_searchable": true,
      "is_visible": true,
      "is_visible_on_front": false,
      "is_required": false,
      "options": [
        {"value": "12", "label": "Black"}
      ]
    }
  ],
  "diagnostics": []
}
```

Auto source uses direct SQL over:

- `eav_entity_type`
- `eav_attribute`
- `catalog_eav_attribute`
- `eav_attribute_label`
- `eav_attribute_option`
- `eav_attribute_option_value`

Admin enrichment is read from `attribute_metadata_json`, keyed by attribute code.

## 11. Security

- Existing public key is required.
- `source_map` is disabled unless explicitly enabled.
- Unsupported URL schemes are rejected.
- Disabled CMS pages are not exported automatically.
- Internal `_source` markers are stripped from public page objects.

## 12. Non-goals

- No LLM description generation.
- No external crawling.
- No full blog/news export.
- No default product entries in sitemap summary.
- No search-index based counts.
