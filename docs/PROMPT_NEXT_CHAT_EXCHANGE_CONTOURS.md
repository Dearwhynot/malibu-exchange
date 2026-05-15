# Prompt For Next Chat

Работаем в теме WordPress проекта Malibu Exchange в `/Users/macuser/Documents/malibu-exchange`.

Сначала обязательно прочитай:

- `/Users/macuser/Documents/malibu-exchange/AGENTS.md`
- `/Users/macuser/Documents/malibu-exchange/docs/ROADMAP_EXCHANGE_CONTOURS.md`

Задача этого чата:

Реализовать первый milestone по roadmap, без расползания на весь merchant-бот.

Что нужно сделать в этом чате:

1. Ввести единый helper company contours.
2. Привести терминологию направления `THB_RUB` к `RUB_THB`.
3. Добавить безопасный migration / compatibility layer для rename pair code.
4. Расширить root company settings так, чтобы root мог управлять направлениями обмена и платежными контурами из одного места.
5. Убрать дублирование root company settings UI, если оно мешает.
6. Подключить `page-settings.php` к этому механизму так, чтобы выключенные направления не показывали rate blocks.
7. Не делать пока полную переработку merchant-бота. Разрешается только заложить helper-и и guards, нужные для следующего этапа.

Жесткие условия:

- Multi-company isolation не нарушать.
- Не использовать fallback между компаниями.
- Все company-scoped проверки должны hard-fail'иться, если контур отключен.
- Для новых schema changes использовать только migration runner.
- Не хранить новые project settings в `wp_options`.
- Не делать новый master JSON setting вида `enabled_contours`, который дублирует текущие storage-слои.
- `orders` и `create-order` не привязывать автоматически к выключению направления `RUB/THB`. Они пока остаются fintech contour.
- Для root company settings UI использовать чекбоксы в текущем визуальном стиле темы, не multiselect.

Что считать правильной архитектурой:

- Exchange directions продолжают храниться через `crm_rate_pairs`.
- Fintech providers продолжают храниться через `fintech_allowed_providers`.
- Unified helper только нормализует чтение / запись / guards.
- Предпочтительный конечный code для направления: `RUB_THB`.
- Если нужен мягкий переход, допустим временный alias `THB_RUB -> RUB_THB`, но конечная цель все равно `RUB_THB`.

Файлы, которые, скорее всего, придется менять:

- `/Users/macuser/Documents/malibu-exchange/inc/company-contours.php`
- `/Users/macuser/Documents/malibu-exchange/inc/ajax/root-rate-pairs.php`
- `/Users/macuser/Documents/malibu-exchange/inc/ajax/settings.php`
- `/Users/macuser/Documents/malibu-exchange/inc/rates.php`
- `/Users/macuser/Documents/malibu-exchange/page-root-companies.php`
- `/Users/macuser/Documents/malibu-exchange/page-users.php`
- `/Users/macuser/Documents/malibu-exchange/page-settings.php`
- `/Users/macuser/Documents/malibu-exchange/inc/migrations/*`

Что не делать в этом чате:

- Не уводить задачу в полную переработку Telegram merchant bot.
- Не делать отдельного бота на каждое направление.
- Не делать отдельного callback per direction.

После реализации:

- Проведи локальную проверку, насколько это возможно.
- Если изменения meaningful, следуй проектному правилу и задеплой измененные файлы на test server.
- В финале дай короткое summary и обязательный QA block в формате из `AGENTS.md`.

