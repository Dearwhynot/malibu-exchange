# Integration Notes — Migrations

## EN
This module should be integrated into Malibu with minimal rewriting.

### Integration target
- WordPress theme: Malibu Exchange

### Preferred placement
- runner: `inc/migration-runner.php`
- migrations: `inc/migrations/`
- seeders: `inc/seeders/`

### Integration rules
- do not rewrite the module without need
- keep naming consistent
- keep migration logic separate from UI logic
- preserve one-time execution tracking
- keep future multi-organization support in mind

### First integration scope
- connect the runner
- verify migration tracking table creation
- verify one example migration works
- verify one example seeder works

---

## RU
Этот модуль нужно интегрировать в Malibu с минимальными переписываниями.

### Целевой проект
- WordPress theme: Malibu Exchange

### Предпочтительное размещение
- runner: `inc/migration-runner.php`
- migrations: `inc/migrations/`
- seeders: `inc/seeders/`

### Правила интеграции
- не переписывать модуль без необходимости
- сохранять единый нейминг
- держать миграции отдельно от UI-логики
- сохранить механизм одноразового выполнения
- держать в голове будущую multi-organization архитектуру

### Первый объём интеграции
- подключить runner
- проверить создание tracking table
- проверить работу одной примерной migration
- проверить работу одного примерного seeder