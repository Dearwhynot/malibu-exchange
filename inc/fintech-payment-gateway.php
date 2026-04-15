<?php
/**
 * Malibu Exchange — Fintech Payment Gateway Helper
 *
 * Адаптация под Malibu:
 * - настройки читаются через crm_fintech_settings() → crm_get_setting()
 * - никакого get_option() / wp_options
 * - company_id используется для multi-company настроек
 *
 * Публичный API:
 *   Fintech_Payment_Gateway::create_invoice(float, ?string, string): array
 *   Fintech_Payment_Gateway::get_order_status(string): array
 *   Fintech_Payment_Gateway::get_order_data(string): array
 *   Fintech_Payment_Gateway::set_company_id(int): void
 *
 * Процедурные обёртки:
 *   fintech_create_invoice()
 *   fintech_get_order_status()
 *   fintech_get_order_data()
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Adapter настроек ────────────────────────────────────────────────────────

/**
 * Читает fintech-настройки из crm_settings для конкретной компании.
 * Возвращает вложенный массив — тот же формат, который ожидает класс.
 */
function crm_fintech_settings( int $company_id = 0 ): array {
	if ( $company_id <= 0 ) {
		$company_id = defined( 'CRM_DEFAULT_ORG_ID' ) ? (int) CRM_DEFAULT_ORG_ID : 1;
	}

	return [
		'company_name'          => crm_get_setting( 'fintech_company_name',           $company_id, '' ),
		'merchant_order_prefix' => crm_get_setting( 'fintech_merchant_order_prefix',  $company_id, 'MALIBU' ),
		'active_provider'       => crm_get_setting( 'fintech_active_provider',        $company_id, 'kanyon' ),
		'debug'                 => crm_get_setting( 'fintech_debug',                  $company_id, '0' ) === '1',
		// callback_path: не редактируется оператором — фиксируем в коде
		'callback_path'         => '/fintech-payment-callback',
		'pay2day'               => [
			'login'          => crm_get_setting( 'fintech_pay2day_login',          $company_id, '' ),
			'password'       => crm_get_setting( 'fintech_pay2day_password',       $company_id, '' ),
			'tsp_id'         => (int) crm_get_setting( 'fintech_pay2day_tsp_id',   $company_id, '0' ),
			'order_currency' => crm_get_setting( 'fintech_pay2day_order_currency', $company_id, 'USDT' ),
		],
		'doverka'               => [
			'api_key'         => crm_get_setting( 'fintech_doverka_api_key',          $company_id, '' ),
			'currency_id'     => (int) crm_get_setting( 'fintech_doverka_currency_id', $company_id, '0' ),
			'approve_url'     => crm_get_setting( 'fintech_doverka_approve_url',       $company_id, '' ),
			'kyc_redirect_url'=> crm_get_setting( 'fintech_doverka_kyc_redirect_url', $company_id, '' ),
			'callback_url'    => '', // всегда используем общий callback URL
		],
	];
}

// ─── Класс gateway ──────────────────────────────────────────────────────────

class Fintech_Payment_Gateway {

	public const PROVIDER_KANYON  = 'kanyon';
	public const PROVIDER_DOVERKA = 'doverka';

	// Base URLs — инфраструктурные значения, оператор не меняет
	private const PAY2DAY_IDENTITY_BASE_URL = 'https://identity.authpoint.pro/api/v1';
	private const PAY2DAY_BASE_URL          = 'https://kanyonpay.pay2day.kz/api/v1';
	private const DOVERKA_BASE_URL          = 'https://api.doverkapay.com';

	private const DEFAULT_CALLBACK_PATH     = '/fintech-payment-callback';
	private const PAY2DAY_TOKEN_TRANSIENT   = 'fintech_pay2day_access_token';

	/** company_id для текущего запроса */
	private static int $company_id = 0;

	/**
	 * Установить company_id до вызова методов.
	 * Если не задан — используется CRM_DEFAULT_ORG_ID (= 1).
	 */
	public static function set_company_id( int $id ): void {
		self::$company_id = $id;
	}

	// ── Публичный API ────────────────────────────────────────────────────────

	public static function create_invoice( float $amount_usdt, ?string $merchant_order_id = null, string $description = '' ): array {
		$provider = self::get_active_provider();

		if ( $provider === self::PROVIDER_DOVERKA ) {
			return self::doverka_create_invoice( $amount_usdt, $merchant_order_id, $description );
		}

		return self::pay2day_create_invoice( $amount_usdt, $merchant_order_id, $description );
	}

	public static function get_order_status( string $order_id ): array {
		$provider = self::get_active_provider();

		if ( $provider === self::PROVIDER_DOVERKA ) {
			return self::doverka_get_order_status( $order_id );
		}

		return self::pay2day_get_order_status( $order_id );
	}

	public static function get_order_data( string $order_id ): array {
		$provider = self::get_active_provider();

		if ( $provider === self::PROVIDER_DOVERKA ) {
			return self::doverka_get_order_data( $order_id );
		}

		return self::pay2day_get_order_data( $order_id );
	}

	// ── Настройки ────────────────────────────────────────────────────────────

	public static function get_settings(): array {
		return crm_fintech_settings( self::$company_id );
	}

	public static function get_active_provider(): string {
		$settings = self::get_settings();
		$provider = strtolower( trim( (string) ( $settings['active_provider'] ?? '' ) ) );

		if ( in_array( $provider, [ self::PROVIDER_KANYON, self::PROVIDER_DOVERKA ], true ) ) {
			return $provider;
		}

		return self::PROVIDER_KANYON;
	}

	public static function get_company_name(): string {
		$settings = self::get_settings();
		$name     = trim( (string) ( $settings['company_name'] ?? '' ) );

		return $name !== '' ? $name : 'Malibu Exchange';
	}

	public static function get_callback_url(): string {
		$settings = self::get_settings();
		$path     = $settings['callback_path'] ?: self::DEFAULT_CALLBACK_PATH;

		return home_url( trailingslashit( ltrim( $path, '/' ) ) );
	}

	// ── Общие хелперы ────────────────────────────────────────────────────────

	private static function generate_request_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return bin2hex( random_bytes( 16 ) );
	}

	private static function amount_to_minor_units( $amount ): ?int {
		if ( $amount === null || $amount === '' ) {
			return null;
		}

		return (int) round( ( (float) $amount ) * 100 );
	}

	private static function extract_qrc_id_from_link( ?string $link, string $fallback = '' ): ?string {
		$link = trim( (string) $link );

		if ( $link !== '' && preg_match( '~https?://qr\.nspk\.ru/([^?]+)~i', $link, $matches ) ) {
			return $matches[1];
		}

		return $fallback !== '' ? $fallback : null;
	}

	private static function build_merchant_order_id(): string {
		$settings = self::get_settings();
		$prefix   = self::normalize_order_prefix( (string) ( $settings['merchant_order_prefix'] ?? '' ) );

		return $prefix . '_' . time() . '_' . wp_rand( 1000, 9999 );
	}

	private static function build_default_description( string $merchant_order_id ): string {
		return self::get_company_name() . ' payment ' . $merchant_order_id;
	}

	private static function debug_log( string $label, $payload = null ): void {
		$settings = self::get_settings();
		if ( empty( $settings['debug'] ) ) {
			return;
		}

		if ( $payload !== null ) {
			if ( is_array( $payload ) ) {
				$payload = self::mask_sensitive_data( $payload );
			}
			if ( is_array( $payload ) || is_object( $payload ) ) {
				$payload = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
			}
			error_log( '[FINTECH] ' . $label . ' ' . (string) $payload );
			return;
		}

		error_log( '[FINTECH] ' . $label );
	}

	private static function mask_sensitive_data( array $data ): array {
		$sensitive_keys = [ 'password', 'api_key', 'apikey', 'authorization', 'authorization-token', 'accessToken', 'token', 'login' ];
		$masked = [];

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$masked[ $key ] = self::mask_sensitive_data( $value );
				continue;
			}
			if ( in_array( strtolower( (string) $key ), $sensitive_keys, true ) ) {
				$masked[ $key ] = self::mask_string( (string) $value );
				continue;
			}
			$masked[ $key ] = $value;
		}

		return $masked;
	}

	private static function mask_string( string $value ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}
		if ( strlen( $value ) <= 6 ) {
			return '***';
		}

		return substr( $value, 0, 3 ) . '***' . substr( $value, -2 );
	}

	private static function http_request( string $method, string $url, array $headers = [], $body = null ): array {
		$method = strtoupper( $method );

		$request_headers = array_merge( [
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
			'x-req-id'     => self::generate_request_id(),
		], $headers );

		$request_args = [
			'method'      => $method,
			'timeout'     => 30,
			'redirection' => 3,
			'headers'     => $request_headers,
		];

		if ( $body !== null ) {
			$request_args['body'] = wp_json_encode( $body, JSON_UNESCAPED_UNICODE );
		}

		self::debug_log( 'HTTP request', [ 'method' => $method, 'url' => $url, 'body' => $body ] );

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			return [
				'success'  => false,
				'httpCode' => 0,
				'data'     => null,
				'raw'      => null,
				'error'    => $response->get_error_message(),
			];
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body  = (string) wp_remote_retrieve_body( $response );
		$decoded   = json_decode( $raw_body, true );

		self::debug_log( 'HTTP response', [ 'httpCode' => $http_code, 'body' => $decoded ?? $raw_body ] );

		return [
			'success'  => $http_code >= 200 && $http_code < 300,
			'httpCode' => $http_code,
			'data'     => is_array( $decoded ) ? $decoded : null,
			'raw'      => $raw_body,
			'error'    => ( $http_code >= 200 && $http_code < 300 ) ? null : ( 'HTTP ' . $http_code ),
		];
	}

	// ── Pay2Day / Kanyon ────────────────────────────────────────────────────

	private static function pay2day_get_access_token(): array {
		$settings = self::get_settings();
		$login    = trim( (string) $settings['pay2day']['login'] );
		$password = trim( (string) $settings['pay2day']['password'] );

		if ( $login === '' || $password === '' ) {
			return [ 'success' => false, 'token' => null, 'error' => 'Pay2Day login/password not configured', 'raw' => null ];
		}

		$cached = get_transient( self::PAY2DAY_TOKEN_TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached['token'] ) && ! empty( $cached['expires'] ) && (int) $cached['expires'] > ( time() + 60 ) ) {
			return [ 'success' => true, 'token' => (string) $cached['token'], 'error' => null, 'raw' => null ];
		}

		$response     = self::http_request( 'POST', rtrim( self::PAY2DAY_IDENTITY_BASE_URL, '/' ) . '/public/login', [], [ 'login' => $login, 'password' => $password ] );
		$data         = $response['data'] ?? [];
		$access_token = $data['accessToken'] ?? null;
		$expires      = isset( $data['expiresAccess'] ) ? (int) $data['expiresAccess'] : 0;

		if ( empty( $response['success'] ) || empty( $access_token ) ) {
			return [ 'success' => false, 'token' => null, 'error' => 'Pay2Day login failed', 'raw' => $response ];
		}

		if ( $expires > 0 ) {
			set_transient( self::PAY2DAY_TOKEN_TRANSIENT, [ 'token' => $access_token, 'expires' => $expires ], max( 60, $expires - time() - 60 ) );
		}

		return [ 'success' => true, 'token' => (string) $access_token, 'error' => null, 'raw' => $data ];
	}

	private static function pay2day_create_invoice( float $amount_usdt, ?string $merchant_order_id, string $description ): array {
		$settings       = self::get_settings();
		$tsp_id         = (int) $settings['pay2day']['tsp_id'];
		$order_currency = strtoupper( trim( (string) $settings['pay2day']['order_currency'] ) );

		if ( $amount_usdt <= 0 ) {
			return self::build_error_response( 'Amount must be greater than zero', self::PROVIDER_KANYON );
		}
		if ( $tsp_id <= 0 ) {
			return self::build_error_response( 'Pay2Day tsp_id not configured', self::PROVIDER_KANYON );
		}
		if ( $order_currency === '' ) {
			return self::build_error_response( 'Pay2Day order_currency not configured', self::PROVIDER_KANYON );
		}

		if ( $merchant_order_id === null || trim( $merchant_order_id ) === '' ) {
			$merchant_order_id = self::build_merchant_order_id();
		}
		if ( $description === '' ) {
			$description = self::build_default_description( $merchant_order_id );
		}

		$token_response = self::pay2day_get_access_token();
		if ( empty( $token_response['success'] ) || empty( $token_response['token'] ) ) {
			return self::build_error_response( 'Unable to get Pay2Day token', self::PROVIDER_KANYON, [ 'raw' => $token_response, 'merchantOrderId' => $merchant_order_id ] );
		}

		$order_amount_cents = (int) round( $amount_usdt * 100 );
		$create_response    = self::http_request(
			'POST',
			rtrim( self::PAY2DAY_BASE_URL, '/' ) . '/order',
			[ 'Authorization-Token' => (string) $token_response['token'] ],
			[
				'merchantOrderId' => $merchant_order_id,
				'orderAmount'     => $order_amount_cents,
				'orderCurrency'   => $order_currency,
				'tspId'           => $tsp_id,
				'description'     => $description,
				'callbackUrl'     => self::get_callback_url(),
			]
		);

		$order    = $create_response['data']['order'] ?? null;
		$order_id = is_array( $order ) ? (string) ( $order['id'] ?? '' ) : '';

		if ( empty( $create_response['success'] ) || $order_id === '' ) {
			return self::build_error_response( 'Pay2Day order creation failed', self::PROVIDER_KANYON, [ 'raw' => $create_response, 'merchantOrderId' => $merchant_order_id ] );
		}

		$qrc_response = self::http_request(
			'POST',
			rtrim( self::PAY2DAY_BASE_URL, '/' ) . '/order/qrcData/' . rawurlencode( $order_id ),
			[ 'Authorization-Token' => (string) $token_response['token'] ],
			(object) []
		);
		$qrc_order = $qrc_response['data']['order'] ?? null;

		if ( empty( $qrc_response['success'] ) || ! is_array( $qrc_order ) ) {
			return self::build_error_response( 'Pay2Day qrcData request failed', self::PROVIDER_KANYON, [ 'raw' => $qrc_response, 'orderId' => $order_id, 'merchantOrderId' => $merchant_order_id ] );
		}

		return [
			'success'           => true,
			'provider'          => self::PROVIDER_KANYON,
			'error'             => null,
			'orderId'           => $order_id,
			'merchantOrderId'   => (string) ( $order['merchantOrderId'] ?? $merchant_order_id ),
			'amountUsdt'        => $amount_usdt,
			'orderAmountCents'  => (int) ( $order['orderAmount'] ?? $order_amount_cents ),
			'paymentAmountRub'  => isset( $order['paymentAmount'] ) ? (int) $order['paymentAmount'] : null,
			'qrcId'             => $qrc_order['qrcId'] ?? null,
			'payload'           => $qrc_order['payload'] ?? null,
			'externalOrderId'   => $qrc_order['externalOrderId'] ?? null,
			'providerStatus'    => $order['status'] ?? null,
			'raw'               => [ 'createOrder' => $create_response, 'qrcData' => $qrc_response ],
		];
	}

	private static function pay2day_get_order_status( string $order_id ): array {
		$token_response = self::pay2day_get_access_token();
		if ( empty( $token_response['success'] ) || empty( $token_response['token'] ) ) {
			return self::build_error_response( 'Unable to get Pay2Day token', self::PROVIDER_KANYON, [ 'raw' => $token_response, 'status' => null ] );
		}

		$response = self::http_request( 'GET', rtrim( self::PAY2DAY_BASE_URL, '/' ) . '/status/' . rawurlencode( $order_id ), [ 'Authorization-Token' => (string) $token_response['token'] ] );
		$status   = $response['data']['order']['status'] ?? null;

		if ( empty( $response['success'] ) ) {
			return self::build_error_response( 'Pay2Day status request failed', self::PROVIDER_KANYON, [ 'raw' => $response, 'status' => $status ] );
		}

		return [ 'success' => true, 'provider' => self::PROVIDER_KANYON, 'status' => $status, 'providerStatus' => $status, 'error' => null, 'raw' => $response ];
	}

	private static function pay2day_get_order_data( string $order_id ): array {
		$token_response = self::pay2day_get_access_token();
		if ( empty( $token_response['success'] ) || empty( $token_response['token'] ) ) {
			return self::build_error_response( 'Unable to get Pay2Day token', self::PROVIDER_KANYON, [ 'raw' => $token_response, 'order' => null, 'status' => null ] );
		}

		$response = self::http_request( 'GET', rtrim( self::PAY2DAY_BASE_URL, '/' ) . '/order/' . rawurlencode( $order_id ), [ 'Authorization-Token' => (string) $token_response['token'] ] );
		$order    = $response['data']['order'] ?? null;
		$status   = is_array( $order ) ? ( $order['status'] ?? null ) : null;

		if ( empty( $response['success'] ) || ! is_array( $order ) ) {
			return self::build_error_response( 'Pay2Day order data request failed', self::PROVIDER_KANYON, [ 'raw' => $response, 'order' => null, 'status' => $status ] );
		}

		return [ 'success' => true, 'provider' => self::PROVIDER_KANYON, 'status' => $status, 'providerStatus' => $status, 'order' => $order, 'error' => null, 'raw' => $response ];
	}

	// ── Doverka ──────────────────────────────────────────────────────────────

	private static function doverka_is_payment_not_found( array $response ): bool {
		if ( (int) ( $response['httpCode'] ?? 0 ) !== 404 ) {
			return false;
		}
		$detail = trim( (string) ( $response['data']['detail'] ?? '' ) );

		return $detail !== '' ? strcasecmp( $detail, 'Payment not found' ) === 0 : stripos( (string) ( $response['raw'] ?? '' ), 'Payment not found' ) !== false;
	}

	private static function doverka_map_status( ?string $provider_status ): ?string {
		$provider_status = strtoupper( (string) $provider_status );
		if ( $provider_status === '' ) {
			return null;
		}
		switch ( $provider_status ) {
			case 'PAID':
				return 'IPS_ACCEPTED';
			case 'CANCELLED':
				return 'DECLINED';
			case 'EXPIRED':
				return 'EXPIRED';
			default:
				return $provider_status;
		}
	}

	private static function doverka_get_payment_payload( string $order_id ): array {
		$settings = self::get_settings();
		$api_key  = trim( (string) $settings['doverka']['api_key'] );

		if ( $api_key === '' ) {
			return [ 'success' => false, 'payment' => null, 'notFound' => false, 'error' => 'Doverka api_key not configured', 'raw' => null ];
		}

		$response = self::http_request( 'GET', rtrim( self::DOVERKA_BASE_URL, '/' ) . '/v1/payments/' . rawurlencode( $order_id ), [ 'Authorization' => 'Bearer ' . $api_key ] );
		$payment  = $response['data'] ?? null;

		if ( empty( $response['success'] ) || ! is_array( $payment ) ) {
			return [ 'success' => false, 'payment' => null, 'notFound' => self::doverka_is_payment_not_found( $response ), 'error' => 'Doverka payment request failed', 'raw' => $response ];
		}

		return [ 'success' => true, 'payment' => $payment, 'notFound' => false, 'error' => null, 'raw' => $response ];
	}

	private static function doverka_create_invoice( float $amount_usdt, ?string $merchant_order_id, string $description ): array {
		$settings         = self::get_settings();
		$api_key          = trim( (string) $settings['doverka']['api_key'] );
		$currency_id      = (int) $settings['doverka']['currency_id'];
		$approve_url      = trim( (string) $settings['doverka']['approve_url'] );
		$kyc_redirect_url = trim( (string) $settings['doverka']['kyc_redirect_url'] );

		if ( $amount_usdt <= 0 ) {
			return self::build_error_response( 'Amount must be greater than zero', self::PROVIDER_DOVERKA );
		}
		if ( $api_key === '' ) {
			return self::build_error_response( 'Doverka api_key not configured', self::PROVIDER_DOVERKA );
		}
		if ( $currency_id <= 0 ) {
			return self::build_error_response( 'Doverka currency_id not configured', self::PROVIDER_DOVERKA );
		}

		if ( $merchant_order_id === null || trim( $merchant_order_id ) === '' ) {
			$merchant_order_id = self::build_merchant_order_id();
		}
		if ( $description === '' ) {
			$description = self::build_default_description( $merchant_order_id );
		}

		$payload = [
			'currency_id'          => $currency_id,
			'amount'               => number_format( $amount_usdt, 2, '.', '' ),
			'order_title'          => $description,
			'order_transaction_id' => $merchant_order_id,
			'callback_url'         => self::get_callback_url(),
		];

		if ( $approve_url !== '' ) {
			$payload['approve_url'] = $approve_url;
		}
		if ( $kyc_redirect_url !== '' ) {
			$payload['kyc_redirect_url'] = $kyc_redirect_url;
		}

		$response = self::http_request( 'POST', rtrim( self::DOVERKA_BASE_URL, '/' ) . '/v1/payments', [ 'Authorization' => 'Bearer ' . $api_key ], $payload );
		$payment  = $response['data'] ?? null;

		if ( empty( $response['success'] ) || ! is_array( $payment ) ) {
			return self::build_error_response( 'Doverka payment creation failed', self::PROVIDER_DOVERKA, [ 'raw' => $response, 'merchantOrderId' => $merchant_order_id ] );
		}

		$provider_status      = $payment['status'] ?? null;
		$payment_link         = $payment['link'] ?? null;
		$order_transaction_id = (string) ( $payment['order_transaction_id'] ?? $merchant_order_id );
		$payment_id           = isset( $payment['id'] ) ? (string) $payment['id'] : $order_transaction_id;
		$payment_amount_rub   = self::amount_to_minor_units( $payment['amount_from'] ?? null );
		$order_amount_cents   = self::amount_to_minor_units( $payment['amount_to'] ?? $amount_usdt );

		if ( $payment_link === null || $payment_link === '' ) {
			return self::build_error_response( 'Doverka payment link is empty', self::PROVIDER_DOVERKA, [ 'raw' => $response, 'orderId' => $order_transaction_id, 'providerStatus' => $provider_status ] );
		}
		if ( $payment_amount_rub === null ) {
			return self::build_error_response( 'Doverka amount_from is missing', self::PROVIDER_DOVERKA, [ 'raw' => $response, 'orderId' => $order_transaction_id, 'providerStatus' => $provider_status ] );
		}

		return [
			'success'              => true,
			'provider'             => self::PROVIDER_DOVERKA,
			'error'                => null,
			'orderId'              => $order_transaction_id,
			'merchantOrderId'      => $order_transaction_id,
			'amountUsdt'           => $amount_usdt,
			'orderAmountCents'     => $order_amount_cents,
			'paymentAmountRub'     => $payment_amount_rub,
			'qrcId'                => self::extract_qrc_id_from_link( $payment_link, $payment_id ),
			'payload'              => $payment_link,
			'externalOrderId'      => $payment_id,
			'providerStatus'       => $provider_status,
			'requiresVerification' => ! empty( $payment['requires_verification'] ),
			'publicLink'           => $payment['public_link'] ?? null,
			'kycUrl'               => $payment['kyc_url'] ?? null,
			'kycExpiresAt'         => $payment['kyc_expires_at'] ?? null,
			'raw'                  => $response,
		];
	}

	private static function doverka_get_order_status( string $order_id ): array {
		$payment_response = self::doverka_get_payment_payload( $order_id );
		if ( empty( $payment_response['success'] ) ) {
			return self::build_error_response( 'Doverka status request failed', self::PROVIDER_DOVERKA, [ 'raw' => $payment_response['raw'] ?? null, 'status' => null, 'notFound' => ! empty( $payment_response['notFound'] ) ] );
		}

		$provider_status = $payment_response['payment']['status'] ?? null;

		return [ 'success' => true, 'provider' => self::PROVIDER_DOVERKA, 'status' => self::doverka_map_status( $provider_status ), 'providerStatus' => $provider_status, 'notFound' => false, 'error' => null, 'raw' => $payment_response['raw'] ];
	}

	private static function doverka_get_order_data( string $order_id ): array {
		$payment_response = self::doverka_get_payment_payload( $order_id );
		if ( empty( $payment_response['success'] ) ) {
			return self::build_error_response( 'Doverka order data request failed', self::PROVIDER_DOVERKA, [ 'raw' => $payment_response['raw'] ?? null, 'order' => null, 'status' => null, 'notFound' => ! empty( $payment_response['notFound'] ) ] );
		}

		$payment         = $payment_response['payment'];
		$provider_status = $payment['status'] ?? null;
		$status          = self::doverka_map_status( $provider_status );
		$payment_link    = $payment['link'] ?? null;
		$qrc_id          = self::extract_qrc_id_from_link( $payment_link, $order_id );

		$order = [
			'id'                   => $order_id,
			'externalOrderId'      => isset( $payment['id'] ) ? (string) $payment['id'] : $order_id,
			'merchantOrderId'      => (string) ( $payment['order_transaction_id'] ?? $order_id ),
			'status'               => $status,
			'providerStatus'       => $provider_status,
			'orderAmount'          => self::amount_to_minor_units( $payment['amount_to'] ?? null ),
			'paymentAmount'        => self::amount_to_minor_units( $payment['amount_from'] ?? null ),
			'orderCurrency'        => $payment['currency_symbol'] ?? 'USDT',
			'paymentCurrency'      => 'RUB',
			'qrcId'                => $qrc_id,
			'payload'              => $payment_link,
			'paymentInfo'          => [ 'qrc' => [ 'qrcId' => $qrc_id, 'payload' => $payment_link ] ],
			'publicLink'           => $payment['public_link'] ?? null,
			'requiresVerification' => ! empty( $payment['requires_verification'] ),
			'kycUrl'               => $payment['kyc_url'] ?? null,
			'kycExpiresAt'         => $payment['kyc_expires_at'] ?? null,
			'date'                 => $payment['date'] ?? null,
			'expiresAt'            => $payment['expires_at'] ?? null,
			'payerName'            => $payment['payer_name'] ?? null,
		];

		return [ 'success' => true, 'provider' => self::PROVIDER_DOVERKA, 'status' => $status, 'providerStatus' => $provider_status, 'order' => $order, 'notFound' => false, 'error' => null, 'raw' => $payment_response['raw'] ];
	}

	// ── Утилиты ──────────────────────────────────────────────────────────────

	private static function build_error_response( string $message, string $provider, array $extra = [] ): array {
		return array_merge( [ 'success' => false, 'provider' => $provider, 'error' => $message ], $extra );
	}

	private static function normalize_order_prefix( string $prefix ): string {
		$prefix = strtoupper( trim( $prefix ) );
		$prefix = (string) preg_replace( '/[^A-Z0-9]/', '', $prefix );

		return $prefix !== '' ? $prefix : 'MALIBU';
	}
}

// ─── Процедурные обёртки ─────────────────────────────────────────────────────

if ( ! function_exists( 'fintech_create_invoice' ) ) {
	function fintech_create_invoice( float $amount_usdt, ?string $merchant_order_id = null, string $description = '' ): array {
		return Fintech_Payment_Gateway::create_invoice( $amount_usdt, $merchant_order_id, $description );
	}
}

if ( ! function_exists( 'fintech_get_order_status' ) ) {
	function fintech_get_order_status( string $order_id ): array {
		return Fintech_Payment_Gateway::get_order_status( $order_id );
	}
}

if ( ! function_exists( 'fintech_get_order_data' ) ) {
	function fintech_get_order_data( string $order_id ): array {
		return Fintech_Payment_Gateway::get_order_data( $order_id );
	}
}
