<?php

/**
 * Базовый fallback шаблон.
 *
 * Даже если ты еще не создал отдельные page templates,
 * тема не должна падать.
 */
if (!defined('ABSPATH')) {
    exit;
}

$target = function_exists('malibu_exchange_get_dashboard_url')
    ? malibu_exchange_get_dashboard_url()
    : home_url('/dashboard/');

wp_safe_redirect($target);
exit;
