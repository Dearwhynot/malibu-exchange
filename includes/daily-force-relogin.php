<?php
//ежедневный «разлогин всех, кроме пользователя ID=1». 
// Это аккуратно и штатно: просто уничтожаем все session tokens у нужных пользователей — 
// на следующем заходе они окажутся разлогинены и попадут на вашу кастом-страницу входа.

if (!defined('ABSPATH')) exit;

/**
 * Кого НЕ разлогинивать ежедневно.
 * Оставляем администратора с ID=1.
 */
// const MCFRL_EXCLUDE_USER_IDS = [1];
const MCFRL_EXCLUDE_USER_IDS = [1];

/**
 * Расписание: во сколько (по часовому поясу сайта) выполнять разлогин.
 * Формат: ЧЧ:ММ (24-час.)
 */
const MCFRL_DAILY_TIME = '03:00';

/**
 * Планировщик события (ставим один раз).
 * Привязан к часовому поясу сайта (Settings → General → Timezone).
 */
function mcfrl_schedule_daily_event()
{
    if (wp_next_scheduled('mcfrl_cron')) return;

    $tz   = wp_timezone(); // WP 5.3+
    $now  = new DateTime('now', $tz);
    $time = explode(':', MCFRL_DAILY_TIME);
    $hh   = (int)($time[0] ?? 3);
    $mm   = (int)($time[1] ?? 0);

    // ближайший запуск: сегодня в HH:MM, либо завтра, если уже прошло
    $first = (clone $now)->setTime($hh, $mm, 0);
    if ($first <= $now) {
        $first->modify('+1 day');
    }

    // Конвертируем в UTC для wp_schedule_event
    $first->setTimezone(new DateTimeZone('UTC'));
    wp_schedule_event($first->getTimestamp(), 'daily', 'mcfrl_cron');
}
add_action('after_setup_theme', 'mcfrl_schedule_daily_event');

/**
 * Сам разлогин (уничтожить все сессии у всех, кроме исключений).
 */
function mcfrl_run()
{
    // Берём только ID, чтобы не грузить память
    $users = get_users([
        'fields'  => ['ID'],
        'exclude' => MCFRL_EXCLUDE_USER_IDS,
        'number'  => -1,
    ]);

    foreach ($users as $u) {
        $uid = (int)$u->ID;
        // Уничтожаем все сессии пользователя (на всех устройствах/браузерах)
        if (class_exists('WP_Session_Tokens')) {
            $manager = WP_Session_Tokens::get_instance($uid);
            $manager->destroy_all();
        } else {
            // Фолбэк: вручную чистим usermeta 'session_tokens'
            delete_user_meta($uid, 'session_tokens');
        }
    }
}
add_action('mcfrl_cron', 'mcfrl_run');

/**
 * Ручной триггер для теста (только для админов):
 * откройте URL с ?mcfrl_test=1 — и все (кроме исключений) будут разлогинены сразу.
 */
function mcfrl_manual_test_trigger()
{
    if (!is_user_logged_in() || !current_user_can('manage_options')) return;
    if (empty($_GET['mcfrl_test'])) return;

    mcfrl_run();
    wp_die('MCFRL: все сессии (кроме исключённых) уничтожены. Уберите ?mcfrl_test=1.');
}
add_action('init', 'mcfrl_manual_test_trigger', 1);

/**
 * Опционально: WP-CLI команда
 *   wp mc:force-relogin
 */
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('mc:force-relogin', function () {
        mcfrl_run();
        WP_CLI::success('All user sessions destroyed (except excluded IDs).');
    });
}
