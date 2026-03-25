<?php
add_action('wp_ajax_malibu_exchange_refresh_orders', function () {
    wp_send_json_success([
        'message' => 'Orders refresh placeholder.',
    ]);
});
