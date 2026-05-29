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

The endpoint returns attribute metadata, not product values. Schema v2 is the default response shape for `/attributes` and `/snapshot/.../stream/attributes`; schema v1 is legacy and is returned only when `schema=v1` is explicitly requested. `load_options` defaults to true. `load_options=false` may be sent as a JSON boolean false, numeric `0`, or string `"0"`, `"false"`, `"no"`, or `"off"`; when disabled, attributes omit `options[]` everywhere and selectable attributes that have options expose `options_count` from a lightweight option count query.

Default schema v2 example:

```json
{
  "schema_version": 2,
  "entity": "attributes",
  "store_code": "default",
  "attributes": {
    "93": {
      "id": 93,
      "code": "color",
      "label": "Colour",
      "labels": {"ua": "Колір", "ru": "Цвет", "default": "Colour"},
      "admin_label": "Color",
      "kind": "select",
      "unit": null,
      "is_filterable": true,
      "is_searchable": true,
      "is_visible": true,
      "is_visible_on_front": false,
      "is_required": false,
      "options": [
        {"value": "12", "label": "Black", "labels": {"ua": "Чорний", "ru": "Черный", "default": "Black"}}
      ]
    }
  },
  "attribute_sets": [
    {
      "id": 4,
      "name": "Default",
      "groups": [
        {"id": 7, "name": "Product Details", "attribute_ids": [93]}
      ]
    }
  ],
  "product_types": [
    {"code": "simple", "label": "Simple Product", "attribute_ids": [93]}
  ],
  "diagnostics": []
}
```

With `load_options=0`, selectable attributes look like:

```json
{
  "id": 93,
  "code": "color",
  "kind": "select",
  "options_count": 24
}
```

Legacy schema v1 (`schema=v1`) retains `items[]`, `product_types[].attribute_codes`, `product_count`, and `attribute_sets[].groups[].attribute_codes` for backward compatibility. New consumers should use schema v2; there is no default `items[]` in schema v2. Top-level `attributes` is a JSON object keyed by stringified attribute id because JSON object keys are strings, while relation arrays (`product_types[].attribute_ids` and `attribute_sets[].groups[].attribute_ids`) contain numeric JSON numbers. Schema v2 relation nodes intentionally contain only IDs and display labels/names, not embedded attribute objects or product counts.

Filtering rules:

- `product_types` contains product type codes that are present on products assigned to the requested store website and relations computed from `catalog_eav_attribute.apply_to` (`NULL`/empty means all product types).
- `attribute_sets` contains only product attribute sets that are used by at least one product on the requested store website.
- `attribute_sets[].groups[]` contains only groups that contain attributes included in the response.
- Attributes contain only catalog-product EAV attributes that are assigned to one of those used attribute sets/groups and have at least one non-empty product value in the admin store or any active store view.
- `attributes.*.labels` and `options[].labels` are keyed by Magento store code for every non-empty localized label available in `eav_attribute_label` / `eav_attribute_option_value`. `admin_label` is emitted only when the admin label is non-empty and differs from the localized labels.
- `codes` still limits attributes by attribute code, after normalization and endpoint GET/POST limits.
- Numeric zero is treated as a non-empty product value; null and blank text values are excluded.

`format=json` on snapshot and changes endpoints returns `Content-Type: application/json` and a JSON envelope instead of protobuf bytes for product streams and category streams, including empty and cursor-expired responses.

Auto source uses direct SQL over:

- `catalog_product_entity`
- `catalog_product_website`
- `eav_entity_type`
- `eav_attribute`
- `eav_entity_attribute`
- `eav_attribute_group`
- `eav_attribute_set`
- `catalog_eav_attribute`
- `eav_attribute_label`
- `eav_attribute_option`
- `eav_attribute_option_value`
- `catalog_product_entity_{varchar,int,text,decimal,datetime}`

Admin enrichment is read from `attribute_metadata_json`, keyed by attribute code. Railway validation should compare before/after endpoint timing because the dictionary now performs value-existence checks across EAV value tables.

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
