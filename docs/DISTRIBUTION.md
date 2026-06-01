# Module distribution / stop the vendored-copy drift (proposal)

## Problem
The module lives in two places that are edited independently and drift apart:
- canonical upstream repo `amida-software/magento-module-delta-content-feed` (root = module), and
- a vendored copy in the deployment repo `jan2` under `app/code/Amida/ProductDeltaFeed`.

There is no enforced sync, so the two diverged substantially (two parallel attributes-v2
implementations). A naive copy in either direction risks discarding work.

## Options
| Option | Summary | Pros | Cons |
| --- | --- | --- | --- |
| **A. Composer package (recommended)** | Tag releases of the upstream repo and install it in `jan2` via `composer require amida/module-product-delta-feed:^x.y` (path/VCS or a private Packagist/Repman). Drop the `app/code` copy. | Idiomatic Magento distribution; one versioned source of truth; clean upgrades and rollbacks | Needs a registry or a VCS `repositories` entry + auth in `auth.json` |
| B. git subtree | Embed the module as a `git subtree` of upstream with documented `subtree pull/push`. | No registry; explicit commands; history preserved | Easy to misuse; noisy merges; still two trees |
| C. One-way CI sync + drift guard | CI syncs upstream→`jan2` on release and **fails** if the vendored copy diverged from the pinned ref. | Keeps current layout | Does not prevent local edits; still two trees |

## Recommendation
Adopt **A (Composer package)**. Minimal steps:
1. Add a `composer.json` package name/version to the upstream repo (already module-shaped) and tag releases.
2. In `jan2`, add a `repositories` entry (VCS or private registry) and `composer require` the module; remove `app/code/Amida/ProductDeltaFeed`.
3. Add a CI check that fails if `app/code/Amida/ProductDeltaFeed` reappears or differs from the locked package version (belt-and-braces while migrating).

Until A lands, treat **upstream `main` as the source of truth** and apply changes there first, then re-sync `jan2` (the previous `legacy-attributes` branch preserves the superseded attributes implementation).
