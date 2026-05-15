# Prompt For Next Chat: Merchant Bot RUB -> USDT

Работаем в теме WordPress проекта Malibu Exchange:

`/Users/macuser/Documents/malibu-exchange`

Сначала обязательно прочитай:

- `/Users/macuser/Documents/malibu-exchange/AGENTS.md`
- `/Users/macuser/Documents/malibu-exchange/docs/ROADMAP_MERCHANT_BOT_RUB_USDT.md`
- `/Users/macuser/Documents/malibu-exchange/docs/LOGGING.md`

Важно: старый plan merchant invoice через Kanyon check-order на 100 USDT считается устаревшим для нового merchant-flow. Новый пилот: мерчант вводит RUB, курс берём из Rapira ask, создаём Kanyon invoice, сохраняем полный economics snapshot.

## Контекст продукта

- Root создаёт компании.
- Компания заводит операторов и мерчантов.
- Мерчант работает через merchant Telegram bot, личного кабинета в web пока нет.
- Пилотное направление merchant invoice: `RUB -> USDT`.
- Kanyon/Pay2Day - провайдер invoice.
- Doverka не участвует в merchant invoice MVP.
- Выплаты мерчантам - отдельная сущность, не то же самое, что оплаченные счета.

## Ключевые решения

- Если `fintech_pay2day_order_currency === RUB`, Kanyon order нужно создавать через `paymentAmount`.
- Если `fintech_pay2day_order_currency === USDT`, старый режим через `orderAmount` должен остаться рабочим.
- Merchant invoice service должен явно принимать RUB-сумму и не ломать legacy `crm_fintech_create_order($amount_usdt, ...)`.
- Источник базового курса: Rapira `USDT/RUB` ask.
- Company setting для наценки эквайринг-партнёра к Rapira: дефолт `6%`.
- Merchant markup берём из карточки мерчанта.
- Мерчанту показываем только его сумму к выплате, без platform fee и внутренней маржи.

## Формула

- `acquirer_rate = rapira_ask_rate * (1 + acquirer_markup_percent / 100)`.
- `merchant_rate = acquirer_rate * (1 + merchant_markup_percent / 100)`.
- `merchant_payable_usdt = requested_rub / merchant_rate`.
- `kanyon_gross_usdt = фактический orderAmount из ответа Kanyon`.
- `platform_fee_usdt = max(kanyon_gross_usdt - merchant_payable_usdt, 0)`.

Внешний долг ЭП перед компанией считать по фактическому `kanyon_gross_usdt`.

Внутренний долг компании перед мерчантом считать по `merchant_payable_usdt`.

## Что делать первым

Сделай только первый узкий implementation milestone:

1. Проверить текущий gateway code:
   - `/Users/macuser/Documents/malibu-exchange/inc/fintech-payment-gateway.php`
   - `/Users/macuser/Documents/malibu-exchange/inc/fintech-orders.php`
   - `/Users/macuser/Documents/malibu-exchange/inc/telegram-merchant-menu.php`
   - `/Users/macuser/Documents/malibu-exchange/inc/merchants.php`

2. Реализовать Kanyon compatibility layer:
   - сохранить `orderAmount` для `USDT`;
   - добавить безопасный `paymentAmount` режим для `RUB`;
   - не менять смысл существующих web/operator order flows без необходимости.

3. Добавить company setting для наценки ЭП:
   - через migration runner;
   - хранить в `crm_settings`;
   - вывести в settings UI в текущем стиле темы;
   - дефолт пилота `6`.

4. Не делать ещё весь merchant-bot сразу, если gateway/settings не проверены.

## Следующие этапы после первого milestone

- Merchant invoice service: создать helper, который принимает `merchant_id + requested_rub`.
- Merchant-bot invoice flow: ввод RUB, создание QR, ответ мерчанту.
- Merchant-bot rates: заменить Kanyon check-order на живую Rapira.
- Merchant-bot orders: списки по статусам + пагинация.
- Merchant-bot payouts: история выплат + уведомления + receipt/tx link.

## Жёсткие правила

- Multi-company isolation не нарушать.
- `company_id = 0` только root; merchant-flow всегда `company_id > 0`.
- Не делать fallback между компаниями.
- Все schema changes только через `inc/migration-runner.php` и `inc/migrations/*.php`.
- Все новые tables только с literal prefix `crm_`.
- Все settings хранить в `crm_settings`, не в `wp_options`.
- Любая бизнес-критичная операция должна логироваться по `docs/LOGGING.md`.
- После meaningful change деплоить изменённые файлы через `./nodejs_scripts/sftp-deploy.sh <files...>`.
- UI не изобретать: использовать текущий Revox/template style.

## QA format

В финале каждого implementation step обязательно дать:

ОБЯЗАТЕЛЬНОЕ ТЕСТИРОВАНИЕ (сделай сразу):
1) Где открыть
2) Что нажать (пошагово)
3) Ожидаемый результат
4) Что считается провалом

Не переходить к следующему этапу без подтверждения теста.
