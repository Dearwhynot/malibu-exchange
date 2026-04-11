<?php 

// начало чтобы убрать всякую чушь лишнию 
// <----DEARWHYNOT
add_filter('show_admin_bar', '__return_false');

remove_action('wp_head',             'print_emoji_detection_script', 7);
remove_action('admin_print_scripts', 'print_emoji_detection_script');
remove_action('wp_print_styles',     'print_emoji_styles');
remove_action('admin_print_styles',  'print_emoji_styles');

remove_action('wp_head', 'wp_resource_hints', 2); //remove dns-prefetch
remove_action('wp_head', 'wp_generator'); //remove meta name="generator"
remove_action('wp_head', 'wlwmanifest_link'); //remove wlwmanifest
remove_action('wp_head', 'rsd_link'); // remove EditURI
remove_action('wp_head', 'rest_output_link_wp_head'); // remove 'https://api.w.org/
remove_action('wp_head', 'rel_canonical'); //remove canonical
remove_action('wp_head', 'wp_shortlink_wp_head', 10); //remove shortlink
remove_action('wp_head', 'wp_oembed_add_discovery_links'); //remove alternate

// SFTP test marker: visible only to admins in page HTML.
function doverka_sftp_test_marker()
{
	if (!is_user_logged_in() || !current_user_can('manage_options')) {
		return;
	}
	echo "\n<!-- DOVERKA SFTP TEST V3 " . esc_html(current_time('Y-m-d H:i:s')) . " -->\n";
}
add_action('wp_footer', 'doverka_sftp_test_marker', 99);

add_filter('updraftplus_textdomain', '__return_false');

// Блокирует REST API доступ к комментариям
add_filter('rest_endpoints', function ($endpoints) {
	if (isset($endpoints['/wp/v2/comments'])) {
		unset($endpoints['/wp/v2/comments']);
	}
	return $endpoints;
});

// Полностью отключаем комментарии и пингбеки
add_action('admin_init', function () {
	// Удалить метабокс с комментами
	remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');

	// Перенаправление при попытке доступа к страницам комментариев
	global $pagenow;
	if ($pagenow === 'edit-comments.php') {
		wp_redirect(admin_url());
		exit;
	}

	// Отключить поддержку комментов у всех типов записей
	foreach (get_post_types() as $post_type) {
		if (post_type_supports($post_type, 'comments')) {
			remove_post_type_support($post_type, 'comments');
			remove_post_type_support($post_type, 'trackbacks');
		}
	}
});

add_action('admin_init', function () {
	if ((function_exists('wp_doing_ajax') && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX)) {
		return;
	}
	if (!is_user_logged_in()) {
		return;
	}
	if (current_user_can('manage_options')) {
		return;
	}

	wp_safe_redirect(home_url('/'));
	exit;
}, 1);

// Отключить вывод комментариев на фронте
add_filter('comments_open', '__return_false', 20, 2);
add_filter('pings_open', '__return_false', 20, 2);
add_filter('comments_array', '__return_empty_array', 10, 2);
// Скрыть меню комментариев в админке
add_action('admin_menu', function () {
	remove_menu_page('edit-comments.php');
});

// Удаление меню "Записи" из админки
add_action('admin_menu', function () {
	remove_menu_page('edit.php');
}, 999);
// Полное отключение стандартного типа записи "post"
add_action('init', function () {
	unregister_post_type('post');
}, 100);
// Убираем виджет "Быстрая публикация" на главной админки
add_action('wp_dashboard_setup', function () {
	remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
});
// Убираем блок "Последние записи"
add_action('wp_dashboard_setup', function () {
	remove_meta_box('dashboard_recent_posts', 'dashboard', 'normal');
});

function is_superviser()
{
	// Получаем текущего пользователя
	$current_user = wp_get_current_user();

	// Проверяем ID пользователя
	if ($current_user->ID == 1) {
		return true; // Пользователь имеет ID равный 1 Superviser
	} else {
		return false; // Пользователь не имеет ID равный 1 Superviser
	}
}

// Disable global-styles-inline-css
function remove_global_styles()
{
	wp_dequeue_style('global-styles');
}
add_action('wp_enqueue_scripts', 'remove_global_styles');

/*  DISABLE GUTENBERG STYLE IN HEADER| WordPress 5.9 */
function wps_deregister_styles()
{
	$ver = time(); // версия для сброса кеша браузера (dev)
	// wp_enqueue_script('jquery');
	wp_deregister_script('wp-embed'); // удалим wp-embed.min.js?ver=5.7.2' в футере

	wp_dequeue_style('global-styles');
}
add_action('wp_enqueue_scripts', 'wps_deregister_styles', 100);

// to delete JQMIGRATE: Migrate is installed, version 3.3.2
// function wpschool_remove_jquery_migrate($scripts)
// {
// 	if (!is_admin() && isset($scripts->registered['jquery'])) {
// 		$script = $scripts->registered['jquery'];
// 		if ($script->deps) {
// 			$script->deps = array_diff($script->deps, array('jquery-migrate'));
// 		}
// 	}
// }
// add_action('wp_default_scripts', 'wpschool_remove_jquery_migrate');

// удалить ссылку, а изменить сопровождающий её текст ошибки на сайте
add_filter('gettext', function ($translated_text, $text, $domain) {
	if ($text === 'Learn more about troubleshooting WordPress.') {
		return ''; // Заменяет текст на пустоту
	}
	return $translated_text;
}, 20, 3);
// конец чтобы убрать всякую чушь лишнию 
// DEARWHYNOT---->