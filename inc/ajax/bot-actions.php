<?php
add_action('wp_ajax_malibu_exchange_ping_bot', function () {
    wp_send_json_success([
        'message' => 'Bot endpoint placeholder is alive.',
    ]);
});
