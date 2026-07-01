<?php
/**
 * Malibu Exchange — Telegram channels AJAX handlers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function _me_telegram_channels_require_access( string $permission = 'telegram_channels.view' ): int {
	if ( ! is_user_logged_in() || ! crm_user_has_permission( get_current_user_id(), $permission ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}

	$uid = get_current_user_id();
	if ( crm_is_root( $uid ) ) {
		wp_send_json_error( [ 'message' => 'Root не работает в company-scoped странице Telegram-каналов.' ], 403 );
	}

	$company_id = crm_get_current_user_company_id( $uid );
	if ( $company_id <= 0 ) {
		crm_log_company_scope_violation(
			'telegram_channels.scope.user_without_company',
			'Попытка доступа к Telegram-каналам без company context',
			[
				'user_id' => $uid,
			]
		);

		wp_send_json_error( [ 'message' => 'Аккаунт не привязан к активной компании.' ], 403 );
	}

	if ( ! function_exists( 'crm_company_contour_is_enabled' ) || ! crm_company_contour_is_enabled( $company_id, 'telegram_channels' ) ) {
		wp_send_json_error( [ 'message' => 'Модуль Telegram-каналы выключен для этой компании.' ], 403 );
	}

	return $company_id;
}

function _me_telegram_channels_verify_nonce(): void {
	$nonce = sanitize_text_field( wp_unslash( $_POST['_nonce'] ?? '' ) );
	if ( ! wp_verify_nonce( $nonce, 'me_telegram_channels' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}
}

function _me_telegram_channels_requested_merchant_id(): int {
	return max( 0, (int) ( $_POST['merchant_id'] ?? $_GET['merchant_id'] ?? 0 ) );
}

function _me_telegram_channels_require_merchant( int $company_id ): int {
	$merchant_id = _me_telegram_channels_requested_merchant_id();
	if ( $merchant_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Выберите мерчанта.' ], 422 );
	}

	$merchant = function_exists( 'crm_telegram_channels_validate_merchant_profile_access' )
		? crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id )
		: new WP_Error( 'telegram_channels_unavailable', 'Модуль Telegram-каналов недоступен.' );
	if ( is_wp_error( $merchant ) ) {
		wp_send_json_error( [ 'message' => $merchant->get_error_message() ], 422 );
	}

	return $merchant_id;
}

function _me_telegram_channels_profile_payload_or_error( int $company_id, int $merchant_id ): array {
	$profile = function_exists( 'crm_telegram_channels_profile_payload' )
		? crm_telegram_channels_profile_payload( $company_id, $merchant_id, 50 )
		: new WP_Error( 'telegram_channels_unavailable', 'Модуль Telegram-каналов недоступен.' );
	if ( is_wp_error( $profile ) ) {
		wp_send_json_error( [ 'message' => $profile->get_error_message() ], 422 );
	}

	return is_array( $profile ) ? $profile : [];
}

add_action( 'wp_ajax_me_telegram_channels_load_profile', 'me_ajax_telegram_channels_load_profile' );
function me_ajax_telegram_channels_load_profile(): void {
	$company_id = _me_telegram_channels_require_access( 'telegram_channels.view' );
	_me_telegram_channels_verify_nonce();
	$merchant_id = _me_telegram_channels_require_merchant( $company_id );

	wp_send_json_success( [
		'profile' => _me_telegram_channels_profile_payload_or_error( $company_id, $merchant_id ),
	] );
}

add_action( 'wp_ajax_me_telegram_channels_save_channel', 'me_ajax_telegram_channels_save_channel' );
function me_ajax_telegram_channels_save_channel(): void {
	$company_id = _me_telegram_channels_require_access( 'telegram_channels.settings' );
	_me_telegram_channels_verify_nonce();
	$merchant_id = _me_telegram_channels_require_merchant( $company_id );

	$result = crm_telegram_channels_update_channel(
		$company_id,
		[
			'title'                     => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'telegram_channel_id'       => sanitize_text_field( wp_unslash( $_POST['telegram_channel_id'] ?? '' ) ),
			'telegram_channel_username' => sanitize_text_field( wp_unslash( $_POST['telegram_channel_username'] ?? '' ) ),
			'status'                    => sanitize_key( $_POST['status'] ?? 'draft' ),
		],
		$merchant_id
	);

	if ( empty( $result['success'] ) ) {
		wp_send_json_error( [ 'message' => (string) ( $result['message'] ?? 'Не удалось сохранить канал.' ) ], 422 );
	}

	wp_send_json_success( [
		'message'   => (string) $result['message'],
		'profile'   => _me_telegram_channels_profile_payload_or_error( $company_id, $merchant_id ),
	] );
}

add_action( 'wp_ajax_me_telegram_channels_save_settings', 'me_ajax_telegram_channels_save_settings' );
function me_ajax_telegram_channels_save_settings(): void {
	$company_id = _me_telegram_channels_require_access( 'telegram_channels.settings' );
	_me_telegram_channels_verify_nonce();
	$merchant_id = _me_telegram_channels_require_merchant( $company_id );

	$result = crm_telegram_channels_save_settings(
		$company_id,
		[
			'telegram_subscription_bot_token'    => sanitize_text_field( wp_unslash( $_POST['telegram_subscription_bot_token'] ?? '' ) ),
			'telegram_channels_reminders_enabled'=> ! empty( $_POST['telegram_channels_reminders_enabled'] ),
			'telegram_channels_reminder_days'    => (int) ( $_POST['telegram_channels_reminder_days'] ?? 3 ),
			'telegram_channels_invite_ttl_hours' => (int) ( $_POST['telegram_channels_invite_ttl_hours'] ?? 24 ),
		],
		$merchant_id
	);

	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			[
				'message' => (string) ( $result['message'] ?? 'Не удалось сохранить настройки.' ),
				'fields'  => array_values( $result['fields'] ?? [] ),
			],
			422
		);
	}

	wp_send_json_success( [
		'message'   => (string) $result['message'],
		'profile'   => _me_telegram_channels_profile_payload_or_error( $company_id, $merchant_id ),
	] );
}

add_action( 'wp_ajax_me_telegram_channels_save_texts', 'me_ajax_telegram_channels_save_texts' );
function me_ajax_telegram_channels_save_texts(): void {
	$company_id = _me_telegram_channels_require_access( 'telegram_channels.settings' );
	_me_telegram_channels_verify_nonce();
	$merchant_id = _me_telegram_channels_require_merchant( $company_id );

	$texts = [];
	foreach ( array_keys( crm_telegram_channels_default_texts() ) as $key ) {
		$texts[ $key ] = sanitize_textarea_field( wp_unslash( $_POST['texts'][ $key ] ?? '' ) );
	}

	$result = function_exists( 'crm_telegram_channels_save_texts' )
		? crm_telegram_channels_save_texts( $company_id, $merchant_id, $texts )
		: [ 'success' => false, 'message' => 'Text helper недоступен.' ];
	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			[
				'message' => (string) ( $result['message'] ?? 'Не удалось сохранить тексты.' ),
			],
			422
		);
	}

	wp_send_json_success( [
		'message' => (string) $result['message'],
		'profile' => _me_telegram_channels_profile_payload_or_error( $company_id, $merchant_id ),
	] );
}

add_action( 'wp_ajax_me_telegram_channels_save_tariffs', 'me_ajax_telegram_channels_save_tariffs' );
function me_ajax_telegram_channels_save_tariffs(): void {
	$company_id = _me_telegram_channels_require_access( 'telegram_channels.tariffs' );
	_me_telegram_channels_verify_nonce();
	$merchant_id = _me_telegram_channels_require_merchant( $company_id );

	$tariffs = [];
	foreach ( array_keys( crm_telegram_channels_default_tariffs() ) as $code ) {
		$tariffs[ $code ] = [
			'price_amount'   => sanitize_text_field( wp_unslash( $_POST['tariffs'][ $code ]['price_amount'] ?? '0' ) ),
			'price_currency' => sanitize_text_field( wp_unslash( $_POST['tariffs'][ $code ]['price_currency'] ?? 'RUB' ) ),
			'active'         => ! empty( $_POST['tariffs'][ $code ]['active'] ),
		];
	}

	$result = crm_telegram_channels_update_tariffs( $company_id, $tariffs, $merchant_id );
	if ( empty( $result['success'] ) ) {
		wp_send_json_error( [ 'message' => (string) ( $result['message'] ?? 'Не удалось сохранить тарифы.' ) ], 422 );
	}

	wp_send_json_success( [
		'message'   => (string) $result['message'],
		'profile'   => _me_telegram_channels_profile_payload_or_error( $company_id, $merchant_id ),
	] );
}

add_action( 'wp_ajax_me_telegram_channels_apply_bot_identity', 'me_ajax_telegram_channels_apply_bot_identity' );
function me_ajax_telegram_channels_apply_bot_identity(): void {
	$company_id = _me_telegram_channels_require_access( 'telegram_channels.settings' );
	_me_telegram_channels_verify_nonce();
	$merchant_id = _me_telegram_channels_require_merchant( $company_id );

	$result = function_exists( 'crm_telegram_channels_apply_bot_identity' )
		? crm_telegram_channels_apply_bot_identity(
			$company_id,
			$merchant_id,
			[
				'identity_name'                 => sanitize_text_field( wp_unslash( $_POST['identity_name'] ?? '' ) ),
				'identity_short_description'    => sanitize_textarea_field( wp_unslash( $_POST['identity_short_description'] ?? '' ) ),
				'identity_description'          => sanitize_textarea_field( wp_unslash( $_POST['identity_description'] ?? '' ) ),
				'identity_language_code'        => sanitize_key( $_POST['identity_language_code'] ?? '' ),
				'identity_menu_button'          => sanitize_key( $_POST['identity_menu_button'] ?? 'commands' ),
				'identity_default_admin_rights' => ! empty( $_POST['identity_default_admin_rights'] ),
			],
			is_array( $_FILES['identity_photo'] ?? null ) ? $_FILES['identity_photo'] : [],
			is_array( $_FILES['identity_promo_image'] ?? null ) ? $_FILES['identity_promo_image'] : []
		)
		: [ 'success' => false, 'message' => 'Telegram identity helper недоступен.' ];

	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			[
				'message' => (string) ( $result['message'] ?? 'Не удалось применить оформление bot.' ),
				'steps'   => $result['steps'] ?? [],
				'profile' => _me_telegram_channels_profile_payload_or_error( $company_id, $merchant_id ),
			],
			422
		);
	}

	wp_send_json_success( [
		'message' => (string) ( $result['message'] ?? 'Оформление bot применено.' ),
		'steps'   => $result['steps'] ?? [],
		'profile' => _me_telegram_channels_profile_payload_or_error( $company_id, $merchant_id ),
	] );
}

add_action( 'wp_ajax_me_telegram_channels_check_admin', 'me_ajax_telegram_channels_check_admin' );
function me_ajax_telegram_channels_check_admin(): void {
	$company_id = _me_telegram_channels_require_access( 'telegram_channels.settings' );
	_me_telegram_channels_verify_nonce();
	$merchant_id = _me_telegram_channels_require_merchant( $company_id );

	$result = crm_telegram_channels_check_bot_admin( $company_id, $merchant_id );
	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			[
				'message'   => (string) ( $result['message'] ?? 'Проверка не прошла.' ),
				'readiness' => crm_telegram_channels_get_readiness_status( $company_id, false, $merchant_id ),
			],
			422
		);
	}

	wp_send_json_success( [
		'message'   => (string) $result['message'],
		'profile'   => _me_telegram_channels_profile_payload_or_error( $company_id, $merchant_id ),
	] );
}

add_action( 'wp_ajax_me_telegram_channels_create_channel_setup', 'me_ajax_telegram_channels_create_channel_setup' );
function me_ajax_telegram_channels_create_channel_setup(): void {
	$company_id = _me_telegram_channels_require_access( 'telegram_channels.settings' );
	_me_telegram_channels_verify_nonce();
	$merchant_id = _me_telegram_channels_require_merchant( $company_id );

	$result = function_exists( 'crm_telegram_channels_create_channel_setup_session' )
		? crm_telegram_channels_create_channel_setup_session( $company_id, $merchant_id, get_current_user_id() )
		: [ 'success' => false, 'message' => 'Setup helper недоступен.' ];

	if ( empty( $result['success'] ) ) {
		wp_send_json_error(
			[
				'message'   => (string) ( $result['message'] ?? 'Не удалось создать setup-ссылку.' ),
				'readiness' => crm_telegram_channels_get_readiness_status( $company_id, false, $merchant_id ),
			],
			422
		);
	}

	wp_send_json_success( [
		'message'    => (string) ( $result['message'] ?? 'Setup-ссылка создана.' ),
		'setup_url'  => (string) ( $result['setup_url'] ?? '' ),
		'expires_at' => (string) ( $result['expires_at'] ?? '' ),
		'profile'    => _me_telegram_channels_profile_payload_or_error( $company_id, $merchant_id ),
	] );
}

add_action( 'wp_ajax_me_telegram_channels_connect_webhook', 'me_ajax_telegram_channels_connect_webhook' );
function me_ajax_telegram_channels_connect_webhook(): void {
	$company_id = _me_telegram_channels_require_access( 'telegram_channels.settings' );
	_me_telegram_channels_verify_nonce();
	$merchant_id = _me_telegram_channels_require_merchant( $company_id );

	$result   = function_exists( 'crm_telegram_channels_connect_merchant_webhook' )
		? crm_telegram_channels_connect_merchant_webhook( $company_id, $merchant_id )
		: [ 'success' => false, 'message' => 'Telegram webhook helper недоступен.' ];

	if ( empty( $result['success'] ) ) {
		wp_send_json_error( [ 'message' => (string) ( $result['message'] ?? 'Не удалось подключить callback.' ) ], 422 );
	}

	wp_send_json_success( [
		'message'      => 'Subscription callback подключён.',
		'callback_url' => (string) ( $result['callback_url'] ?? '' ),
		'profile'      => _me_telegram_channels_profile_payload_or_error( $company_id, $merchant_id ),
	] );
}

add_action( 'wp_ajax_me_telegram_channels_reissue_invite', 'me_ajax_telegram_channels_reissue_invite' );
function me_ajax_telegram_channels_reissue_invite(): void {
	$company_id = _me_telegram_channels_require_access( 'telegram_channels.manage_subscribers' );
	_me_telegram_channels_verify_nonce();
	$merchant_id = _me_telegram_channels_require_merchant( $company_id );

	global $wpdb;

	$subscriber_id = max( 0, (int) ( $_POST['subscriber_id'] ?? 0 ) );
	$subscriber = $subscriber_id > 0
		? $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM crm_telegram_channel_subscribers WHERE id = %d AND company_id = %d LIMIT 1',
				$subscriber_id,
				$company_id
			)
		)
		: null;

	if ( ! $subscriber || (int) $subscriber->merchant_id !== $merchant_id ) {
		wp_send_json_error( [ 'message' => 'Подписчик не найден.' ], 404 );
	}

	$result = crm_telegram_channels_reissue_invite_for_client(
		$company_id,
		(string) $subscriber->telegram_user_id,
		(string) $subscriber->chat_id,
		(int) $subscriber->channel_id
	);

	if ( empty( $result['success'] ) ) {
		wp_send_json_error( [ 'message' => (string) ( $result['message'] ?? 'Не удалось создать invite.' ) ], 422 );
	}

	if ( ! empty( $subscriber->chat_id ) && ! empty( $result['keyboard'] ) ) {
		crm_telegram_channels_send_message( $company_id, (string) $subscriber->chat_id, (string) $result['message'], $result['keyboard'], $merchant_id );
	}

	wp_send_json_success( [ 'message' => 'Invite-ссылка создана и отправлена.' ] );
}
