# Module Template

## ⚠️ IMPORTANT
This rule is mandatory for every module.
If any part is missing, the module is considered incomplete.

## EN

Use this template mindset for every module.

### Required folders
```text
module-name/
├── source/
├── extracted/
├── docs/
└── archives/
```

### Meaning of folders
- `source/` — raw legacy code, old project files, or reference snippets
- `extracted/` — cleaned reusable version of the module
- `docs/` — rules, readme, integration notes, pitfalls
- `archives/` — zip files, snapshots, exported packages, Codex deliverables

### Minimum documentation
Each module should eventually contain:
- module README
- extraction notes
- integration notes
- known pitfalls, if any

### Design rules
- one module = one clear responsibility
- keep reusable logic separate from project-specific business logic
- prefer neutral naming
- make future integration easy

---

## RU

Используй этот шаблон мышления для каждого модуля.

### Обязательные папки
```text
module-name/
├── source/
├── extracted/
├── docs/
└── archives/
```

### Назначение папок
- `source/` — сырой legacy-код, файлы старого проекта или reference-фрагменты
- `extracted/` — очищенная переиспользуемая версия модуля
- `docs/` — правила, readme, заметки по интеграции, подводные камни
- `archives/` — zip-файлы, снапшоты, экспортированные пакеты, результаты Codex

### Минимальная документация
Со временем в каждом модуле должно появиться:
- README модуля
- notes по extraction
- notes по интеграции
- known pitfalls, если есть

### Правила проектирования
- один модуль = одна понятная зона ответственности
- переиспользуемую логику держать отдельно от бизнес-логики проекта
- использовать нейтральный нейминг
- делать будущую интеграцию максимально простой

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

## Packaging rule

Before a module is integrated into Malibu, it must first be:
1. packaged into `archives/`
2. unpacked into `extracted/`
3. documented in `docs/`
4. given its own `AGENT.md`

A module is not integration-ready until this structure exists.

## Правило упаковки

Прежде чем модуль интегрируется в Malibu, он должен быть:
1. упакован в `archives/`
2. распакован в `extracted/`
3. описан в `docs/`
4. снабжён собственным `AGENT.md`

Модуль не считается готовым к интеграции, пока эта структура не существует.