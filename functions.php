<?php

if (!defined('ABSPATH')) {
	exit;
}

// error_log('functions.php загружен');

// require_once get_template_directory() . '/inc/setup.php';
// require_once get_template_directory() . '/inc/helpers.php';
// Централизованный аудит-лог (загружается первым — нужен для хуков аутентификации)
require_once get_template_directory() . '/inc/audit-log.php';

require_once get_template_directory() . '/inc/security.php';

// CRM RBAC: роли, права, статусы пользователей
require_once get_template_directory() . '/inc/rbac.php';

// Управление пользователями: трекинг last_login, хелперы
require_once get_template_directory() . '/inc/users.php';

// AJAX-обработчики управления пользователями
require_once get_template_directory() . '/inc/ajax/users.php';

// Настройки системы (crm_settings)
require_once get_template_directory() . '/inc/settings.php';
require_once get_template_directory() . '/inc/ajax/settings.php';

// Курсы валют
require_once get_template_directory() . '/inc/rates.php';
require_once get_template_directory() . '/inc/ajax/rates.php';

// Журнал действий (страница логов)
require_once get_template_directory() . '/inc/ajax/logs.php';

// require_once get_template_directory() . '/inc/menus.php';
// require_once get_template_directory() . '/inc/enqueue.php';
// require_once get_template_directory() . '/inc/template-tags.php';
require_once get_template_directory() . '/inc/migration-runner.php';
require_once get_template_directory() . '/inc/telegram-callback.php';
// require_once get_template_directory() . '/inc/ajax/bot-actions.php';
// require_once get_template_directory() . '/inc/ajax/orders.php';

// дебаг лог -->
//? Нужны три константы в wp-config.php:
//? define( 'WP_DEBUG',         true  );   // включить режим отладки
//? define( 'WP_DEBUG_LOG',     true  );   // писать ошибки в wp-content/debug.log
//? define( 'WP_DEBUG_DISPLAY', false );   // не выводить ошибки на страницу
require_once get_template_directory() . '/includes/debug-log-2.php';
// <-- дебаг лог

// простая капча -->
require_once get_template_directory() . '/includes/simple-captcha.php';
// <-- простая капча

// ежедневный форс-логин -->
require_once get_template_directory() . '/includes/daily-force-relogin.php';
// <-- ежедневный форс-логин

// форс-логин -->
require_once get_template_directory() . '/includes/force-login.php';
// форс-логин -->

// рабочий сетам для старта форс-логин -->
require_once get_template_directory() . '/includes/dearwhynot-start.php';
// <-- рабочий сетам для старта



/**
 * Основные функции темы Malibu Exchange Pages Starter.
 *
 * Подход здесь намеренно быстрый и практичный:
 * - не переусложняем архитектуру;
 * - подключаем только базовые вещи;
 * - готовим платформу, от которой потом удобно плодить страницы.
 */

// /**
//  * Базовая настройка темы.
//  */
// function malibu_exchange_theme_setup(): void
// {
// 	add_theme_support('title-tag');
// 	add_theme_support('post-thumbnails');
// 	add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);

// 	register_nav_menus([
// 		'primary' => 'Primary Menu',
// 		'sidebar' => 'Sidebar Menu',
// 	]);
// }
// add_action('after_setup_theme', 'malibu_exchange_theme_setup');

/**
 * PAGES assets: single source of truth.
 * Удалить все ручные <script> и <link> из шаблонов.
 * Удалить/отключить другие enqueue этих же файлов.
 */
function malibu_exchange_enqueue_pages_assets(): void
{
	if (is_admin()) {
		return;
	}

	$theme_uri = get_template_directory_uri();
	$ver = wp_get_theme()->get('Version');

	/**
	 * Используем jQuery из шаблона Pages,
	 * чтобы совпадало с оригинальным default_layout.html
	 */
	wp_deregister_script('jquery');
	wp_register_script( 'jquery', $theme_uri . '/vendor/pages/assets/plugins/jquery/jquery-3.2.1.min.js', [], '3.2.1', true );

	/* =========================
	 * CSS
	 * ========================= */
	wp_enqueue_style( 'pages-pace',             $theme_uri . '/vendor/pages/assets/plugins/pace/pace-theme-flash.css',             [], $ver );
	wp_enqueue_style( 'pages-bootstrap',        $theme_uri . '/vendor/pages/assets/plugins/bootstrap/css/bootstrap.min.css',       [], $ver );
	wp_enqueue_style( 'pages-material-icons',   'https://fonts.googleapis.com/icon?family=Material+Icons',                         [], null );
	wp_enqueue_style( 'pages-jquery-scrollbar', $theme_uri . '/vendor/pages/assets/plugins/jquery-scrollbar/jquery.scrollbar.css', [], $ver );
	wp_enqueue_style( 'pages-select2',          $theme_uri . '/vendor/pages/assets/plugins/select2/css/select2.min.css',           [], $ver );
	wp_enqueue_style( 'pages-core',             $theme_uri . '/vendor/pages/pages/css/pages.css',                                  ['pages-bootstrap'], $ver );
	// wp_enqueue_style( 'pages-demo-style', $theme_uri . '/vendor/pages/assets/css/style.css', ['pages-core'], $ver );

	/* =========================
	 * JS
	 * ========================= */

	// В оригинале pace грузится раньше остальных.
	wp_enqueue_script( 'pages-pace',             $theme_uri . '/vendor/pages/assets/plugins/pace/pace.min.js',                        [], $ver, false );
	wp_enqueue_script('jquery');
	wp_enqueue_script( 'pages-liga',             $theme_uri . '/vendor/pages/assets/plugins/liga.js',                                 [], $ver, true );
	wp_enqueue_script( 'pages-modernizr',        $theme_uri . '/vendor/pages/assets/plugins/modernizr.custom.js',                     [], $ver, true );
	wp_enqueue_script( 'pages-jquery-ui',        $theme_uri . '/vendor/pages/assets/plugins/jquery-ui/jquery-ui.min.js',              ['jquery'], $ver, true );
	wp_enqueue_script( 'pages-popper',           $theme_uri . '/vendor/pages/assets/plugins/popper/umd/popper.min.js',                [], $ver, true );
	wp_enqueue_script( 'pages-bootstrap',        $theme_uri . '/vendor/pages/assets/plugins/bootstrap/js/bootstrap.min.js',           ['jquery', 'pages-popper'], $ver, true );
	wp_enqueue_script( 'pages-jquery-easy',      $theme_uri . '/vendor/pages/assets/plugins/jquery/jquery-easy.js',                   ['jquery'], $ver, true );
	wp_enqueue_script( 'pages-jquery-unveil',    $theme_uri . '/vendor/pages/assets/plugins/jquery-unveil/jquery.unveil.min.js',      ['jquery'], $ver, true );
	wp_enqueue_script( 'pages-jquery-ios-list',  $theme_uri . '/vendor/pages/assets/plugins/jquery-ios-list/jquery.ioslist.min.js',   ['jquery'], $ver, true );
	wp_enqueue_script( 'pages-jquery-actual',    $theme_uri . '/vendor/pages/assets/plugins/jquery-actual/jquery.actual.min.js',      ['jquery'], $ver, true );
	wp_enqueue_script( 'pages-jquery-scrollbar', $theme_uri . '/vendor/pages/assets/plugins/jquery-scrollbar/jquery.scrollbar.min.js', ['jquery'], $ver, true );
	wp_enqueue_script( 'pages-select2',          $theme_uri . '/vendor/pages/assets/plugins/select2/js/select2.full.min.js',          ['jquery'], $ver, true );
	wp_enqueue_script( 'pages-classie',          $theme_uri . '/vendor/pages/assets/plugins/classie/classie.js',                      [], $ver, true );
	wp_enqueue_script( 'pages-core-js',          $theme_uri . '/vendor/pages/pages/js/pages.js',                                      ['jquery', 'pages-bootstrap', 'pages-jquery-ui', 'pages-jquery-easy', 'pages-jquery-unveil', 'pages-jquery-ios-list', 'pages-jquery-actual', 'pages-jquery-scrollbar', 'pages-select2', 'pages-classie'], $ver, true );
	wp_enqueue_script( 'pages-custom-js',        $theme_uri . '/vendor/pages/assets/js/scripts.js',                                   ['pages-core-js'], $ver, true );

	// Sidebar pin persistence: remember pinned state across page loads (desktop only)
	wp_add_inline_script( 'pages-custom-js', <<<'JS'
(function($){
    var KEY = 'malibu_sidebar_pinned';
    $(function(){
        if ($(window).width() >= 1200 && localStorage.getItem(KEY) === '1') {
            $('body').addClass('menu-pin').removeClass('menu-unpinned');
        }
        $(document).on('click.malibu.pin', '[data-toggle-pin="sidebar"]', function(){
            setTimeout(function(){
                if ($('body').hasClass('menu-pin')) {
                    localStorage.setItem(KEY, '1');
                } else {
                    localStorage.removeItem(KEY);
                }
            }, 0);
        });
    });
})(jQuery);
JS
	);

	if (is_page_template('page-login.php')) {
		wp_enqueue_script( 'pages-jquery-validate', $theme_uri . '/vendor/pages/assets/plugins/jquery-validation/js/jquery.validate.min.js', ['jquery'], $ver, true );
		wp_add_inline_script( 'pages-jquery-validate', "jQuery(function($){ $('#form-login').validate(); });" );
	}
}
add_action('wp_enqueue_scripts', 'malibu_exchange_enqueue_pages_assets', 20);