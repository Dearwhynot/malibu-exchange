# Debug Log Via SFTP

Для этого проекта `wp-content/debug.log` читается напрямую по SFTP. Не просить пользователя смотреть лог, пока SFTP работает.

## Откуда брать доступ

Открыть `.vscode/sftp.json` и взять:
- `host`
- `port`
- `username`
- `password`
- `remotePath`

В этом проекте `remotePath` темы:

`/home/malibuex/public_html/wp-content/themes/malibu-exchange`

Значит валидный путь к WordPress debug log:

`/home/malibuex/public_html/wp-content/debug.log`

## Обязательное правило

Сначала читать `debug.log` самому по SFTP, и только если SFTP реально не работает, спрашивать пользователя про лог.

## Предпочтительный способ

Не передавать пароль в длинной команде с видимыми аргументами. Собирать временный `lftp` script в `/private/tmp`, скачивать лог во временный локальный файл, читать только хвост или релевантные совпадения, затем удалять временные файлы.

Подготовить переменные окружения из `.vscode/sftp.json`:

```bash
export SFTP_HOST="..."
export SFTP_PORT="22"
export SFTP_USERNAME="..."
export SFTP_PASSWORD="..."
export REMOTE_DEBUG_LOG="/home/malibuex/public_html/wp-content/debug.log"
```

## Снять хвост лога

```bash
tmp_script="/private/tmp/malibu-debug-log-tail.lftp"
tmp_log="/private/tmp/malibu-debug.log"
cat > "$tmp_script" <<EOF
set sftp:auto-confirm yes
set net:max-retries 1
set net:timeout 10
open -u "$SFTP_USERNAME","$SFTP_PASSWORD" -p "$SFTP_PORT" sftp://$SFTP_HOST
get "$REMOTE_DEBUG_LOG" -o "$tmp_log"
EOF
chmod 600 "$tmp_script"
lftp -f "$tmp_script"
tail -n 60 "$tmp_log"
rm -f "$tmp_script" "$tmp_log"
```

## Поиск по логу

```bash
tmp_script="/private/tmp/malibu-debug-log-search.lftp"
tmp_log="/private/tmp/malibu-debug.log"
cat > "$tmp_script" <<EOF
set sftp:auto-confirm yes
set net:max-retries 1
set net:timeout 10
open -u "$SFTP_USERNAME","$SFTP_PASSWORD" -p "$SFTP_PORT" sftp://$SFTP_HOST
get "$REMOTE_DEBUG_LOG" -o "$tmp_log"
EOF
chmod 600 "$tmp_script"
lftp -f "$tmp_script"
rg "admin-ajax|PHP Fatal error|Uncaught|WordPress database error|MySQL|action_name|handler_name" "$tmp_log" | tail -n 80
rm -f "$tmp_script" "$tmp_log"
```

## Что искать в первую очередь

Для AJAX и серверных падений искать:
- `admin-ajax.php`
- имя AJAX action
- `PHP Fatal error`
- `Uncaught`
- `WordPress database error`
- `MySQL`
- локальные маркеры вроде `[rates.ajax]`, `[FINTECH]`, имена провайдеров
- строки рядом по времени с ошибкой в браузере

## Если SFTP не сработал

Если чтение лога упёрлось в sandbox/network restriction, запросить escalation именно на команду чтения лога по SFTP и продолжить. Только если автоматизированное чтение реально недоступно, можно просить пользователя открыть лог вручную.
