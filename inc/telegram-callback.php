<?php

if (!defined('ABSPATH')) {
    exit;
}

function malibu_exchange_get_telegram_callback_route( string $context = 'merchant' ): string
{
    if ( sanitize_key( $context ) === 'legacy' ) {
        return '/telegram/callback-universal';
    }

    $context = function_exists( 'crm_telegram_normalize_bot_context' )
        ? crm_telegram_normalize_bot_context( $context )
        : sanitize_key( $context );

    if ( $context === 'operator' ) {
        return '/telegram/operator-callback';
    }

    return '/telegram/merchant-callback';
}

function malibu_exchange_get_telegram_callback_url( int $company_id = 0, string $context = 'merchant' ): string
{
    $url = rest_url('malibu-exchange/v1' . malibu_exchange_get_telegram_callback_route( $context ));

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
    register_rest_route('malibu-exchange/v1', malibu_exchange_get_telegram_callback_route( 'merchant' ), [
        'methods' => ['GET', 'POST'],
        'callback' => static function ( WP_REST_Request $request ) {
            return malibu_exchange_handle_telegram_callback_request( $request, 'merchant' );
        },
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('malibu-exchange/v1', malibu_exchange_get_telegram_callback_route( 'operator' ), [
        'methods' => ['GET', 'POST'],
        'callback' => static function ( WP_REST_Request $request ) {
            return malibu_exchange_handle_telegram_callback_request( $request, 'operator' );
        },
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('malibu-exchange/v1', malibu_exchange_get_telegram_callback_route( 'legacy' ), [
        'methods' => ['GET', 'POST'],
        'callback' => static function ( WP_REST_Request $request ) {
            return malibu_exchange_handle_telegram_callback_request( $request, 'merchant' );
        },
        'permission_callback' => '__return_true',
    ]);
}
add_action('rest_api_init', 'malibu_exchange_register_telegram_callback_route');

function malibu_exchange_handle_telegram_callback_request(WP_REST_Request $request, string $context = 'merchant')
{
    $context = function_exists( 'crm_telegram_normalize_bot_context' )
        ? crm_telegram_normalize_bot_context( $context )
        : sanitize_key( $context );

    if (!defined('TG_UNIVERSAL_EMBEDDED')) {
        define('TG_UNIVERSAL_EMBEDDED', true);
    }

    $GLOBALS['CRM_TELEGRAM_CALLBACK_CONTEXT'] = $context;
    $GLOBALS['TG_UNIVERSAL_RAW_UPDATE'] = (string) $request->get_body();

    $callback_file = $context === 'operator'
        ? '/callbacks/telegram/telegram-callback-operator.php'
        : '/callbacks/telegram/telegram-callback-merchant.php';

    require_once get_template_directory() . $callback_file;

    if (!function_exists('tg_universal_callback_dispatch')) {
        unset($GLOBALS['TG_UNIVERSAL_RAW_UPDATE']);
        unset($GLOBALS['CRM_TELEGRAM_CALLBACK_CONTEXT']);
        return new WP_REST_Response('Telegram callback handler is missing', 500);
    }

    $result = tg_universal_callback_dispatch();
    unset($GLOBALS['TG_UNIVERSAL_RAW_UPDATE']);
    unset($GLOBALS['CRM_TELEGRAM_CALLBACK_CONTEXT']);

    $status = isset($result['status']) ? (int) $result['status'] : 200;
    $body = isset($result['body']) ? (string) $result['body'] : 'OK';
    $content_type = isset($result['content_type']) ? (string) $result['content_type'] : 'text/plain; charset=utf-8';

    $response = new WP_REST_Response($body, $status);
    $response->header('Content-Type', $content_type);

    return $response;
}
