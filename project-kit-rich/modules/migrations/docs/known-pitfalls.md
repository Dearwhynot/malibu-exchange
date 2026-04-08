# Known Pitfalls — Migrations

## EN
### Things to watch
- do not run schema-changing SQL blindly on every request
- do not duplicate migration keys
- do not keep test migrations forever in production
- do not mix seeders with schema migrations
- do not put project business logic into the runner itself

### Future cleanup
- remove test table / test row after integration is verified
- replace demo migrations with real project migrations

---

## RU
### На что смотреть
- не выполнять schema-changing SQL слепо на каждом запросе
- не дублировать migration keys
- не держать тестовые миграции в проде вечно
- не смешивать seeders со schema migrations
- не тащить бизнес-логику проекта внутрь самого runner

### Что потом почистить
- удалить test table / test row после проверки интеграции
- заменить демо-миграции на реальные проектные