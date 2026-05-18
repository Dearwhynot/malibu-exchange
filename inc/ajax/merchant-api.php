<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function _me_merchant_api_error( string $message, int $status = 400 ): void {
	wp_send_json_error( [ 'message' => $message ], $status );
}

function _me_merchant_api_require_access(): void {
	if ( ! is_user_logged_in() ) {
		_me_merchant_api_error( 'Требуется вход в систему.', 403 );
	}

	if ( ! function_exists( 'crm_can_manage_merchant_api' ) || ! crm_can_manage_merchant_api() ) {
		_me_merchant_api_error( 'Недостаточно прав для Merchant API.', 403 );
	}
}

function _me_merchant_api_require_scoped_merchant( int $merchant_id ): object {
	$merchant = function_exists( 'crm_get_merchant_by_id' ) ? crm_get_merchant_by_id( $merchant_id ) : null;
	if ( ! $merchant ) {
		_me_merchant_api_error( 'Мерчант не найден.', 404 );
	}

	$company_id = (int) ( $merchant->company_id ?? 0 );
	if ( $company_id <= 0 ) {
		_me_merchant_api_error( 'Мерчант имеет некорректный company scope.', 500 );
	}

	$current_uid = get_current_user_id();
	if ( ! crm_is_root( $current_uid ) ) {
		$current_company_id = function_exists( 'crm_get_current_user_company_id' ) ? crm_get_current_user_company_id( $current_uid ) : 0;
		if ( $current_company_id <= 0 || $current_company_id !== $company_id ) {
			_me_merchant_api_error( 'Доступ к Merchant API другой компании запрещён.', 403 );
		}
	}

	return $merchant;
}

add_action( 'wp_ajax_me_merchant_api_client_create', 'me_ajax_merchant_api_client_create' );
function me_ajax_merchant_api_client_create(): void {
	_me_merchant_api_require_access();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_merchant_api_client_create' ) ) {
		_me_merchant_api_error( 'Нарушена безопасность запроса.', 403 );
	}

	$merchant_id  = (int) ( $_POST['merchant_id'] ?? 0 );
	$client_name  = sanitize_text_field( wp_unslash( $_POST['client_name'] ?? '' ) );
	$merchant     = _me_merchant_api_require_scoped_merchant( $merchant_id );
	$create       = crm_merchant_api_create_client(
		$merchant_id,
		[
			'client_name'   => $client_name,
			'actor_user_id' => get_current_user_id(),
		]
	);

	if ( is_wp_error( $create ) ) {
		_me_merchant_api_error( $create->get_error_message(), 422 );
	}

	$payload = crm_merchant_api_admin_payload_for_merchant( $merchant_id );

	wp_send_json_success(
		[
			'message'            => 'Merchant API ключ выпущен.',
			'merchant_api'       => $payload,
			'client'             => $create['client'] ?? null,
			'raw_token'          => (string) ( $create['raw_token'] ?? '' ),
			'raw_webhook_secret' => (string) ( $create['raw_webhook_secret'] ?? '' ),
			'test_endpoint_url'  => rest_url( 'malibu/v1/merchant/me' ),
			'test_curl'          => sprintf(
				'curl -sS -H %s %s',
				escapeshellarg( 'Authorization: Bearer ' . (string) ( $create['raw_token'] ?? '' ) ),
				escapeshellarg( rest_url( 'malibu/v1/merchant/me' ) )
			),
			'merchant_id'        => (int) $merchant->id,
		]
	);
}

add_action( 'wp_ajax_me_merchant_api_client_revoke', 'me_ajax_merchant_api_client_revoke' );
function me_ajax_merchant_api_client_revoke(): void {
	_me_merchant_api_require_access();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_merchant_api_client_revoke' ) ) {
		_me_merchant_api_error( 'Нарушена безопасность запроса.', 403 );
	}

	$merchant_id = (int) ( $_POST['merchant_id'] ?? 0 );
	$client_id   = (int) ( $_POST['client_id'] ?? 0 );
	$merchant    = _me_merchant_api_require_scoped_merchant( $merchant_id );
	$client      = crm_merchant_api_get_client( $client_id );

	if ( ! $client || (int) $client->merchant_id !== $merchant_id || (int) $client->company_id !== (int) $merchant->company_id ) {
		_me_merchant_api_error( 'Merchant API client не найден в текущем контексте мерчанта.', 404 );
	}

	$revoke = crm_merchant_api_revoke_client( $client_id, get_current_user_id() );
	if ( is_wp_error( $revoke ) ) {
		_me_merchant_api_error( $revoke->get_error_message(), 422 );
	}

	wp_send_json_success(
		[
			'message'      => 'Merchant API ключ отозван.',
			'merchant_api' => crm_merchant_api_admin_payload_for_merchant( $merchant_id ),
			'client'       => $revoke['client'] ?? null,
		]
	);
}
