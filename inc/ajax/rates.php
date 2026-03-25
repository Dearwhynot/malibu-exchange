<?php
add_action('wp_ajax_malibu_exchange_save_rates', function () {
    check_ajax_referer('malibu_exchange_nonce', 'nonce');

    wp_send_json_success([
        'message' => 'Rates saved placeholder.',
        'data'    => $_POST,
    ]);
});
