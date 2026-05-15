# Roadmap: Exchange Contours And Company Availability

## Goal

Сделать в CRM единый и понятный механизм company-scoped контуров, чтобы root мог для каждой компании включать и выключать:

- направления обмена;
- платежные провайдеры;
- отдельные bot / merchant related контуры;
- связанные UI-блоки и действия.

Главная задача этого этапа: если направление или контур отключен, он должен не только "не работать", но и не светиться в UI, кнопках, экранах, настройках и прямых AJAX-вызовах.

## Decisions Fixed

- Для root-настроек компании использовать чекбоксы в текущей стилистике темы, не multiselect.
- Главный экран управления контурами должен жить в root-настройках компании, а не в россыпи разрозненных root-страниц.
- `orders` и `create-order` пока остаются отдельным fintech-контуром. Они не должны автоматически выключаться только из-за отключения направления `RUB/THB`.
- Для merchant-бота пока не заводить отдельного бота на каждое направление.
- На компанию оставлять:
  - 1 merchant bot;
  - 1 operator bot.
- Доступные направления в merchant-боте должны определяться не ботом, а включенными направлениями компании.
- Терминологию направления `THB_RUB` нужно привести к `RUB_THB`.
- Предпочтительное конечное состояние: и в коде, и в БД использовать канонический код `RUB_THB`.
- Для безопасного перехода допустим временный compatibility-layer, который понимает старый код `THB_RUB`.

## What Exists Now

### 1. Exchange directions

- Хранятся в `crm_rate_pairs`.
- Но реестр допустимых направлений захардкожен в [inc/ajax/root-rate-pairs.php](/Users/macuser/Documents/malibu-exchange/inc/ajax/root-rate-pairs.php:32).
- Сейчас там:
  - `THB_RUB`
  - `USDT_THB`
  - `RUB_USDT`

### 2. Payment providers

- Уже есть company-scoped allowlist `fintech_allowed_providers` в `crm_settings`.
- Реестр провайдеров захардкожен в [inc/fintech-payment-gateway.php](/Users/macuser/Documents/malibu-exchange/inc/fintech-payment-gateway.php:28).

### 3. Telegram contours

- Уже есть два company-scoped контекста:
  - `merchant`
  - `operator`
- Это реализовано в [inc/telegram-bot.php](/Users/macuser/Documents/malibu-exchange/inc/telegram-bot.php:5).

### 4. Merchant extras

- Уже есть company-scoped флаги:
  - `merchant_bonus_enabled`
  - `merchant_referral_enabled`
- Это реализовано в [inc/merchants.php](/Users/macuser/Documents/malibu-exchange/inc/merchants.php:80).

## Main Problems In Current State

- Нет одного общего registry / API контуров. Каждый модуль сам читает свое хранилище.
- Root UI настроек компании задублирован:
  - [page-root-companies.php](/Users/macuser/Documents/malibu-exchange/page-root-companies.php:268)
  - [page-users.php](/Users/macuser/Documents/malibu-exchange/page-users.php:950)
- Управление exchange pairs и payment providers разнесено по разным root-экранам:
  - [page-root-rate-pairs.php](/Users/macuser/Documents/malibu-exchange/page-root-rate-pairs.php:20)
  - [page-root-fintech-providers.php](/Users/macuser/Documents/malibu-exchange/page-root-fintech-providers.php:20)
- `settings` показывает все rate-блоки, даже когда направление выключено root-ом:
  - [page-settings.php](/Users/macuser/Documents/malibu-exchange/page-settings.php:519)
- `rates` все еще частично заточен под один исторический кейс:
  - [page-rates.php](/Users/macuser/Documents/malibu-exchange/page-rates.php:18)
  - [inc/rates.php](/Users/macuser/Documents/malibu-exchange/inc/rates.php:7)
- Сайдбар не знает про контуры:
  - [template-parts/sidebar.php](/Users/macuser/Documents/malibu-exchange/template-parts/sidebar.php:135)
- Merchant bot уже частично читает доступные пары, но кнопки и сценарии еще местами захардкожены:
  - [inc/telegram-merchant-menu.php](/Users/macuser/Documents/malibu-exchange/inc/telegram-merchant-menu.php:648)
  - [inc/telegram-merchant-menu.php](/Users/macuser/Documents/malibu-exchange/inc/telegram-merchant-menu.php:859)

## Recommended Target Model

Не вводить один новый глобальный JSON-setting типа `enabled_contours`, потому что он задублирует уже существующие источники истины.

Вместо этого сделать unified-layer поверх текущих хранилищ.

### New helper

Создать файл:

- [inc/company-contours.php](/Users/macuser/Documents/malibu-exchange/inc/company-contours.php)

### Required API

- `crm_company_contours_registry(): array`
- `crm_company_contour_exists(string $code): bool`
- `crm_company_contour_is_enabled(int $company_id, string $code): bool`
- `crm_company_get_enabled_exchange_pairs(int $company_id): array`
- `crm_company_get_enabled_fintech_providers(int $company_id): array`
- `crm_company_get_enabled_invoice_directions(int $company_id): array`
- `crm_company_has_any_exchange_direction(int $company_id): bool`
- `crm_company_set_exchange_pair_enabled(int $company_id, string $pair_code, bool $enabled): array|WP_Error`

### Registry groups

На первом этапе registry должен покрывать как минимум:

- exchange pairs:
  - `RUB_THB`
  - `USDT_THB`
  - `RUB_USDT`
- fintech providers:
  - `kanyon`
  - `doverka`
- telegram contexts:
  - `merchant`
  - `operator`
- merchant features:
  - `bonus`
  - `referral`

### Important storage rule

- Exchange directions продолжают жить в `crm_rate_pairs`.
- Fintech providers продолжают жить в `fintech_allowed_providers`.
- Merchant bonus / referral продолжают жить в текущих settings keys.
- Telegram merchant / operator продолжают жить в текущей settings-архитектуре.

Registry не заменяет storage, а только унифицирует доступ и guards.

## Recommended UI

### Root company settings

Основной root-экран:

- [page-root-companies.php](/Users/macuser/Documents/malibu-exchange/page-root-companies.php:268)

В модалке "Настройки компании" сделать 4 секции:

1. `Направления обмена`
2. `Платежные контуры`
3. `Telegram-контуры`
4. `Merchant-контуры`

Для текущего этапа обязательно реализовать первые две.

### UI control type

Использовать чекбоксы, а не multiselect:

- это уже соответствует текущему модальному паттерну;
- меньше шанс сломать тему Pages / Revox;
- проще визуально объяснить смысл каждого контура;
- проще под каждую строку дать короткий hint.

### Transition rule

После внедрения единого helper-а:

- `root-companies` становится основным экраном управления доступностью компании;
- `root-rate-pairs` и `root-fintech-providers` временно можно оставить как thin UI над тем же helper/API;
- позже либо убрать их из sidebar, либо превратить в redirect / read-only.

## Terminology And Pair Rename

### Problem

Сейчас исторически используется код `THB_RUB`, при том что в бизнес-терминах вы хотите считать это направлением `RUB/THB`.

### Recommended end state

- Канонический pair code: `RUB_THB`
- Канонический title: `RUB/THB`
- В кнопках, bot UI, settings, alerts, breadcrumbs, логах использовать именно `RUB/THB`

### Safe migration strategy

1. Добавить compatibility helper:
   - `crm_normalize_legacy_pair_code('THB_RUB') -> 'RUB_THB'`
2. Обновить код, чтобы новый canonical code был `RUB_THB`.
3. Добавить миграцию, которая:
   - меняет `crm_rate_pairs.code` с `THB_RUB` на `RUB_THB`;
   - чинит связанные root definitions;
   - не трогает историю по `pair_id`, где код не хранится.
4. После полного прохода удалить legacy alias.

Если при реализации окажется, что rename слишком широко цепляет runtime, допустим короткий промежуточный этап:

- в БД пока оставить старый code;
- в UI и helper-слое уже считать canonical code = `RUB_THB`;
- затем отдельной миграцией добить rename полностью.

Но предпочтительный вариант все же довести rename до конца.

## Phases

### Phase 1. Foundation And Canonical Naming

Цель: перестать жить на scattered hardcode.

Сделать:

- добавить `inc/company-contours.php`;
- подключить его в `functions.php`;
- описать registry контуров;
- завести helper для enabled / disabled checks;
- ввести canonical code `RUB_THB`;
- добавить migration для pair rename и compatibility audit;
- пройтись по прямым строковым reference:
  - [inc/rates.php](/Users/macuser/Documents/malibu-exchange/inc/rates.php:7)
  - [page-rates.php](/Users/macuser/Documents/malibu-exchange/page-rates.php:18)
  - [inc/ajax/rates.php](/Users/macuser/Documents/malibu-exchange/inc/ajax/rates.php:58)
  - [inc/ajax/settings.php](/Users/macuser/Documents/malibu-exchange/inc/ajax/settings.php:170)
  - [inc/telegram-merchant-menu.php](/Users/macuser/Documents/malibu-exchange/inc/telegram-merchant-menu.php:421)
  - [inc/ajax/root-rate-pairs.php](/Users/macuser/Documents/malibu-exchange/inc/ajax/root-rate-pairs.php:32)

### Phase 2. Root Company Settings As Main Control Surface

Цель: включение и выключение направлений и провайдеров из одного места.

Сделать:

- расширить модалку в [page-root-companies.php](/Users/macuser/Documents/malibu-exchange/page-root-companies.php:268);
- вынести ее в shared partial, чтобы не поддерживать дубликат в [page-users.php](/Users/macuser/Documents/malibu-exchange/page-users.php:950);
- добавить секцию `Направления обмена` с чекбоксами:
  - `RUB -> THB`
  - `USDT -> THB`
  - `RUB -> USDT`
- секцию `Платежные контуры` оставить на тех же чекбоксах;
- сохранять состояние через общий AJAX + contour helper;
- логировать каждое изменение контура.

Важно:

- новая root UI не должна напрямую писать "как попало" в таблицы;
- запись должна идти только через helper / service layer;
- для exchange pairs helper сам решает:
  - создать row при первом включении;
  - выставить `is_active = 0/1`;
  - оставить коэффициенты нетронутыми.

### Phase 3. Settings Page Becomes Contour-Aware

Цель: скрывать лишнее, а не просто показывать "не настроено".

Сделать в [page-settings.php](/Users/macuser/Documents/malibu-exchange/page-settings.php:519):

- показывать только блоки коэффициентов для включенных направлений;
- если у компании нет ни одного exchange direction:
  - показать один clean empty-state;
  - скрыть pair-specific blocks;
- Kanyon / Doverka уже скрываются по allowed providers, это нужно оставить и перевести на unified helper;
- Telegram и merchant blocks пока не прятать полностью по direction flags, если они не входят в текущий milestone.

Также обновить:

- [inc/ajax/settings.php](/Users/macuser/Documents/malibu-exchange/inc/ajax/settings.php:170)

Чтобы:

- нельзя было сохранить коэффициент по выключенному направлению;
- нельзя было сменить market source по выключенному направлению;
- сообщения были в новой терминологии `RUB/THB`.

### Phase 4. Rates Page Refactor

Цель: убрать историческую заточку под один кейс.

Сделать в [page-rates.php](/Users/macuser/Documents/malibu-exchange/page-rates.php:18):

- перестроить экран как pair-driven page;
- рендерить только включенные направления;
- для `RUB/THB` использовать текущую Ex24-логику;
- для `USDT/THB` и `RUB/USDT` выводить только релевантные блоки и историю, если для них реально есть источник;
- при отсутствии направлений показывать один пустой экран;
- не показывать market cards, не связанные ни с одним включенным направлением.

Сделать в [inc/ajax/rates.php](/Users/macuser/Documents/malibu-exchange/inc/ajax/rates.php:47):

- guard через contour helper;
- сохранить special-case для `orders`-связанного Kanyon контекста;
- при отключенном направлении отдавать hard-fail, а не молча работать.

### Phase 5. Navigation And Entry Guards

Цель: пользователь не должен видеть мертвые разделы.

Сделать:

- сделать sidebar contour-aware:
  - [template-parts/sidebar.php](/Users/macuser/Documents/malibu-exchange/template-parts/sidebar.php:135)
- если у компании нет exchange directions:
  - скрыть пункт `Курсы`;
- если нет права или контура для operator bot:
  - скрыть `Операторы TG`;
- если есть прямой URL на page, но контур выключен:
  - показывать clean denied / unavailable state.

### Phase 6. Merchant Bot Minimal Adaptation

Это лучше вынести в отдельную задачу, но foundation заложить нужно.

Рекомендация:

- оставить 1 merchant bot на компанию;
- не плодить отдельные callback URL на каждое направление;
- использовать один callback merchant-бота;
- внутри bot menu строить кнопки из enabled directions.

Что делать в отдельной задаче:

- в [inc/telegram-merchant-menu.php](/Users/macuser/Documents/malibu-exchange/inc/telegram-merchant-menu.php:859)
  - скрыть кнопки недоступных направлений;
  - если направлений нет, показать аккуратный empty-state;
  - screens `invoice_*` открывать только для включенных направлений;
  - rates screen already partially supports this, но его тоже нужно дочистить.

Что не делать сейчас:

- не заводить по одному боту на каждое направление;
- не заводить отдельный callback per direction;
- не пытаться сейчас переписать весь merchant flow.

## Bot Recommendation

### Recommended now

На компанию оставить:

- 1 merchant bot;
- 1 operator bot.

Почему это лучший вариант сейчас:

- текущая архитектура уже так устроена;
- меньше настроек в `crm_settings`;
- меньше Telegram webhook complexity;
- проще company isolation;
- кнопки направлений можно строить динамически из enabled pairs;
- если направление выключено, кнопка просто не рендерится и handler hard-fail'ит.

### When separate bots may be justified later

Имеет смысл думать о separate merchant bots per direction только если появятся:

- разные бренды;
- разные команды операторов;
- разные юридические / compliance требования;
- разная UX-логика и отдельные onboarding flows.

Сейчас для этого оснований в коде нет. Это только усложнит систему.

## Logging Requirements

Все изменения контуров логировать.

Минимум:

- event code;
- actor user id;
- company id;
- contour code;
- old state;
- new state;
- source page / handler.

Логировать через существующий audit/log policy:

- [docs/LOGGING.md](/Users/macuser/Documents/malibu-exchange/docs/LOGGING.md)

## Files Most Likely To Change

- [inc/company-contours.php](/Users/macuser/Documents/malibu-exchange/inc/company-contours.php)
- [inc/ajax/root-rate-pairs.php](/Users/macuser/Documents/malibu-exchange/inc/ajax/root-rate-pairs.php)
- [inc/ajax/settings.php](/Users/macuser/Documents/malibu-exchange/inc/ajax/settings.php)
- [inc/ajax/rates.php](/Users/macuser/Documents/malibu-exchange/inc/ajax/rates.php)
- [inc/rates.php](/Users/macuser/Documents/malibu-exchange/inc/rates.php)
- [page-root-companies.php](/Users/macuser/Documents/malibu-exchange/page-root-companies.php)
- [page-users.php](/Users/macuser/Documents/malibu-exchange/page-users.php)
- [page-settings.php](/Users/macuser/Documents/malibu-exchange/page-settings.php)
- [page-rates.php](/Users/macuser/Documents/malibu-exchange/page-rates.php)
- [template-parts/sidebar.php](/Users/macuser/Documents/malibu-exchange/template-parts/sidebar.php)
- [inc/telegram-merchant-menu.php](/Users/macuser/Documents/malibu-exchange/inc/telegram-merchant-menu.php)
- [inc/migrations/](/Users/macuser/Documents/malibu-exchange/inc/migrations/)

## Acceptance Criteria

- Root может в настройках компании включать и выключать направления обмена.
- Терминология `RUB/THB` в UI становится консистентной.
- Канонический code направления больше не путает разработку.
- Выключенное направление не видно на `settings` и `rates`.
- Прямой AJAX по выключенному направлению hard-fail'ится.
- `orders` и `create-order` не ломаются при отключении `RUB/THB`, если company still has valid fintech provider access.
- Merchant bot later сможет строить кнопки из тех же contour checks без новой архитектуры.

## Not In Scope Of The First Implementation Pass

- Полная перепись merchant-бота.
- Отдельный bot per direction.
- Полный новый FX-engine для всех направлений.
- Полная ликвидация всех legacy root pages за один проход.

