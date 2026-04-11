<?php

// function malibu_exchange_theme_asset_path(string $relative_path): string
// {
//     return get_template_directory() . '/' . ltrim($relative_path, '/');
// }

// function malibu_exchange_theme_asset_uri(string $relative_path): string
// {
//     $segments = array_map('rawurlencode', explode('/', ltrim($relative_path, '/')));
//     return trailingslashit(get_template_directory_uri()) . implode('/', $segments);
// }

// function malibu_exchange_theme_asset_version(string $relative_path): string
// {
//     $full_path = malibu_exchange_theme_asset_path($relative_path);

//     if (file_exists($full_path)) {
//         return (string) filemtime($full_path);
//     }

//     return (string) wp_get_theme()->get('Version');
// }

// add_action('wp_enqueue_scripts', function () {
//     if (is_admin()) {
//         return;
//     }

//     if (is_page_template('page-default.php')) {
//         return;
//     }

//     $uri = get_template_directory_uri();
//     $dir = get_template_directory();

//     if (is_page_template('page-login.php')) {
//         wp_enqueue_style(
//             'malibu-exchange-login-bootstrap',
//             malibu_exchange_theme_asset_uri('theme source html bootstrap demo/condensed/assets/plugins/bootstrap/css/bootstrap.min.css'),
//             [],
//             malibu_exchange_theme_asset_version('theme source html bootstrap demo/condensed/assets/plugins/bootstrap/css/bootstrap.min.css')
//         );

//         wp_enqueue_style(
//             'malibu-exchange-login-pages',
//             malibu_exchange_theme_asset_uri('theme source html bootstrap demo/condensed/pages/css/pages.css'),
//             ['malibu-exchange-login-bootstrap'],
//             malibu_exchange_theme_asset_version('theme source html bootstrap demo/condensed/pages/css/pages.css')
//         );

//         wp_enqueue_style(
//             'malibu-exchange-login',
//             $uri . '/assets/css/login.css',
//             ['malibu-exchange-login-pages'],
//             filemtime($dir . '/assets/css/login.css')
//         );

//         wp_enqueue_script('jquery');

//         wp_enqueue_script(
//             'malibu-exchange-login',
//             $uri . '/assets/js/login.js',
//             ['jquery'],
//             filemtime($dir . '/assets/js/login.js'),
//             true
//         );

//         wp_localize_script(
//             'malibu-exchange-login',
//             'malibuLogin',
//             [
//                 'ajaxUrl' => admin_url('admin-ajax.php'),
//             ]
//         );

//         return;
//     }

//     wp_enqueue_style(
//         'malibu-exchange-app',
//         $uri . '/assets/css/app.css',
//         [],
//         filemtime($dir . '/assets/css/app.css')
//     );

//     wp_enqueue_script('jquery');

//     wp_enqueue_script(
//         'malibu-exchange-app',
//         $uri . '/assets/js/app.js',
//         ['jquery'],
//         filemtime($dir . '/assets/js/app.js'),
//         true
//     );

//     if (is_page_template('page-rates.php')) {
//         wp_enqueue_script(
//             'malibu-exchange-rates',
//             $uri . '/assets/js/rates.js',
//             ['jquery', 'malibu-exchange-app'],
//             filemtime($dir . '/assets/js/rates.js'),
//             true
//         );
//     }
// });
