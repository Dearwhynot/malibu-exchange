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

wp_safe_redirect( home_url( '/dashboard/' ) );
exit;