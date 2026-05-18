# Prompt For Next Chat

Работаем в теме WordPress проекта Malibu Exchange в `/Users/macuser/Documents/malibu-exchange`.

Сначала обязательно прочитай:

- `/Users/macuser/Documents/malibu-exchange/AGENTS.md`
- `/Users/macuser/Documents/malibu-exchange/docs/TZ_SERVICE_BOT_COMPANY_ACL.md`
- `/Users/macuser/Documents/malibu-exchange/docs/ROADMAP_SERVICE_BOT_COMPANY_ACL.md`

Задача следующего чата:

Реализовать только foundation для service bot, без попытки сразу переносить весь backoffice в Telegram.

Что нужно сделать в этом чате:

1. Подготовить service ACL layer.
2. Добавить service Telegram invite model.
3. Добавить service Telegram access model.
4. Добавить новые RBAC permissions для service bot.
5. Добавить users-page UI для:
   - выдачи service invite;
   - просмотра service invite history;
   - revoke service access.
6. Реализовать `/start svc_...` handshake для service bot.
7. Сделать жёсткий callback ACL:
   - без service access меню не открывается;
   - без invite доступ не создаётся.

Что пока не делать в этом чате:

- не переносить сразу merchant payouts;
- не переносить acquirer payouts;
- не делать full orders/rates UI в Telegram;
- не делать create-order;
- не делать “все функции сайта” одним коммитом.

Жёсткие условия:

- multi-company isolation не нарушать;
- root не участвует в service bot;
- не делать fallback по компании;
- не пускать пользователя по одному только chat_id;
- доступ должен требовать:
  - CRM permission;
  - active service ACL;
  - company-scoped Telegram binding.

Архитектурные решения, которые нужно сохранить:

- `crm_user_telegram_accounts` остаётся общим профилем Telegram пользователя;
- service access не хранить только в history invites;
- history invites и active access — разные сущности;
- backend должен hard-fail’ить при отсутствии company context или access.

Файлы, которые, скорее всего, придётся менять:

- `/Users/macuser/Documents/malibu-exchange/inc/rbac.php`
- `/Users/macuser/Documents/malibu-exchange/inc/ajax/users.php`
- `/Users/macuser/Documents/malibu-exchange/page-users.php`
- `/Users/macuser/Documents/malibu-exchange/page-root-users.php` только если реально нужно root-view для истории
- `/Users/macuser/Documents/malibu-exchange/inc/telegram-bot.php`
- `/Users/macuser/Documents/malibu-exchange/inc/telegram-callback.php`
- `/Users/macuser/Documents/malibu-exchange/inc/telegram-operators-handler.php` как референс
- `/Users/macuser/Documents/malibu-exchange/inc/migrations/*`
- возможно новый файл:
  - `/Users/macuser/Documents/malibu-exchange/inc/telegram-service-handler.php`
  - `/Users/macuser/Documents/malibu-exchange/inc/service-bot.php`
  - `/Users/macuser/Documents/malibu-exchange/inc/ajax/service-bot.php`

Критерий успеха этого next chat:

- можно выдать service invite пользователю компании;
- пользователь проходит `/start svc_...`;
- Telegram привязывается к CRM user;
- service access появляется и проверяется;
- без active access service bot не даёт рабочее меню.

После реализации:

- проверить локально, насколько возможно;
- задеплоить changed files;
- в финале дать короткое summary и обязательный QA block из `AGENTS.md`.
