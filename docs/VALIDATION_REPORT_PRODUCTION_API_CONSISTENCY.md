# Report: Magento ProductDeltaFeed production API consistency

**Artifact:** `magento-product-delta-feed-production-api-consistency.zip`  
**Scope:** implemented methods only: `attributes`, `snapshot`, `changes`, `store`, `health`, `stats`.

## 1. Initial audit findings

The current module was functional but not fully consistent with the new API rules:

1. `store`, `attributes`, `health`, and `stats` were effectively JSON-only.
2. `snapshot` and `changes` had protobuf/JSON support, but only through a feed-specific mechanism.
3. Missing `store` was treated as default store instead of all-store/multilingual mode.
4. OpenAPI still exposed legacy streams: `seo`, `price`, `availability`.
5. Admin stream config still exposed legacy stream toggles.
6. Product state still contained separate low-level `seo`, `price`, `availability` paths in old documentation and some selection logic.
7. Initial sync algorithm was not written clearly enough for a new store baseline + changes handoff.
8. `has_more` could be correct in headers while the encoded body could keep the trial meta value from the last accepted item. This was fixed by final envelope re-encoding.

## 2. Implemented changes

### 2.1 Unified JSON/protobuf transport

Added:

```text
Model/Feed/OpenApiDocumentEncoder.php
```

Added protobuf schema:

```proto
message OpenApiDocument {
  uint32 schema_version = 1;
  string entity = 2;
  string openapi_path = 3;
  string schema_ref = 4;
  string content_type = 5;
  bytes json_payload = 6;
  string payload_hash = 7;
  string generated_at = 8;
}
```

Updated controller base:

```text
Controller/V1/AbstractFeedAction.php
```

New behavior:

```text
?format=json      -> application/json
?format=protobuf  -> application/x-protobuf
Accept header is also respected.
```

For OpenAPI-document endpoints:

```text
store, attributes, health, stats -> OpenApiDocument protobuf wrapper
```

For feed endpoints:

```text
snapshot, changes -> dedicated feed protobuf envelopes
```

### 2.2 Optional `store`

Changed controllers to distinguish:

```text
store omitted       => all-store / multilingual mode
store=<code> valid  => single store mode
store=<bad>         => 400 Invalid store code
```

Updated:

```text
Controller/V1/AbstractFeedAction.php
Controller/V1/Snapshot.php
Controller/V1/Changes.php
Controller/V1/Store.php
Controller/V1/Attributes.php
```

All-store feed responses now include headers:

```text
X-Amida-Store: *
X-Amida-Store-Scope: all
```

Item-level rows keep their real `store_code` instead of `*`.

### 2.3 Content absorbs SEO

Updated:

```text
Model/AttributeSelector.php
```

SEO text fields are now part of content:

```text
name
url_key
description
short_description
meta_title
meta_description
meta_keyword
```

The content stream no longer automatically excludes SEO attributes.

### 2.4 Removed public standalone `seo`, `price`, `availability` streams

Updated:

```text
Model/Config.php
Model/Config/Source/Streams.php
etc/adminhtml/system.xml
etc/config.xml
Model/State/SnapshotRebuilder.php
Model/Change/ChangeProcessor.php
```

Production public streams are now:

```text
content
category
categories
curated
offer
attributes
all
```

`price` and `availability` changes are routed into `offer`.

### 2.5 Offer projection

Added optional query filter:

```text
offer_parts=price
offer_parts=availability
offer_parts=price,availability
```

Supported in:

```text
SnapshotService
ChangesService
```

Works for:

```text
stream=offer
include_offer=1 on product streams
```

If `offer_parts` is omitted, full offer is returned.

### 2.6 Snapshot high-water handoff

Added optional high-water pinning:

```text
changes_highwater_event_id=<H0>
snapshot_highwater_event_id=<H0>  // alias accepted by controller
```

Updated:

```text
SnapshotService
CategorySnapshotService
Controller/V1/Snapshot.php
```

New-store initial sync algorithm is now documented in:

```text
docs/TZ_PRODUCTION_API_CONSISTENCY.md
```

Algorithm:

1. Request first snapshot page with `after_state_id=0`.
2. Store `changes_highwater_event_id` as `H0`.
3. Continue snapshot pages with `after_state_id=<to_state_id>&changes_highwater_event_id=<H0>`.
4. Stop when `has_more=false`.
5. Start changes from `after_event_id=H0`.

For price/stock, use the same algorithm with `stream=offer`.

### 2.7 Fixed final `has_more` encoding

Updated services now re-encode the final envelope after final `has_more` is computed:

```text
SnapshotService
ChangesService
CategorySnapshotService
CategoryChangesService
```

This keeps JSON/protobuf body metadata consistent with response headers.

### 2.8 OpenAPI and documentation

Updated:

```text
docs/openapi.yaml
docs/TZ_PRODUCTION_API_CONSISTENCY.md
docs/SPEC.md
docs/TECHNICAL.md
docs/PROTOBUF_SCHEMA.md
docs/SPEC_OFFER_CATEGORIES_SQL.md
```

`docs/openapi.yaml` now documents:

- JSON + protobuf for every public method;
- optional `store` semantics;
- production stream enum without `seo`, `price`, `availability`;
- `offer_parts`;
- OpenApiDocument protobuf wrapper;
- initial snapshot high-water pin.

### 2.9 Tests and mock/static checks

Added:

```text
Model/Feed/OpenApiDocumentEncoder.php
Test/Unit/Model/Feed/OpenApiDocumentEncoderTest.php
tools/mock_api_methods_openapi_contract.php
```

Updated existing tests:

```text
Test/Unit/Model/Feed/SnapshotServiceTest.php
Test/Unit/Model/Feed/ChangesServiceTest.php
Test/Integration/Controller/StoreControllerTest.php
Test/Integration/Controller/AttributesControllerTest.php
```

## 3. Verification run in sandbox

Executed:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
php tools/source_contract_check.php
php tools/source_contract_check_store.php
php tools/mock_offer_math_test.php
php tools/mock_offer_category_smoke.php
php tools/mock_store_metadata_contract.php
php tools/mock_attribute_dictionary_contract.php
php tools/proto_encoder_parity.php
php tools/mock_api_methods_openapi_contract.php
php tools/smoke.php
python - <<'PY'
import xml.etree.ElementTree as ET
for path in [
  'etc/adminhtml/system.xml','etc/config.xml','etc/db_schema.xml','etc/di.xml',
  'etc/frontend/routes.xml','etc/module.xml','etc/acl.xml','etc/events.xml','etc/crontab.xml'
]:
    ET.parse(path)
    print(path, 'OK')
PY
```

Result:

```text
All PHP files: syntax OK
Source contract OK
Store source contract OK
OfferMath mock OK
OK: mock offer/category smoke checks passed
Store metadata mock contract OK
Attribute dictionary contract OK
Proto/encoder parity OK
OpenAPI/mock method contract OK
Smoke OK
XML parse OK
```

## 4. Not executed in sandbox

The sandbox does not include a full Magento runtime, Composer vendor tree, or test database, so these must be run by the integration agent:

```bash
bin/magento setup:upgrade
bin/magento setup:di:compile
vendor/bin/phpunit Test/Unit
vendor/bin/phpunit Test/Integration
bin/magento amida:productdeltafeed:process-dirty
bin/magento amida:productdeltafeed:snapshot-rebuild
```

## 5. Agent verification checklist

1. Confirm DI compilation after adding `OpenApiDocumentEncoder` constructor dependency to all controllers.
2. Confirm route responses:

```bash
curl -i "$BASE/amidafeed/v1/store/key/$KEY?format=json"
curl -i "$BASE/amidafeed/v1/store/key/$KEY?format=protobuf"
curl -i "$BASE/amidafeed/v1/attributes/key/$KEY?format=json"
curl -i "$BASE/amidafeed/v1/attributes/key/$KEY?format=protobuf"
curl -i "$BASE/amidafeed/v1/health/key/$KEY?format=protobuf"
curl -i "$BASE/amidafeed/v1/stats/key/$KEY?format=protobuf"
```

3. Confirm feed route behavior:

```bash
curl -i "$BASE/amidafeed/v1/snapshot/key/$KEY/stream/content?format=json"
curl -i "$BASE/amidafeed/v1/snapshot/key/$KEY/stream/content?format=protobuf"
curl -i "$BASE/amidafeed/v1/changes/key/$KEY/stream/offer?after_event_id=0&format=json"
curl -i "$BASE/amidafeed/v1/changes/key/$KEY/stream/offer?after_event_id=0&format=protobuf"
```

4. Confirm removed streams return 404:

```bash
curl -i "$BASE/amidafeed/v1/snapshot/key/$KEY/stream/seo?format=json"
curl -i "$BASE/amidafeed/v1/snapshot/key/$KEY/stream/price?format=json"
curl -i "$BASE/amidafeed/v1/snapshot/key/$KEY/stream/availability?format=json"
```

5. Confirm all-store mode without `store`:

```bash
curl -i "$BASE/amidafeed/v1/snapshot/key/$KEY/stream/content?format=json"
```

Expected headers:

```text
X-Amida-Store: *
X-Amida-Store-Scope: all
```

6. Confirm `offer_parts` projection:

```bash
curl -i "$BASE/amidafeed/v1/snapshot/key/$KEY/stream/offer?offer_parts=price&format=json"
curl -i "$BASE/amidafeed/v1/snapshot/key/$KEY/stream/offer?offer_parts=availability&format=json"
```

7. Confirm initial sync handoff:

```bash
# first page: capture H0 = changes_highwater_event_id
# next pages: pass changes_highwater_event_id=H0
# changes: after_event_id=H0
```

## 6. Honest limitations

- The protobuf wrapper for OpenAPI-document endpoints intentionally carries canonical JSON bytes instead of duplicating every OpenAPI schema as nested protobuf messages. This keeps schema drift low and makes every OpenAPI method automatically protobuf-capable.
- `offer` remains a direct-SQL baseline from source tables. It is not a full cart quote simulation with customer groups, tax, coupons, third-party price plugins, or configurable custom option pricing.
- Existing historical docs still contain old design sections, but they now begin with a current production API note pointing to the new canonical spec and OpenAPI contract.
