# Installation

## Requirements

- Magento Open Source / Adobe Commerce 2.4.x
- PHP 8.1+
- `ext-zstd` recommended and enabled if compressed responses are required

## Install as local module

```bash
mkdir -p app/code/Amida
cp -R Amida_ProductDeltaFeed app/code/Amida/ProductDeltaFeed
bin/magento module:enable Amida_ProductDeltaFeed
bin/magento setup:upgrade
bin/magento cache:flush
```

## Optional compile

```bash
bin/magento setup:di:compile
```

## First-time setup

1. Go to **Stores -> Configuration -> Catalog -> Amida Product Delta Feed**.
2. Save once with empty **Public feed key** to auto-generate a unique key.
3. Select store views to export, or leave empty to export all active store views.
4. Keep `max_batch_size_bytes=2097152` unless the receiver explicitly needs another limit.
5. Keep **Curated product stream** enabled if the receiver needs a ready-to-import product document instead of joining low-level streams.
6. Optionally run an initial snapshot rebuild (recommended for large catalogs):

```bash
bin/magento amidafeed:snapshot:rebuild
```

7. New runtime defaults:

- `Monopoly request mode = Yes`
- `Monopoly request timeout = 5` seconds

If the snapshot state table is still empty and the client requests `snapshot` with `after_state_id=0` (or without the cursor parameter), the module lazily rebuilds the initial snapshot and returns products in the same deterministic order without additional filtering.

## Useful CLI commands

```bash
bin/magento amidafeed:key:rotate
bin/magento amidafeed:process-dirty
bin/magento amidafeed:snapshot:rebuild
```

## Validation checklist

```bash
curl -I "https://example.com/amidafeed/v1/health/key/<KEY>"
curl --output /tmp/feed.pb --compressed "https://example.com/amidafeed/v1/changes/key/<KEY>/stream/all?after_event_id=0&store=default"
```

> If `zstd_enabled=1` and `ext-zstd` is missing, health will report `compression_enabled=false` and feed endpoints will return `503`.


## Running tests

Unit tests:

```bash
php -f vendor/bin/phpunit -- -c dev/tests/unit/phpunit.xml.dist app/code/Amida/ProductDeltaFeed/Test/Unit
```

Integration/controller tests:

```bash
php -f vendor/bin/phpunit -- -c dev/tests/integration/phpunit.xml.dist app/code/Amida/ProductDeltaFeed/Test/Integration
```

If you want to add REST/SOAP style Web API tests later, Adobe recommends `Magento\TestFramework\TestCase\WebapiAbstract` for public Web API endpoints. This module ships controller-level API tests because the feed uses a custom binary frontend route instead of Magento JSON/SOAP serializers.
