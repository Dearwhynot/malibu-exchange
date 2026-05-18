# Roadmap: Service Bot, company ACL, Telegram binding

Дата: 2026-05-17

Этот roadmap фиксирует поэтапную реализацию service Telegram bot для компании. Цель — не делать сразу гигантский “второй сайт в Telegram”, а последовательно перенести критичные действия из backoffice в управляемый service contour.

## Базовые решения

- Service bot — отдельный Telegram context компании.
- Доступ в service bot только для явно разрешённых CRM-пользователей компании.
- Root в этом контуре не участвует.
- Telegram-привязка пользователя должна идти через invite-link и `/start payload`, а не вручную по chat_id.
- Сайт и service bot должны использовать общий backend contour для операций.
- Нельзя делать отдельную Telegram-математику и отдельные Telegram-валидаторы там, где уже есть рабочий web contour.
- Первый executable flow в service bot: `merchant payouts`.
- Второй executable flow: `acquirer payouts`.
- Полное дублирование control panel в Telegram — это поздний этап, а не первый milestone.

## Этап 0. Документация

Статус: done

Цель:

- зафиксировать ТЗ;
- зафиксировать roadmap;
- подготовить prompt для следующего чата.

Проверка:

- есть `docs/TZ_SERVICE_BOT_COMPANY_ACL.md`;
- есть `docs/ROADMAP_SERVICE_BOT_COMPANY_ACL.md`;
- есть `docs/PROMPT_NEXT_CHAT_SERVICE_BOT_COMPANY_ACL.md`.

## Этап 1. Service ACL и data model

Статус: pending

Цель:

подготовить таблицы и permissions для service bot, не ломая operator contour.

Изменения:

- добавить новые permissions:
  - `service.telegram.view`
  - `service.telegram.invite`
  - `service.telegram.merchant_payouts`
  - `service.telegram.acquirer_payouts`
  - `service.telegram.orders`
  - `service.telegram.rates`
- добавить таблицу `crm_service_telegram_invites`;
- добавить таблицу `crm_service_telegram_access`;
- не трогать root;
- не ослаблять company isolation.

Проверка:

- миграции создают таблицы без ручных SQL;
- permissions появляются в RBAC;
- root не попадает в service users flow;
- данные company-scoped.

Провал:

- доступ можно выдать пользователю другой компании;
- root появляется в invite/access таблицах;
- callback сможет пройти без access-check.

## Этап 2. UI на users page

Статус: pending

Цель:

дать компании управлять service bot access на странице пользователей.

Изменения:

- добавить action menu на users page:
  - `Выдать Service invite`
  - `Отозвать Service access`
  - `История Service invites`
- показывать service bot status:
  - `Не привязан`
  - `Invite выдан`
  - `Привязан`
  - `Доступ отозван`
- не смешивать это с merchant logic.

Проверка:

- администратор компании видит action menu только в своей компании;
- можно выдать invite;
- можно отозвать access;
- история читается только в рамках компании.

Провал:

- пользователь компании A видит access пользователя компании B;
- root показывается среди кандидатов;
- invite создаётся без permission-check.

## Этап 3. Service bot binding и callback ACL

Статус: pending

Цель:

запустить service `/start` flow и запретить доступ всем, кроме явно разрешённых пользователей.

Изменения:

- добавить service invite handler;
- обрабатывать `/start svc_...`;
- использовать `crm_user_telegram_accounts` как профиль Telegram пользователя;
- активировать доступ через `crm_service_telegram_access`;
- на обычный `/start` без доступа отдавать отказ.

Проверка:

- без invite сервисный бот не открывается;
- с валидным invite пользователь привязывается;
- при revoke access повторный `/start` не открывает меню;
- company scope сохраняется.

Провал:

- любой chat_id получает доступ;
- invite одной компании активирует доступ в другой;
- привязка работает без явного service ACL.

## Этап 4. Service bot shell и menu

Статус: pending

Цель:

собрать минимальное service menu и session contour.

Изменения:

- главное меню service bot;
- slash-команды;
- inline navigation;
- только разрешённые пункты;
- логирование входов и отказов.

Проверка:

- пользователь с access видит меню;
- пользователь без access получает отказ;
- в меню нет разделов без permission.

Провал:

- меню одинаковое для всех;
- нет ACL по пунктам;
- компания без contour видит выключенные разделы.

## Этап 5. Merchant payouts в service bot

Статус: pending

Цель:

перенести ручную выплату мерчанту в Telegram service bot.

Изменения:

- выбрать мерчанта;
- показать баланс `к выплате`;
- собрать:
  - amount;
  - network;
  - wallet;
  - `tx_hash`;
  - screenshot;
  - notes;
- вызывать shared backend create payout;
- после save отправлять мерчанту payout notification.

Проверка:

- payout создаётся из service bot;
- запись попадает в `crm_merchant_payouts`;
- ledger создаётся один раз;
- мерчант получает уведомление и screenshot/link.

Провал:

- Telegram и web создают payout по разной логике;
- service bot сохраняет payout напрямую в БД без shared contour;
- уведомление мерчанту не доходит.

## Этап 6. Acquirer payouts в service bot

Статус: pending

Цель:

перенести фиксацию входящих выплат от ЭП в service bot.

Изменения:

- выбрать provider;
- ввести amount;
- приложить wallet / tx / screenshot / notes;
- вызвать shared backend contour;
- записать audit trail.

Проверка:

- выплата от ЭП корректно создаётся из service bot;
- company debt/summary пересчитывается так же, как на сайте;
- screenshot и metadata сохраняются.

Провал:

- сайт и Telegram пишут разные сущности;
- provider можно выбрать вне разрешённого company contour;
- операция проходит без company scope.

## Этап 7. Read-only разделы

Статус: pending

Цель:

дать безопасный read-only доступ к ключевым данным компании из Telegram.

Изменения:

- `Orders`
- `Rates`
- `Merchants`
- `Balances`

Проверка:

- списки фильтруются по компании;
- нет cross-company leakage;
- выключенные company contours не показываются.

Провал:

- service bot показывает чужие заказы;
- старые выключенные контуры видны в меню;
- root aggregate accidentally leaking в обычную компанию.

## Этап 8. Create-order contour

Статус: pending

Цель:

перенести в service bot company order creation.

Изменения:

- использовать тот же contour, что и web `create-order` и operator bot;
- при `fintech_pay2day_order_currency = RUB` использовать RUB input + `paymentAmount`;
- при `USDT` использовать legacy `orderAmount`;
- использовать тот же `payment purpose / Ext Order ID`.

Проверка:

- web / operator / service создают ордера по одной логике;
- не возникает отдельной service-математики;
- payment purpose одинаковый.

Провал:

- service bot начинает жить на отдельном order contour;
- RUB/USDT logic расходится с web/operator;
- payment purpose отличается.

## Этап 9. Hardening и rollout

Статус: pending

Цель:

закрыть остаточные риски перед активным использованием.

Изменения:

- логирование;
- revoke / blocked flows;
- invite expiration;
- ретест callback ACL;
- аккуратные Telegram texts;
- cleanup временных states.

Проверка:

- revoke работает;
- старые invites не проходят;
- ошибки не открывают доступ;
- audit logs читаемые.

Провал:

- заблокированный пользователь всё ещё входит;
- invite reuse работает повторно;
- логи не позволяют восстановить картину действий.

## Рекомендуемый старт следующего чата

Не пытаться делать весь service bot сразу.

Правильный первый implementation milestone:

1. `crm_service_telegram_invites`
2. `crm_service_telegram_access`
3. новые permissions
4. users page action menu
5. `/start svc_...` + ACL

Только после этого переходить к `merchant payouts`.
