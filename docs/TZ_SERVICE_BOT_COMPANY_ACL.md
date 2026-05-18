# ТЗ: Service Bot для компании, ACL, Telegram-привязка пользователей

Дата: 2026-05-17

## 1. Цель

Нужен отдельный `service` Telegram-бот компании, через который ограниченный круг CRM-пользователей сможет выполнять ключевые действия из backoffice без входа на сайт.

Первый практический приоритет:

- ручные выплаты мерчантам;
- фиксация входящих выплат от эквайринг-партнёра;
- дальнейшее поэтапное дублирование основных функций control panel.

## 2. Что уже есть в проекте

- В проекте уже существует company-scoped `service` Telegram context:
  - service bot settings в `crm_settings`;
  - отдельный callback route `service-callback`;
  - source channel `telegram_service` в order/runtime-логике.
- В проекте уже существует рабочий паттерн Telegram-привязки CRM-пользователя:
  - `crm_user_telegram_accounts`;
  - `crm_operator_telegram_invites`;
  - обработчик `inc/telegram-operators-handler.php`.
- В проекте уже существует merchant payout contour на сайте:
  - страница `merchant-payouts`;
  - запись в `crm_merchant_payouts`;
  - ledger entry `merchant_payout`;
  - Telegram-уведомление мерчанту после сохранения выплаты.
- В проекте уже существует acquirer payout contour на сайте:
  - запись входящих выплат от ЭП компании;
  - company-scoped storage и UI.

## 3. Принципиальное решение

Service bot не должен стать третьей независимой бизнес-логикой.

Правильная архитектура:

- сайт и service bot используют одни и те же backend helper / storage / validations;
- Telegram-бот только собирает ввод, вызывает shared backend contour и показывает результат;
- нельзя дублировать отдельно в service bot математику, статусы, ACL, company scope, payment purpose и provider logic.

## 4. Модель доступа

Доступ в service bot должен быть двухуровневым.

### 4.1 Уровень CRM permissions

Пользователь должен иметь соответствующие CRM-права.

Минимальный набор новых прав:

- `service.telegram.view`
- `service.telegram.invite`
- `service.telegram.merchant_payouts`
- `service.telegram.acquirer_payouts`
- `service.telegram.orders`
- `service.telegram.rates`

Допустимо вводить их поэтапно, но не открывать бот пользователю без явного permission-контроля.

### 4.2 Уровень явного service ACL

Даже если у пользователя есть CRM permission, service bot должен быть доступен только тем пользователям компании, которым явно выдали доступ.

То есть нужен отдельный service allowlist / access layer.

## 5. Telegram-привязка пользователя

Для service bot нужен invite-based handshake, аналогичный operator bot.

Обязательные требования:

- root никогда не участвует в этом контуре;
- привязка всегда company-scoped;
- нельзя дать доступ Telegram chat одной компании к данным другой;
- нельзя пускать пользователя в service bot просто по факту существования chat_id.

### 5.1 Предлагаемая схема

Не плодить новую сущность пользователя.

Использовать:

- существующую таблицу `crm_user_telegram_accounts` как универсальную привязку CRM user <-> Telegram profile;
- отдельную таблицу истории service invite;
- отдельную таблицу активного service access.

### 5.2 Рекомендуемые таблицы

`crm_service_telegram_invites`

- история invite-ссылок в service bot;
- кто выдал, кому выдал, какой start payload, срок действия, статус, кем использовано.

`crm_service_telegram_access`

- активный доступ пользователя к service bot;
- `company_id + user_id`;
- статус `active|blocked|revoked`;
- кто выдал доступ;
- когда выдал;
- кем последний раз использовался.

### 5.3 Почему не хранить всё только в invites

Invite history и текущее право доступа — разные вещи.

Нужны:

- понятная история выдачи/отзыва;
- независимый revoke access без порчи истории;
- быстрый ACL-check на каждый callback.

## 6. Поведение /start в service bot

Service bot должен поддерживать два сценария:

### 6.1 `/start` без payload

- если Telegram уже связан с CRM user этой компании и access активен:
  - открыть service menu;
- если Telegram привязан, но access отозван/заблокирован:
  - показать отказ;
- если Telegram не привязан:
  - показать текст, что нужен service invite из CRM.

### 6.2 `/start svc_...`

- проверить service invite;
- проверить, что user активен и принадлежит компании;
- создать или обновить `crm_user_telegram_accounts`;
- активировать или подтвердить запись в `crm_service_telegram_access`;
- открыть service menu.

## 7. Service bot menu

Нельзя сразу пытаться сделать полный клон control panel в один этап.

Меню должно расти по фазам.

Целевой набор разделов:

- `Merchant payouts`
- `Acquirer payouts`
- `Create order`
- `Orders`
- `Rates`
- `Merchants`
- `Company balances`
- `Help`

Важное правило:

- пользователь должен видеть только те разделы, которые ему разрешены:
  - через CRM permissions;
  - через service ACL;
  - через company contours / provider settings / enabled directions.

## 8. Merchant payout flow в service bot

Это первый обязательный executable flow.

Service bot должен уметь:

1. выбрать мерчанта текущей компании;
2. показать текущий основной баланс `к выплате`;
3. запросить сумму;
4. запросить сеть;
5. запросить wallet address;
6. запросить `tx_hash`;
7. запросить screenshot / receipt image;
8. запросить comment / notes;
9. сохранить выплату через тот же backend contour, что и сайт;
10. отправить мерчанту payout notification с деталями и скриншотом.

### 8.1 Важное архитектурное требование

Service bot не должен писать payout напрямую в базу собственной логикой.

Правильно:

- вынести create payout в shared helper/service layer;
- сайт и service bot вызывают один и тот же backend path.

## 9. Acquirer payout flow в service bot

Второй обязательный executable flow.

Service bot должен уметь фиксировать входящую выплату от ЭП компании:

- provider;
- amount;
- currency / asset;
- wallet;
- `tx_hash`;
- screenshot;
- notes;
- дата/время.

Это тоже должен быть shared backend contour с сайтом, а не отдельная Telegram-версия хранения.

## 10. Orders / create-order / rates в service bot

Эти разделы нужны, но не в первом milestone.

При добавлении later:

- `create-order` в service bot обязан использовать тот же contour компании, что и web `create-order` и operator bot;
- `paymentAmount` vs `orderAmount` берётся из company settings;
- `payment purpose / Ext Order ID` тоже единый с web/operator contour;
- если company contour выключен, service bot hard-fail’ится, а не пытается угадать fallback.

## 11. Company isolation

Обязательные жёсткие правила:

- root не участвует в service bot вообще;
- `company_id = 0` недопустим;
- любой ACL-check должен быть company-scoped;
- любой выбор сущности в service bot должен фильтроваться по `company_id`;
- никаких cross-company fallback;
- если company context отсутствует, операция должна hard-fail’иться.

## 12. Логи и аудит

Все ключевые действия service bot должны логироваться:

- invite created;
- invite used;
- Telegram linked;
- access granted;
- access revoked;
- merchant payout created;
- acquirer payout created;
- service bot access denied;
- callback security failures.

## 13. UI на сайте

Для управления service bot нужен UI на сайте.

Минимум:

- action в users table: `Service bot access`;
- выдача service invite;
- revoke / block access;
- статус привязки Telegram;
- история invite.

Пользователь компании должен быть виден только в рамках своей компании.

## 14. Non-goals первого этапа

Пока не делать:

- полный клон всех экранов control panel в Telegram;
- чат support / operator-to-merchant conversation system;
- сложные media gallery / file vault;
- мультибот на каждое направление;
- обход CRM ACL через один только Telegram access.

## 15. Рекомендуемая стратегия реализации

Делать поэтапно:

1. service ACL + invite + binding;
2. service menu shell;
3. merchant payouts;
4. acquirer payouts;
5. read-only orders / rates / balances;
6. create-order;
7. остальное.

Именно такой порядок сейчас выглядит наименее рискованным.
