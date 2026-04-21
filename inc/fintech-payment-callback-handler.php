<?php
/**
 * Malibu Exchange — Fintech Payment Callback Handler
 *
 * REST endpoint: POST /wp-json/malibu/v1/fintech-callback
 *
 * Что делает этот файл:
 * - принимает входящий callback/webhook от платёжного провайдера;
 * - определяет провайдера по payload;
 * - валидирует подпись (если включено);
 * - сохраняет transport-level запись в crm_fintech_payment_callbacks;
 * - нормализует payload в единый event-формат;
 * - файрит action-хук fintech_payment_callback_received;
 * - пишет лог в crm_audit_log.
 *
 * Что этот файл НЕ делает:
 * - не обновляет платёжный ордер напрямую;
 * - не знает бизнес-логику обработки;
 * - не шлёт уведомления.
 *
 * Бизнес-логика подключается через хук:
 *   add_action('fintech_payment_callback_received', function(array $event, array $payload, array $headers) {
 *       // найти ордер, обновить статус, записать timestamp
 *   }, 10, 3);
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'crm_fintech_register_callback_route' );

function crm_fintech_register_callback_route(): void {
	register_rest_route( 'malibu/v1', '/fintech-callback', [
		'methods'             => 'POST',
		'callback'            => 'crm_fintech_callback_handler',
		'permission_callback' => '__return_true', // публичный endpoint — провайдер не авторизован в WP
	] );
}

function crm_fintech_callback_handler( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;

	$raw_body = $request->get_body();
	$headers  = _crm_fintech_normalize_headers( $request->get_headers() );

	_crm_fintech_callback_log( 'Incoming callback', [ 'headers_count' => count( $headers ), 'body_length' => strlen( $raw_body ) ] );

	// ── Парсинг ──────────────────────────────────────────────────────────────
	$payload = json_decode( $raw_body, true );
	if ( ! is_array( $payload ) ) {
		_crm_fintech_save_callback_record( 'unknown', $raw_body, $headers, null, 'invalid_json', 400, 'Invalid JSON payload' );
		crm_log( 'payment.callback.invalid_json', [
			'category'    => 'callbacks',
			'level'       => 'warning',
			'action'      => 'callback',
			'message'     => 'Получен callback с невалидным JSON',
			'target_type' => 'payment_order',
			'is_success'  => false,
			'context'     => [ 'body_length' => strlen( $raw_body ) ],
		] );

		return new WP_REST_Response( 'Invalid JSON', 400 );
	}

	// ── Определение провайдера ───────────────────────────────────────────────
	$provider = _crm_fintech_detect_provider( $payload );
	if ( $provider === 'unknown' ) {
		_crm_fintech_save_callback_record( 'unknown', $raw_body, $headers, null, 'unknown_provider', 400, 'Unable to detect provider' );
		crm_log( 'payment.callback.unknown_provider', [
			'category'    => 'callbacks',
			'level'       => 'warning',
			'action'      => 'callback',
			'message'     => 'Callback от неизвестного провайдера',
			'target_type' => 'payment_order',
			'is_success'  => false,
		] );

		return new WP_REST_Response( 'Unknown provider', 400 );
	}

	$callback_company_id = _crm_fintech_detect_callback_company_id( $provider, $payload );
	if ( $callback_company_id === null ) {
		_crm_fintech_save_callback_record( $provider, $raw_body, $headers, null, 'company_not_resolved', 400, 'Company scope was not resolved for callback' );
		crm_log( 'payment.callback.company_not_resolved', [
			'category'    => 'callbacks',
			'level'       => 'warning',
			'action'      => 'callback',
			'message'     => 'Callback отклонён: не удалось определить компанию ордера',
			'target_type' => 'payment_order',
			'is_success'  => false,
			'context'     => [ 'provider' => $provider ],
		] );

		return new WP_REST_Response( 'Company scope not resolved', 400 );
	}

	// ── Проверка подписи (Kanyon) ────────────────────────────────────────────
	$signature_valid = null;
	if ( $provider === 'kanyon' ) {
		$signature_valid = _crm_fintech_verify_kanyon_signature( $headers, $raw_body, $callback_company_id );
		if ( $signature_valid === false ) {
			_crm_fintech_save_callback_record( $provider, $raw_body, $headers, null, 'invalid_signature', 400, 'Invalid signature', false );
			crm_log( 'payment.callback.invalid_signature', [
				'category'    => 'callbacks',
				'level'       => 'warning',
				'action'      => 'callback',
				'message'     => 'Невалидная подпись Kanyon callback',
				'target_type' => 'payment_order',
				'org_id'      => $callback_company_id,
				'is_success'  => false,
				'context'     => [ 'provider' => $provider ],
			] );

			return new WP_REST_Response( 'Invalid signature', 400 );
		}
	}

	// ── Нормализация ────────────────────────────────────────────────────────
	$event = _crm_fintech_normalize_event( $provider, $payload );

	// ── Сохранение transport-записи ──────────────────────────────────────────
	$callback_id = _crm_fintech_save_callback_record(
		$provider,
		$raw_body,
		$headers,
		$event,
		'received',
		200,
		null,
		$signature_valid
	);

	// ── Лог receipt ─────────────────────────────────────────────────────────
	crm_log( 'payment.provider.callback_received', [
		'category'    => 'callbacks',
		'level'       => 'info',
		'action'      => 'callback',
		'message'     => 'Получен callback от платёжного провайдера',
		'target_type' => 'payment_order',
		'org_id'      => $callback_company_id,
		'context'     => [
			'provider'          => $provider,
			'merchant_order_id' => $event['merchantOrderId'] ?? '',
			'order_id'          => $event['orderId'] ?? '',
			'status'            => $event['status'] ?? null,
			'signature_valid'   => $signature_valid,
			'callback_id'       => $callback_id,
		],
	] );

	// ── Бизнес-хук ──────────────────────────────────────────────────────────
	// Подписчик в бизнес-логике должен:
	// 1. Найти ордер по merchantOrderId или orderId
	// 2. Обновить статус в crm_fintech_payment_orders
	// 3. Записать запись в crm_fintech_payment_order_status_history
	// 4. Обновить processed_at в crm_fintech_payment_callbacks через callback_id
	do_action( 'fintech_payment_callback_received', $event, $payload, $headers, $callback_id );

	return new WP_REST_Response( 'OK', 200 );
}

// ─── Вспомогательные функции ─────────────────────────────────────────────────

function _crm_fintech_callback_log( string $label, $payload = null, int $company_id = 0 ): void {
	// debug-логирование — включается только если fintech_debug = '1' в crm_settings
	if ( $company_id < 0 ) {
		return;
	}

	$debug = crm_get_setting( 'fintech_debug', $company_id, '0' );
	if ( $debug !== '1' ) {
		return;
	}

	if ( $payload !== null ) {
		if ( is_array( $payload ) ) {
			$payload = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
		}
		error_log( '[FINTECH CALLBACK] ' . $label . ' ' . (string) $payload );
		return;
	}

	error_log( '[FINTECH CALLBACK] ' . $label );
}

/**
 * REST headers приходят как 'Content-Type' => ['application/json'].
 * Нормализуем в 'Content-Type' => 'application/json'.
 */
function _crm_fintech_normalize_headers( array $rest_headers ): array {
	$headers = [];
	foreach ( $rest_headers as $key => $value ) {
		$headers[ $key ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}

	return $headers;
}

function _crm_fintech_header( array $headers, string $name ): ?string {
	$name_lower = strtolower( $name );
	foreach ( $headers as $key => $value ) {
		if ( strtolower( (string) $key ) === $name_lower ) {
			return (string) $value;
		}
	}

	return null;
}

function _crm_fintech_detect_provider( array $payload ): string {
	if ( isset( $payload['order'] ) && is_array( $payload['order'] ) ) {
		return 'kanyon';
	}
	if ( isset( $payload['payment'] ) && is_array( $payload['payment'] ) ) {
		return 'doverka';
	}
	if ( isset( $payload['order_transaction_id'] ) || isset( $payload['id'] ) ) {
		return 'doverka';
	}

	return 'unknown';
}

function _crm_fintech_detect_callback_company_id( string $provider, array $payload ): ?int {
	global $wpdb;

	$event             = _crm_fintech_normalize_event( $provider, $payload );
	$merchant_order_id = trim( (string) ( $event['merchantOrderId'] ?? '' ) );
	$provider_order_id = trim( (string) ( $event['orderId'] ?? '' ) );

	if ( $merchant_order_id !== '' ) {
		$company_id = $wpdb->get_var( $wpdb->prepare(
			'SELECT company_id FROM crm_fintech_payment_orders WHERE merchant_order_id = %s LIMIT 1',
			$merchant_order_id
		) );
		if ( $company_id !== null ) {
			return (int) $company_id;
		}
	}

	if ( $provider_order_id !== '' ) {
		$company_id = $wpdb->get_var( $wpdb->prepare(
			'SELECT company_id FROM crm_fintech_payment_orders WHERE provider_order_id = %s LIMIT 1',
			$provider_order_id
		) );
		if ( $company_id !== null ) {
			return (int) $company_id;
		}
	}

	return null;
}

/**
 * Проверка подписи Kanyon.
 * Возвращает true/false или null если проверка отключена.
 */
function _crm_fintech_verify_kanyon_signature( array $headers, string $raw_body, int $company_id ): ?bool {
	if ( $company_id < 0 ) {
		return false;
	}

	// Проверка включается через crm_settings: fintech_kanyon_verify_signature = '1'
	$verify = crm_get_setting( 'fintech_kanyon_verify_signature', $company_id, '0' );
	if ( $verify !== '1' ) {
		return null; // проверка отключена
	}

	$payment_sign = _crm_fintech_header( $headers, 'payment-sign' );
	if ( $payment_sign === null || trim( $payment_sign ) === '' ) {
		_crm_fintech_callback_log( 'Missing payment-sign header', null, $company_id );

		return false;
	}

	$public_key_pem = crm_get_setting( 'fintech_kanyon_public_key_pem', $company_id, '' );
	if ( trim( $public_key_pem ) === '' ) {
		_crm_fintech_callback_log( 'Kanyon public key is empty', null, $company_id );

		return false;
	}

	$signature = base64_decode( $payment_sign, true );
	if ( $signature === false ) {
		return false;
	}

	$public_key = openssl_pkey_get_public( $public_key_pem );
	if ( ! $public_key ) {
		return false;
	}

	$verified = openssl_verify( $raw_body, $signature, $public_key, OPENSSL_ALGO_SHA1 );

	return $verified === 1;
}

function _crm_fintech_normalize_event( string $provider, array $payload ): array {
	if ( $provider === 'kanyon' ) {
		return _crm_fintech_normalize_kanyon( $payload );
	}
	if ( $provider === 'doverka' ) {
		return _crm_fintech_normalize_doverka( $payload );
	}

	return [ 'provider' => 'unknown', 'orderId' => '', 'merchantOrderId' => '', 'status' => null, 'providerStatus' => null, 'paymentAmount' => null, 'orderAmount' => null, 'orderCurrency' => null, 'paymentCurrency' => null, 'qrcId' => null, 'payload' => null, 'externalOrderId' => null, 'raw' => $payload ];
}

function _crm_fintech_normalize_kanyon( array $payload ): array {
	$order = $payload['order'] ?? [];
	$payment_details = isset( $order['paymentInfo']['paymentDetails'] ) && is_array( $order['paymentInfo']['paymentDetails'] )
		? $order['paymentInfo']['paymentDetails']
		: [];

	return [
		'provider'       => 'kanyon',
		'orderId'        => (string) ( $order['id'] ?? '' ),
		'merchantOrderId'=> (string) ( $order['merchantOrderId'] ?? '' ),
		'status'         => $order['status'] ?? null,
		'providerStatus' => $order['status'] ?? null,
		'paymentAmount'  => isset( $order['paymentAmount'] ) ? (int) $order['paymentAmount'] : null,
		'orderAmount'    => isset( $order['orderAmount'] ) ? (int) $order['orderAmount'] : null,
		'orderCurrency'  => $order['orderCurrency'] ?? null,
		'paymentCurrency'=> $order['paymentCurrency'] ?? null,
		'qrcId'          => $order['paymentInfo']['qrc']['qrcId'] ?? null,
		'payload'        => $order['paymentInfo']['qrc']['payload'] ?? null,
		'externalOrderId'=> $order['externalOrderId'] ?? ( $payment_details['externalId'] ?? null ),
		'raw'            => $payload,
	];
}

function _crm_fintech_normalize_doverka( array $payload ): array {
	$payment         = isset( $payload['payment'] ) && is_array( $payload['payment'] ) ? $payload['payment'] : $payload;
	$provider_status = $payment['status'] ?? null;

	// Маппинг Doverka → единые статусы
	$status_map = [ 'PAID' => 'IPS_ACCEPTED', 'CANCELLED' => 'DECLINED', 'EXPIRED' => 'EXPIRED' ];
	$upper      = strtoupper( (string) $provider_status );
	$status     = $status_map[ $upper ] ?? $provider_status;

	return [
		'provider'       => 'doverka',
		'orderId'        => (string) ( $payment['order_transaction_id'] ?? $payment['id'] ?? '' ),
		'merchantOrderId'=> (string) ( $payment['order_transaction_id'] ?? '' ),
		'status'         => $status,
		'providerStatus' => $provider_status,
		'paymentAmount'  => isset( $payment['amount_from'] ) ? (int) round( ( (float) $payment['amount_from'] ) * 100 ) : null,
		'orderAmount'    => isset( $payment['amount_to'] )   ? (int) round( ( (float) $payment['amount_to'] )   * 100 ) : null,
		'orderCurrency'  => $payment['currency_symbol'] ?? null,
		'paymentCurrency'=> 'RUB',
		'qrcId'          => null,
		'payload'        => $payment['link'] ?? null,
		'externalOrderId'=> $payment['id'] ?? null,
		'raw'            => $payload,
	];
}

/**
 * Сохраняет transport-запись в crm_fintech_payment_callbacks.
 * Возвращает ID вставленной записи или null.
 */
function _crm_fintech_save_callback_record(
	string $provider,
	string $raw_body,
	array $headers,
	?array $event,
	string $processing_status,
	?int $http_response_code,
	?string $error_message,
	?bool $signature_valid = null
): ?int {
	global $wpdb;

	$normalized_event_json = null;
	if ( $event !== null ) {
		$safe_event = $event;
		unset( $safe_event['raw'] ); // не пишем сырые данные повторно
		$normalized_event_json = wp_json_encode( $safe_event, JSON_UNESCAPED_UNICODE );
	}

	$headers_json = wp_json_encode( $headers, JSON_UNESCAPED_UNICODE );

	$wpdb->insert( 'crm_fintech_payment_callbacks', [
		'provider_code'          => $provider,
		'merchant_order_id_hint' => $event['merchantOrderId'] ?? null,
		'order_id_hint'          => $event['orderId'] ?? null,
		'signature_valid'        => $signature_valid !== null ? ( $signature_valid ? 1 : 0 ) : null,
		'processing_status'      => $processing_status,
		'http_response_code'     => $http_response_code,
		'error_message'          => $error_message ? substr( $error_message, 0, 255 ) : null,
		'headers_raw_json'       => $headers_json,
		'body_raw'               => $raw_body,
		'normalized_event_json'  => $normalized_event_json,
		'received_at'            => current_time( 'mysql' ),
	] );

	$insert_id = (int) $wpdb->insert_id;

	return $insert_id > 0 ? $insert_id : null;
}
