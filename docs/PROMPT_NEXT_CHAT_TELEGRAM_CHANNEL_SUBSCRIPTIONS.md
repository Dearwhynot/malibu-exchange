# Prompt For Next Chat

Работаем в теме WordPress проекта Malibu Exchange в `/Users/macuser/Documents/malibu-exchange`.

Сначала обязательно прочитай:

- `/Users/macuser/Documents/malibu-exchange/AGENTS.md`
- `/Users/macuser/Documents/malibu-exchange/docs/TZ_TELEGRAM_CHANNEL_SUBSCRIPTIONS.md`
- `/Users/macuser/Documents/malibu-exchange/docs/LOGGING.md`

Задача следующего чата:

Начать реализацию company-scoped модуля "Telegram-каналы" для платных подписок на закрытый Telegram-канал.

Не пытайся реализовать весь модуль одним заходом. Сделай только foundation-этап из ТЗ.

Что нужно сделать в первом чате реализации:

1. Добавить миграции для базовых таблиц:
   - `crm_telegram_channels`
   - `crm_telegram_channel_tariffs`
   - `crm_telegram_channel_sales`
   - `crm_telegram_channel_subscribers`
   - `crm_telegram_channel_payments`
   - `crm_telegram_channel_invites`
2. Добавить seed company-scoped settings для subscription bot и Telegram channels.
3. Добавить seed трёх тарифов:
   - monthly = 30 дней
   - quarterly = 90 дней
   - yearly = 365 дней
   Цены по умолчанию пустые/0, модуль не готов к работе до заполнения всех трёх цен.
4. Расширить company contour registry:
   - группа `company_modules`
   - модуль `telegram_channels`
   - setting `module_telegram_channels_enabled`
5. Добавить root checkbox включения модуля в company settings modal.
6. Добавить RBAC permissions:
   - `telegram_channels.view`
   - `telegram_channels.settings`
   - `telegram_channels.tariffs`
   - `telegram_channels.subscribers`
   - `telegram_channels.payments`
   - `telegram_channels.sales`
   - `telegram_channels.manage_subscribers`
7. Добавить helper readiness status:
   - модуль включён root;
   - company_id > 0;
   - subscription bot settings;
   - channel row/id;
   - три тарифа;
   - все три цены > 0;
   - fintech provider настроен.

Что пока не делать:

- не делать полноценный subscription bot callback;
- не добавлять payment creation;
- не делать paid side-effect;
- не делать cron удаления из канала;
- не делать несколько каналов на компанию;
- не делать root aggregate page;
- не смешивать subscription bot с merchant bot.

Жёсткие условия:

- multi-company isolation не нарушать;
- все таблицы с `company_id`;
- `company_id = 0` не использовать;
- root не является subscriber/client;
- не хранить настройки в `wp_options`;
- не хардкодить токены, channel id, цены или тексты;
- все schema changes только через migration runner;
- все важные state changes логировать через `crm_log`;
- после code changes выполнить деплой изменённых файлов через `./nodejs_scripts/sftp-deploy.sh ...`.

Архитектурное решение, которое нужно сохранить:

- merchant bot нужен для бизнес-стороны продажи/инициации ссылки;
- subscription bot нужен для конечного клиента;
- клиент не должен видеть merchant/internal функции;
- payment activation должна быть идемпотентной и later должна срабатывать через общий paid side-effect, не только через raw callback платёжки.

Критерий успеха первого чата:

- root может включить модуль "Telegram-каналы" для компании;
- в БД есть tables/settings/tariffs;
- readiness helper корректно сообщает, почему модуль ещё не готов;
- без заполненных трёх цен публичный flow считается заблокированным;
- данные не читаются и не пишутся без явного `company_id`.

После реализации:

- проверить синтаксис PHP изменённых файлов;
- задеплоить changed files;
- открыть нормальную WordPress-страницу, чтобы миграции выполнились;
- проверить debug log на новые fatal/schema errors;
- в финале дать короткое summary и обязательный QA block из `AGENTS.md`.
