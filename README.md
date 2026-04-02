# Amida_ProductDeltaFeed

Installable Magento 2 module that publishes product deltas over public HTTP endpoints in **Protocol Buffers** with optional **zstd** compression.

## What it does

- Maintains its own append-only product change log.
- Publishes streams: `content`, `seo`, `price`, `availability`, `category`, `all`.
- Uses a monotonic `event_id` cursor.
- Stores normalized per-product per-store per-stream state snapshots.
- Applies special lifecycle rules:
  - disable -> publish `STATUS_ONLY`
  - while disabled -> suppress ordinary deltas
  - enable -> publish full replay for all enabled streams
- Handles category changes explicitly.
- Supports batch size by compressed response bytes.
- Can serialize public `changes` / `snapshot` requests through a configurable monopoly-request lock with timeout.
- Auto-bootstrap for the very first snapshot request when state cache is still empty.
- Includes unit tests and controller-level API tests.

## Endpoints

- `GET /amidafeed/v1/changes/key/<KEY>/stream/<STREAM>?after_event_id=<ID>&store=<CODE>`
- `GET /amidafeed/v1/snapshot/key/<KEY>/stream/<STREAM>?after_state_id=<ID>&store=<CODE>`
- `GET /amidafeed/v1/health/key/<KEY>`
- `GET /amidafeed/v1/stats/key/<KEY>`

## Installation

See [docs/INSTALL.md](docs/INSTALL.md).

## Technical design

See [docs/TECHNICAL.md](docs/TECHNICAL.md).

## Protobuf schema

See [proto/amida_product_delta_feed_v1.proto](proto/amida_product_delta_feed_v1.proto).

## Specification

See [docs/SPEC.md](docs/SPEC.md).
