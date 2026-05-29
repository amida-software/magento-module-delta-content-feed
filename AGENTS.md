# AGENTS.md — ProductDeltaFeed Codex Web workflow

This repository is the source of truth for `Amida_ProductDeltaFeed`.

## Repositories

- Source module repo: `amida-software/magento-module-delta-content-feed`, default branch `main`.
- Deployment repo: `amida-software/jan2`, default branch `amida/ecommbot`.
- Deployed module path in `jan2`: `app/code/Amida/ProductDeltaFeed`.

## Required workflow

1. Develop module changes in this repository, not in the vendored `jan2` copy.
2. Start work from the remote `main` branch and use a feature branch/PR when possible.
3. Do not use local Docker Desktop, local Windows Magento, or local MariaDB for validation.
4. For endpoint or payload changes, measure the current Railway production endpoint before changes and record timings in `docs/delta/`.
5. Add or update module tests/docs in this repository.
6. After merge/push to `main`, sync the repository contents into `jan2/app/code/Amida/ProductDeltaFeed` in a separate `jan2` commit.
7. Validate via Railway branch/preview deployment first when possible, then production after the `jan2` auto-deploy completes.

## Testing policy

- Prefer static checks and lightweight mock/contract tools inside Codex Web.
- Magento integration and DB-backed smoke tests must run against Railway, not a local database.
- Never commit secrets, feed keys, `auth.json`, production dumps, or generated Magento caches.
- For API smoke tests, hide feed keys in logs and chat.

See `docs/CODEX_WEB_RAILWAY_WORKFLOW.md` for the full handoff process.