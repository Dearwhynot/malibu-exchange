<?php
function malibu_exchange_require_login() {
    if (!is_user_logged_in()) {
        wp_safe_redirect(wp_login_url(get_permalink()));
        exit;
    }
}

add_action('template_redirect', function () {
    if (is_admin() && !wp_doing_ajax()) {
        return;
    }

    if (is_page_template('page-login.php')) {
        return;
    }

    if (is_page() && !is_user_logged_in()) {
        $login_page = get_page_by_path('login');
        if ($login_page) {
            wp_safe_redirect(get_permalink($login_page));
            exit;
        }
    }
});
