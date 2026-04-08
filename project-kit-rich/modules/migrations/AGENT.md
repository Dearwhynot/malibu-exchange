# AGENT.md — migrations

## EN
This module should stay focused on its own responsibility: database migrations and data patches.

Rules:
- do not mix unrelated logic into this module
- prefer reusable neutral naming
- keep documentation aligned with extracted code
- avoid project-specific business rules unless explicitly stored in `source/`
- extracted code should be ready for Malibu and future projects

## RU
Этот модуль должен оставаться сфокусированным на своей задаче: миграции базы данных и data patches.

Правила:
- не смешивать в модуле постороннюю логику
- использовать переиспользуемый нейтральный нейминг
- держать документацию согласованной с extracted-кодом
- избегать проектно-специфичной бизнес-логики, если она не хранится специально в `source/`
- extracted-код должен быть пригоден для Malibu и будущих проектов
