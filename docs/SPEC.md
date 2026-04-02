# ТЗ: Magento-модуль публичной инкрементальной выгрузки товаров в Protobuf+ZSTD

**Дата:** 2026-03-30  
**Статус:** рабочее ТЗ / MVP+  
**Язык:** RU  
**Назначение:** модуль для Magento Open Source / Adobe Commerce (PaaS/on-prem), который отдает инкрементальные изменения товарного каталога наружу в бинарном формате `protobuf`, сжатом `zstd`, без классической авторизации, через уникальные URL-ключи фида.

---

## 1. Цель

Сделать для Magento модуль, который:

1. Надежно отдает **дельты** по товарам, а не полный каталог на каждый запрос.
2. Поддерживает **несколько stream-ов** изменений:
   - `content`
   - `seo`
   - `price`
   - `availability`
   - `category`
   - `all` (объединенный поток)
3. Позволяет **настраивать, какие характеристики экспортируются**.
4. Умеет корректно обрабатывать:
   - частые текстовые правки,
   - смену категории,
   - выключение/включение товара,
   - store-view / locale scoped поля,
   - одинаковые timestamp у нескольких изменений,
   - большие описания и batch splitting.
5. Может быть **отключен** без удаления кода.
6. Имеет **обязательные автотесты через моки** и **обязательные API-тесты**.

---

## 2. Что делаем, а что нет

### 2.1. Входит в MVP

- Публичная pull-выгрузка дельт по товарам.
- Собственный внутренний changelog модуля.
- Пакетная отдача в `protobuf` + `zstd`.
- Настройка allowlist атрибутов для `content` stream.
- Отдельные stream-ы для `seo`, `price`, `availability`, `category`.
- Надежная обработка `disable -> status only`, `enable -> full replay`.
- Начальный full snapshot.
- Ротация уникальных URL-ключей.
- Диагностика и dead-letter для проблемных записей.

### 2.2. Не входит в MVP

- Push/webhook доставка наружу.
- Полноценный гарантированный exactly-once transport между Magento и внешней системой.
- Полный расчет финальной витринной цены с учетом всех скидок, customer groups и catalog price rules во всех вариантах.
- Полная медиа-репликация изображений и галереи.
- Реализация через стандартный Magento REST JSON endpoint как основной transport.

> Причина последнего пункта: здесь нужен легковесный binary export feed, а не обычный JSON/SOAP Web API.

---

## 3. Главный архитектурный выбор

### 3.1. Не использовать `updated_at` как единственный источник правды

**Запрещено** строить надежность модуля только на схеме:

- взять товары по `updated_at > last_ts`
- отсортировать
- отдать пачку
- запомнить последнюю дату

Это слишком хрупко. На такой схеме теряются кейсы:

- несколько изменений в одну секунду,
- category-only изменения,
- store-view scoped обновления,
- коллизии на одинаковом `updated_at`,
- отключение товара без желаемой логики экспорта.

### 3.2. Правильная схема

Модуль должен иметь **собственный append-only changelog** с монотонным внутренним курсором:

- `event_id BIGINT AUTO_INCREMENT` — главный курсор
- `created_at` — время фиксации события в модуле
- `source_updated_at` — оригинальный `updated_at` из Magento, если применимо

**Клиентский курсор = `event_id`, а не timestamp.**

`updated_at` остается полезным как поле данных и для диагностики, но не как единственный механизм надежности.

---

## 4. Высокоуровневая схема работы

```text
Magento events / observers / plugins
    -> Change Collector
    -> Diff Resolver
    -> Stream Router
    -> Internal Change Log (append-only)
    -> Snapshot State Cache
    -> Public Feed Controller
    -> Protobuf serialization
    -> ZSTD compression
    -> External collector
```

### 4.1. Поток обработки

1. В Magento происходит изменение товара / цены / наличия / категории / статуса.
2. Модуль ловит событие или вызов сервиса.
3. Change Collector определяет, какие stream-ы затронуты.
4. Diff Resolver вычисляет changed fields.
5. Записывается одно или несколько событий в changelog.
6. Endpoint по курсору читает changelog, собирает batch, сериализует в protobuf, сжимает zstd и отдает наружу.

---

## 5. Совместимость

### 5.1. Целевая платформа

- Magento Open Source 2.4.x
- Adobe Commerce PaaS / on-prem 2.4.x

### 5.2. Не целимся в Cloud Service SaaS как primary target

MVP проектируется под классический Magento/Adobe Commerce модуль, где у нас есть доступ к коду, observers, storage и маршрутам.

### 5.3. Inventory

Поддержать оба режима:

- MSI (Multi-Source Inventory)
- Legacy stock

Через адаптер `InventoryProviderInterface`.

---

## 6. Streams

## 6.1. Обязательные streams

### `content`
Все выбранные товарные характеристики, **кроме**:

- SEO-полей
- цены
- наличия
- category relations
- lifecycle-only полей

### `seo`
Отдельный поток текстово-SEO данных.

### `price`
Отдельный поток ценовых данных.

### `availability`
Отдельный поток складской доступности / salability.

### `category`
Отдельный поток category assignments.

### `all`
Объединенный поток всех событий в единой очередности по `event_id`.

> `all` нужен как главный надежный поток. Отдельные stream-ы нужны для downstream-разделения и независимой обработки.

---

## 7. Настройка атрибутов

## 7.1. Общее правило

Модуль обязан поддерживать **конфигурируемый allowlist** атрибутов для `content` stream.

### Поведение по умолчанию

Экспортировать:

- **все экспортируемые товарные характеристики**,
- **минус** SEO,
- **минус** цена,
- **минус** наличие,
- **минус** category relations,
- **минус** внутренние системные поля модуля.

## 7.2. Отдельные списки по умолчанию

### SEO stream default fields

```text
name
url_key
description
short_description
meta_title
meta_description
meta_keyword
```

### Price stream default fields

```text
price
special_price
special_from_date
special_to_date
tier_price (optional)
group_price (optional)
```

### Availability stream default fields

```text
is_in_stock
is_salable
qty
manage_stock
backorders
stock_status
```

### Category stream default fields

```text
category_ids
category_positions
```

## 7.3. Конфиг модуля

Пример логического конфига:

```yaml
feed:
  enabled: true
  route_enabled: true
  key_mode: generated_url_key
  zstd_enabled: true
  zstd_level: 3
  max_batch_size_bytes: 2097152
  hard_single_item_limit_bytes: 4194304
  retention_days: 30
  stores: [default, uk, ru]

streams:
  all: true
  content: true
  seo: true
  price: true
  availability: true
  category: true

content:
  attribute_mode: auto_all_minus_excluded
  include_attributes: []
  exclude_attributes:
    - price
    - special_price
    - special_from_date
    - special_to_date
    - status
    - url_key
    - name
    - description
    - short_description
    - meta_title
    - meta_description
    - meta_keyword
    - category_ids

seo:
  include_attributes:
    - name
    - url_key
    - description
    - short_description
    - meta_title
    - meta_description
    - meta_keyword

price:
  mode: raw_catalog_fields

availability:
  mode: aggregated
  include_source_items: false

category:
  export_positions: true

runtime:
  suppress_changes_while_disabled: true
  reemit_full_state_on_enable: true
  export_deleted_as_tombstone: true
```

---

## 8. Endpoint-дизайн

## 8.1. Transport

- Протокол: HTTP(S)
- Формат ответа: `application/x-protobuf`
- Сжатие: `Content-Encoding: zstd`
- Основной метод чтения: `GET`

## 8.2. Уникальный URL вместо классической авторизации

Поскольку данные публичные, **классическая авторизация не нужна**.

Но чтобы клиенты друг друга не парсили, модуль должен поддерживать **уникальные feed keys**.

Пример:

```text
/amidafeed/v1/changes/key/<feedKey>/stream/all
/amidafeed/v1/changes/key/<feedKey>/stream/content
/amidafeed/v1/snapshot/key/<feedKey>/stream/content
```

### Требования к ключу

- криптографически случайный,
- длина не менее 128 бит энтропии,
- хранится в хеше или в защищенном конфиге,
- умеет ротироваться,
- в логах редактируется / маскируется.

> Важно: это **не security boundary уровня private API**, а механизм изоляции и антислучайного сканирования.

## 8.3. Основные endpoints

### 1. Получение изменений

```text
GET /amidafeed/v1/changes/key/<feedKey>/stream/{stream}?after_event_id=<id>&store=<code>
```

Где `{stream}`:

- `all`
- `content`
- `seo`
- `price`
- `availability`
- `category`

### 2. Начальный snapshot

```text
GET /amidafeed/v1/snapshot/key/<feedKey>/stream/{stream}?after_state_id=<cursor>&store=<code>
```

### 3. Health / diagnostics

```text
GET /amidafeed/v1/health/key/<feedKey>
GET /amidafeed/v1/stats/key/<feedKey>
```

### 8.3.1. Monopoly-request mode

Для публичных `snapshot` и `changes` endpoints добавляется runtime-настройка монопольного запроса.

- по умолчанию режим включен;
- пока один feed request держит lock, следующий ждет не дольше `api_request_timeout_seconds`;
- если lock не освобожден вовремя, запрос дропается с HTTP `429`.

### 8.3.2. Первый запуск без snapshot cursor

Если клиент делает первый `snapshot` запрос с `after_state_id=0`, а state cache еще пуст, модуль сначала перестраивает snapshot cache и затем возвращает товары в том же детерминированном порядке, но без дополнительной фильтрации по прежнему состоянию.

## 8.4. Ответ changes endpoint

Логически ответ содержит:

- `stream`
- `from_event_id`
- `to_event_id`
- `has_more`
- `items[]`
- `diagnostics`

---

## 9. Cursor и порядок

## 9.1. Главный курсор

Для `changes`:

- `after_event_id`

Для `snapshot`:

- отдельный snapshot cursor

## 9.2. Гарантия порядка

События должны отдаваться строго по:

```text
event_id ASC
```

Если есть дополнительная сортировка внутри подготовки batch, она **не может ломать итоговый порядок**.

## 9.3. Поведение при отставании клиента

Если клиент читает слишком старый курсор, который уже вышел за retention window:

- endpoint возвращает `cursor_expired=true`
- клиент обязан перейти в режим `snapshot + changes from new cursor`

---

## 10. Batching

## 10.1. Настройка

Обязательный конфиг:

- `max_batch_size_bytes`
- default = `2_097_152` (2 MB)

## 10.2. Что именно ограничиваем

`max_batch_size_bytes` — это **максимальный размер сжатого response body**.

Поскольку точный compressed size известен только после сериализации и компрессии, batching работает так:

1. собираем кандидатов,
2. сериализуем protobuf,
3. сжимаем zstd,
4. если размер > лимита — делим batch,
5. повторяем до достижения лимита.

## 10.3. Oversize single item

Если один-единственный item не помещается в `max_batch_size_bytes`, модуль обязан:

1. попробовать отдать его **как single-item batch**, если он <= `hard_single_item_limit_bytes`
2. если и это превышено:
   - не молча терять запись,
   - писать событие в `dead_letter` / diagnostics,
   - отдавать отдельную ошибку диагностики,
   - не блокировать весь feed навсегда.

**Запрещено** silently truncate payload.

---

## 11. Протокол изменения товара

## 11.1. Общая модель события

Каждая запись в changelog должна содержать как минимум:

- `event_id`
- `stream`
- `product_id`
- `sku`
- `store_code`
- `event_type`
- `changed_fields[]`
- `source_updated_at`
- `emitted_at`
- `payload_version`
- `payload_hash`

## 11.2. Типы событий

Минимально:

- `UPSERT_PARTIAL`
- `UPSERT_FULL`
- `STATUS_ONLY`
- `CATEGORY_FULL`
- `TOMBSTONE`
- `SNAPSHOT_ITEM`

---

## 12. Специальная логика по статусу товара

Это критический блок.

## 12.1. Если товар выключили

При переходе `enabled -> disabled`:

- наружу передается **только status-only событие**,
- payload содержит минимум:
  - `product_id`
  - `sku`
  - `status=disabled`
  - `event_type=STATUS_ONLY`
- дальнейшие обычные content/seo/price/availability/category изменения **не публикуются**, пока товар выключен.

## 12.2. Если товар остается выключенным

Пока товар выключен:

- module может внутренно отмечать dirty-state,
- но наружу **не должен сыпать обычные дельты**.

## 12.3. Если товар включили обратно

При переходе `disabled -> enabled`:

- модуль обязан сформировать **полный replay текущего состояния**,
- то есть enqueue:
  - `content` full
  - `seo` full
  - `price` full
  - `availability` full
  - `category` full
- даже если часть полей менялась пока товар был выключен.

## 12.4. Причина

Это делает downstream состояние самовосстанавливаемым. Иначе реанимация товара после серии скрытых изменений превращается в лотерею.

---

## 13. Категории

Смена категории должна быть явно предусмотрена и проверена.

## 13.1. Почему category change нельзя оставлять на `updated_at`

Category assignment может происходить через relation-таблицы и отдельные операции, которые логически важны для витрины, но не обязаны надежно лечь в ваш простейший polling по товарному `updated_at`.

## 13.2. Правильное поведение

Любое изменение category relation должно приводить к записи в `category` stream.

### Category payload

Минимально:

- `product_id`
- `sku`
- `store_code` (если нужно store-aware представление)
- `category_ids_full[]` — полный текущий отсортированный набор
- `added_category_ids[]`
- `removed_category_ids[]`
- `positions[]` (опционально)
- `event_type=CATEGORY_FULL`

## 13.3. Почему отдавать полный набор лучше, чем только delta

Потому что:

- downstream проще сделать идемпотентным,
- меньше шанс рассинхрона,
- легче восстановление после пропущенного батча.

---

## 14. Store view / locale

## 14.1. Экспорт должен быть store-aware

Поскольку часть текстов и атрибутов в Magento scoped per store view, каждая запись должна быть привязана к конкретному `store_code`.

## 14.2. Настройка stores

В конфиге задается список store views для экспорта.

## 14.3. Правила

- изменение в store `uk` не должно порождать ложную дельту в `ru`, если значение там не изменилось;
- при snapshot каждый store выгружается явно;
- payload всегда содержит store context.

---

## 15. Типы данных атрибутов

## 15.1. Поддержать типы

- scalar
- text / textarea
- int
- decimal
- bool
- date / datetime
- select
- multiselect

## 15.2. Select / multiselect

Для select/multiselect желательно отдавать:

- `value_raw` — исходные ids / codes
- `value_label` — резолвленные labels для store view

Это сильно снижает downstream-запросы и упрощает нормализацию.

## 15.3. Null semantics

Если значение стало `null` / unset:

- это должно явно кодироваться в protobuf,
- а не исчезать как будто поле просто не пришло.

Иначе невозможно различить:

- поле не изменилось
- поле сброшено в null

---

## 16. Snapshot state cache

Модуль должен хранить последнюю нормализованную экспортную форму товара по stream/store, чтобы:

- уметь делать diff,
- отличать реальные изменения от шумовых save-операций,
- не публиковать пустые дельты,
- корректно reemit full state on enable.

### Пример таблицы

`amida_product_delta_state`

Поля:

- `state_id`
- `entity_id`
- `sku`
- `store_code`
- `stream_code`
- `is_enabled`
- `state_hash`
- `state_json`
- `updated_at`

---

## 17. Internal storage

Минимально нужны таблицы:

### 1. Config value `amida_productdeltafeed/general/public_key`
- публичный feed key
- ротация через CLI / backend model

### 2. `amida_product_delta_event`
- `event_id`
- `stream_code`
- `origin_stream`
- `entity_id`
- `sku`
- `store_code`
- `event_type`
- `schema_version`
- `payload_version`
- `changed_fields_json`
- `payload_json`
- `payload_hash`
- `source_updated_at`
- `created_at`

### 3. `amida_product_delta_state`
- кэш последнего экспортного состояния

### 4. `amida_product_delta_dead_letter`
- несерилизуемые / oversize / проблемные события

### 5. Derived stats endpoint
- отдельная таблица не требуется; `stats` собирается на лету из `amida_product_delta_event`, `amida_product_delta_state`, `amida_product_delta_dirty` и `amida_product_delta_dead_letter`

---

## 18. Где и как ловить изменения

## 18.1. Источники изменений

Минимально поддержать:

- product save / repository save
- product status change
- SEO-полей изменения
- price changes
- stock / salability changes
- category relation changes
- product delete

## 18.2. Реализация

Допустима комбинация:

- observers
- plugins around/after service layer
- cron-based reconciliation fallback

## 18.3. Reconciliation fallback

Даже если основной путь event-driven, должен быть фоновый reconciliation job, который:

- периодически сверяет source state и snapshot state,
- добирает потерянные события,
- не делает полный тяжелый scan на каждом запросе,
- работает как repair layer, а не как основной transport.

---

## 19. Сериализация и компрессия

## 19.1. Формат

Только:

- `Content-Type: application/x-protobuf`
- `Content-Encoding: zstd`

## 19.2. Версионирование protobuf

Обязательны:

- `schema_version`
- `payload_version`
- backward-compatible evolution rules

## 19.3. Требования

- нельзя менять смысл существующих полей без bump версии,
- deprecated поля удаляются только после окна совместимости,
- неизвестные поля у клиента не должны ломать парсинг.

---

## 20. Поведение при удалении товара

Если товар удален:

- модуль должен публиковать `TOMBSTONE` событие,
- downstream по нему удаляет или архивирует запись.

Минимальный payload:

- `product_id`
- `sku`
- `event_type=TOMBSTONE`
- `deleted=true`

---

## 21. Диагностика и эксплуатация

## 21.1. Health endpoint

Должен возвращать:

- `module_enabled`
- `route_enabled`
- `compression_enabled`
- `last_event_id`
- `oldest_retained_event_id`
- `dead_letter_count`
- `snapshot_state_ok`
- `reconciliation_lag`

## 21.2. Metrics

Считать минимум:

- events/sec
- bytes raw / compressed
- avg batch items
- avg compressed batch bytes
- oversize item count
- dead-letter count
- per-stream lag
- per-store lag

## 21.3. Логи

Логировать:

- rotation keys
- snapshot jobs
- reconciliation runs
- oversize items
- serialization errors
- endpoint errors

**Нельзя** логировать feed keys в открытом виде.

---

## 22. Отключение модуля

## 22.1. Runtime disable

Через конфиг:

- `feed.enabled=false`

Поведение:

- endpoint возвращает `503 feed_disabled` или предсказуемый disabled response,
- новые события не публикуются,
- фоновые задачи останавливаются.

## 22.2. Полное отключение Magento module

Должна сохраняться совместимость с обычным `bin/magento module:disable`.

---

## 23. Надежность и инварианты

## 23.1. Инварианты

1. **Порядок событий строго по `event_id`.**
2. **Одинаковый запрос с одинаковым курсором и состоянием changelog должен давать идентичный batch.**
3. **Нельзя silently терять события category change.**
4. **Нельзя silently терять disable/enable lifecycle.**
5. **Нельзя silently обрезать oversized payload.**
6. **Пока товар disabled, обычные публичные дельты не публикуются.**
7. **При re-enable всегда идет full replay текущего состояния.**
8. **Store-scoped изменения не должны протекать в чужой store.**
9. **Если курсор устарел — клиент получает явный сигнал, а не молчаливую дырку.**

---

## 24. Обязательные распространенные кейсы

Ниже список кейсов, которые обязательно должны быть предусмотрены и покрыты.

### 24.1. Контент
- изменение `description`
- изменение `short_description`
- изменение `name`
- null/reset значения
- длинное описание
- несколько изменений подряд до следующего pull

### 24.2. SEO
- смена `url_key`
- смена `meta_title`
- смена `meta_description`
- смена SEO только в одном store view

### 24.3. Атрибуты
- изменение scalar custom attribute
- select attribute
- multiselect attribute
- новый пользовательский attribute code в Magento
- attribute входит/не входит в allowlist

### 24.4. Цена
- изменение `price`
- изменение `special_price`
- изменение периода special price
- несколько ценовых изменений подряд

### 24.5. Availability
- in stock -> out of stock
- out of stock -> in stock
- qty change
- MSI source change
- legacy inventory change

### 24.6. Категории
- добавить категорию
- убрать категорию
- изменить набор категорий без product save
- изменить позицию товара в категории

### 24.7. Lifecycle
- enabled -> disabled
- disabled -> enabled
- disabled + несколько скрытых изменений + enabled
- delete product

### 24.8. Batch / cursor
- identical timestamp у нескольких изменений
- много маленьких записей
- один очень большой товар
- cursor expired
- повторный запрос с тем же курсором
- чтение двумя разными клиентами с разными feed keys

### 24.9. Runtime / ops
- route disabled
- module disabled
- invalid feed key
- rotated feed key
- zstd failure fallback policy
- protobuf schema version mismatch handling

---

## 25. API контракт: логические ответы

## 25.1. Успешный ответ changes

Логически должен содержать:

- `schema_version`
- `stream`
- `after_event_id`
- `last_event_id_in_batch`
- `has_more`
- `compressed_bytes`
- `items[]`

## 25.2. Ошибки

Минимальные коды/режимы:

- `invalid_feed_key`
- `feed_disabled`
- `route_disabled`
- `cursor_expired`
- `unsupported_stream`
- `internal_serialization_error`

В production нельзя светить stacktrace.

---

## 26. Тестирование

Тесты обязательны. Разделять на уровни.

## 26.1. Unit tests (через моки)

Покрыть минимум:

1. `AttributeFilterResolver`
2. `DiffResolver`
3. `StreamRouter`
4. `LifecyclePolicy`
5. `CategoryChangeDetector`
6. `BatchBuilder`
7. `CursorService`
8. `ProtobufSerializer`
9. `ZstdCompressor`
10. `OversizePolicy`
11. `SnapshotStateHasher`
12. `FeedKeyService`

### Обязательные unit кейсы

- выбранные атрибуты корректно попадают в `content`
- SEO не течет в `content`
- цена не течет в `content`
- наличие не течет в `content`
- category change детектится отдельно
- identical timestamp не ломает порядок, потому что курсор event-based
- disable публикует только `STATUS_ONLY`
- enable вызывает `UPSERT_FULL` для всех streams
- null diff детектится правильно
- oversize item уходит в special handling

## 26.2. Integration tests

Проверить:

- установка модуля
- создание таблиц
- конфиг включения/выключения
- сохранение товара
- category relation change
- store-view scoped изменения
- snapshot state cache update
- reconciliation job

## 26.3. API tests — обязательны

Это жесткое требование.

Покрыть минимум:

1. `GET changes/all` отдает protobuf+zstd
2. `GET changes/content` работает
3. `GET snapshot/content` работает
4. invalid feed key -> корректная ошибка
5. disabled feed -> корректная ошибка
6. category change реально попадает в `category` stream
7. disabled product -> only status
8. enabled after disabled -> full replay
9. store-specific SEO изменение приходит только в нужный store
10. batch splitting при превышении лимита
11. repeated same cursor -> deterministic response
12. cursor expired -> explicit signal
13. delete product -> tombstone
14. module off -> predictable API behavior

## 26.4. End-to-end scenario tests

Нужно минимум 3 сценария:

### Сценарий A: обычная жизнь
- создать товар
- получить snapshot
- изменить описание
- изменить цену
- изменить наличие
- проверить 3 stream-а + `all`

### Сценарий B: сложный lifecycle
- выключить товар
- поменять SEO, category, цену, атрибуты пока он выключен
- убедиться, что наружу идет только disable status
- включить товар
- убедиться, что пришел full replay всего текущего состояния

### Сценарий C: category edge case
- изменить только category relation
- не трогать обычные атрибуты
- проверить, что category stream получил событие
- content stream при этом не получил ложную дельту

---

## 27. Производительность

## 27.1. Нельзя делать тяжелый full catalog scan в request path

Endpoint чтения должен работать из:

- changelog
- snapshot cache
- компактных индексируемых таблиц

## 27.2. Индексы

Нужны индексы минимум на:

- `event_id`
- `(stream_code, store_code, event_id)`
- `(entity_id, store_code, stream_code)`
- `created_at`

## 27.3. Batch assembly

Сборка batch должна быть O(k) по количеству реально выдаваемых событий, а не O(N) по всему каталогу.

---

## 28. Безопасность и здравый смысл

Хотя данные публичные, модуль должен:

- скрывать внутренние ошибки в production,
- ротировать feed keys,
- маскировать feed keys в логах,
- иметь rate-limiting / reverse proxy recommendations,
- не позволять directory-style enumeration.

Дополнительно рекомендуется:

- отдавать через CDN / reverse proxy,
- ограничивать доступ по IP опционально,
- включить отдельный host/path namespace для фида.

---

## 29. Рекомендованный технический состав

### Основные классы

- `FeedConfig`
- `FeedKeyService`
- `ChangeCollector`
- `DiffResolver`
- `StreamRouter`
- `LifecyclePolicy`
- `CategoryChangeDetector`
- `SnapshotStateRepository`
- `ChangeLogRepository`
- `BatchBuilder`
- `ProtobufSerializer`
- `ZstdCompressor`
- `FeedController`
- `HealthController`
- `ReconciliationCron`

### Интерфейсы

- `InventoryProviderInterface`
- `PriceProviderInterface`
- `CategoryProviderInterface`
- `AttributeValueNormalizerInterface`
- `CompressorInterface`
- `SerializerInterface`

---

## 30. Итоговое решение

### Что именно считаем правильным решением

Для этого модуля **лучшее надежное решение**:

1. **не использовать `updated_at` как единственный курсор**;
2. сделать **внутренний append-only changelog** с `event_id`;
3. сделать **несколько stream-ов** + объединенный `all`;
4. использовать **protobuf + zstd**;
5. batching делать по **максимальному compressed size**, default 2 MB;
6. category changes отслеживать **отдельно и явно**;
7. `disable -> status only`, `enable -> full replay`;
8. иметь **snapshot + changes**;
9. сделать **моки + unit/integration/API tests обязательными**;
10. модуль должен **отключаться конфигом и штатным Magento способом**.

Это решение заметно надежнее примитивного polling по `updated_at` и закрывает реальные продовые дыры, которые почти гарантированно всплывут в ecommerce.

---

## 31. Критерии приемки

Модуль считается принятым, если:

1. Собирается и устанавливается на целевой Magento 2.4.x.
2. Может быть включен/выключен конфигом.
3. Отдает `protobuf + zstd`.
4. Поддерживает `max_batch_size_bytes=2MB` по умолчанию.
5. Умеет отдельные streams: `content`, `seo`, `price`, `availability`, `category`, `all`.
6. Корректно работает логика disabled/enabled.
7. Category-only изменения не теряются.
8. Есть snapshot endpoint.
9. Есть health endpoint.
10. Есть unit tests через моки.
11. Есть integration tests.
12. Есть обязательные API tests.
13. Нет silent data loss на common cases из раздела 24.

---

## 32. Короткий вывод

Если сделать это ТЗ честно, получится не “еще один JSON endpoint”, а нормальный экспортный модуль каталога:

- устойчивый к реальным изменениям,
- пригодный для частых SEO-правок,
- удобный для downstream ingest,
- без типичной дыры со сменой категорий,
- без лотереи на одинаковых timestamp,
- и без грязной мешанины из всех товарных данных в одном неуправляемом потоке.

Именно так и надо делать такой модуль, если цель — продовая надежность, а не демо на два дня.
