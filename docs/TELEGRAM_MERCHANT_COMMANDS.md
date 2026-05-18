# Telegram Merchant Commands

Короткая инструкция по обновлению slash-команд merchant bot.

## Где лежит source of truth

Файл:

`/Users/macuser/Documents/malibu-exchange/inc/telegram-merchant-commands.php`

В нём находятся:

- список slash-команд и их описаний для Telegram menu;
- helper `crm_merchant_tg_set_my_commands(...)`;
- постоянный sync-route для вызова `setMyCommands`.

## Что менять

Открой `inc/telegram-merchant-commands.php` и правь массив:

`crm_merchant_tg_command_definitions()`

Обычно меняются поля:

- `command`
- `description`
- `screen`
- `entrypoint`

## Как применить изменения

Если вы просто подключаете merchant-бота через настройки компании, отдельный ручной sync обычно больше не нужен:

- после успешного `Подключить callback` система теперь автоматически вызывает `setMyCommands` для merchant-бота;
- ручной sync-route ниже остаётся как служебный fallback и инструмент для повторной синхронизации.

1. Задеплой изменённый файл:

```bash
./nodejs_scripts/sftp-deploy.sh inc/telegram-merchant-commands.php
```

Если менялась логика экранов или routing, задеплой также связанные файлы, например:

```bash
./nodejs_scripts/sftp-deploy.sh inc/telegram-merchant-commands.php inc/telegram-merchant-menu.php functions.php
```

2. Вызови sync-route для нужной компании:

```text
https://malibu.exchange/wp-json/malibu-exchange/v1/merchant-commands-sync?company=<COMPANY_ID>&token=merchant-commands-20260516
```

Пример для компании `2`:

```text
https://malibu.exchange/wp-json/malibu-exchange/v1/merchant-commands-sync?company=2&token=merchant-commands-20260516
```

## Что вернёт route

В ответе будет JSON:

- `success`
- `message`
- `result`
- `verify`

Блок `verify` сразу возвращает результат `getMyCommands`, то есть по нему можно проверить, что Telegram уже принял новый набор команд.

## Важно

- route намеренно оставлен постоянным как служебный инструмент;
- route защищён токеном;
- команды синхронизируются отдельно для каждой компании через `company=<ID>`;
- если изменить только тексты в `inc/telegram-merchant-commands.php`, обычно достаточно задеплоить только этот файл и повторно вызвать sync-route.
