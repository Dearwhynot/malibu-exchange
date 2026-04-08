# Integration Checklist

## EN

Use this checklist before and after integrating a module into Malibu or any future project.

### Before integration
- module has a clear purpose
- extracted code is cleaned from business-specific logic
- naming is neutral enough
- docs are present
- prompt is prepared
- integration scope is limited and testable

### During integration
- do not rewrite working logic without reason
- do not mix UI and infrastructure code
- keep changes small
- deploy changed files only
- document what was integrated

### After integration
- verify the target project still works
- verify the module is actually connected
- verify no extra files were introduced
- update docs if structure changed
- run QA before moving on

---

## RU

Используй этот чеклист до и после интеграции модуля в Malibu или любой будущий проект.

### До интеграции
- у модуля есть понятная цель
- extracted-код очищен от бизнес-логики
- нейминг достаточно нейтральный
- документация присутствует
- промпт подготовлен
- объём интеграции ограничен и тестируем

### Во время интеграции
- не переписывать рабочую логику без причины
- не смешивать UI-код и инфраструктурный код
- делать изменения небольшими
- деплоить только изменённые файлы
- фиксировать, что именно было интегрировано

### После интеграции
- проверить, что целевой проект всё ещё работает
- проверить, что модуль реально подключён
- проверить, что не появилось лишних файлов
- обновить docs, если структура изменилась
- провести QA перед переходом дальше
