# ТЗ: production-grade consistency API для Amida ProductDeltaFeed

**Status:** implemented in this module revision  
**Scope:** только уже реализованные методы: `attributes`, `snapshot`, `changes`, `store`, `health`, `stats`.

## 1. Цель

Привести модуль к консистентному production API, где один OpenAPI-контракт описывает JSON-поверхность, а каждый публичный метод автоматически имеет JSON и protobuf транспорт.

Главный инвариант:

```text
OpenAPI JSON contract = canonical payload semantics
protobuf = binary transport for the same method contract
```

Для feed-методов (`snapshot`, `changes`) остаются нативные protobuf-сообщения. Для методов, которые логически являются OpenAPI/JSON-документами (`store`, `attributes`, `health`, `stats`), используется универсальный protobuf wrapper `OpenApiDocument`.

## 2. Требования

### R1. Все методы доступны в JSON и protobuf

Каждый публичный endpoint должен поддерживать:

```text
?format=json
?format=protobuf
Accept: application/json
Accept: application/x-protobuf
```

Правила по умолчанию:

| Endpoint | Default | protobuf |
|---|---:|---|
| `/snapshot/...` | protobuf | dedicated feed protobuf envelope |
| `/changes/...` | protobuf | dedicated feed protobuf envelope |
| `/attributes/...` | json | `OpenApiDocument` |
| `/store/...` | json | `OpenApiDocument` |
| `/health/...` | json | `OpenApiDocument` |
| `/stats/...` | json | `OpenApiDocument` |

### R2. `store` больше не обязателен

Если `store` указан — endpoint работает по конкретной store view.

Если `store` не указан:

- `snapshot` / `changes` возвращают данные по всем configured storefront store views;
- `store` endpoint возвращает default/root store passport + `languages[]`/sitemap по scope, по умолчанию `all`;
- `attributes` возвращает multilingual labels/options там, где они отличаются, и помечает `store_scope=all`.

Некорректный явно переданный `store` должен давать `400 Invalid store code`.

### R3. Mock и автотесты всех методов на синтетических данных

Добавить mock/static checks без Magento runtime:

- JSON/protobuf wrapper для `store`, `attributes`, `health`, `stats`;
- feed payload contract для `snapshot`/`changes`;
- stream enum без `seo`, `price`, `availability`;
- `offer_parts` projection;
- all-store mode metadata and headers;
- OpenAPI consistency check.

### R4. `seo` и `content` не разделяются

SEO-поля являются content-полями:

```text
name
url_key
description
short_description
meta_title
meta_description
meta_keyword
```

Отдельного public stream `seo` больше нет.

### R5. Отдельные `price` и `availability` убираются

Цены и наличие экспортируются только через `offer`.

`offer_parts` — optional projection:

```text
offer_parts=price
offer_parts=availability
offer_parts=price,availability
```

Если `offer_parts` не указан — возвращается полный offer.

### R6. Алгоритм первичной загрузки нового магазина

Consumer должен использовать двухфазный алгоритм:

1. Первый snapshot-запрос:

```http
GET /amidafeed/v1/snapshot/key/<KEY>/stream/content?after_state_id=0
```

2. Сохранить `changes_highwater_event_id` из первого ответа как `H0`.
3. Продолжать paging snapshot по `to_state_id`:

```http
GET /amidafeed/v1/snapshot/key/<KEY>/stream/content?after_state_id=<last_to_state_id>&changes_highwater_event_id=<H0>
```

4. Повторять пока `has_more=false`.
5. После окончания snapshot читать changes:

```http
GET /amidafeed/v1/changes/key/<KEY>/stream/content?after_event_id=<H0>
```

Так consumer получает полный baseline и не теряет изменения, которые произошли во время первичной выгрузки.

Для цен/наличия используется тот же алгоритм, но stream = `offer`.

### R7. Production API consistency

- OpenAPI должен перечислять только актуальные production streams:

```text
content, category, categories, curated, offer, attributes, all
```

- Старые `seo`, `price`, `availability` не должны быть доступны как public stream.
- Admin stream selector не должен предлагать legacy streams.
- `source` не должен появляться в обычных sitemap/page entries; provenance только через `source_map` при `include_sources=1` и если разрешено конфигом.
- `description` optional в `store`, `pages[]`, `sitemap.entries[]`.

## 3. Solution

### 3.1 Transport layer

Добавлен `OpenApiDocumentEncoder`:

```text
schema_version
entity
openapi_path
schema_ref
content_type=json
json_payload
payload_hash
generated_at
```

Это позволяет любому OpenAPI-документу получить protobuf-transport без ручного дублирования всей schema graph в `.proto`.

### 3.2 Optional store semantics

Контроллеры перешли с `resolveStoreCode()` на:

```text
requestedStoreCode()
resolveRequestedStoreCode()
invalidRequestedStoreResponse()
```

`null` означает all-store mode, а не default store.

### 3.3 Offer projection

`SnapshotService` и `ChangesService` поддерживают `offer_parts` для native `offer` stream и inline `include_offer=1`.

### 3.4 Content stream

`AttributeSelector` включает SEO text fields в content base fields и больше не исключает SEO атрибуты по умолчанию.

### 3.5 Stream lifecycle

`SnapshotRebuilder` и `ChangeProcessor` работают по streams:

```text
content, offer, category, curated
```

`categories` и `attributes` — dictionary streams/endpoints, не per-product state streams.

Price/availability dirty flags маршрутизируются в `offer`.

## 4. Acceptance criteria

1. `GET /store/key/<KEY>?format=json` возвращает JSON.
2. `GET /store/key/<KEY>?format=protobuf` возвращает `application/x-protobuf` + `X-Amida-Protobuf-Message=amida.productdelta.v1.OpenApiDocument`.
3. То же работает для `attributes`, `health`, `stats`.
4. `snapshot`/`changes` поддерживают `format=json` и `format=protobuf`.
5. `stream=seo`, `stream=price`, `stream=availability` не проходят `isStreamEnabled`.
6. `stream=offer&offer_parts=price` возвращает только price part + identity.
7. `stream=offer&offer_parts=availability` возвращает только availability part + identity.
8. Без `store` в snapshot/changes headers содержат:

```text
X-Amida-Store: *
X-Amida-Store-Scope: all
```

9. В item-level payload `store_code` остается конкретным store code строки.
10. OpenAPI stream enum не содержит legacy streams.
11. Mock checks проходят без Magento runtime.

## 5. Non-goals

- Не реализуем новые бизнес-методы за пределами уже существующих endpoints.
- Не делаем cart quote simulation для customer-group-specific price.
- Не строим отдельные SEO/price/availability streams.
- Не генерируем content через LLM внутри Magento-модуля.
