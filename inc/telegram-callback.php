<?php

if (!defined('ABSPATH')) {
    exit;
}

function malibu_exchange_get_telegram_callback_route(): string
{
    return '/telegram/callback-universal';
}

function malibu_exchange_get_telegram_callback_url( int $company_id = 0 ): string
{
    $url = rest_url('malibu-exchange/v1' . malibu_exchange_get_telegram_callback_route());

    if ( $company_id > 0 ) {
        $url = add_query_arg(
            [
                'company' => $company_id,
            ],
            $url
        );
    }

    return $url;
}

function malibu_exchange_register_telegram_callback_route(): void
{
    register_rest_route('malibu-exchange/v1', malibu_exchange_get_telegram_callback_route(), [
        'methods' => ['GET', 'POST'],
        'callback' => 'malibu_exchange_handle_telegram_callback_request',
        'permission_callback' => '__return_true',
    ]);
}
add_action('rest_api_init', 'malibu_exchange_register_telegram_callback_route');

function malibu_exchange_handle_telegram_callback_request(WP_REST_Request $request)
{
    if (!defined('TG_UNIVERSAL_EMBEDDED')) {
        define('TG_UNIVERSAL_EMBEDDED', true);
    }

    $GLOBALS['TG_UNIVERSAL_RAW_UPDATE'] = (string) $request->get_body();

    require_once get_template_directory() . '/callbacks/telegram/telegram-callback-universal.php';

    if (!function_exists('tg_universal_callback_dispatch')) {
        unset($GLOBALS['TG_UNIVERSAL_RAW_UPDATE']);
        return new WP_REST_Response('Telegram callback handler is missing', 500);
    }

    $result = tg_universal_callback_dispatch();
    unset($GLOBALS['TG_UNIVERSAL_RAW_UPDATE']);

    $status = isset($result['status']) ? (int) $result['status'] : 200;
    $body = isset($result['body']) ? (string) $result['body'] : 'OK';
    $content_type = isset($result['content_type']) ? (string) $result['content_type'] : 'text/plain; charset=utf-8';

    $response = new WP_REST_Response($body, $status);
    $response->header('Content-Type', $content_type);

    return $response;
}
