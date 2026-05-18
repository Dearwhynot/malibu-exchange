# ТЗ / Roadmap: Merchant API и публичная документация

Дата: 2026-05-17

Документ фиксирует целевую архитектуру собственного merchant API для части мерчантов, а также план реализации публичной страницы документации API без ручного написания статических описаний хендлеров.

API должен стать отдельным интеграционным слоем поверх существующего merchant-контура. Он не должен быть "HTTP-версией Telegram-меню", но может использовать его как ориентир по полезным бизнес-функциям.

## 0. Цель

Сделать в проекте собственный merchant API, который:

- доступен только явно разрешённым мерчантам;
- жёстко соблюдает company isolation;
- опирается на существующий merchant/fintech слой, а не дублирует бизнес-логику;
- имеет стабильный внешний контракт;
- сопровождается красивой публичной документацией на базе OpenAPI;
- не требует React, build pipeline или тяжёлого API-gateway.

## 1. Базовые решения

- Merchant API делаем на WordPress REST API, не на `admin-ajax.php`.
- Новый внешний namespace фиксируем как:
  - `/wp-json/malibu/v1/merchant/...`
- Legacy REST-route'ы проекта не переписываем, если они не относятся к merchant API.
- Merchant API строится как отдельный контракт для интеграций, а не как прямое отражение внутренних PHP-структур и не как копия Telegram callback data.
- Merchant API должен быть contour-aware:
  - список доступных направлений определяется company/root contour settings;
  - source of truth для разрешённых invoice directions: `crm_company_get_enabled_invoice_directions($company_id)`;
  - если направление включено root'ом для компании, оно должно попадать в read-модель API;
  - если направление включено, но бизнес-flow для него ещё не реализован в API, endpoint должен возвращать явную ошибку `contour_not_supported`.
- Company isolation является абсолютной:
  - токен должен разрешать доступ только к одному `company_id > 0`;
  - токен должен разрешать доступ только к одному `merchant_id`;
  - `company_id` и `merchant_id` не принимаются от клиента как источник истины;
  - при любой потере scope запрос блокируется с hard-fail.
- Root не является субъектом merchant API и не может быть merchant API client.
- Merchant API create-flow для направления `RUB_USDT` должен поддерживать оба Kanyon-контура компании:
  - если `fintech_pay2day_order_currency = USDT`, используем legacy `orderAmount`:
    merchant input = `USDT`, customer payment = `RUB`, settlement = `USDT`;
  - если `fintech_pay2day_order_currency = RUB`, используем новый `paymentAmount`:
    merchant input = `RUB`, customer payment = `RUB`, settlement = `USDT`.
- В первом delivered step foundation и первый read-layer Merchant API должны покрывать:
  - просмотр ордеров;
  - просмотр балансов;
  - просмотр выплат;
  - просмотр базового merchant profile.
- Для публичной документации source of truth храним в OpenAPI-файле внутри репозитория.
- Документацию не генерируем из PHP автоматически. Для данного стека это создаст хрупкий слой и плохой контроль над схемами.
- Публичную документацию рендерим готовым движком:
  - основной вариант: `Redoc CE`;
  - дополнительный интерактивный вариант: `Swagger UI` как отдельная console-page.
- Внешний API не должен использовать `wp_send_json_success()` как внешний контракт. Для него нужен отдельный, нормальный JSON-формат ответа.
- Все суммы в API отдаем строками, а не PHP float:
  - `"1000.00"`, `"12.34567890"`.
- Все timestamps в API отдаем в ISO 8601 UTC.
- Все enum/status/code значения в API должны быть стабильными и заранее зафиксированными в OpenAPI.

## 2. Что должно уметь v1

Первый production-ready релиз merchant API должен покрывать реальные merchant-задачи, уже существующие в контуре Telegram/backoffice.

### 2.1 Обязательные read endpoints

- `GET /merchant/me`
- `GET /merchant/balances`
- `GET /merchant/rates`
- `GET /merchant/orders`
- `GET /merchant/orders/{id}`
- `GET /merchant/payouts`
- `GET /merchant/payouts/{id}`

### 2.2 Обязательные write endpoints

- `POST /merchant/invoices`

### 2.3 Outgoing webhooks

В текущий MVP не входят.

Если позже вернёмся к webhook-модели, минимальный список событий будет таким:

- `invoice.created`
- `invoice.paid`
- `invoice.expired`
- `invoice.cancelled`
- `payout.created`

### 2.4 Что должен возвращать API пользователю

- профиль мерчанта;
- статус мерчанта;
- компания мерчанта;
- доступные направления invoice creation;
- активные курсы для merchant flow;
- список счетов;
- детали конкретного счета;
- история выплат мерчанту;
- балансы мерчанта;
- результат создания нового счёта;
- QR/payload/payment URL или иные платёжные реквизиты, если они уже есть в текущем Kanyon flow.

## 3. Что не входит в MVP

- мульти-merchant токены;
- root cross-company API;
- HMAC request signing как обязательный протокол авторизации;
- auto-generated SDK;
- отдельный API gateway вне WordPress;
- generic CRUD по всем внутренним таблицам;
- произвольный доступ к данным по `merchant_id` из URL без привязки к текущему токену;
- write-операции по выплатам или merchant profile;
- массовые export endpoints;
- полноценная developer portal система уровня Postman/Stoplight/SwaggerHub.

## 4. Основной бизнес-контур v1

Merchant API не должен выдумывать новый контур. Он должен использовать уже существующие бизнес-правила проекта.

### 4.1 Invoice creation

`POST /merchant/invoices` должен использовать active Kanyon contour компании для направления `RUB_USDT`.

Предпочтительная переиспользуемая функция:

- `crm_fintech_create_order()` в `inc/fintech-orders.php`

Правила:

- API обязан читать список разрешённых направлений из company contour helper;
- если `fintech_pay2day_order_currency = USDT`, create-flow работает как:
  - merchant input currency = `USDT`;
  - customer payment currency = `RUB`;
  - provider mode = `orderAmount`;
- если `fintech_pay2day_order_currency = RUB`, create-flow работает как:
  - merchant input currency = `RUB`;
  - customer payment currency = `RUB`;
  - provider mode = `paymentAmount`;
- если root включил компании другое направление, API должен уметь отразить его в `available_directions`, но не обязан сразу уметь создать по нему invoice;
- если клиент пытается создать invoice по включенному, но ещё не реализованному направлению, нужно вернуть `contour_not_supported`;
- если направление отключено root-ом или компанией, endpoint отвечает отказом;
- если мерчант заблокирован, архивирован или pending, endpoint отвечает отказом;
- если у компании не настроен нужный provider contour, endpoint отвечает отказом;
- если provider не вернул валидный платёжный ответ, API отвечает ошибкой и пишет лог;
- нельзя создавать invoice в `company_id = 0`.

### 4.2 Orders

Orders endpoint должен отражать merchant-only view:

- только ордера текущего мерчанта;
- только ордера текущей компании;
- без доступа к чужим мерчантам той же компании;
- без company/operator order leakage.

### 4.3 Balances

Balances endpoint должен опираться на существующий merchant ledger/balance summary.

Предпочтительная переиспользуемая логика:

- `crm_get_merchant_balance_summary_map()` в `inc/merchants.php`

### 4.4 Rates

Rates endpoint не должен создавать технические payment orders ради простого просмотра курса.

Правила:

- для чтения курса использовать уже существующие рыночные источники и merchant-oriented rate helpers;
- endpoint должен возвращать только те направления, которые реально разрешены мерчанту/компании;
- внутреннюю platform fee и техническую маржу мерчанту не показывать.

### 4.5 Payouts

Payouts endpoint только read-only:

- сумма;
- валюта;
- сеть;
- tx hash;
- explorer URL, если применимо;
- комментарий;
- receipt URL, если доступно и безопасно отдавать.

## 5. Правила внешнего API-контракта

### 5.1 Формат ответов

У внешнего API должен быть единый ответный формат.

Успех:

```json
{
  "request_id": "req_01H...",
  "data": {},
  "meta": {}
}
```

Ошибка:

```json
{
  "request_id": "req_01H...",
  "error": {
    "code": "forbidden",
    "message": "Merchant is blocked."
  }
}
```

### 5.2 Обязательные правила

- не возвращать HTML;
- не возвращать WordPress warning/notice;
- не возвращать сырые SQL/stack traces;
- не использовать русские `status`/`code` значения в machine fields;
- human-readable `message` можно держать на английском для внешнего API;
- request_id должен присутствовать и в ответе, и в логах.

### 5.3 Коды ошибок

Минимальный набор стабильных error codes:

- `unauthorized`
- `forbidden`
- `merchant_blocked`
- `merchant_pending`
- `company_inactive`
- `contour_disabled`
- `validation_failed`
- `provider_unavailable`
- `provider_error`
- `not_found`
- `conflict`
- `rate_limit_exceeded`
- `internal_error`

### 5.4 Формат денег

Для всех финансовых значений:

- `value` строкой;
- `currency_code` отдельным полем;
- без неявных локальных форматирований;
- без пробелов и разделителей тысяч.

Пример:

```json
{
  "payment_amount": {
    "value": "10000.00",
    "currency_code": "RUB"
  }
}
```

## 6. Модель авторизации и доступа

### 6.1 MVP-авторизация

Для MVP использовать:

- `Authorization: Bearer <token>`

Где:

- токен генерируется системой;
- токен показывается оператору только один раз в момент создания;
- в БД хранится не сам токен, а только его hash;
- один токен привязан к одному merchant API client;
- один merchant API client привязан к одному `merchant_id` и одному `company_id`.

### 6.2 Почему не HMAC сразу

HMAC request signing можно добавить позже, но не нужно делать его обязательным уже в MVP, потому что:

- интеграция для мерчантов должна быть простой;
- основной риск сейчас не в криптографии запроса, а в неправильном scope и утечке raw token;
- Bearer token + HTTPS + hash storage + scopes достаточно для первого production-step.

### 6.3 Что обязан проверить auth-layer

При каждом запросе:

- token существует;
- token активен;
- client не revoked;
- merchant существует;
- merchant принадлежит именно тому `company_id`, который записан в client;
- merchant status допускает доступ;
- компания активна;
- требуемый scope разрешён;
- если scope потерян, запрос блокируется.

### 6.4 Scopes

Минимальные scopes v1:

- `profile:read`
- `balances:read`
- `rates:read`
- `orders:read`
- `orders:write`
- `payouts:read`

### 6.5 Дополнительные защитные меры

Сразу закладываем в архитектуру, даже если включим позже:

- `allowed_ip_cidrs` как optional field у API client;
- rate limit per client;
- last used at / last used ip;
- revoke / rotate token;
- отдельный статус client:
  - `active`
  - `revoked`
  - `paused`

### 6.6 Сколько API clients разрешать одному мерчанту

Для v1 сразу разрешаем несколько API clients на одного мерчанта.

Причины:

- отдельный ключ для production;
- отдельный ключ для staging/тестов;
- безопасная ротация без простоя;
- возможность отключить одну интеграцию, не ломая остальные;
- более чистая привязка webhook endpoint'а к конкретной интеграции.

Это почти не усложняет storage, потому что модель с `crm_merchant_api_clients` и так проектируется как `1 merchant -> N clients`.

### 6.7 Модель webhooks в v1

В v1 делаем outgoing webhooks как настройку конкретного API client, а не как отдельный тяжёлый subsystem.

Базовые правила:

- у каждого API client может быть один webhook endpoint URL;
- у каждого API client свой webhook secret;
- у каждого API client свой набор событий;
- delivery должен быть подписан секретом клиента;
- повторные попытки доставки нужны, но без тяжёлой очереди на первом этапе;
- webhook не должен ломать основной бизнес-процесс invoice/payout, если внешний endpoint недоступен.

## 7. Хранение данных

### 7.1 Новая таблица клиентов API

Рекомендуемая новая таблица:

- `crm_merchant_api_clients`

Назначение:

- хранить выданные merchant API credentials;
- позволять одному мерчанту иметь несколько интеграционных клиентов при необходимости;
- хранить webhook configuration конкретной интеграции;
- не смешивать credential storage с таблицей `crm_merchants`.

### 7.2 Предлагаемые поля таблицы

- `id`
- `company_id`
- `merchant_id`
- `client_name`
- `status`
- `token_prefix`
- `token_hash`
- `scopes_json`
- `webhook_url`
- `webhook_secret_prefix`
- `webhook_secret_hash`
- `webhook_events_json`
- `webhook_last_status_code`
- `webhook_last_attempt_at`
- `webhook_last_success_at`
- `allowed_ip_cidrs`
- `last_used_at`
- `last_used_ip`
- `revoked_at`
- `created_by_user_id`
- `updated_by_user_id`
- `created_at`
- `updated_at`

Правила:

- `company_id > 0`;
- FK на `crm_companies`;
- FK на `crm_merchants`;
- unique constraint на `token_hash`;
- индексы по `(company_id, merchant_id, status)`.

### 7.3 Нужно ли поле merchant_api_enabled

Отдельный bool-флаг в `crm_merchants` не обязателен.

Предпочтительное правило:

- API доступ считается включенным, если у мерчанта есть хотя бы один `active` client.

Это проще и не создаёт второй источник истины.

### 7.4 Лог request-level операций

Полный raw request log в отдельную таблицу для MVP не обязателен.

На первом этапе достаточно:

- писать audit entries в `crm_audit_log`;
- логировать auth failures, create attempts, create success/failure, token issue/revoke, forbidden scope, provider failures;
- не логировать raw secret/token;
- не логировать полные sensitive payloads без маскировки.

Если позже понадобится сильная операционная аналитика, можно добавить:

- `crm_merchant_api_request_log`

Но не делать это обязательным для первого релиза.

## 8. Новые backend-модули

### 8.1 Новый основной include

Создать:

- `inc/merchant-api.php`

В нём:

- register_rest_route для merchant API;
- auth helpers;
- response helpers;
- request_id generation;
- scope checks;
- data formatters;
- endpoint callbacks.

### 8.2 Новый AJAX/admin слой для управления ключами

Создать:

- `inc/ajax/merchant-api.php`

В нём:

- create client;
- revoke client;
- rotate client;
- list clients for merchant;
- permission checks;
- nonce checks;
- audit logging.

### 8.3 Подключение

Подключить новые include'ы через `functions.php`.

## 9. Права доступа внутри CRM

Управление merchant API ключами не стоит автоматически давать всем, кто может редактировать карточку мерчанта.

Рекомендуется добавить отдельное permission:

- `merchants.manage_api`

Правила:

- root может всегда;
- обычный CRM user только если у него есть `merchants.manage_api`;
- non-root пользователь видит и управляет ключами только своей компании;
- нельзя выпустить ключ для мерчанта другой компании;
- нельзя работать с `company_id = 0`.

Миграцией нужно:

- добавить permission;
- привязать его нужным ролям;
- не давать его всем автоматически без явного решения.

## 10. Админский UI

### 10.1 Где управлять API-доступом

Предпочтительное место:

- страница мерчантов / карточка конкретного мерчанта

Не нужно выносить MVP в отдельный глобальный раздел, если это можно сделать внутри существующего merchant-management flow.

### 10.2 Что должно быть в UI

В карточке мерчанта:

- секция `Merchant API`;
- список выданных клиентов;
- client name;
- scopes;
- статус;
- дата создания;
- последний вызов;
- IP последнего вызова;
- webhook URL;
- webhook events;
- webhook last delivery status;
- кнопка `Создать ключ`;
- кнопка `Отозвать`;
- кнопка `Перевыпустить`.

### 10.3 UX-правила

- raw token показывать только один раз после создания/rotate;
- старый token после rotate сразу перестаёт работать;
- в таблице показывать только token prefix, например `mapi_live_abcd...`;
- revoke должен быть обратимой только через выпуск нового ключа, а не через восстановление старого секрета.

## 11. Публичная документация API

### 11.1 Source of truth

Создать в репозитории:

- `docs/api/merchant/openapi.yaml`

Именно этот файл является главным контрактом API.

### 11.2 Публичные страницы

Создать минимум одну публичную страницу:

- slug: `merchant-api`

В той же первой поставке делаем второй экран:

- slug: `merchant-api-console`

Назначение:

- `merchant-api` — красивая документация на Redoc;
- `merchant-api-console` — интерактивный Swagger UI "try it out".

### 11.3 Важное правило для force-login

Так как это обычные WordPress pages, их нужно явно открыть для неавторизованных пользователей:

- добавить slug'и в allowlist `includes/force-login.php`

Иначе docs page будет редиректить на `/authorization`.

### 11.4 Создание страниц

Страницы нельзя создавать вручную.

Нужно:

- сделать шаблон страницы;
- сделать миграцию создания страницы;
- назначить шаблон автоматически.

Рекомендуемые будущие миграции:

- `0067_create_page_merchant_api_docs.php`
- `0068_create_page_merchant_api_console.php`

### 11.5 Технология рендера

Предпочтительное решение:

- `Redoc CE` для главной docs page
- `Swagger UI` для interactive console page

Причины:

- хорошо выглядит из коробки;
- не требует вручную верстать все хендлеры;
- работает от OpenAPI-файла;
- хорошо подходит для публичного reference.

Здесь нет архитектурной проблемы или тяжёлой сложности. Разница только в назначении:

- `Redoc` лучше как читаемый reference;
- `Swagger UI` лучше как "сразу отправить запрос и проверить".

Поэтому для первого docs-релиза планируем оба экрана.

### 11.6 Как отдавать OpenAPI spec

Есть два допустимых варианта:

1. Хранить spec как static file и отдавать его напрямую с сайта.
2. Сделать public REST endpoint, который читает и отдаёт `openapi.yaml`/`openapi.json`.

Для данного проекта предпочтительнее:

- держать файл в репозитории;
- отдавать его отдельным public route;
- на docs page подключать spec по URL этого route.

Это упрощает обновление документации без ручной правки HTML.

### 11.7 Assets

Так как проект без build pipeline, vendor assets должны подключаться просто.

Предпочтительно:

- либо использовать pin-версию готового standalone bundle;
- либо закоммитить static dist assets в тему.

Нежелательно:

- тянуть runtime через сложный npm/build шаг ради одной docs page.

## 12. Структура v1 endpoint-ов

### 12.1 `GET /merchant/me`

Возвращает:

- merchant id;
- company id;
- name;
- telegram username, если нужен;
- status;
- allowed directions;
- created at;
- merchant contour settings, которые безопасно показывать вовне.

### 12.2 `GET /merchant/balances`

Возвращает:

- available / payable balance;
- bonus balance;
- referral balance;
- total balance;
- currency codes;
- updated at.

### 12.3 `GET /merchant/rates`

Возвращает:

- направление;
- payment currency;
- payout currency;
- отображаемый merchant rate;
- checked_at;
- source code;
- опционально disclaimer, что rate informational.

### 12.4 `GET /merchant/orders`

Поддерживает:

- фильтр по status;
- пагинацию;
- limit;
- cursor или page-based пагинацию.

Для MVP достаточно page-based:

- `page`
- `per_page`

Возвращает:

- local order id;
- external_order_id;
- created_at;
- status;
- payment amount;
- payout amount;
- provider code;
- payment URL / short payment payload, если ещё актуально;
- flags `is_active`, `is_paid`, `is_expired`.

### 12.5 `GET /merchant/orders/{id}`

Возвращает детальную карточку счета:

- order identifiers;
- status timeline summary;
- payment amount;
- merchant payable;
- provider data, безопасная для клиента;
- QR payload / payment link;
- expiration;
- timestamps.

### 12.6 `GET /merchant/payouts`

Возвращает список выплат мерчанту:

- amount;
- currency;
- network;
- wallet;
- tx hash;
- explorer URL;
- comment;
- created_at;
- receipt URL.

### 12.7 `GET /merchant/payouts/{id}`

Возвращает одну выплату подробно.

### 12.8 `POST /merchant/invoices`

Минимальный request body для MVP:

```json
{
  "direction_code": "RUB_USDT",
  "requested_amount": {
    "value": "100.00",
    "currency_code": "USDT"
  },
  "external_order_id": "invoice-100245",
  "description": "Invoice 100245"
}
```

Правила:

- `direction_code` обязателен;
- `direction_code = RUB_USDT` остаётся единым бизнес-кодом для обоих Kanyon contours;
- конкретная `requested_amount.currency_code` зависит от company contour:
  - `USDT`, если у компании `fintech_pay2day_order_currency = USDT`;
  - `RUB`, если у компании `fintech_pay2day_order_currency = RUB`;
- список допустимых значений для конкретной компании должен основываться на `crm_company_get_enabled_invoice_directions($company_id)`;
- если пришёл contour, который включён для компании, но ещё не реализован сервером, вернуть `contour_not_supported`;
- `external_order_id` обязателен и уникален в пределах `merchant_id`;
- при повторном create с тем же `external_order_id` endpoint должен вести себя идемпотентно:
  - либо вернуть уже существующий order;
  - либо вернуть `409 conflict` по заранее зафиксированному правилу.

Предпочтительнее для MVP:

- возвращать уже существующий order как идемпотентный результат, если payload по смыслу совпадает.

## 13. Логирование

Новый merchant API обязан соблюдать `docs/LOGGING.md`.

### 13.1 Что логировать обязательно

- выдача ключа;
- revoke ключа;
- rotate ключа;
- auth success на debug/info уровне по safe-summary;
- auth failure;
- forbidden scope;
- blocked merchant access;
- create invoice start;
- create invoice validation failure;
- provider request failure;
- provider success;
- order created;
- rate limit exceeded;
- docs/spec endpoint errors, если такие будут.

### 13.2 Что нельзя логировать

- raw bearer token;
- полный `Authorization` header;
- секреты провайдеров;
- небезопасные payment payloads без маскировки;
- персональные данные без необходимости.

### 13.3 Пример event codes

- `merchant_api_client_created`
- `merchant_api_client_revoked`
- `merchant_api_auth_failed`
- `merchant_api_access_denied`
- `merchant_api_invoice_create_started`
- `merchant_api_invoice_create_failed`
- `merchant_api_invoice_created`
- `merchant_api_webhook_delivery_failed`
- `merchant_api_webhook_delivery_succeeded`

## 14. Подробный план реализации

### Фактический статус на 2026-05-18

- Этап 0 завершён: ТЗ и границы `v1` зафиксированы.
- Этап 1 завершён: подняты storage, auth-layer, permission `merchants.manage_api`, таблица `crm_merchant_api_clients`.
- Этап 2 выполнен частично: в merchant UI есть отдельная вкладка `Merchant API`, выпуск и отзыв ключей работают; `rotate` и редактирование webhook config в UI ещё не реализованы.
- Этап 3 завершён: read endpoints `me`, `balances`, `rates`, `orders`, `order detail`, `payouts`, `payout detail` уже подняты и прошли self-test.
- Во время self-test найден и исправлен контрактный баг в `/merchant/rates`: invoice-семантика теперь применяется только к `RUB_USDT`; для остальных направлений read-model остаётся информационной.
- Этап 4 выполняется: `POST /merchant/invoices` уже реализован для `RUB_USDT` с автоматическим выбором `orderAmount` или `paymentAmount` по company settings.
- Live self-test уже подтверждён для компании с `USDT` contour (`orderAmount`).
- Webhooks выведены из критического пути текущего MVP и отложены до отдельного решения.

## Этап 0. Документ и фиксация решения

Статус: completed

Цель:

- зафиксировать ТЗ;
- договориться о границах v1;
- не начинать реализацию без общего контракта.

Действия:

- создать этот файл;
- утвердить базовые решения;
- зафиксировать подтверждённые решения в конце документа.

Результат:

- есть один source of truth для разработки merchant API.

## Этап 1. Foundation: storage, auth, permissions

Статус: completed

Цель:

- подготовить безопасную основу для выдачи merchant API credentials.

Действия:

- создать миграцию таблицы `crm_merchant_api_clients`;
- добавить permission `merchants.manage_api`;
- подключить новый include `inc/merchant-api.php`;
- реализовать helper поиска client по token hash;
- реализовать helper auth context:
  - `company_id`
  - `merchant_id`
  - `client_id`
  - `scopes`
- заложить webhook fields в `crm_merchant_api_clients`;
- реализовать response helper для внешнего API;
- реализовать request_id helper;
- реализовать базовый rate-limit scaffold.

Проверка:

- ключ можно создать;
- raw secret сохраняется только на момент выдачи;
- без токена API не пускает;
- с revoked token API не пускает;
- scope mismatch даёт отказ;
- запрос не может выйти в `company_id = 0`.

Провал:

- токен хранится в raw виде;
- API доверяет `merchant_id` из URL/POST без auth context;
- non-root user может выдать ключ мерчанту другой компании.

## Этап 2. Admin UI для ключей

Статус: in progress

Цель:

- дать root/оператору удобный экран управления merchant API доступом.

Действия:

- добавить секцию `Merchant API` в существующий merchant management UI;
- сделать AJAX list/create/revoke/rotate;
- показывать token только один раз;
- писать аудит на все операции;
- показывать last used info.
- дать настроить webhook URL и webhook events на уровне client.

Проверка:

- оператор своей компании может выпустить ключ;
- ключ видно один раз;
- можно отозвать ключ;
- можно перевыпустить ключ;
- мерчанты другой компании недоступны.

Провал:

- ключ можно посмотреть повторно в raw виде;
- revoke не блокирует доступ;
- root/company boundary нарушается.

Фактический статус на 2026-05-18:

- отдельная вкладка `Merchant API` в карточке мерчанта уже есть;
- выпуск ключа работает;
- отзыв ключа работает;
- raw token показывается один раз после выпуска;
- `rotate` ещё не реализован;
- редактирование `webhook_url` и `webhook_events` в UI ещё не реализовано.

## Этап 3. Read API

Статус: completed

Цель:

- быстро дать мерчантам безопасный read-only доступ.

Действия:

- реализовать:
  - `GET /merchant/me`
  - `GET /merchant/balances`
  - `GET /merchant/rates`
  - `GET /merchant/orders`
  - `GET /merchant/orders/{id}`
  - `GET /merchant/payouts`
  - `GET /merchant/payouts/{id}`
- переиспользовать существующие query/helper-функции;
- отфильтровать все company/merchant scope на backend;
- привести ответы к фиксированным DTO.

Проверка:

- мерчант видит только свои данные;
- пагинация работает;
- disabled contour скрыт/заблокирован;
- blocked merchant не получает доступ.

Провал:

- можно запросить чужой `order_id` и получить данные;
- payouts другой компании видны по прямому ID;
- API отдаёт неструктурированный сырый объект БД.

Фактический статус на 2026-05-18:

- все read endpoints из `v1` уже подняты;
- self-test подтвердил `401` для невалидного Bearer token;
- self-test подтвердил `404` для несуществующих `order_id` и `payout_id`;
- read-layer отвечает нормальным JSON-контрактом, а не HTML/redirect;
- баг в `/merchant/rates` после self-test исправлен: поля `provider_mode`, `requested_amount_currency`, `payment_currency_code`, `settlement_currency_code` теперь привязаны только к `RUB_USDT`, где реально поддерживается invoice creation.

## Этап 4. Write API: invoice creation

Статус: in progress

Цель:

- дать внешнему клиенту тот же полезный create-invoice flow, что и в merchant/payment contour компании.

Действия:

- реализовать `POST /merchant/invoices`;
- использовать существующий merchant invoice orchestration;
- сделать проверку merchant/company/provider contour;
- поддержать `external_order_id` как merchant-scoped idempotency key;
- вернуть payment link / QR payload / statuses;
- залогировать все неуспехи и успехи.

Проверка:

- валидный merchant создаёт RUB invoice;
- повторный запрос с тем же `external_order_id` ведёт себя предсказуемо;
- provider failure корректно возвращается наружу;
- order создаётся только в текущем company scope.

Провал:

- duplicate invoice создаётся повторно без контроля;
- невалидный merchant всё равно получает order;
- invoice появляется без `merchant_id`.

Фактический статус на 2026-05-18:

- endpoint `POST /merchant/invoices` уже поднят;
- поддержан единый `direction_code = RUB_USDT`;
- `requested_amount.currency_code` теперь зависит от company contour:
  - `USDT` для `orderAmount`;
  - `RUB` для `paymentAmount`;
- live self-test подтверждён для `USDT` company contour:
  - новый create;
  - идемпотентный replay по `external_order_id`;
  - `409 conflict` при повторном create с другим payload.
- `RUB` contour реализован в коде, но ещё не проходил отдельный live smoke на компании с `fintech_pay2day_order_currency = RUB`.

## Этап 5. Outgoing webhooks

Статус: backlog

Цель:

- дать мерчанту server-to-server уведомления о важных событиях без постоянного polling.

Действия:

- определить минимальный набор событий:
  - `invoice.created`
  - `invoice.paid`
  - `invoice.expired`
  - `invoice.cancelled`
  - `payout.created`
- хранить webhook config на уровне API client;
- генерировать отдельный webhook secret;
- подписывать webhook delivery;
- логировать delivery attempts;
- делать мягкие retry при неуспехе;
- не блокировать основной order/payout flow из-за ошибки webhook.

Примечание на 2026-05-18:

- по решению продукта webhooks сейчас не входят в критический путь MVP;
- основной operational contour остаётся таким:
  - merchant API создаёт и читает счета;
  - Malibu продолжает сам отслеживать статусы через callback/polling/cron;
  - merchant при необходимости получает статус через polling своего API-клиента.

Проверка:

- webhook уходит на настроенный URL;
- подпись присутствует;
- недоступный endpoint не ломает invoice flow;
- успешные и неуспешные доставки логируются.

Провал:

- webhook вызывает повторное создание бизнес-объекта;
- ошибка доставки ломает основной order flow;
- нельзя отследить факт и результат попытки доставки.

## Этап 6. OpenAPI spec и docs page

Статус: pending

Цель:

- сделать нормальную внешнюю документацию без ручной верстки списка хендлеров.

Действия:

- создать `docs/api/merchant/openapi.yaml`;
- описать в нём все endpoints, security scheme, DTO, enums, errors;
- сделать public endpoint отдачи spec;
- создать page template для docs page;
- сделать миграцию WordPress page `merchant-api`;
- сделать миграцию WordPress page `merchant-api-console`;
- открыть slug в force-login allowlist;
- встроить Redoc CE.
- встроить Swagger UI.

Проверка:

- docs page открывается без логина;
- страницы создаются миграцией;
- spec соответствует реальным endpoint-ам;
- документация рендерится автоматически из OpenAPI.

Провал:

- docs page редиректит на `/authorization`;
- page создана вручную, а не миграцией;
- OpenAPI расходится с фактическим API.

## Этап 7. Hardening

Статус: pending

Цель:

- довести API до безопасного production-surface.

Действия:

- отрицательные тесты на cross-company access;
- тесты на blocked/pending merchants;
- тесты на revoked tokens;
- тесты на disabled contour;
- тесты на rate limiting;
- audit review на отсутствие secret leakage;
- ручной QA по docs page и основным endpoint-ам.

Проверка:

- нет company leakage;
- нет root leakage;
- нет raw token leakage;
- нет fallback на default org.

Провал:

- любой запрос может обойти company scope;
- логи содержат секреты;
- API молча подставляет другой company context.

## Этап 8. Необязательное продолжение после v1

Статус: backlog

После стабилизации v1 можно добавить:

- HMAC-signed webhooks;
- IP allowlist enforcement;
- multi-client analytics;
- cursor-based pagination;
- `Swagger UI` console page;
- SDK/examples snippets;
- versioned `v2`, если контракт существенно расширится.

## 15. Definition of Done для v1

Merchant API можно считать готовым только если:

- есть безопасная выдача и отзыв merchant API credentials;
- есть read endpoints;
- есть `POST /merchant/invoices`;
- контур полностью company-scoped;
- нет `company_id = 0` для обычного merchant-flow;
- есть OpenAPI spec в репозитории;
- есть публичная docs page;
- есть публичная Swagger UI console page;
- docs page создаётся миграцией;
- docs page не требует логина;
- все важные действия логируются;
- нет утечки raw token/secret в логах и ответах.

## 16. Решения, подтверждённые на 2026-05-17

- docs page делаем полностью публичной;
- merchant API делаем contour-aware и завязываем на root/company settings;
- create-flow для `RUB_USDT` должен поддерживать оба Kanyon режима компании:
  - `orderAmount`, если company contour работает через `USDT`;
  - `paymentAmount`, если company contour работает через `RUB`;
- webhooks пока выведены за рамки текущего MVP-критического пути;
- одному мерчанту разрешаем несколько API clients;
- в docs-релиз планируем и `Redoc`, и `Swagger UI`.
