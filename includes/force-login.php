<?php
if (!defined('ABSPATH')) exit;

// error_log('[MCFL] force-login.php LOADED at ' . gmdate('c')); 

// add_action('init', function () {
//     error_log('[MCFL] init FIRED at ' . gmdate('c'));
// }, 0);

// --- DEBUG TOGGLE & LOGGER ---
if (!defined('MCFL_DEBUG')) {
    // ВКЛ/ВЫКЛ мини-лог:
    define('MCFL_DEBUG', false); // ← после проверки поменяйте на false
}
function mcfl_dbg(string $msg): void {
    if (!MCFL_DEBUG) return;
    $uri    = $_SERVER['REQUEST_URI'] ?? '';
    $script = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
    error_log('[MCFL] ' . $msg . ' | path=' . mcfl_current_path() . ' | uri=' . $uri . ' | script=' . $script);
}

/** Укажи путь к кастомной странице логина */
const MC_LOGIN_PATH = '/authorization';

/**
 * Публичные страницы (строгие совпадения)
 * Пример: '/authorization'
 */
const MC_PUBLIC_PATHS = [
    '/authorization',
    // '/cron-one-min',
    // '/test2',
    // '/telegramaoe',
    // // '/telegrammanager-testing-page',
    // '/artofeventsticketbot2',
    // '/rates',
    // '/cron-custom-payments',
    // '/cron-refreshing-classic-rates',
    // '/classic-rates-staile',
    // '/monitor-rates-api',
    // '/megatix-bot-callback',
    // '/callback-custom-payments',
    // '/classic-rates-monitor-portrait',
    // '/rates-for-money-club',
    // '/tegramv3megatixbali',
    // '/tegramv3megatixthai',
    // '/mamy-refreshing-classic-rates',
    // '/telegram-register-new-user',
    // '/cron-merchant-payments',
    // '/telegram-web-app-test-page',
    // '/test-web-app-page',
    // '/callback-web-app-telegram-tickets',
    // '/web-app-telegram-tickets',
    // '/telegram-web-app-calc-referal',
    // '/telegram-service-bot',
    // '/telegram-web-app-bot-echange-scanning-page',
    // '/kanyon-callback',
    // '/kanyon-callback-mc',
];

/**
 * Публичные префиксы (разрешить ВСЁ, что начинается с этих путей)
 * Пример: '/web-app-telegram-tickets' даст доступ и '/web-app-telegram-tickets/abc'
 */
const MC_PUBLIC_PREFIXES = [
    '/cron',        // разрешит /cron, /cron-*, /cron/...
    '/callback',    // разрешит /callback-*
];

/** ===== Хелперы нормализации ===== */

function mcfl_norm_lead(string $p): string {
    if ($p === '') return '/';
    $p = '/' . ltrim($p, '/');
    // убираем завершающий слэш, кроме корня
    return rtrim($p, '/') ?: '/';
}

/** Базовый путь сайта (если WP в подпапке) */
function mcfl_site_base(): string {
    static $base = null;
    if ($base !== null) return $base;
    $home = parse_url(home_url('/'), PHP_URL_PATH) ?: '/';
    $base = mcfl_norm_lead($home);
    return $base;
}

/** Текущий путь без базового префикса сайта */
function mcfl_current_path(): string {
    $raw = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $raw = mcfl_norm_lead($raw);

    $base = mcfl_site_base();
    if ($base !== '/' && strpos($raw, $base) === 0) {
        $raw = substr($raw, strlen($base));
        $raw = mcfl_norm_lead($raw);
    }
    return $raw;
}

function mcfl_is_rest_request(): bool {
    if (defined('REST_REQUEST') && REST_REQUEST) return true;
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return (stripos($uri, '/wp-json/') !== false);
}

function mcfl_is_ajax_request(): bool {
    return (defined('DOING_AJAX') && DOING_AJAX) || (function_exists('wp_doing_ajax') && wp_doing_ajax());
}

function mcfl_is_cron_or_cli(): bool {
    // Обычный WP-крон / WP-CLI
    if (defined('DOING_CRON') && DOING_CRON) return true;
    if (defined('WP_CLI') && WP_CLI) return true;

    // Любой запуск через CLI (php script.php)
    if (PHP_SAPI === 'cli' || php_sapi_name() === 'cli') return true;

    // Признаки "не веб-запроса": нет URI и IP
    $no_uri = empty($_SERVER['REQUEST_URI']);
    $no_ip  = empty($_SERVER['REMOTE_ADDR']);
    if ($no_uri && $no_ip) return true;

    // Ваши кастомные кроны из темы (подстрахуемся по пути к файлу)
    $script_filename = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if ($script_filename && preg_match('~/wp-content/themes/[^/]+/(cron|cron-.*|cronpostrequest.*)~i', $script_filename)) {
        return true;
    }

    return false;
}

function mcfl_is_login_page_request(): bool {
    return mcfl_current_path() === mcfl_norm_lead(MC_LOGIN_PATH);
}

/** Точное совпадение разрешённых путей */
function mcfl_is_public_exact(): bool {
    $path = mcfl_current_path();
    foreach (MC_PUBLIC_PATHS as $p) {
        if ($path === mcfl_norm_lead($p)) return true;
    }
    return false;
}

/** Совпадение по префиксу (напр. '/foo' разрешит и '/foo/bar') */
function mcfl_is_public_prefix(): bool {
    $path = mcfl_current_path();
    foreach (MC_PUBLIC_PREFIXES as $pref) {
        $pref = mcfl_norm_lead($pref);
        if ($pref === '/') continue;
        if ($path === $pref || strpos($path, $pref . '/') === 0) {
            return true;
        }
    }
    return false;
}

function mcfl_is_core_cron_or_ajax_by_script(): bool {
    $script = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    // Нормализуем на всякий случай
    $script = strtolower($script);
    return (strpos($script, '/wp-cron.php') !== false) || (strpos($script, '/wp-admin/admin-ajax.php') !== false);
}

function mcfl_is_allowed_wp_login_request(): bool {
    $script = strtolower($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    if (strpos($script, '/wp-login.php') === false) {
        return false;
    }

    $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
    $checkemail = isset($_REQUEST['checkemail']) ? sanitize_key(wp_unslash($_REQUEST['checkemail'])) : '';

    return in_array($action, ['lostpassword', 'retrievepassword', 'rp', 'resetpass'], true)
        || $checkemail !== '';
}

/** ===== Основной редирект ===== */
function mcfl_force_login_redirect() {
    if (is_user_logged_in()) { mcfl_dbg('logged_in'); return; }

    // ВАЖНЫЕ БАЙПАСЫ С ЛОГОМ:
    if (mcfl_is_core_cron_or_ajax_by_script()) { mcfl_dbg('bypass: script wp-cron/admin-ajax'); return; }
    if (mcfl_is_cron_or_cli())  { mcfl_dbg('bypass: DOING_CRON / WP_CLI'); return; }
    if (mcfl_is_ajax_request()) { mcfl_dbg('bypass: DOING_AJAX'); return; }
    if (mcfl_is_rest_request()) { mcfl_dbg('bypass: REST'); return; }
    if (mcfl_is_allowed_wp_login_request()) { mcfl_dbg('bypass: wp-login action'); return; }

    if (mcfl_is_login_page_request()) { mcfl_dbg('bypass: login page'); return; }
    if (mcfl_is_public_exact())   { mcfl_dbg('bypass: public exact'); return; }
    if (mcfl_is_public_prefix())  { mcfl_dbg('bypass: public prefix'); return; }

    // Редирект — тоже логируем
    mcfl_dbg('REDIRECT');
    $uri     = $_SERVER['REQUEST_URI'] ?? '/';
    $target  = home_url(mcfl_norm_lead(MC_LOGIN_PATH));
    $current = (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . $uri;
    $target  = add_query_arg('redirect_to', $current, $target);
    wp_safe_redirect($target, 302);
    exit;
}

add_action('init', 'mcfl_force_login_redirect', 1);
