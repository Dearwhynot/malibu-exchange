# Гайд интеграции Friendly Pay

Дата: 2026-05-29

Документ фиксирует, что нужно добавить и проверить, чтобы подключить Friendly Pay
как новый платежный провайдер Malibu Exchange.

Не хранить API token, secret key и любые другие ключи в этом файле, PHP-константах
или коде темы. Креды должны сохраняться только через company-scoped настройки в
`crm_settings`.

## Область провайдера

Код провайдера: `friendly_pay`

Первый целевой сценарий: создание SBP-транзакции, получение QR/ссылки, проверка
статуса и обработка callback-уведомлений.

Документация Friendly Pay:
- Auth: https://friendly-pay.gitbook.io/friendly-pay-docs
- Создание SBP-транзакции: https://friendly-pay.gitbook.io/friendly-pay-docs/tranzakcii/sbp/sozdat-tranzakciyu
- Получение SBP-транзакции: https://friendly-pay.gitbook.io/friendly-pay-docs/tranzakcii/sbp/poluchit-tranzakciyu
- Верификация callback: https://friendly-pay.gitbook.io/friendly-pay-docs/uvedomleniya/verifikaciya
- Callback смены статуса: https://friendly-pay.gitbook.io/friendly-pay-docs/uvedomleniya/izmenenie-statusa-tranzakcii

## Известные требования Friendly Pay

- Каждый API-запрос подписывается заголовками:
  - `x-fp-timestamp`
  - `x-fp-nonce`
  - `x-fp-api-token`
  - `x-fp-signature`
- Для запроса с body строка подписи описана как `{timestamp}_{nonce}_{body}`.
- Для запроса без body в документации сказано не включать body, но пример C#
  формирует строку через nullable body. Реализация пробует оба варианта только
  при авторизационной ошибке: `{timestamp}_{nonce}` и `{timestamp}_{nonce}_`.
- В тексте endpoint-документации написано про base64-подпись, но пример C#
  возвращает lowercase hex HMAC-SHA256, а примеры headers выглядят как hex.
  Реализация сначала пробует hex, затем base64 только при авторизационной ошибке.
- В общем разделе auth пример timestamp похож на миллисекунды, а endpoint-примеры
  показывают Unix timestamp в секундах. Реализация сначала пробует миллисекунды,
  затем секунды только при авторизационной ошибке.
- Endpoint создания SBP: `POST /api/v1/transactions/sbp`.
- Endpoint получения статуса SBP: `GET /api/v1/transactions/sbp/{transactionId}`.
- API Base URL провайдера зафиксирован в коде: `https://pay.friendlypay.io/api`.
  Это инфраструктурное значение, не company-scoped настройка.
- Статусы из документации: `created`, `initialized`, `success`, `failed`,
  `expired`.
- Callback body содержит минимум:
  - `id`
  - `merchantOrderId`
- Callback нужно проверять тем же механизмом signed headers.
- Техническое уточнение от провайдера: при создании платежа обязательно
  передавать `cart`.
- Лимиты одной транзакции: минимум `30 RUB`, максимум `200000 RUB`.

Пример payload создания платежа без кредов:

```json
{
  "merchantOrderId": "MALIBU_...",
  "callbackUrl": "https://example.com/fintech-payment-callback/",
  "orderAmount": {
    "value": 10000.00,
    "currency": "RUB"
  },
  "cart": [
    {
      "name": "ProductName",
      "price": 10000.00,
      "currency": "RUB",
      "quantity": 1
    }
  ]
}
```

## Настройки, которые надо добавить

Создать миграцию `inc/migrations/NNNN_seed_friendly_pay_settings.php`.
Настройки сидить для компаний с `id > 0`; реальные креды не сидить.

Рекомендуемые ключи:
- `fintech_friendly_pay_api_token`
- `fintech_friendly_pay_secret_key`
- `fintech_friendly_pay_transaction_type` с дефолтом `sbp`
- `fintech_friendly_pay_cart_name` с дефолтом `Payment`
- `fintech_friendly_pay_cart_currency` с дефолтом `RUB`
- `fintech_friendly_pay_min_amount_rub` с дефолтом `30`
- `fintech_friendly_pay_max_amount_rub` с дефолтом `200000`
- опционально: `fintech_friendly_pay_debug`

Также обновить `inc/sql/settings.sql` как reference-документацию схемы.

## Где провайдер не подхватится динамически

Friendly Pay нельзя просто добавить в настройку и ожидать, что все заработает.
Сейчас часть системы динамическая, но критичные места завязаны на конкретные
провайдеры Kanyon/Doverka.

### Регистрация провайдера

Файлы:
- `inc/fintech-payment-gateway.php`
- `inc/company-contours.php`

Что сделать:
- добавить `friendly_pay` в `crm_fintech_provider_labels()`;
- добавить `friendly_pay` в `fintech_providers`;
- добавить чтение настроек в `crm_fintech_collect_settings()`;
- добавить нормализованные настройки в `crm_fintech_settings()`;
- добавить проверки готовности в `crm_fintech_get_configuration_status()`;
- добавить константу `PROVIDER_FRIENDLY_PAY`;
- добавить явный роутинг create/status/data/QR методов.

Важный риск: сейчас неизвестный не-Doverka провайдер в gateway может попасть в
старую ветку Pay2Day/Kanyon. Для Friendly Pay нужен явный `if/elseif`, без
fallback на другой провайдер.

### Доступ компании к платёжным контурам

Файл:
- `inc/ajax/companies.php`

Что учесть:
- в бизнес-логике нет понятия "дефолтный платёжный провайдер";
- новая компания не получает платёжные контуры автоматически;
- пустой `fintech_allowed_providers` означает, что root ещё не выдал компании
  доступ ни к одному платёжному провайдеру;
- root должен включать нужный провайдер точечно для нужной компании через
  `/root-fintech-providers/`.

Для существующих компаний:
- миграция должна добавить settings seed, но не должна включать provider в
  `fintech_allowed_providers` автоматически;
- если автодоступ был добавлен ошибочно, отдельная миграция должна снять
  `friendly_pay` из allowlist компаний без заполненных Friendly Pay кредов.

### Страница настроек

Файлы:
- `page-settings.php`
- `inc/ajax/settings.php`

Что сделать:
- добавить блок Friendly Pay, видимый только когда провайдер разрешен компании;
- поля: API token, secret key, название позиции в `cart`, лимиты;
- добавить проверку готовности в JS `collectFintechStatus()`;
- добавить AJAX-секцию `fintech_friendly_pay`;
- валидировать наличие token/secret, min/max amount;
- в audit/log context маскировать секреты, не писать raw credentials.

### Gateway

Файл:
- `inc/fintech-payment-gateway.php`

Сделано:
- реализован helper подписи Friendly Pay;
- используется один и тот же JSON string для подписи и отправки;
- реализовано создание SBP-транзакции через `POST /v1/transactions/sbp`;
- реализовано получение транзакции через `GET /v1/transactions/sbp/{transactionId}`;
- ответ провайдера нормализуется к текущей форме invoice:
  - `provider = friendly_pay`
  - `orderId = transactionId`
  - `merchantOrderId`
  - `payload = qrLink`
  - `qrcId = transactionId` или QR id из `qrLink`
  - `paymentAmountRub = RUB * 100`
  - `amountUsdt = null`, без фиктивной USDT-конвертации.

### Callback

Файл:
- `inc/fintech-payment-callback-handler.php`

Что сделать:
- добавить определение Friendly Pay callback shape;
- находить компанию по `merchantOrderId` или `id`;
- проверять `x-fp-*` заголовки секретом конкретной компании;
- нормализовать callback в общий event;
- если callback присылает только IDs, после него делать `GET /transactions/sbp/{id}`
  и уже по результату обновлять локальный статус.

### Маппинг статусов

Файл:
- `inc/fintech-orders.php`

Предварительная карта:
- `created` -> локально `created` или `pending`;
- `initialized` -> локально `pending`;
- `success` -> локально `paid`;
- `failed` -> локально `declined` или `error`, после уточнения семантики;
- `expired` -> локально `expired`.

### Создание ордера из backoffice

Файлы:
- `inc/fintech-orders.php`
- `page-create-order.php`
- `inc/ajax/orders.php`

Что сделать:
- добавить отдельный Friendly Pay RUB/SBP mode;
- не пропускать Friendly Pay через текущую Kanyon-ориентированную модель;
- показать ввод суммы в RUB;
- на backend enforced limits: `30 <= amount <= 200000`;
- добавлять `cart` при каждом create request.

### Telegram

Файлы:
- `inc/telegram-operators-handler.php`
- `inc/telegram-orders-handler.php`

Что сделать:
- добавить текст/кнопки оператора для Friendly Pay, если платежи должны
  создаваться из Telegram;
- старые callback labels вида `kanyon_paid:*` переименовать или обобщить,
  если Friendly Pay заказы тоже должны проверяться из Telegram.

### Merchant API

Файлы:
- `inc/merchant-api.php`
- `docs/api/merchant/openapi.yaml`

Что учесть:
- Merchant API сейчас выглядит Kanyon-ориентированным по invoice creation;
- Friendly Pay надо добавлять явно, только если этот провайдер должен быть
  доступен внешним мерчантам;
- после добавления обновить OpenAPI-документацию.

### Payouts и учет

Файлы:
- `page-payouts.php`
- `inc/ajax/payouts.php`

Что учесть:
- фильтры payout уже provider-aware;
- текущая логика EP debt опирается на `amount_asset_value` как USDT;
- Friendly Pay SBP является RUB-first потоком;
- нельзя записывать RUB в USDT-поле без отдельного правила конвертации;
- до включения payout для Friendly Pay нужно решить settlement currency:
  RUB, USDT или провайдерская конвертация.

## Вопросы к провайдеру до реализации

1. Есть ли отдельный sandbox base URL, кроме production `https://pay.friendlypay.io/api`?
2. `x-fp-signature` должен быть lowercase hex или base64?
3. Какая точная строка подписи для GET без body?
4. `x-fp-timestamp` в секундах или миллисекундах? В примерах есть конфликт.
5. `cart` обязателен только для SBP или для всех типов платежей?
6. Для этого мерчанта `cart.currency` может быть только `RUB`, или `USD` тоже
   реально поддерживается?
7. Должно ли `cart.price * quantity` строго равняться `orderAmount.value`?
8. Какой TTL у SBP-транзакции?
9. `qrLink` появляется сразу после create или только после `initialized`?
10. Callback смены статуса всегда содержит только `id` и `merchantOrderId`, или
    может содержать `status`?
11. Что означает `failed`: отмена пользователем, отказ провайдера, техническая
    ошибка или все эти случаи?

## Рекомендуемый порядок интеграции

1. Добавить provider registration и settings seed migration.
2. Добавить UI настроек и AJAX save.
3. Добавить signer Friendly Pay с маскированием секретов в логах.
4. Сначала проверить sandbox `GET /rates`.
5. Реализовать SBP create с `cart` и лимитами.
6. Реализовать status polling.
7. Реализовать callback detection и signature verification.
8. Добавить web create-order mode.
9. Добавить Telegram и Merchant API только после стабильного web-flow.
10. После каждого provider-вызова проверять `wp-content/debug.log` на сервере.
