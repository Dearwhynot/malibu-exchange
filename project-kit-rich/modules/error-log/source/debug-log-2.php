<?php

/**
 * Admin page: Debug Log viewer with emoji severity/source tags + DB kind.
 */

add_action('admin_menu', 'register_clear_log_menu_page');
function register_clear_log_menu_page()
{
    add_menu_page('🪲 Журнал отладки', 'Журнал отладки', 'manage_options', 'debug-log', 'debug_log_page', 'dashicons-visibility', 100);
}

function debug_log_page()
{
    // Путь к wp-content/debug.log
    $log_file = WP_CONTENT_DIR . '/debug.log';

    $lines_per_page = 100;

    // Текущая страница берётся из GET (или из POST-хиддена, если придёт)
    $current_page = isset($_GET['paged'])
        ? max(1, intval($_GET['paged']))
        : (isset($_POST['current_paged']) ? max(1, intval($_POST['current_paged'])) : 1);

    // Поиск: поддержим и POST (форма) и GET (ссылки пагинации/refresh)
    $search_term = '';
    if (isset($_POST['search_term'])) {
        $search_term = sanitize_text_field($_POST['search_term']);
    } elseif (isset($_GET['search_term'])) {
        $search_term = sanitize_text_field($_GET['search_term']);
    }

    // Вкл/выкл эмодзи: принимаем и через POST (чекбокс), и через GET (ссылки)
    $use_emojis = isset($_REQUEST['use_emojis']) ? (bool)intval($_REQUEST['use_emojis']) : true;

    // Кнопка "Clear"
    if (isset($_POST['clear_log'])) {
        $message = clear_debug_log();
        echo "<div class='notice notice-success is-dismissible'><p>" . esc_html($message) . "</p></div>";
        // после очистки вернёмся на ту же страницу/настройки
        $redirect_url = add_query_arg([
            'page'       => 'debug-log',
            'paged'      => 1,
            'use_emojis' => (int)$use_emojis,
        ], admin_url('admin.php'));
        echo '<script>window.location.href = "' . esc_url($redirect_url) . '";</script>';
        exit;
    }

    // Собираем URL для Refresh (GET-ссылка, чтобы ничего не терять)
    $refresh_args = [
        'page'       => 'debug-log',
        'paged'      => $current_page,
        'use_emojis' => (int)$use_emojis,
    ];
    if ($search_term !== '') {
        $refresh_args['search_term'] = $search_term;
    }
    $refresh_url = add_query_arg($refresh_args, admin_url('admin.php'));

    // Получаем содержимое
    $log_content = get_debug_log_content($log_file, $lines_per_page, $current_page, $search_term, $use_emojis);
?>
    <div class="wrap">
        <h1>Журнал отладки</h1>

        <form method="post" action="">
            <p style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <input type="submit" name="clear_log" class="button button-primary" value="Очистить журнал">
                <a class="button" href="<?php echo esc_url($refresh_url); ?>">Обновить</a>

                <!-- сохраняем текущую страницу в POST-кейсе (на будущее) -->
                <input type="hidden" name="current_paged" value="<?php echo (int)$current_page; ?>">

                <input type="text" name="search_term" value="<?php echo esc_attr($search_term); ?>" placeholder="Поиск...">

                <!-- двухсостояльная отправка: 0 всегда, 1 если чекбокс включён -->
                <input type="hidden" name="use_emojis" value="0">
                <label style="user-select:none; display:inline-flex; gap:6px; align-items:center;">
                    <input type="checkbox"
                        name="use_emojis"
                        value="1"
                        <?php checked($use_emojis, true); ?>
                        onchange="this.form.submit()">
                    Добавить эмодзи-префиксы (уровень и источник + тип БД)
                </label>

                <input type="submit" name="search_log" class="button" value="Поиск">
            </p>
        </form>

        <h2>Содержимое журнала:</h2>
        <textarea readonly rows="45" style="width: 100%; font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;"><?php
                                                                                                                                                                    echo esc_textarea($log_content['content']);
                                                                                                                                                                    ?></textarea>

        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                $total_pages = (int)$log_content['total_pages'];
                if ($total_pages > 1) {
                    // ссылки пагинации сохраняют текущие настройки
                    $query_args = [
                        'page'       => 'debug-log',
                        'use_emojis' => (int)$use_emojis,
                    ];
                    if ($search_term !== '') {
                        $query_args['search_term'] = $search_term;
                    }
                    $base_url = add_query_arg($query_args, admin_url('admin.php'));

                    echo paginate_links([
                        'base'      => $base_url . '%_%',
                        'format'    => '&paged=%#%',
                        'current'   => $current_page,
                        'total'     => $total_pages,
                        'prev_text' => __('&laquo; Назад'),
                        'next_text' => __('Вперёд &raquo;'),
                        'type'      => 'plain',
                    ]);
                }
                ?>
            </div>
        </div>
    </div>
<?php
}

/**
 * Очищаем debug.log
 */
function clear_debug_log()
{
    $log_file = WP_CONTENT_DIR . '/debug.log';
    if (file_exists($log_file)) {
        @file_put_contents($log_file, '');
        return "Журнал успешно очищен.";
    }
    return "Файл журнала не существует.";
}

/**
 * Классификация серьёзности.
 */
function mcx_detect_severity($line)
{
    $map = [
        '/PHP Fatal error|Uncaught (?:Error|Exception)|Allowed memory size|Segmentation fault/i' => ['fatal',  '🛑🛑🛑'],
        '/WordPress database error|MySQL server has gone away|Deadlock found|Lock wait timeout/i' => ['error', '❗❗❗'],
        '/PHP Parse error|E_PARSE/i' => ['error', '❗❗❗'],
        '/PHP Warning|E_WARNING|Deprecated function argument|headers already sent/i' => ['warn',   '⚠️⚠️⚠️'],
        '/PHP Notice|E_NOTICE|Undefined (?:index|variable|array key)/i' => ['notice', '⚠️'],
        '/Doing it wrong|_doing_it_wrong|Translation loading .* too early|Function .* was called incorrectly/i' => ['notice', '⚠️'],
        '/PHP message:|info/i' => ['info', 'ℹ️'],
    ];
    foreach ($map as $re => $out) {
        if (preg_match($re, $line)) return ['level' => $out[0], 'emoji' => $out[1]];
    }
    return ['level' => 'other', 'emoji' => '•'];
}

/**
 * Классификация источников.
 */
function mcx_detect_sources($line)
{
    $tags = [];
    if (preg_match('/WordPress database error|wpdb|mysqli|PDO|SQLSTATE|MySQL/i', $line)) $tags[] = ['DB', '🧰'];
    if (preg_match('/PHP (?:Fatal error|Warning|Notice|Deprecated|Parse error)|Uncaught/i', $line)) $tags[] = ['PHP', '🐘'];
    if (preg_match('/WordPress|Doing it wrong|_doing_it_wrong|WP_|wp-includes|wp-content/i', $line)) $tags[] = ['WP', '🧩'];
    if (preg_match('/CRON|WP\-Cron|doing_cron/i', $line)) $tags[] = ['CRON', '⏰'];
    if (preg_match('/REST API|wp-json|rest_(?:do_request|validate)/i', $line)) $tags[] = ['REST', '🔗'];
    if (preg_match('/i18n|_load_textdomain_just_in_time|Translation/i', $line)) $tags[] = ['i18n', '🌐'];
    if (preg_match('/filesystem|permissions|open stream|No such file or directory|Permission denied/i', $line)) $tags[] = ['FS', '📁'];
    if (preg_match('/Allowed memory size|Out of memory/i', $line)) $tags[] = ['MEM', '💾'];
    if (preg_match('/headers already sent/i', $line)) $tags[] = ['HDR', '✉️'];
    if (!$tags) $tags[] = ['misc', '🔎'];
    return $tags;
}

/**
 * ВИД БД-ошибки (вторая характеристика для источника DB).
 */
function mcx_detect_db_kind($line)
{
    $map = [
        '/(MySQL server has gone away|Lost connection|Can\'t connect|Connection refused|Packets out of order|server has gone away)/i' => 'CONN',
        '/Deadlock found/i'                                                                                                      => 'DEADLOCK',
        '/Lock wait timeout/i'                                                                                                   => 'TIMEOUT',
        '/Unknown column|Unknown table|doesn\'t exist|Unknown database/i'                                                        => 'SCHEMA',
        '/Duplicate entry/i'                                                                                                     => 'DUP',
        '/You have an error in your SQL syntax|SQL syntax/i'                                                                     => 'SYNTAX',
        '/foreign key constraint fails|Cannot add or update a child row/i'                                                       => 'FK',
        '/Access denied for user/i'                                                                                              => 'ACCESS',
        '/Data too long for column|Truncated incorrect/i'                                                                        => 'TRUNC',
        '/table is full|No space left on device/i'                                                                               => 'SPACE',
        '/read[-\s]?only/i'                                                                                                      => 'READONLY',
    ];
    foreach ($map as $re => $code) {
        if (preg_match($re, $line)) return $code;
    }
    if (preg_match('/SQLSTATE\[(\w+)\]/i', $line, $m)) {
        return 'STATE:' . strtoupper(substr($m[1], 0, 2));
    }
    return '';
}

/**
 * Собираем бейджи источников (для DB добавляем «вид» ошибки).
 */
function mcx_build_source_tags_with_kinds($line, $sources)
{
    $parts = [];
    foreach ($sources as $s) {
        [$label, $emoji] = $s;
        if ($label === 'DB') {
            $kind = mcx_detect_db_kind($line);
            if ($kind !== '') {
                $parts[] = $emoji . $label . '·' . $kind;
                continue;
            }
        }
        $parts[] = $emoji . $label;
    }
    return $parts;
}

/**
 * Форматирование строки.
 */
function mcx_format_log_line($line)
{
    $sev  = mcx_detect_severity($line);
    $srcs = mcx_detect_sources($line);

    $prefix_parts = [];
    if (!empty($sev['emoji'])) {
        $prefix_parts[] = $sev['emoji'];
    }
    if ($srcs) {
        $prefix_parts = array_merge($prefix_parts, mcx_build_source_tags_with_kinds($line, $srcs));
    }
    $prefix = $prefix_parts ? implode(' ', $prefix_parts) . ' ' : '';

    return $prefix . $line;
}

/**
 * Получение содержимого лога.
 */
function get_debug_log_content($log_file, $lines_per_page, $current_page, $search_term, $use_emojis = true)
{
    if (!file_exists($log_file)) {
        return ['content' => "Debug log file does not exist.", 'total_pages' => 1];
    }

    $log_lines = @file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($log_lines)) {
        return ['content' => "Unable to read debug log.", 'total_pages' => 1];
    }

    if ($search_term !== '') {
        $log_lines = array_values(array_filter($log_lines, function ($line) use ($search_term) {
            return stripos($line, $search_term) !== false;
        }));
    }

    $total_lines = count($log_lines);
    if ($total_lines === 0) {
        return ['content' => "No log entries for current filters.", 'total_pages' => 1];
    }

    $total_pages = (int)ceil($total_lines / max(1, $lines_per_page));
    $current_page = min(max(1, (int)$current_page), $total_pages);
    $start_line   = ($current_page - 1) * $lines_per_page;

    $page_lines = array_slice($log_lines, $start_line, $lines_per_page);

    if ($use_emojis) {
        foreach ($page_lines as &$ln) $ln = mcx_format_log_line($ln);
        unset($ln);
    }

    return [
        'content'     => implode("\n", $page_lines),
        'total_pages' => $total_pages,
    ];
}
