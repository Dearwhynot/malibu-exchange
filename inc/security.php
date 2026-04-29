<?php
function malibu_exchange_get_login_path(): string
{
    if (defined('MC_LOGIN_PATH') && is_string(MC_LOGIN_PATH) && MC_LOGIN_PATH !== '') {
        return MC_LOGIN_PATH;
    }

    return '/authorization';
}

function malibu_exchange_get_login_url(string $redirect_to = ''): string
{
    $url = home_url(malibu_exchange_get_login_path());

    if ($redirect_to !== '') {
        $url = add_query_arg('redirect_to', $redirect_to, $url);
    }

    return $url;
}

function malibu_exchange_get_company_dashboard_url(): string
{
    $dashboard_page = get_page_by_path('dashboard');

    if ($dashboard_page instanceof WP_Post) {
        return get_permalink($dashboard_page);
    }

    return home_url('/dashboard');
}

function malibu_exchange_get_root_dashboard_url(): string
{
    $dashboard_page = get_page_by_path('root-dashboard');

    if ($dashboard_page instanceof WP_Post) {
        return get_permalink($dashboard_page);
    }

    return home_url('/root-dashboard');
}

function malibu_exchange_get_dashboard_url(int $user_id = 0): string
{
    if ($user_id === 0 && is_user_logged_in()) {
        $user_id = get_current_user_id();
    }

    if ($user_id > 0 && function_exists('crm_is_root') && crm_is_root($user_id)) {
        return malibu_exchange_get_root_dashboard_url();
    }

    return malibu_exchange_get_company_dashboard_url();
}

function malibu_exchange_normalize_local_redirect_path(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $path = wp_parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = $url;
    }

    $path = '/' . ltrim($path, '/');

    return untrailingslashit($path) ?: '/';
}

function malibu_exchange_should_redirect_to_user_dashboard(string $redirect_to): bool
{
    $normalized = malibu_exchange_normalize_local_redirect_path($redirect_to);
    if ($normalized === '') {
        return true;
    }

    $default_targets = [
        malibu_exchange_normalize_local_redirect_path(home_url('/')),
        malibu_exchange_normalize_local_redirect_path(malibu_exchange_get_company_dashboard_url()),
    ];

    return in_array($normalized, $default_targets, true);
}

function malibu_exchange_get_root_blocked_company_templates(): array
{
    return [
        'page-users.php',
        'page-dashboard.php',
        'page-rates.php',
        'page-orders.php',
        'page-create-order.php',
        'page-payouts.php',
        'page-logs.php',
        'page-merchants.php',
        'page-settings.php',
    ];
}

function malibu_exchange_is_root_blocked_company_page(): bool
{
    foreach (malibu_exchange_get_root_blocked_company_templates() as $template) {
        if (is_page_template($template)) {
            return true;
        }
    }

    return false;
}

function malibu_exchange_render_root_company_scope_denied(): void
{
    $template = locate_template('template-parts/root-company-denied.php');

    if (is_string($template) && $template !== '' && file_exists($template)) {
        require $template;
        exit;
    }

    global $wp_query;
    if ($wp_query instanceof WP_Query) {
        $wp_query->set_404();
    }

    status_header(404);
    nocache_headers();

    $fallback = get_404_template();
    if (is_string($fallback) && $fallback !== '' && file_exists($fallback)) {
        require $fallback;
        exit;
    }

    wp_die('404', '404', ['response' => 404]);
}

add_action('template_redirect', 'malibu_exchange_block_root_from_company_pages', 16);
function malibu_exchange_block_root_from_company_pages(): void
{
    if ((is_admin() && !wp_doing_ajax()) || !is_user_logged_in()) {
        return;
    }

    if (!function_exists('crm_is_root') || !crm_is_root(get_current_user_id())) {
        return;
    }

    if (!malibu_exchange_is_root_blocked_company_page()) {
        return;
    }

    malibu_exchange_render_root_company_scope_denied();
}

function malibu_exchange_is_login_captcha_enabled(): bool
{
    return (bool) apply_filters('malibu_exchange_login_captcha_enabled', false);
}

function malibu_exchange_normalize_redirect_target(string $redirect_to = ''): string
{
    $redirect_to = $redirect_to !== '' ? $redirect_to : (string) ($_REQUEST['redirect_to'] ?? '');
    $redirect_to = trim(wp_unslash($redirect_to));

    if ($redirect_to === '') {
        return malibu_exchange_get_dashboard_url();
    }

    for ($i = 0; $i < 2; $i++) {
        $decoded = rawurldecode($redirect_to);
        if ($decoded === $redirect_to) {
            break;
        }
        $redirect_to = $decoded;
    }

    $validated = wp_validate_redirect($redirect_to, '');

    return $validated !== '' ? $validated : malibu_exchange_get_dashboard_url();
}

function malibu_exchange_require_login(): void
{
    if (is_user_logged_in()) {
        return;
    }

    $redirect_to = is_singular() ? get_permalink() : home_url('/');
    wp_safe_redirect(malibu_exchange_get_login_url($redirect_to));
    exit;
}

function malibu_exchange_handle_login_submission(): array
{
    $state = [
        'errors'      => [],
        'notice'      => '',
        'username'    => '',
        'remember'    => true,
        'redirect_to' => malibu_exchange_normalize_redirect_target(),
    ];

    if (!empty($_GET['loggedout'])) {
        $state['notice'] = 'Сессия завершена. Войдите снова, чтобы продолжить работу.';
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || empty($_POST['me_login_action'])) {
        return $state;
    }

    $state['username'] = sanitize_text_field(wp_unslash($_POST['log'] ?? ''));
    $state['remember'] = !empty($_POST['rememberme']);
    $state['redirect_to'] = malibu_exchange_normalize_redirect_target((string) ($_POST['redirect_to'] ?? ''));

    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'malibu_exchange_login')) {
        $state['errors'][] = 'Сессия формы истекла. Обновите страницу и попробуйте снова.';
        return $state;
    }

    $honeypot = trim((string) wp_unslash($_POST['website'] ?? ''));
    if ($honeypot !== '') {
        $state['errors'][] = 'Проверка формы не пройдена.';
        return $state;
    }

    $password = (string) wp_unslash($_POST['pwd'] ?? '');
    if ($state['username'] === '' || $password === '') {
        $state['errors'][] = 'Введите логин и пароль.';
        return $state;
    }

    if (malibu_exchange_is_login_captcha_enabled()) {
        $captcha_result = MC_Simple_Captcha::verify_from_post();
        if (is_wp_error($captcha_result)) {
            $state['errors'][] = $captcha_result->get_error_message();
            return $state;
        }
    }

    $user = wp_signon(
        [
            'user_login'    => $state['username'],
            'user_password' => $password,
            'remember'      => $state['remember'],
        ],
        is_ssl()
    );

    if (is_wp_error($user)) {
        foreach ($user->get_error_messages() as $message) {
            $state['errors'][] = wp_strip_all_tags($message);
        }
        return $state;
    }

    if (malibu_exchange_should_redirect_to_user_dashboard($state['redirect_to'])) {
        $state['redirect_to'] = malibu_exchange_get_dashboard_url((int) $user->ID);
    }

    wp_safe_redirect($state['redirect_to']);
    exit;
}

add_action('template_redirect', function () {
    if (is_admin() && !wp_doing_ajax()) {
        return;
    }

    if (is_page_template('page-login.php')) {
        return;
    }

    if (is_page() && !is_user_logged_in()) {
        wp_safe_redirect(malibu_exchange_get_login_url());
        exit;
    }
});
