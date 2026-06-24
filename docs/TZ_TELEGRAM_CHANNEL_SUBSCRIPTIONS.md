# ТЗ и roadmap: платные подписки на закрытые Telegram-каналы

Дата: 2026-06-24

## 1. Цель

Нужно реализовать company-scoped модуль "Telegram-каналы" для платной подписки на закрытый Telegram-канал.

Первая версия:

- одна компания = один закрытый Telegram-канал;
- одна компания = один отдельный subscription bot;
- subscription bot общается только с конечным клиентом;
- merchant bot используется для бизнес-стороны продажи подписки;
- платёжная инфраструктура переиспользует существующий fintech layer проекта;
- все настройки, тарифы, подписчики, платежи и логи строго привязаны к `company_id > 0`.

Модуль должен быть переиспользуемым для разных компаний. Root включает модуль конкретной компании, после чего у этой компании появляется страница модуля и соответствующие настройки.

## 2. Жёсткие архитектурные правила

1. Multi-company isolation абсолютна.
2. `company_id = 0` запрещён для подписок, каналов, тарифов, клиентов, платежей и Telegram-настроек.
3. Root не является участником подписочного flow.
4. Нельзя использовать глобальный subscription bot на все компании.
5. Нельзя показывать клиентам функции merchant/operator/service bot.
6. Нельзя добавлять внешних клиентов подписки в CRM users / WP users только ради подписки.
7. Нельзя хранить токены, channel id, тарифы или тексты в коде.
8. Нельзя активировать подписку, если не удалось связать оплату с Telegram-клиентом и компанией.
9. Нельзя полагаться только на callback платёжки: paid side-effect должен срабатывать и при callback, и при polling/manual-confirm пути.
10. Все даты подписок хранятся в UTC.

## 3. Главное решение по ботам

В системе участвуют два разных Telegram-контура.

### 3.1 Merchant bot

Merchant bot остаётся рабочим ботом мерчанта/партнёра.

Через него мерчант должен:

- инициировать продажу подписки;
- выбрать тариф;
- получить клиентскую ссылку в subscription bot;
- видеть связанные открытые/закрытые подписочные ордера;
- использовать существующие merchant-функции: ордера, бонусы, балансы, выплаты.

Merchant bot не должен становиться клиентским интерфейсом подписки.

### 3.2 Subscription bot

Subscription bot отдельный для каждой компании.

Через него конечный клиент должен видеть только:

- статус подписки;
- выбор/оплату тарифа;
- продление;
- дату окончания доступа;
- кнопку входа в закрытый канал;
- напоминания об окончании;
- повторную выдачу invite-ссылки, если подписка активна.

Subscription bot не должен показывать:

- merchant orders;
- merchant balances;
- payouts;
- exchange tools;
- CRM/internal actions.

## 4. Правильный payment flow

Важный момент: Telegram ID конечного клиента нельзя надёжно определить из платёжного callback, если клиент заранее не открыл subscription bot.

Поэтому базовый flow должен быть таким:

1. Мерчант в merchant bot выбирает действие "Продать подписку".
2. Merchant bot создаёт company-scoped subscription sale/start payload.
3. Merchant bot отдаёт мерчанту ссылку:
   `https://t.me/{subscription_bot_username}?start={payload}`.
4. Мерчант передаёт ссылку клиенту.
5. Клиент открывает subscription bot по ссылке.
6. Subscription bot фиксирует `telegram_user_id`, `chat_id`, username и company context.
7. Subscription bot создаёт fintech payment order по выбранному тарифу.
8. Payment order получает:
   - `company_id`;
   - `source_channel = telegram_channel_subscription`;
   - `meta_json.module = telegram_channels`;
   - `meta_json.telegram_user_id`;
   - `meta_json.chat_id`;
   - `meta_json.tariff_id`;
   - `meta_json.merchant_id`, если продажа пришла от мерчанта;
   - `meta_json.sale_id` / start payload.
9. После перехода order в `paid` модуль активирует или продлевает подписку.
10. Subscription bot создаёт одноразовую invite-ссылку в закрытый канал.
11. Клиент получает сообщение с датой окончания и кнопкой входа.
12. Мерчант/админ получает уведомление об успешной продаже, если это включено в настройках.

Допустимый future-case: если клиент уже известен subscription bot, merchant bot может сразу создать payment order для известного клиента. В первой версии не нужно усложнять этим основной flow.

## 5. Страница модуля

Новая backoffice-страница:

- title: `Telegram-каналы`;
- template: `page-telegram-channels.php`;
- slug: `telegram-channels`.

Страница создаётся миграцией. Вручную через админку страницу не создавать.

Базовый шаблон страницы создавать копированием существующего актуального page template, предпочтительно `page-users.php`, с сохранением:

- `Template Name`;
- `Slug`;
- `malibu_exchange_require_login()`;
- permission check;
- стандартного header block;
- стандартного breadcrumb/jumbotron;
- `quickview`;
- `overlay`.

### 5.1 Доступ к странице

Страница доступна только если выполнены оба условия:

1. пользователь имеет CRM permission `telegram_channels.view`;
2. root включил модуль `telegram_channels` для компании пользователя.

Если company context отсутствует или `company_id <= 0`, страница должна hard-fail/block access.

Root может получить отдельную root-only сводку позже. В первой версии root не должен заходить на company page как обычный пользователь и не должен видеть данные через ослабление фильтров.

### 5.2 Вкладки страницы

Рекомендуемые вкладки первой версии:

1. `Обзор`
   - статус готовности модуля;
   - включён ли модуль root;
   - настроен ли subscription bot;
   - настроен ли channel id;
   - заполнены ли все тарифы;
   - есть ли активные подписчики;
   - есть ли ошибки последнего webhook/API вызова.

2. `Канал`
   - название канала;
   - Telegram channel id (`-100...`);
   - статус канала;
   - кнопка проверки прав бота;
   - подсказка, что subscription bot должен быть администратором канала.

3. `Тарифы`
   - месяц: 30 дней;
   - квартал: 90 дней;
   - год: 365 дней;
   - цена;
   - валюта;
   - активность тарифа.

4. `Подписчики`
   - Telegram ID;
   - username;
   - имя;
   - тариф;
   - подписка до;
   - статус;
   - последняя оплата;
   - повторная invite-ссылка;
   - ручная отмена/разблокировка позже.

5. `Платежи`
   - payment order id;
   - merchant order id;
   - тариф;
   - сумма;
   - статус;
   - дата оплаты;
   - связанный subscriber;
   - merchant/source, если есть.

6. `Настройки`
   - subscription bot token;
   - subscription bot username;
   - webhook URL/status;
   - admin chat id;
   - напоминания включены/выключены;
   - за сколько дней предупреждать;
   - TTL invite-ссылки;
   - тексты сообщений.

Настройки можно держать на странице модуля, не обязательно дублировать в общей `/settings/`. Это делает модуль цельным и переносимым.

## 6. Root-включение модуля

Нужно расширить существующий company contour registry.

Рекомендуемое решение:

- добавить новую группу `company_modules`;
- добавить код модуля `telegram_channels`;
- хранить флаг компании в `crm_settings`:
  `module_telegram_channels_enabled = 1|0`.

Root UI:

- в root company settings modal добавить чекбокс `Telegram-каналы`;
- при включении сеять дефолтные настройки и три тарифа;
- при выключении не удалять данные, только блокировать UI и Telegram flow.

Все runtime checks должны использовать единый helper:

- `crm_company_contour_is_enabled($company_id, 'telegram_channels')`
  или новый тонкий wrapper поверх него.

## 7. Настройки модуля

Все настройки company-scoped. Хранение в `crm_settings` с `org_id = company_id`.

Рекомендуемые ключи:

- `telegram_subscription_bot_token`
- `telegram_subscription_bot_username`
- `telegram_subscription_webhook_url`
- `telegram_subscription_webhook_connected_at`
- `telegram_subscription_webhook_last_error`
- `telegram_subscription_webhook_lock`
- `telegram_channels_admin_chat_id`
- `telegram_channels_reminders_enabled`
- `telegram_channels_reminder_days`
- `telegram_channels_invite_ttl_hours`
- `telegram_channels_texts_json`
- `telegram_channels_debug`

Если расширяем существующий `crm_telegram_bot_context_labels()`, добавить контекст:

- `subscription` => `Subscription bot`

Тогда callback route должен быть отдельным:

- `/wp-json/malibu-exchange/v1/telegram/subscription-callback?company={company_id}`

Нельзя смешивать token/webhook subscription bot с merchant/operator/service bot.

## 8. Тарифы

В первой версии обязательны три тарифа.

Миграция должна создать для каждой компании при включении/инициализации:

| Код | Название | duration_days | Цена | Статус |
| --- | --- | ---: | ---: | --- |
| `monthly` | Месяц | 30 | 0/null | disabled |
| `quarterly` | Квартал | 90 | 0/null | disabled |
| `yearly` | Год | 365 | 0/null | disabled |

Правило готовности:

- модуль не должен работать публично, пока цена не заполнена у всех трёх тарифов;
- цена должна быть `> 0`;
- валюта должна быть задана;
- нельзя показывать тарифы клиенту и нельзя создавать payment order, если хотя бы одна цена не заполнена.

Месяц считается как последние 30 дней, не календарный месяц.

Продление:

```php
$base = max($now_utc, $subscription_until_utc);
$new_until = $base + ($duration_days * DAY_IN_SECONDS);
```

Рекомендуемые duration:

- месяц = 30 дней;
- квартал = 90 дней;
- год = 365 дней.

## 9. Рекомендуемые таблицы

Все таблицы используют literal prefix `crm_`, создаются только через `inc/migrations/*.php`.

### 9.1 `crm_telegram_channels`

Один канал на компанию в первой версии, но схема должна не мешать нескольким каналам позже.

Поля:

- `id`
- `company_id`
- `title`
- `telegram_channel_id`
- `telegram_channel_username`
- `status` (`draft`, `active`, `disabled`)
- `bot_admin_checked_at`
- `bot_admin_check_status`
- `bot_admin_check_error`
- `created_by_user_id`
- `created_at`
- `updated_at`

Индексы:

- unique `company_id` для первой версии;
- index `company_id,status`.

### 9.2 `crm_telegram_channel_tariffs`

Поля:

- `id`
- `company_id`
- `channel_id`
- `code` (`monthly`, `quarterly`, `yearly`)
- `title`
- `duration_days`
- `price_amount`
- `price_currency`
- `status` (`disabled`, `active`)
- `sort_order`
- `created_at`
- `updated_at`

Индексы:

- unique `company_id, channel_id, code`;
- index `company_id, status`.

### 9.3 `crm_telegram_channel_sales`

Сущность для merchant-issued client links до момента оплаты.

Поля:

- `id`
- `company_id`
- `channel_id`
- `tariff_id`
- `merchant_id`
- `created_from_context` (`merchant_bot`, `web`, `admin`)
- `start_payload`
- `client_telegram_user_id`
- `client_chat_id`
- `status` (`new`, `opened`, `payment_created`, `paid`, `expired`, `cancelled`)
- `payment_order_id`
- `expires_at`
- `opened_at`
- `created_at`
- `updated_at`

Индексы:

- unique `start_payload`;
- index `company_id,status,created_at`;
- index `company_id,merchant_id,created_at`.

### 9.4 `crm_telegram_channel_subscribers`

Текущее состояние подписки клиента.

Поля:

- `id`
- `company_id`
- `channel_id`
- `telegram_user_id`
- `chat_id`
- `username`
- `first_name`
- `last_name`
- `current_tariff_id`
- `subscription_start`
- `subscription_until`
- `status` (`active`, `expired`, `cancelled`)
- `last_payment_order_id`
- `last_invite_link`
- `last_invite_created_at`
- `reminder_sent_for_until`
- `removed_from_channel_at`
- `remove_from_channel_status`
- `remove_from_channel_error`
- `created_at`
- `updated_at`

Индексы:

- unique `company_id, channel_id, telegram_user_id`;
- index `company_id,status,subscription_until`;
- index `company_id,chat_id`.

### 9.5 `crm_telegram_channel_payments`

История оплат/продлений.

Поля:

- `id`
- `company_id`
- `channel_id`
- `subscriber_id`
- `tariff_id`
- `sale_id`
- `payment_order_id`
- `provider_code`
- `amount`
- `currency`
- `paid_at`
- `period_from`
- `period_until`
- `created_at`

Индексы:

- unique `payment_order_id`;
- index `company_id,subscriber_id,paid_at`;
- index `company_id,channel_id,paid_at`.

### 9.6 `crm_telegram_channel_invites`

История invite-ссылок в закрытый канал.

Поля:

- `id`
- `company_id`
- `channel_id`
- `subscriber_id`
- `telegram_user_id`
- `invite_link`
- `telegram_invite_link_id`
- `expire_date`
- `member_limit`
- `status` (`created`, `sent`, `failed`, `revoked`)
- `telegram_response_json`
- `error_message`
- `created_at`
- `sent_at`

Индексы:

- index `company_id,subscriber_id,created_at`;
- index `company_id,status,created_at`.

## 10. Telegram Bot API

Subscription bot должен использовать существующую библиотеку `callbacks/telegram/Telegram.php`.

Библиотека поддерживает `__call()`, поэтому можно вызывать методы Bot API без добавления отдельного метода:

- `createChatInviteLink`;
- `banChatMember`;
- `unbanChatMember`;
- `getChatMember`;
- `sendMessage`;
- `answerCallbackQuery`.

### 10.1 Invite-ссылка

После успешной оплаты:

- создать одноразовую ссылку в закрытый канал;
- `member_limit = 1`;
- `expire_date` = now + configured TTL;
- отправить клиенту inline button с URL.

### 10.2 Удаление после окончания подписки

Рекомендуемая стратегия:

1. `banChatMember` для удаления из канала;
2. сразу `unbanChatMember`, чтобы пользователь мог снова войти после новой оплаты по новой invite-ссылке.

Если Telegram API вернул ошибку:

- статус подписки всё равно может стать `expired`;
- ошибка удаления логируется;
- в subscriber row сохраняется `remove_from_channel_status = failed`;
- cron должен уметь retry для failed removals.

## 11. Callback data

Для subscription bot использовать отдельный префикс, например `sub:`.

Рекомендуемые callback actions:

- `sub:main`
- `sub:status`
- `sub:tariffs`
- `sub:pay:{tariff_id}`
- `sub:invite`
- `sub:renew`
- `sub:help`

Для merchant bot использовать отдельный префикс, например `ch:`.

Рекомендуемые merchant actions:

- `ch:main`
- `ch:sell`
- `ch:tariff:{tariff_id}`
- `ch:create_sale:{tariff_id}`
- `ch:sales`
- `ch:orders`

Нельзя использовать callback data без префикса, чтобы не пересечься с существующими merchant/operator/service actions.

## 12. Тексты сообщений

Тексты должны быть редактируемыми через настройки модуля.

Рекомендуется хранить JSON в `telegram_channels_texts_json` и иметь дефолты в PHP helper на случай пустой настройки.

Ключи текстов:

- `payment_success`
- `subscription_active`
- `renewal_success`
- `expiry_warning`
- `expired`
- `tariffs_intro`
- `not_configured`
- `payment_created`
- `invite_reissued`
- `admin_payment_received`

Дефолтные тексты можно взять из исходного ТЗ, но не хардкодить как единственный источник.

## 13. Уведомления

### 13.1 Клиент

Subscription bot отправляет:

- успешная оплата;
- продление;
- активная подписка;
- скоро закончится;
- подписка закончилась;
- повторная invite-ссылка.

### 13.2 Админ/владелец

После оплаты отправлять сообщение в `telegram_channels_admin_chat_id`, если настройка заполнена.

Сообщение:

- пользователь;
- Telegram ID;
- тариф;
- сумма;
- доступ до;
- merchant/source, если есть.

### 13.3 Мерчант

Если sale создан из merchant bot, merchant получает статус:

- клиент открыл ссылку;
- платёж создан;
- платёж оплачен;
- доступ выдан.

Эти уведомления должны быть опциональными, чтобы не засорять чат.

## 14. Cron

Нужно добавить WP cron event, например:

- `malibu_telegram_channels_daily`

Задачи cron:

1. найти active subscriptions, у которых `subscription_until < now UTC`;
2. удалить пользователя из канала;
3. перевести subscription в `expired`;
4. отправить клиенту сообщение об окончании;
5. отправить reminder тем, у кого скоро окончание и reminder ещё не отправлялся для текущего `subscription_until`;
6. retry failed removals.

Настройки:

- `telegram_channels_reminders_enabled`;
- `telegram_channels_reminder_days`.

## 15. Payment integration

Платёжные ордера создаются через существующие fintech helpers.

Обязательное:

- `company_id` всегда > 0;
- `source_channel = telegram_channel_subscription`;
- `meta_json.module = telegram_channels`;
- `meta_json.sale_id`;
- `meta_json.channel_id`;
- `meta_json.tariff_id`;
- `meta_json.telegram_user_id`;
- `meta_json.chat_id`;
- `meta_json.merchant_id`, если есть.

Активация подписки должна быть идемпотентной:

- `crm_telegram_channel_payments.payment_order_id` unique;
- duplicate callback/poll не должен продлевать подписку второй раз;
- логировать duplicate как info/warning, не как fatal.

Paid side-effect должен срабатывать не только из `fintech_payment_callback_received`, а из общего terminal/paid order path. В текущем проекте это значит: подключаться к месту, где уже обрабатываются terminal side effects после callback/poll/manual status update.

## 16. Readiness check

Нужен helper, например:

- `crm_telegram_channels_get_readiness_status(int $company_id): array`

Он должен проверять:

- модуль включён root;
- company active;
- subscription bot token задан;
- subscription bot username задан;
- webhook подключён или может быть подключён;
- channel row существует;
- channel id заполнен;
- bot является администратором канала;
- три тарифа существуют;
- все три тарифа имеют price > 0;
- валюта задана;
- fintech provider настроен для компании;
- тексты валидны или есть дефолты.

Если readiness false:

- page показывает список проблем;
- subscription bot не показывает тарифы;
- merchant bot не создаёт sale/payment;
- payment order не создаётся.

## 17. RBAC

Минимальные новые permissions:

- `telegram_channels.view`
- `telegram_channels.settings`
- `telegram_channels.tariffs`
- `telegram_channels.subscribers`
- `telegram_channels.payments`
- `telegram_channels.sales`
- `telegram_channels.manage_subscribers`

Для первого этапа можно дать company owner/admin role полный набор.

Root user rule:

- root не должен попадать в CRM user listings;
- root не должен быть subscriber;
- root-only включение модуля делается через root company management UI.

## 18. Logging

Следовать `docs/LOGGING.md`.

Обязательные event codes:

- `telegram_channels.module_enabled`
- `telegram_channels.module_disabled`
- `telegram_channels.settings_updated`
- `telegram_channels.tariffs_updated`
- `telegram_channels.sale_created`
- `telegram_channels.client_started`
- `telegram_channels.payment_created`
- `telegram_channels.payment_paid`
- `telegram_channels.subscription_activated`
- `telegram_channels.subscription_renewed`
- `telegram_channels.invite_created`
- `telegram_channels.invite_failed`
- `telegram_channels.expiry_warning_sent`
- `telegram_channels.subscription_expired`
- `telegram_channels.member_removed`
- `telegram_channels.member_remove_failed`
- `telegram_channels.readiness_failed`

Логи не должны содержать bot token, provider secrets, private payloads, cookies, full auth headers.

## 19. Roadmap

### Этап 1. Foundation: schema, registry, RBAC

Цель: подготовить безопасный каркас без Telegram/payment side effects.

Сделать:

- миграции таблиц;
- seed settings;
- seed tariffs 30/90/365 с пустыми ценами;
- `company_modules.telegram_channels` в contour registry;
- root checkbox для включения модуля;
- RBAC permissions;
- helper readiness status.

Критерий:

- root может включить модуль компании;
- компания видит, что модуль включён, но не готов без цен/настроек;
- данные строго company-scoped.

### Этап 2. Page UI: Telegram-каналы

Сделать:

- `page-telegram-channels.php`;
- вкладки overview/channel/tariffs/subscribers/payments/settings;
- AJAX save settings;
- AJAX save tariffs;
- toast через штатный `pgNotification`;
- все запросы с company filter.

Критерий:

- company admin может заполнить channel id, bot settings, тарифы;
- readiness показывает точную причину блокировки;
- без всех трёх цен публичный flow заблокирован.

### Этап 3. Subscription bot callback

Сделать:

- добавить Telegram context `subscription`;
- REST route `subscription-callback`;
- connect webhook button/status;
- `/start {payload}`;
- callback actions `sub:*`;
- клиентское меню без merchant/internal функций.

Критерий:

- клиент открывает subscription bot;
- bot сохраняет client Telegram profile;
- без readiness bot показывает понятную ошибку и не создаёт payment.

### Этап 4. Merchant bot sale flow

Сделать:

- пункт "Telegram-канал" / "Продать подписку" в merchant bot;
- выбор тарифа;
- создание `crm_telegram_channel_sales`;
- выдача клиентской ссылки в subscription bot;
- merchant notification/status.

Критерий:

- merchant получает ссылку для клиента;
- sale row содержит company/channel/tariff/merchant;
- клиент по ссылке попадает в subscription bot.

### Этап 5. Payment order creation

Сделать:

- в subscription bot создать payment order по sale/tariff;
- source channel `telegram_channel_subscription`;
- корректный `meta_json`;
- отправить payment link/QR клиенту;
- показать статус.

Критерий:

- payment order виден в existing fintech orders;
- order не смешивается с обычными merchant invoice orders;
- company scope сохранён.

### Этап 6. Paid side-effect and invite

Сделать:

- идемпотентный paid side-effect;
- upsert subscriber;
- продление от `max(now, subscription_until)`;
- запись payment history;
- createChatInviteLink;
- отправка клиенту invite button;
- admin/merchant notifications.

Критерий:

- duplicate callback не продлевает повторно;
- активная подписка продлевается от текущего окончания;
- expired subscription продлевается от now.

### Этап 7. Cron reminders and expiration

Сделать:

- daily cron;
- reminder before expiry;
- expire active subscriptions;
- ban/unban channel member;
- retry failed removals;
- logs.

Критерий:

- истёкший клиент удаляется из канала;
- может войти снова после новой оплаты;
- ошибки Telegram API видны в logs/page status.

### Этап 8. Hardening and reporting

Сделать:

- фильтры подписчиков/платежей;
- ручная повторная invite-ссылка;
- ручная отмена подписки;
- root-only aggregate page позже, если потребуется;
- audit review;
- debug-log self-service protocol for failures.

## 20. Что не делать в первой версии

- не делать несколько каналов на компанию;
- не шарить subscription bot между компаниями;
- не переносить клиентов в WP users;
- не строить React/Vue/build pipeline;
- не делать отдельный внешний сайт;
- не хранить настройки в `wp_options`;
- не делать payment activation только по raw provider callback;
- не ослаблять company filters ради root overview;
- не показывать клиенту merchant/operator/service меню.

## 21. Открытые вопросы перед реализацией

Пока явных blocker-вопросов нет.

Все изменяемые бизнес-параметры выносятся в настройки модуля:

- цены;
- валюта;
- тексты;
- включение напоминаний;
- дни напоминаний;
- TTL invite;
- admin chat id;
- channel id/title;
- bot token/username.

Если в реализации всплывёт решение, которое нельзя безопасно вынести в настройки и которое влияет на бизнес-поведение, нужно остановиться и задать вопрос пользователю.
