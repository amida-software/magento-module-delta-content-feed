# Codex Web + Railway development workflow

## Purpose

`amida-software/magento-module-delta-content-feed` is the canonical source for the Magento module. `amida-software/jan2` only vendors the module for Railway deployment.

This workflow replaces local Docker/Windows/MariaDB testing with Codex Web development and Railway validation.

## Repositories and branches

| Role | Repository | Branch | Path |
| --- | --- | --- | --- |
| Module source | `amida-software/magento-module-delta-content-feed` | `main` | repository root |
| Railway deployment | `amida-software/jan2` | `amida/ecommbot` | `app/code/Amida/ProductDeltaFeed` |

## Module development in Codex Web

1. Open `amida-software/magento-module-delta-content-feed` in Codex Web.
2. Create a feature branch from remote `main`.
3. Implement the module change with tests and documentation.
4. For feed/API changes, add a `docs/delta/<change>.md` entry with:
   - current production endpoint measured before the change;
   - test endpoint/branch used for Railway validation;
   - after timings and payload notes.
5. Run available non-local checks in Codex Web:
   - PHP syntax checks if PHP is available;
   - mock/contract tools under `tools/`;
   - unit tests that do not require local Magento services.
6. Push/merge to `main` only after review and Railway validation plan is clear.

## Sync into `jan2`

After the module repo has the intended commit on `main`:

1. Open `amida-software/jan2` in Codex Web on branch `amida/ecommbot` or a short-lived sync branch.
2. Replace only `app/code/Amida/ProductDeltaFeed` with the module repo contents from the chosen module commit.
3. Do not edit module behavior directly in `jan2` unless it is an emergency hotfix that is immediately backported to the module repo.
4. Commit with a message like:

```text
Sync ProductDeltaFeed module from <module-sha>
```

5. Push the branch and let Railway deploy it.

## Railway validation

Use Railway for Magento/runtime checks:

1. Measure the current production endpoint before deployment when behavior/performance changes.
2. Deploy a `jan2` branch/preview service when possible for smoke testing.
3. Run HTTP checks against Railway URLs only; do not start local Docker/MariaDB.
4. After production auto-deploy, repeat the smoke tests and timings.
5. Record final results in module docs when they are part of the feature acceptance criteria.

## Secrets and safety

- Do not commit feed keys, Railway tokens, Composer auth, DB credentials, dumps, media archives, generated Magento caches, or local `.env` files.
- Redact feed keys in commands, logs, screenshots, and chat.
- Keep module commits and `jan2` sync commits separate so production deploy diffs stay auditable.