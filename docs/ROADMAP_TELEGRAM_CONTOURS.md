# Roadmap: Telegram contours, merchant invoices, payouts

Дата: 2026-05-08

Документ фиксирует рабочий план по разделению Telegram-контуров, merchant invoice flow через Kanyon, учету прибыли и выплатам мерчантам. Работа ведется поэтапно: после каждого этапа делаем деплой, тестирование и подтверждение результата перед переходом дальше.

## Базовые решения

- Иерархия системы:
  - root создает и настраивает компании;
  - каждая компания ведет своих CRM-операторов и мерчантов;
  - root не является CRM-пользователем компании и не участвует в company-scoped расчетах.
- У каждой компании свой платежный контур:
  - свои настройки провайдера;
  - свои логины, пароли, API keys;
  - свои provider/Telegram/merchant настройки.
- В системе есть два долговых контура:
  - внешний: эквайринг-партнер должен компании gross USDT по paid orders;
  - внутренний: компания должна мерчанту merchant payable по paid merchant orders.
- Внешний и внутренний контуры нельзя смешивать:
  - выплаты от ЭП закрывают долг ЭП перед компанией;
  - выплаты мерчантам закрывают долг компании перед конкретным мерчантом.
- Company/operator orders и merchant orders живут в общем payment order storage, но должны быть различимы по субъекту:
  - `created_for_type = company|merchant`;
  - `source_channel = web|telegram_operator|telegram_merchant|...`;
  - для web/operator orders должен быть понятен создавший CRM user/operator context;
  - для merchant orders должен быть понятен `merchant_id`.
- В системе должны быть два разных Telegram-бота внутри каждой компании:
  - операторский бот;
  - мерчантский бот.
- Operator bot для company-order creation не должен жить на отдельной математике относительно web:
  - при `fintech_pay2day_order_currency = RUB` он должен запрашивать RUB и создавать Kanyon order через `paymentAmount`;
  - при `fintech_pay2day_order_currency = USDT` он должен сохранять legacy USDT contour через `orderAmount`.
- Operator bot для company-order creation не должен жить и на отдельной схеме `Ext Order ID` / payment purpose:
  - company default брать из `fintech_pay2day_default_payment_purpose`;
  - если оператору дадим возможность заменить назначение платежа перед выпуском счёта, это должен быть тот же contour, что и в web `create-order`;
  - в operator bot нельзя возвращаться к техническому internal order id как к видимому назначению платежа, если в web уже используется company/business purpose.
- Текущий Telegram-функционал считается legacy/base для merchant-контура, потому что уже содержит merchant invites и merchant menu.
- Мерчант вводит сумму в RUB.
- Первый платежный контур для merchant invoice - только Kanyon.
- Doverka остается в текущем состоянии и не участвует в merchant invoice MVP.
- Для определения курса Kanyon создается тестовый order, который обязательно помечается как технический и не участвует в бизнес-статистике.
- Наценка merchant order полностью принадлежит платформе.
- Бонусная и реферальная механика сейчас не включается в бизнес-логику, но поля и архитектура не должны мешать добавить ее позже.
- Kanyon выплачивает USDT платформе. Платформа отдельно фиксирует входящую выплату от Kanyon и отдельно фиксирует выплаты мерчантам.
- Выплата мерчанту на первом этапе ручная: оператор нажимает кнопку, указывает сумму, опционально TRC20 tx hash и скриншот.

## Расчетная модель компании

Для каждой компании нужно видеть минимум четыре агрегата:

- `acquirer_debt`: сколько ЭП должен компании.
  Расчет: paid orders gross USDT минус зафиксированные выплаты от ЭП, обязательно в разрезе `company_id + provider_code`.
- `merchant_debt`: сколько компания должна всем мерчантам.
  Расчет: merchant accruals минус merchant payouts, в разрезе `company_id`, `merchant_id` и типа баланса.
- `merchant_payouts_total`: сколько компания уже выплатила мерчантам.
- `company_profit_balance`: накопленная прибыль компании по merchant markup/fee и другим company-owned поступлениям, с учетом будущих withdrawals.

Баланс мерчанта должен разделяться минимум на:

- основной баланс / к выплате: `merchant_accrual + manual_credit - merchant_payouts/manual_debit`;
- бонусный баланс: задел под будущую бонусную механику;
- реферальный баланс: задел под будущую реферальную механику.

`Ledger` не является основным рабочим экраном выплат. Это вспомогательная история движений по мерчанту. Основной рабочий экран для закрытия долга перед мерчантами - отдельная страница выплат мерчантам.

## Экономическая модель merchant order

Мерчант вводит `requested_rub`.

Система:

1. Создает Kanyon quote/test order.
2. Получает текущий `base_rate_rub_usdt`.
3. Считает gross USDT с округлением вниз так, чтобы финальный RUB-счет не превышал сумму, введенную мерчантом.
4. Создает реальный Kanyon order.
5. Сохраняет фактическую RUB-сумму, которую вернул Kanyon.

Поля, которые должны быть понятны в order/economics:

- `amount_asset_value` - gross USDT, который Kanyon должен выплатить платформе.
- `payment_amount_value` - фактическая RUB-сумма счета от Kanyon.
- `merchant_requested_rub_value` - RUB-сумма, которую ввел мерчант.
- `merchant_payable_value` - USDT, который платформа должна выплатить мерчанту.
- `merchant_markup_value` - разница в USDT между gross и merchant payable.
- `platform_fee_value` - прибыль платформы. На MVP равна merchant markup.
- `referral_reward_value` - 0 на MVP.
- `merchant_profit_value` - не использовать на MVP или хранить 0, чтобы не путать с прибылью платформы.

## Этап 0. Roadmap

Статус: done

Цель: зафиксировать план в отдельном файле и дальше работать по нему.

Изменения:

- Создать этот документ.
- Задеплоить документ на тестовый сервер.

Проверка:

- Файл существует в `docs/ROADMAP_TELEGRAM_CONTOURS.md`.
- Файл доступен в проекте после деплоя.

## Этап 1. Разделить Telegram settings и callback routes

Статус: done

Цель: убрать смешение operator/merchant Telegram-контуров.

Изменения:

- Добавить company-scoped настройки:
  - `telegram_merchant_bot_token`
  - `telegram_merchant_bot_username`
  - `telegram_merchant_webhook_url`
  - `telegram_merchant_webhook_connected_at`
  - `telegram_merchant_webhook_last_error`
  - `telegram_merchant_webhook_lock`
  - `telegram_operator_bot_token`
  - `telegram_operator_bot_username`
  - `telegram_operator_webhook_url`
  - `telegram_operator_webhook_connected_at`
  - `telegram_operator_webhook_last_error`
  - `telegram_operator_webhook_lock`
- Миграцией скопировать старые `telegram_bot_*` в merchant-настройки, если merchant-настройки пустые.
- Обновить settings UI: две отдельные карточки "Telegram - мерчантский бот" и "Telegram - операторский бот".
- Добавить два REST route:
  - `/wp-json/malibu-exchange/v1/telegram/merchant-callback`
  - `/wp-json/malibu-exchange/v1/telegram/operator-callback`
- Сохранить старый `/telegram/callback-universal` как временный compatibility route.
- Подготовить переименование callback-файлов:
  - `callbacks/telegram/telegram-callback-merchant.php`
  - `callbacks/telegram/telegram-callback-operator.php`
- Запретить использование одного и того же Telegram-бота в двух контурах одной компании:
  - совпадение по token;
  - совпадение по username.

Проверка:

- В настройках компании видны два отдельных Telegram-блока.
- Merchant bot callback URL содержит `company=ID`.
- Operator bot callback URL содержит `company=ID`.
- Подключение webhook работает отдельно для каждого бота.
- Система не дает сохранить одинаковый token или username в merchant/operator блоках.
- Merchant invite flow не ломается после миграции старых настроек.

Провал:

- Старые merchant invites перестали работать.
- Один бот перезаписывает webhook другого.
- Один и тот же token или username можно сохранить в двух контурах.
- Callback теряет company context.
- Non-root компания может увидеть или сохранить настройки другой компании.

## Этап 2. Операторский Telegram-доступ

Статус: testing

Цель: привязать операторский бот к CRM-пользователям без создания отдельной бизнес-сущности "operator".

Изменения:

- Создать отдельную страницу "Операторский бот" или "Операторы Telegram".
- Хранить Telegram-привязку к существующему WP/CRM user.
- Возможная схема:
  - `crm_user_telegram_accounts`
  - `crm_operator_telegram_invites`
- Исключить root из любых списков.
- Привязка всегда company-scoped.
- Первый подэтап: выдача invite-ссылки CRM-пользователю и привязка Telegram через operator bot.
- Операторское рабочее меню и команды идут следующим подэтапом после проверки привязки.
- Когда дойдём до operator order flow, не делать для него отдельный currency mode selector:
  - брать текущий contour компании из Kanyon settings;
  - использовать ту же логику, что и на странице `create-order`.
- Аналогично, не делать для operator order flow отдельный источник `Ext Order ID`:
  - брать company default payment purpose из тех же company settings;
  - повторять web-логику показа и override этого значения.

Проверка:

- Администратор компании может выдать invite оператору.
- Оператор привязывает Telegram через operator bot.
- Оператор видит только свою компанию.
- Root не появляется в UI.

Провал:

- Оператор без компании получает доступ.
- Оператор одной компании видит данные другой.
- Root появляется в списке.

## Этап 3. Merchant invoice MVP через Kanyon

Цель: активный мерчант может выставить счет из merchant bot, вводя RUB.

Изменения:

- В merchant bot добавить pipeline создания счета:
  - выбрать направление `RUB -> USDT`;
  - ввести RUB-сумму;
  - получить Kanyon quote/test order;
  - рассчитать gross USDT с округлением вниз;
  - создать реальный Kanyon order;
  - отправить мерчанту QR/платежные данные.
- Тестовые Kanyon orders маркировать как технические:
  - `source_channel = rate_check` или отдельный `merchant_quote`;
  - `status_code = untracked`;
  - `local_order_ref = kanyon_rate_check` или `merchant_quote_rate`;
  - `meta_json.purpose`.
- Реальные merchant orders сохранять как:
  - `created_for_type = merchant`;
  - `merchant_id > 0`;
  - `source_channel = merchant_telegram`;
  - `provider_code = kanyon`.

Проверка:

- Активный мерчант вводит RUB и получает счет.
- Тестовый order не отображается в обычном списке ордеров.
- Реальный order виден в списке ордеров мерчанта.
- Сумма счета не превышает введенную RUB-сумму.

Провал:

- Тестовый order попал в dashboard/payout stats.
- Реальный order создан без `merchant_id`.
- Order создан в `company_id = 0`.
- Doverka случайно используется в merchant invoice flow.

## Этап 4. Settlement on paid

Цель: при оплате merchant order один раз фиксировать долг мерчанту и прибыль платформы.

Изменения:

- Добавить недостающие economics columns через миграцию.
- При переходе order в `paid`:
  - проверить `created_for_type = merchant`;
  - создать settlement/accrual запись для мерчанта;
  - зафиксировать platform profit;
  - сделать операцию идемпотентной.
- Повторные callbacks/polls не должны создавать дубль начисления.

Проверка:

- Paid merchant order создает одно начисление.
- Повторный callback не создает второе начисление.
- Company/operator order не создает merchant accrual.

Провал:

- Начисление появляется дважды.
- Начисление создается для неоплаченного order.
- Начисление создается для operator/company order.

## Этап 5. Merchant payouts

Статус: testing

Цель: вручную фиксировать выплаты USDT мерчантам.

Изменения:

- Создать таблицу `crm_merchant_payouts`.
- Поля MVP:
  - `company_id`
  - `merchant_id`
  - `amount_usdt`
  - `network` default `TRC20`
  - `wallet_address`
  - `tx_hash`
  - `receipt_filename`
  - `notes`
  - `paid_by_user_id`
  - `paid_at`
  - `created_at`
- Создать отдельную страницу выплат мерчантам по аналогии с `/payouts/`.
- Страница должна быть company-scoped и показывать простую таблицу по мерчантам:
  - мерчант;
  - основной баланс / к выплате;
  - бонусный баланс;
  - реферальный баланс;
  - всего к выплате;
  - уже выплачено;
  - последнее движение/выплата.
- В строке мерчанта добавить action menu с пунктом "Произвести выплату".
- В форме выплаты:
  - сумма USDT;
  - `network` через Select2, первый вариант `TRC20`;
  - `wallet_address` необязательно;
  - `tx_hash` необязательно;
  - скриншот/receipt необязательно;
  - комментарий необязательно.
- Поддержать загрузку скриншота.
- Выплата должна создавать payout-row и debit-запись в merchant ledger, чтобы долг мерчанту уменьшался.
- Bonus/referral показатели на MVP могут быть нулевыми, но UI и агрегаты должны быть готовы к расширению.

Проверка:

- Оператор может отметить выплату мерчанту.
- Скриншот опционален.
- Wallet address опционален.
- Tx hash опционален.
- Долг мерчанту уменьшается на сумму выплаты.
- Выплата видна в истории выплат мерчантам и в ledger-истории конкретного мерчанта.

Провал:

- Выплата одного мерчанта влияет на другого.
- Выплату можно создать в другой компании.
- Выплата создается без company filter.
- Выплата не уменьшает основной баланс мерчанта.

## Этап 5A. Ledger / баланс мерчанта

Статус: testing

Цель: привести существующий ledger UI к реальной расчетной модели, не делая его главным рабочим экраном выплат.

Изменения:

- В `crm_get_merchant_balance_summary_map()` добавить явный основной баланс.
- Основной баланс должен считать начисления по paid merchant orders и списания выплат.
- В списке мерчантов и ledger modal главным показывать основной баланс / к выплате.
- Бонусный и реферальный балансы оставить вторичными.
- Пункт меню `Ledger` переименовать в `Баланс` или `История баланса`.
- Ledger modal оставить как историю движений, а не как экран ручных выплат.

Проверка:

- У мерчанта с paid merchant order основной баланс больше нуля.
- Бонус/рефка не маскируют основной долг.
- Выплата мерчанту уменьшает основной баланс.

Провал:

- Основной долг опять виден только внутри `Итого`.
- Бонусный баланс выглядит как главный.
- Ledger используется как единственный рабочий экран выплат.

## Этап 6. Provider-aware acquirer payouts

Статус: next

Цель: корректно учитывать выплаты от Kanyon отдельно от других провайдеров.

Изменения:

- Добавить `provider_code` в `crm_acquirer_payouts`.
- Для текущих данных выбрать безопасный backfill, вероятно `kanyon`, если фактический контур был Kanyon.
- Обновить UI выплат от эквайринга: фильтр/поле провайдера.
- Статистика долга должна считаться по `company_id + provider_code`.

Проверка:

- Можно внести выплату от Kanyon.
- Долг Kanyon уменьшается.
- Doverka не смешивается с Kanyon.

Провал:

- Общая выплата закрывает долг другого провайдера.
- Provider field можно подменить на недоступный компании провайдер.

## Этап 6A. Company wallet withdrawals

Статус: backlog

Цель: фиксировать вывод средств компанией из собственного накопленного баланса/прибыли.

Изменения:

- Создать отдельную таблицу для company withdrawals или company wallet ledger.
- Поля MVP:
  - `company_id`;
  - `amount_usdt`;
  - `network` default `TRC20`;
  - `wallet_address` необязательно;
  - `tx_hash` необязательно;
  - `receipt_filename` необязательно;
  - `notes` необязательно;
  - `created_by_user_id`;
  - `created_at`.
- Вывод должен уменьшать company profit/wallet balance.
- Операторские/company orders без merchant markup должны учитываться отдельно от merchant profit.

Проверка:

- Компания может зафиксировать withdrawal.
- Withdrawal не влияет на баланс мерчантов.
- Withdrawal не закрывает долг ЭП.

Провал:

- Вывод компании списывает долг мерчанта.
- Вывод компании меняет provider/acquirer debt.
- Запись создается без company scope.

## Этап 7. Dashboard компании

Цель: показать владельцу компании понятную картину.

Разрезы:

- Operator/self web orders.
- Operator Telegram orders.
- Merchant orders.
- Open/paid/cancelled.
- Долг ЭП перед компанией по provider_code.
- Долг компании перед мерчантами.
- Выплачено мерчантам.
- Прибыль платформы по merchant markup.
- Company profit/wallet balance.
- Company withdrawals.
- Деньги/ордера, которые висят в operator/self контуре без merchant-процента.

Проверка:

- Company dashboard не показывает данные другой компании.
- Root не использует этот экран для all-company аналитики.

## Этап 8. Root dashboard v2

Цель: отдельная root-only аналитика по всем компаниям.

Разрезы:

- Company.
- Provider.
- Operator vs merchant.
- Acquirer debt.
- Merchant payable.
- Merchant payouts.
- Platform profit.
- Company withdrawals.
- Operator/self volume.

Проверка:

- Только root видит страницу.
- Обычные company pages не ослабляют фильтры ради root.

## Этап 9. Cleanup старого Telegram universal

Цель: убрать legacy-неопределенность после успешного разделения ботов.

Изменения:

- Проверить, что Telegram webhook-и смотрят на новые routes.
- Удалить или оставить редиректом старый universal route.
- Удалить старый remote callback-файл через SFTP, если deploy script поддерживает удаление.
- Если удаление через deploy невозможно, удалить вручную через файловый менеджер.

Проверка:

- Старый route не используется Telegram webhook-ами.
- Merchant bot работает.
- Operator bot работает.

## Текущий следующий шаг

Текущий рабочий фокус после уточнения расчетной модели:

1. Этап 5A: привести ledger/balance UI к основной сумме "к выплате".
2. Этап 5: создать отдельную company-scoped страницу выплат мерчантам.
3. Этап 6: сделать выплаты ЭП provider-aware.
4. Этап 7: собрать company dashboard с внешним долгом ЭП, внутренним долгом перед мерчантами, выплатами, profit/wallet и withdrawals.
5. После этого вернуться к этапу 3: полноценный merchant invoice flow через merchant bot.
