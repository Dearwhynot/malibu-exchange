# Project Kit — Malibu Exchange

## EN

Project Kit is a reusable internal toolkit for Malibu Exchange and future WordPress backoffice projects.

It lives inside the Malibu theme root and is part of the same project tree.

Its purpose is to help build small, practical systems from proven modules instead of rewriting the same infrastructure every time.

This is not a framework.  
This is not a generic boilerplate.  
This is a controlled modular toolbox.

### Main goals
- reuse proven infrastructure from older projects
- extract business-agnostic modules from legacy code
- standardize prompts, docs, and integration flow
- make Codex work predictably
- reduce startup time for new projects

### Core principles
- simplicity over complexity
- reuse over reinvention
- explicit structure over hidden magic
- controlled automation over chaos
- step-by-step integration over big-bang rewrites

### Recommended workflow
1. Put raw legacy code into `modules/<module>/source/`
2. Prepare cleaned reusable code in `modules/<module>/extracted/`
3. Describe the module in `modules/<module>/docs/`
4. Store archives and snapshots in `modules/<module>/archives/`
5. Prepare Codex prompts in `prompts/codex/`
6. Integrate the module into the target project step by step
7. Validate every step before moving on

### Module structure
Each module should follow the same structure:

```text
module-name/
├── source/
├── extracted/
├── docs/
└── archives/
```

### Current modules
- `auth`
- `telegram-callback`
- `migrations`
- `project-briefs`

### Important notes
- modules should stay isolated and understandable
- do not mix UI logic and infrastructure logic
- prefer small modules that can be reused independently
- avoid overengineering
- every module should have a clear purpose

---

## RU

Project Kit — это внутренний набор переиспользуемых модулей для Malibu Exchange и будущих WordPress backoffice-проектов.

Он лежит внутри корня темы Malibu и является частью того же дерева проекта.

Его задача — помогать быстро собирать небольшие практичные системы из уже проверенных модулей, а не переписывать одну и ту же инфраструктуру каждый раз.

Это не фреймворк.  
Это не универсальный boilerplate.  
Это контролируемый модульный набор инструментов.

### Главные цели
- переиспользовать проверенную инфраструктуру из старых проектов
- вытаскивать из legacy-кода модули без привязки к бизнес-логике
- стандартизировать промпты, документацию и порядок интеграции
- сделать работу Codex предсказуемой
- сократить время старта новых проектов

### Базовые принципы
- простота важнее сложности
- переиспользование важнее изобретения
- явная структура важнее скрытой магии
- контролируемая автоматизация важнее хаоса
- поэтапная интеграция важнее больших одномоментных переделок

### Рекомендуемый процесс
1. Кладём сырой legacy-код в `modules/<module>/source/`
2. Подготавливаем очищенный переиспользуемый код в `modules/<module>/extracted/`
3. Описываем модуль в `modules/<module>/docs/`
4. Храним архивы и снапшоты в `modules/<module>/archives/`
5. Готовим промпты для Codex в `prompts/codex/`
6. Интегрируем модуль в целевой проект по этапам
7. Проверяем каждый шаг перед переходом дальше

### Структура модуля
Каждый модуль должен иметь одинаковую структуру:

```text
module-name/
├── source/
├── extracted/
├── docs/
└── archives/
```

### Текущие модули
- `auth`
- `telegram-callback`
- `migrations`
- `project-briefs`

### Важные замечания
- модули должны оставаться изолированными и понятными
- не смешивать UI-логику и инфраструктурную логику
- лучше маленькие независимые модули, чем большой комбайн
- избегать overengineering
- у каждого модуля должна быть чёткая цель

# Module Completion Rule

## EN
Every reusable module in Project Kit must be assembled in the same order:

1. `archives/`
   - store the exported package or snapshot from Codex

2. `source/`
   - store legacy source files or raw origin code if useful

3. `extracted/`
   - store the cleaned reusable version that is ready for integration

4. `docs/README.md`
   - describe the module purpose and current status

5. `docs/extraction-notes.md`
   - explain where the module came from and what was removed

6. `docs/integration-notes.md`
   - explain how the module should be integrated into Malibu

7. `docs/known-pitfalls.md`
   - record risks, caveats, and future cleanup notes

8. `AGENT.md`
   - define module-specific rules for Codex

A module is not considered complete until these parts are present.

---

## RU
Каждый переиспользуемый модуль в Project Kit должен собираться в одинаковом порядке:

1. `archives/`
   - хранит экспортированный пакет или снапшот от Codex

2. `source/`
   - хранит legacy-исходники или сырой код происхождения, если это полезно

3. `extracted/`
   - хранит очищенную переиспользуемую версию, готовую к интеграции

4. `docs/README.md`
   - описывает назначение модуля и его текущий статус

5. `docs/extraction-notes.md`
   - объясняет, откуда модуль взят и что было удалено

6. `docs/integration-notes.md`
   - объясняет, как модуль нужно интегрировать в Malibu

7. `docs/known-pitfalls.md`
   - фиксирует риски, caveats и заметки на будущую чистку

8. `AGENT.md`
   - задаёт модульно-специфичные правила для Codex

Модуль не считается собранным, пока все эти части не присутствуют.
