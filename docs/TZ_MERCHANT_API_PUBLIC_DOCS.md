# ТЗ: публичная документация Merchant API

Дата: 2026-05-19

Документ фиксирует отдельный рабочий этап по публичной документации Merchant API. Он нужен как локальный execution brief, чтобы не потерять ни один уже реализованный endpoint и не смешать documentation/public surface с дальнейшей бизнес-логикой.

## 1. Цель этапа

Поднять публичную документацию Merchant API для уже работающего `v1`:

- `GET /merchant/me`
- `GET /merchant/balances`
- `GET /merchant/rates`
- `GET /merchant/orders`
- `GET /merchant/orders/{id}`
- `GET /merchant/payouts`
- `GET /merchant/payouts/{id}`
- `POST /merchant/invoices`

Публичная документация должна:

- быть доступной без WordPress-логина;
- использовать один OpenAPI source of truth;
- показывать оба Kanyon-контура для `RUB_USDT`;
- не требовать React или build pipeline;
- не создавать ручной HTML-список хендлеров.

## 2. Что входит в поставку

### 2.1 Контракт

- файл `docs/api/merchant/openapi.yaml`;
- security scheme `Bearer`;
- описание request/response envelopes;
- описание DTO для read и write endpoints;
- примеры ошибок `401`, `403`, `404`, `409`, `422`;
- два примера `POST /merchant/invoices`:
  - `orderAmount / USDT input`;
  - `paymentAmount / RUB input`.

### 2.2 Public surface

- public spec URL;
- helper-ы URL для:
  - docs page;
  - console page;
  - raw spec.

### 2.3 Публичные страницы

- WordPress page `merchant-api` с `Redoc`;
- WordPress page `merchant-api-console` со `Swagger UI`;
- навигация между двумя страницами;
- ссылка на raw spec URL.

### 2.4 Platform wiring

- обе страницы создаются миграцией;
- обе страницы открыты в `includes/force-login.php`;
- templates не требуют backoffice-auth.

## 3. Выбранная реализация

Для текущего этапа chosen implementation такой:

- canonical spec хранится в репозитории как `docs/api/merchant/openapi.yaml`;
- spec отдаётся как public static file URL из темы;
- `Redoc` и `Swagger UI` читают этот же YAML-файл;
- vendor bundles подключаются без build pipeline.

Причина выбора:

- это самый простой и прозрачный способ для текущего WordPress-theme проекта;
- не нужен отдельный PHP transport-layer ради одного spec-файла;
- later upgrade на отдельный route остаётся возможным без смены самих docs pages.

## 4. Что не входит в этот этап

- webhooks;
- rotate key UI;
- webhook config UI;
- новый backend business flow;
- SDK generation;
- Postman collection;
- отдельный developer portal.

## 5. Обязательные страницы и URL

### 5.1 Docs

- slug: `merchant-api`
- рендер: `Redoc`
- назначение: читаемый reference

### 5.2 Console

- slug: `merchant-api-console`
- рендер: `Swagger UI`
- назначение: ручные запросы и try-it-out

### 5.3 Raw spec

- source file: `docs/api/merchant/openapi.yaml`
- должен открываться публично как URL темы

## 6. Обязательные разделы OpenAPI

Spec обязательно должен описывать:

- `info`
- `servers`
- `security`
- `tags`
- `paths`
- `components.schemas`
- `components.securitySchemes`

Минимальные schema groups:

- envelopes:
  - `SuccessEnvelope`
  - `ErrorEnvelope`
- common:
  - `Money`
  - `RequestId`
  - `PaginationMeta`
- auth/profile:
  - `MerchantProfile`
  - `CompanySummary`
  - `ApiClientSummary`
  - `MerchantCapabilities`
- rates:
  - `DirectionRate`
- orders:
  - `OrderSummary`
  - `OrderDetail`
  - `OrderStatusTimeline`
- payouts:
  - `PayoutSummary`
  - `PayoutStatus`
- write:
  - `CreateInvoiceRequest`
  - `CreateInvoiceResponse`

## 7. Особые правила для `RUB_USDT`

В documentation обязательно явно зафиксировать:

- `direction_code` остаётся `RUB_USDT`;
- но `requested_amount.currency_code` зависит от company contour;
- есть два supported modes:
  - `orderAmount`: merchant enters `USDT`, customer pays `RUB`;
  - `paymentAmount`: merchant enters `RUB`, customer pays `RUB`.

Нельзя оставлять это только “между строк”, иначе интегратор снова упрётся в терминологическую путаницу.

## 8. Порядок реализации

1. Обновить roadmap и зафиксировать это ТЗ.
2. Подготовить `openapi.yaml`.
3. Поднять helper-ы URL для docs/console/spec.
4. Создать public page templates.
5. Создать миграцию WordPress pages.
6. Добавить slug'и в force-login allowlist.
7. Задеплоить.
8. Прогнать smoke:
   - `200 OK` на `merchant-api`;
   - `200 OK` на `merchant-api-console`;
   - `200 OK` на raw spec URL;
   - отсутствие redirect на `/authorization`.

## 9. Definition of Done

Этап считается завершённым только если:

- `openapi.yaml` лежит в репозитории;
- docs и console pages созданы миграцией;
- обе страницы публичные;
- raw spec открывается публично;
- `Redoc` и `Swagger UI` используют один и тот же spec;
- в spec присутствуют оба режима `RUB_USDT`;
- документация покрывает все текущие v1 endpoints;
- нет ручной статической простыни с хендлерами вместо OpenAPI-driven pages.
