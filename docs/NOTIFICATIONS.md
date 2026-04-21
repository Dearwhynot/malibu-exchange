# Notifications / Toasts

## Источник истины

Для toast-уведомлений в проекте нужно использовать нативный компонент темы **Revox Pages Notification plugin (`pgNotification`)**, а не придумывать отдельную toast-систему под конкретную страницу.

Официальная документация:
- [Pages UI: Notifications](https://docs.pages.revox.io/ui-elements/notifications)

Референсы внутри проекта:
- `theme source html bootstrap demo/condensed/notifications.html`
- `theme source html bootstrap demo/condensed/assets/js/notifications.js`
- `vendor/pages/pages/js/pages.js`
- `template-parts/toast-host.php`

## Что использовать в коде

Стандартный способ для проекта:
1. Подключить общий host:
   `<?php get_template_part( 'template-parts/toast-host' ); ?>`
2. Вызывать из JS:
   `window.MalibuToast.show(message, type)`

Поддерживаемые типы:
- `success`
- `info`
- `warning`
- `danger`

## Как это работает у нас

- `template-parts/toast-host.php` — это проектная обёртка над нативным `pgNotification`.
- По умолчанию она использует **Pages `pgNotification`** со стилем `simple` и позицией `top-right`.
- Самописный fallback внутри этого файла допустим только как запасной режим, если `pgNotification` по какой-то причине не загружен на странице.
- Публичный API для страниц должен оставаться единым: `MalibuToast.show(...)`.

## Обязательное правило

Для новых страниц и новых действий:
- не делать отдельные Bootstrap toast/snackbar-решения;
- не копировать локальные самописные уведомления со старых страниц;
- не внедрять новую библиотеку уведомлений;
- сначала использовать `template-parts/toast-host.php`.

Если требуется нестандартный вариант уведомления:
- сначала проверить, есть ли подходящий вариант в `pgNotification`;
- только если его реально нет, отдельно согласовать отклонение от стандарта.

## Legacy note

Если в старом коде уже есть локальная реализация уведомлений, это считается legacy.
Для новых работ такие реализации не являются образцом и не должны копироваться дальше.
