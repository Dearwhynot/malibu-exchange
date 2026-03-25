<?php
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) {
        return;
    }

    $uri = get_template_directory_uri();
    $dir = get_template_directory();

    wp_enqueue_style(
        'malibu-exchange-app',
        $uri . '/assets/css/app.css',
        [],
        filemtime($dir . '/assets/css/app.css')
    );

    wp_enqueue_script('jquery');

    wp_enqueue_script(
        'malibu-exchange-app',
        $uri . '/assets/js/app.js',
        ['jquery'],
        filemtime($dir . '/assets/js/app.js'),
        true
    );

    if (is_page_template('page-rates.php')) {
        wp_enqueue_script(
            'malibu-exchange-rates',
            $uri . '/assets/js/rates.js',
            ['jquery', 'malibu-exchange-app'],
            filemtime($dir . '/assets/js/rates.js'),
            true
        );
    }
});
