# Roadmap: Telegram contours, merchant invoices, payouts

Дата: 2026-05-08

Документ фиксирует рабочий план по разделению Telegram-контуров, merchant invoice flow через Kanyon, учету прибыли и выплатам мерчантам. Работа ведется поэтапно: после каждого этапа делаем деплой, тестирование и подтверждение результата перед переходом дальше.

## Базовые решения

- В системе должны быть два разных Telegram-бота внутри каждой компании:
  - операторский бот;
  - мерчантский бот.
- Текущий Telegram-функционал считается legacy/base для merchant-контура, потому что уже содержит merchant invites и merchant menu.
- Мерчант вводит сумму в RUB.
- Первый платежный контур для merchant invoice - только Kanyon.
- Doverka остается в текущем состоянии и не участвует в merchant invoice MVP.
- Для определения курса Kanyon создается тестовый order, который обязательно помечается как технический и не участвует в бизнес-статистике.
- Наценка merchant order полностью принадлежит платформе.
- Бонусная и реферальная механика сейчас не включается в бизнес-логику, но поля и архитектура не должны мешать добавить ее позже.
- Kanyon выплачивает USDT платформе. Платформа отдельно фиксирует входящую выплату от Kanyon и отдельно фиксирует выплаты мерчантам.
- Выплата мерчанту на первом этапе ручная: оператор нажимает кнопку, указывает сумму, опционально TRC20 tx hash и скриншот.

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

Цель: вручную фиксировать выплаты USDT мерчантам.

Изменения:

- Создать таблицу `crm_merchant_payouts`.
- Поля MVP:
  - `company_id`
  - `merchant_id`
  - `amount_usdt`
  - `network` default `TRC20`
  - `tx_hash`
  - `receipt_filename`
  - `notes`
  - `paid_by_user_id`
  - `paid_at`
  - `created_at`
- Добавить кнопку "Выплатили" в merchant UI.
- Поддержать загрузку скриншота.

Проверка:

- Оператор может отметить выплату мерчанту.
- Скриншот опционален.
- Tx hash опционален.
- Долг мерчанту уменьшается на сумму выплаты.

Провал:

- Выплата одного мерчанта влияет на другого.
- Выплату можно создать в другой компании.
- Выплата создается без company filter.

## Этап 6. Provider-aware acquirer payouts

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

## Этап 7. Dashboard компании

Цель: показать владельцу компании понятную картину.

Разрезы:

- Operator/self orders.
- Merchant orders.
- Open/paid/cancelled.
- Долг Kanyon перед платформой.
- Долг платформы перед мерчантами.
- Выплачено мерчантам.
- Прибыль платформы по merchant markup.

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

Этап 1 закрыт после UI/UX-доводки настроек Telegram-контуров. Текущий фокус - этап 2: проверить и довести операторский Telegram-доступ:

- страница `/operator-telegram/`;
- список CRM-пользователей текущей компании без root;
- выдача invite-ссылки пользователю;
- привязка Telegram через operator bot по `/start op_*`;
- проверка company-scoped ограничений.

После подтверждения этапа 2 переходим к этапу 3: merchant invoice MVP через Kanyon.
