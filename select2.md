# Select2 в проекте

## Подключение

Select2 поставляется в составе шаблона Pages и загружается глобально через `project-kit-rich/`.

## Как использовать

Добавь атрибут `data-init-plugin="select2"` на `<select>` — тема инициализирует его автоматически:

```html
<select class="full-width" data-init-plugin="select2">
    <option value="">Все</option>
    <option value="foo">Foo</option>
</select>
```

Для placeholder и кнопки сброса:

```html
<select data-init-plugin="select2" data-placeholder="Выберите..." data-allow-clear="true">
    <option value=""></option>
    <!-- опции -->
</select>
```

## Правила

- Список опций рендерить через PHP `foreach` прямо в теле страницы — не через AJAX.
- Не инициализировать Select2 вручную через JS, если достаточно `data-init-plugin`.
- Для сброса через JS использовать `.val('').trigger('change')`.
