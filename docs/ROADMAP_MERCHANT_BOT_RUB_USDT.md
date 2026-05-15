# Roadmap: Merchant Bot RUB -> USDT через Rapira + Kanyon

Дата: 2026-05-16

Этот документ заменяет старую идею merchant invoice flow через Kanyon check-order на 100 USDT. Новый пилотный контур: мерчант работает только через Telegram, вводит сумму в RUB, система считает экономику по Rapira и создаёт Kanyon invoice.

## 0. Базовые решения

- Пилотный merchant-flow: только `RUB -> USDT`.
- Источник базового курса: Rapira `USDT/RUB`, верхний курс стакана / `askPrice`.
- У каждой компании должен быть company-scoped параметр наценки эквайринг-партнёра к Rapira, по пилоту дефолт `6%`.
- У каждого мерчанта есть свой процент из карточки мерчанта.
- Мерчант вводит только RUB-сумму счёта.
- Мерчанту не показываем platform fee, acquirer gross, внутреннюю маржу и технические расчёты.
- В backoffice сохраняем полный снимок экономики каждого order.
- Kanyon остаётся платежным провайдером merchant invoice MVP.
- Doverka в merchant invoice MVP не участвует.
- Старый Kanyon check-order для курса merchant invoice больше не является источником расчёта.

## 1. Kanyon API contract

Текущий код Kanyon/Pay2Day уже умеет создавать order через `orderAmount`. Новый flow нужно расширить вариативностью, не ломая обратную совместимость.

### 1.1 Режим `orderAmount`

Используется для старых сценариев и для компаний, где настройка `fintech_pay2day_order_currency` равна `USDT`.

Семантика:

- вход системы: USDT amount;
- payload в Kanyon: `orderAmount`;
- локально `amount_asset_value` = переданная USDT-сумма;
- `payment_amount_value` заполняется из ответа Kanyon, если провайдер вернул RUB-сумму.

### 1.2 Режим `paymentAmount`

Используется для нового merchant-flow, когда настройка `fintech_pay2day_order_currency` равна `RUB`.

Семантика:

- вход системы: RUB amount, введённый мерчантом;
- payload в Kanyon: `paymentAmount`;
- Kanyon сам конвертирует RUB в валютную сумму order;
- локально `payment_amount_value` = RUB-сумма счёта;
- локально `amount_asset_value` = фактический USDT/gross, который вернул Kanyon в `orderAmount`;
- если Kanyon не вернул `orderAmount`, order нельзя считать экономически валидным: сохранять ошибку/лог, не создавать полноценный merchant accrual.

### 1.3 Правило совместимости

Не менять существующий смысл старого `crm_fintech_create_order($amount_usdt, ...)` без явного refactor. Для merchant-flow лучше добавить отдельный orchestration/helper, который ясно принимает RUB amount и вызывает Kanyon в режиме `paymentAmount`.

## 2. Формула экономики

Вход:

- `requested_rub` - RUB-сумма, которую ввёл мерчант.
- `rapira_ask_rate` - живой Rapira ask, RUB за 1 USDT.
- `acquirer_markup_percent` - company setting, пилотный дефолт `6`.
- `merchant_markup_percent` - процент из карточки мерчанта.
- `kanyon_gross_usdt` - фактический USDT/gross из ответа Kanyon.

Расчёт:

- `acquirer_rate = rapira_ask_rate * (1 + acquirer_markup_percent / 100)`.
- `merchant_rate = acquirer_rate * (1 + merchant_markup_percent / 100)`.
- `merchant_payable_usdt = requested_rub / merchant_rate`.
- `platform_fee_usdt = max(kanyon_gross_usdt - merchant_payable_usdt, 0)`.
- `merchant_markup_value = platform_fee_usdt`.
- `referral_reward_value = 0` на MVP.
- `merchant_profit_value = 0`, чтобы не путать прибыль мерчанта с прибылью платформы.

Важное правило: внешний долг ЭП перед компанией считаем по фактическому `kanyon_gross_usdt`, а внутренний долг компании перед мерчантом считаем по `merchant_payable_usdt`.

## 3. Что хранить в order

В `crm_fintech_payment_orders` и `merchant_meta_json` нужно сохранять полный снимок, чтобы потом можно было сверить расчёт.

Обязательные поля order:

- `company_id > 0`.
- `created_for_type = merchant`.
- `merchant_id > 0`.
- `source_channel = telegram_merchant`.
- `provider_code = kanyon`.
- `payment_currency_code = RUB`.
- `payment_amount_value = requested_rub`.
- `amount_asset_code = USDT`.
- `amount_asset_value = kanyon_gross_usdt`.
- `merchant_requested_rub_value = requested_rub`.
- `merchant_payable_value = merchant_payable_usdt`.
- `merchant_markup_value = platform_fee_usdt`.
- `platform_fee_value = platform_fee_usdt`.
- `referral_reward_value = 0`.
- `merchant_profit_value = 0`.

Обязательный snapshot в `merchant_meta_json`:

- `rapira_symbol`.
- `rapira_bid`.
- `rapira_ask`.
- `rapira_close`, если есть.
- `rapira_checked_at`.
- `acquirer_markup_percent`.
- `merchant_markup_type`.
- `merchant_markup_value`.
- `acquirer_rate`.
- `merchant_rate`.
- `expected_acquirer_gross_usdt = requested_rub / acquirer_rate`.
- `kanyon_gross_usdt`.
- `kanyon_order_amount_raw`.
- `kanyon_payment_amount_raw`.
- `kanyon_payload_mode = paymentAmount|orderAmount`.

Если `expected_acquirer_gross_usdt` заметно отличается от `kanyon_gross_usdt`, это не блокер для MVP, но нужно писать warning в лог. Источник внешнего долга всё равно фактический ответ Kanyon.

## 4. Merchant-bot: меню

Мерчант работает только через Telegram, поэтому меню должно быть полноценным.

Основные пункты:

- `Курс` - показывает живой Rapira `USDT/RUB` для понимания рынка. Не показывать platform fee и внутреннюю маржу.
- `Выставить счёт` - flow RUB -> USDT.
- `Мои счета` - список ордеров мерчанта.
- `Балансы` - к выплате, бонусный, реферальный, итого.
- `Выплаты` - история выплат мерчанту.
- `Профиль` - имя, компания, chat_id, статус.
- `Помощь` - короткая инструкция.
- `Связь с оператором` - на вырост через service bot, без тяжёлой chat-страницы в CRM на первом этапе.

## 5. Merchant-bot: экран курса

Старый экран `Kanyon check-order` для `RUB -> USDT` убрать из merchant-flow.

Новый экран:

- каждый запрос берёт свежий Rapira `askPrice` через backend;
- не пишет отдельную историю курса ради самого курса;
- не создаёт технический Kanyon order;
- не показывает индивидуальную маржу/fee мерчанту;
- может показывать timestamp проверки и ошибку источника.

Backoffice rates page можно оставить для отображения рыночных источников и других контуров, но не использовать старую таблицу Kanyon checks как источник merchant invoice.

## 6. Merchant-bot: создание счёта

Flow:

1. Мерчант нажимает `Выставить счёт`.
2. Система показывает доступное направление `RUB -> USDT`.
3. Мерчант вводит RUB-сумму.
4. Backend проверяет активный merchant access по `company_id + chat_id`.
5. Backend берёт свежий Rapira ask.
6. Backend считает `merchant_rate`, `merchant_payable_usdt`.
7. Backend создаёт Kanyon order:
   - если `fintech_pay2day_order_currency = RUB`, payload через `paymentAmount`;
   - если `fintech_pay2day_order_currency = USDT`, оставить legacy `orderAmount`, но этот режим не является целевым для пилота RUB invoice.
8. Backend сохраняет order и полный economics snapshot.
9. Бот отправляет QR/платёжные данные.

Ответ мерчанту после создания:

- номер счёта;
- RUB-сумма к оплате;
- его сумма к выплате в USDT;
- статус;
- QR/payload;
- кнопки `Проверить оплату`, `Мои счета`, `Меню`.

Не показывать:

- platform fee;
- acquirer gross;
- Rapira + 6% как внутренний расчёт;
- разницу между Kanyon gross и merchant payable.

## 7. Merchant-bot: счета

Раздел `Мои счета`:

- фильтры: активные, оплаченные, отменённые/истёкшие;
- пагинация через callback data;
- список только по текущему `merchant_id`, без доступа к другим мерчантам компании;
- карточка счёта: дата, RUB, к выплате USDT, статус, provider order id/локальный номер, QR/payload если активен.

Оплаченный счёт и выплата мерчанту - разные сущности.

- `Оплаченные счета` показывают факт оплаты клиентом.
- `Выплаты` показывают, что компания реально выплатила деньги мерчанту.
- Одна выплата может закрывать несколько оплаченных счетов, потому на MVP выплаты остаются агрегированными по мерчанту, а не привязанными к конкретному order.

## 8. Merchant-bot: выплаты

Раздел `Выплаты`:

- последние выплаты с пагинацией;
- сумма USDT;
- сеть, MVP default `TRC20`;
- wallet address, если указан;
- tx hash, если указан;
- ссылка на blockchain explorer для TRC20;
- комментарий, если указан;
- скриншот/receipt, если прикреплён.

При создании выплаты в backoffice бот должен отправлять мерчанту уведомление:

- сумма;
- сеть;
- tx hash;
- ссылка на explorer;
- комментарий;
- скриншот оплаты отдельным сообщением/файлом, если есть.

## 9. Settlement on paid

При переходе merchant order в `paid`:

- проверить `company_id > 0`;
- проверить `created_for_type = merchant`;
- проверить `merchant_id > 0`;
- создать ledger entry `merchant_accrual` на `merchant_payable_value`;
- операция должна быть идемпотентной;
- повторные callbacks/polls не должны создавать дубль;
- company/operator orders не должны создавать merchant accrual.

Если order создан в режиме `paymentAmount`, но не содержит `amount_asset_value` из Kanyon, settlement должен блокироваться и писать error log.

## 10. Backoffice changes

Нужно добавить/проверить company-scoped setting:

- `fintech_kanyon_rapira_markup_percent` или близкое имя.
- Хранить в `crm_settings`.
- Сидировать через миграцию.
- Показывать в настройках компании рядом с Kanyon/fintech настройками.
- Дефолт пилота: `6`.

Страница orders:

- merchant orders остаются в общем потоке, но должны иметь фильтр/субъект `merchant`.
- GET-фильтры по merchant/operator должны оставаться.
- В деталях order backoffice может показывать economics snapshot.

Страница merchant payouts:

- остаётся основным рабочим экраном выплат мерчантам;
- после создания выплаты отправляет Telegram notification мерчанту.

## 11. Этапы реализации

### Этап 1. Документ и контракт

Статус: planned

- Зафиксировать этот roadmap.
- Подтвердить Kanyon `paymentAmount`/`orderAmount` compatibility.
- Не менять runtime до согласования.

### Этап 2. Gateway compatibility

Статус: planned

- Расширить Kanyon wrapper под `paymentAmount` для `RUB`.
- Сохранить legacy `orderAmount` для `USDT`.
- Добавить unit/smoke checks без реального provider call там, где возможно.

### Этап 3. Settings

Статус: planned

- Добавить company setting для `acquirer_markup_percent`.
- Вывести в settings UI.
- Сидировать миграцией.

### Этап 4. Merchant invoice service

Статус: planned

- Добавить helper создания merchant RUB invoice.
- Сохранить economics snapshot.
- Жёстко фильтровать по `company_id + merchant_id`.
- Не использовать Doverka.

### Этап 5. Merchant-bot invoice flow

Статус: planned

- Переписать `RUB -> USDT` invoice flow.
- Ввод RUB.
- Создание Kanyon QR.
- Ответ мерчанту без внутренних fee.

### Этап 6. Merchant-bot rates

Статус: planned

- Убрать Kanyon check-order из merchant rates.
- Показывать живую Rapira.
- Не сохранять отдельную историю курса, кроме snapshot в order.

### Этап 7. Merchant-bot orders

Статус: planned

- Реальные списки ордеров по статусам.
- Пагинация.
- Карточка order.
- Проверка статуса.

### Этап 8. Merchant-bot payouts

Статус: planned

- Раздел выплат.
- Пагинация.
- Уведомления при выплате.
- Receipt screenshot и tx link.

### Этап 9. Service bot support

Статус: later

- Связь мерчанта с оператором/владельцем компании через service bot.
- Не делать тяжёлую CRM chat-страницу на первом проходе.

## 12. Что не делать

- Не создавать Kanyon check-order для merchant invoice курса.
- Не показывать мерчанту platform fee.
- Не смешивать paid orders и merchant payouts.
- Не ослаблять company filters.
- Не использовать `company_id = 0` в merchant-flow.
- Не делать отдельный бот на каждое направление.
- Не переписывать весь backoffice UI ради Telegram этапа.
