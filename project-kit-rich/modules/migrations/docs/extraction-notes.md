# Extraction Notes — Migrations

## EN
This module was extracted from the Doverka project and cleaned for reuse in Malibu Exchange.

### Source
- legacy project: Doverka
- extraction date: 2026-03-25
- extracted package: `malibu-migrations-module-2026-03-25.zip`

### What was preserved
- migration runner concept
- one-time execution tracking
- separation of migrations and seeders
- WordPress-friendly `$wpdb` / `dbDelta` approach

### What was removed
- project-specific table logic
- business-specific SQL
- unrelated backoffice code
- UI dependencies

### Current goal
Integrate this cleaned module into Malibu as the first reusable infrastructure block.

---

## RU
Этот модуль был извлечён из проекта Doverka и очищен для повторного использования в Malibu Exchange.

### Источник
- legacy-проект: Doverka
- дата извлечения: 2026-03-25
- extracted package: `malibu-migrations-module-2026-03-25.zip`

### Что было сохранено
- концепция migration runner
- отслеживание одноразового выполнения
- разделение migrations и seeders
- WordPress-friendly подход через `$wpdb` / `dbDelta`

### Что было удалено
- проектно-специфичная логика таблиц
- бизнес-специфичный SQL
- несвязанный backoffice-код
- UI-зависимости

### Текущая цель
Интегрировать этот очищенный модуль в Malibu как первый переиспользуемый инфраструктурный блок.