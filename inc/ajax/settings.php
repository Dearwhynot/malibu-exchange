<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX: сохранение настроек системы.
 * Action: me_settings_save
 * Доступ: только залогиненные пользователи с правом settings.edit
 */
add_action( 'wp_ajax_me_settings_save', 'me_ajax_settings_save' );

function _me_settings_fintech_status_payload( int $org_id ): array {
	$status = crm_fintech_get_configuration_status( $org_id );

	return [
			'is_configured'         => ! empty( $status['is_configured'] ),
			'provider'              => (string) ( $status['provider'] ?? '' ),
			'provider_label'        => (string) ( $status['provider_label'] ?? '' ),
			'general_section_label' => (string) ( $status['general_section_label'] ?? '' ),
			'provider_section_label'=> (string) ( $status['provider_section_label'] ?? '' ),
			'missing_general'       => array_values( $status['missing_general'] ?? [] ),
			'missing_provider'      => array_values( $status['missing_provider'] ?? [] ),
			'missing_fields'        => array_values( $status['missing_fields'] ?? [] ),
			'allowed_providers'     => array_values( $status['allowed_providers'] ?? [] ),
			'allowed_provider_labels' => array_values( $status['allowed_provider_labels'] ?? [] ),
			'provider_unavailable'  => ! empty( $status['provider_unavailable'] ),
			'blocked_reason'        => (string) ( $status['blocked_reason'] ?? '' ),
		];
}

function _me_settings_fintech_status_message( string $saved_message, int $org_id ): array {
	$status = _me_settings_fintech_status_payload( $org_id );

	if ( ! empty( $status['is_configured'] ) ) {
		return [
			'message'        => $saved_message . ' Платёжный шлюз полностью настроен.',
			'fintech_status' => $status,
		];
	}

	$missing_labels = array_map(
		static fn( $item ) => (string) ( $item['label'] ?? '' ),
		$status['missing_fields']
	);
	$missing_labels = array_values( array_filter( $missing_labels ) );

	return [
		'message'        => $saved_message . ( ! empty( $missing_labels )
			? ' До полной готовности не хватает: ' . implode( ', ', $missing_labels ) . '.'
			: ''
		),
		'fintech_status' => $status,
	];
}

function _me_settings_require_allowed_fintech_provider( int $org_id, string $provider ): void {
	$provider = crm_fintech_normalize_provider_code( $provider );
	if ( $provider === '' ) {
		return;
	}

	if ( ! crm_fintech_is_provider_allowed( $org_id, $provider ) ) {
		wp_send_json_error( [
			'message' => sprintf(
				'Контур %s отключён в настройках компании и недоступен для сохранения.',
				crm_fintech_provider_label( $provider )
			),
		], 403 );
	}
}

function me_ajax_settings_save(): void {
	check_ajax_referer( 'me_settings_save', 'nonce' );

	$current_uid = get_current_user_id();

	if ( ! crm_user_has_permission( $current_uid, 'settings.edit' ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав' ], 403 );
	}

	// Root (uid=1) → org_id = 0 (системный контекст, изолирован от всех компаний).
	// Обычный пользователь без компании блокируется на уровне gate (template_redirect),
	// но проверяем повторно на случай прямого AJAX-запроса.
	if ( crm_is_root( $current_uid ) ) {
		$org_id = 0;
	} else {
		$org_id = crm_get_current_user_company_id( $current_uid );
		if ( $org_id === 0 ) {
			wp_send_json_error( [ 'message' => 'Аккаунт не привязан к компании.' ], 403 );
		}
	}

	$section = isset( $_POST['section'] ) ? sanitize_key( $_POST['section'] ) : 'telegram';

	// Система — общие (таймзона и т.п.)
	if ( $section === 'system' ) {
		$timezone = sanitize_text_field( wp_unslash( $_POST['timezone'] ?? 'UTC' ) );

		// Проверяем, что это допустимый идентификатор PHP-таймзоны
		try {
			new DateTimeZone( $timezone );
		} catch ( \Exception $ex ) {
			wp_send_json_error( [ 'message' => 'Неверный часовой пояс: ' . esc_html( $timezone ) . ' — ' . $ex->getMessage() ], 422 );
		}

		crm_set_setting( 'timezone', $timezone, $org_id );

		crm_log_entity( 'settings.system_saved', 'settings', 'update',
			'Обновлены системные настройки: timezone=' . $timezone,
			'settings', 0, [ 'context' => [ 'section' => 'system', 'timezone' => $timezone ] ]
		);

		wp_send_json_success( [ 'message' => 'Системные настройки сохранены.' ] );
		return;
	}

	if ( $section === 'rates_coefficient' ) {
		$pair = rates_get_pair( RATES_PAIR_CODE, $org_id );
		if ( ! $pair ) {
			wp_send_json_error( [ 'message' => 'Активная пара не найдена.' ], 500 );
		}

		$coeff = isset( $_POST['rates_coefficient'] )
			? (float) $_POST['rates_coefficient']
			: 0.05;

		if ( $coeff < 0 || $coeff > 100 ) {
			wp_send_json_error( [ 'message' => 'Некорректное значение коэффициента.' ], 422 );
		}

		$ok = rates_update_coefficient( (int) $pair->id, $coeff );

		if ( ! $ok ) {
			wp_send_json_error( [ 'message' => 'Ошибка сохранения коэффициента.' ], 500 );
		}

		crm_log_entity( 'settings.rates_coefficient_saved', 'settings', 'update',
			"Обновлён коэффициент курса: {$coeff}",
			'settings',
			0,
			[ 'context' => [ 'section' => 'rates_coefficient', 'coefficient' => $coeff ] ]
		);

		wp_send_json_success( [ 'message' => 'Коэффициент сохранён.' ] );
		return;
	}

	// Fintech: общие
	if ( $section === 'fintech_general' ) {
		$active_provider = crm_fintech_normalize_provider_code(
			sanitize_key( wp_unslash( $_POST['fintech_active_provider'] ?? '' ) )
		);
		_me_settings_require_allowed_fintech_provider( $org_id, $active_provider );

		$values = [
			'fintech_company_name'          => sanitize_textarea_field( wp_unslash( $_POST['fintech_company_name'] ?? '' ) ),
			'fintech_merchant_order_prefix' => sanitize_textarea_field( wp_unslash( $_POST['fintech_merchant_order_prefix'] ?? '' ) ),
			'fintech_active_provider'       => $active_provider,
			'fintech_debug'                 => isset( $_POST['fintech_debug'] ) && wp_unslash( $_POST['fintech_debug'] ) === '1' ? '1' : '0',
		];

		foreach ( $values as $key => $value ) {
			crm_set_setting( $key, $value, $org_id );
		}
		crm_log_entity( 'settings.fintech_general_saved', 'settings', 'update',
			'Обновлены общие настройки Fintech',
			'settings', 0, [ 'context' => [ 'section' => 'fintech_general' ] ]
		);
		wp_send_json_success( _me_settings_fintech_status_message( 'Общие настройки сохранены.', $org_id ) );
		return;
	}

	// Fintech: Kanyon / Pay2Day
	if ( $section === 'fintech_kanyon' ) {
		_me_settings_require_allowed_fintech_provider( $org_id, 'kanyon' );

		$keys = [
			'fintech_pay2day_login',
			'fintech_pay2day_password',
			'fintech_pay2day_tsp_id',
			'fintech_pay2day_order_currency',
			'fintech_kanyon_verify_signature',
			'fintech_kanyon_public_key_pem',
		];
		foreach ( $keys as $key ) {
			crm_set_setting( $key, sanitize_textarea_field( wp_unslash( $_POST[ $key ] ?? '' ) ), $org_id );
		}
		crm_log_entity( 'settings.fintech_kanyon_saved', 'settings', 'update',
			'Обновлены учётные данные Kanyon / Pay2Day',
			'settings', 0, [ 'context' => [ 'section' => 'fintech_kanyon' ] ]
		);
		wp_send_json_success( _me_settings_fintech_status_message( 'Настройки Kanyon сохранены.', $org_id ) );
		return;
	}

	// Fintech: Doverka
	if ( $section === 'fintech_doverka' ) {
		_me_settings_require_allowed_fintech_provider( $org_id, 'doverka' );

		$keys = [
			'fintech_doverka_api_key',
			'fintech_doverka_currency_id',
			'fintech_doverka_approve_url',
			'fintech_doverka_kyc_redirect_url',
		];
		foreach ( $keys as $key ) {
			crm_set_setting( $key, sanitize_textarea_field( wp_unslash( $_POST[ $key ] ?? '' ) ), $org_id );
		}
		crm_log_entity( 'settings.fintech_doverka_saved', 'settings', 'update',
			'Обновлены учётные данные Doverka',
			'settings', 0, [ 'context' => [ 'section' => 'fintech_doverka' ] ]
		);
		wp_send_json_success( _me_settings_fintech_status_message( 'Настройки Doverka сохранены.', $org_id ) );
		return;
	}

	// Fintech: legacy section (backward compat — save all)
	if ( $section === 'fintech' ) {
		$active_provider = crm_fintech_normalize_provider_code(
			sanitize_key( wp_unslash( $_POST['fintech_active_provider'] ?? '' ) )
		);
		_me_settings_require_allowed_fintech_provider( $org_id, $active_provider );

		$values = [
			'fintech_company_name'          => sanitize_textarea_field( wp_unslash( $_POST['fintech_company_name'] ?? '' ) ),
			'fintech_merchant_order_prefix' => sanitize_textarea_field( wp_unslash( $_POST['fintech_merchant_order_prefix'] ?? '' ) ),
			'fintech_active_provider'       => $active_provider,
			'fintech_debug'                 => isset( $_POST['fintech_debug'] ) && wp_unslash( $_POST['fintech_debug'] ) === '1' ? '1' : '0',
			'fintech_pay2day_login'         => sanitize_textarea_field( wp_unslash( $_POST['fintech_pay2day_login'] ?? '' ) ),
			'fintech_pay2day_password'      => sanitize_textarea_field( wp_unslash( $_POST['fintech_pay2day_password'] ?? '' ) ),
			'fintech_pay2day_tsp_id'        => sanitize_textarea_field( wp_unslash( $_POST['fintech_pay2day_tsp_id'] ?? '' ) ),
			'fintech_pay2day_order_currency' => sanitize_textarea_field( wp_unslash( $_POST['fintech_pay2day_order_currency'] ?? '' ) ),
			'fintech_doverka_api_key'       => sanitize_textarea_field( wp_unslash( $_POST['fintech_doverka_api_key'] ?? '' ) ),
			'fintech_doverka_currency_id'   => sanitize_textarea_field( wp_unslash( $_POST['fintech_doverka_currency_id'] ?? '' ) ),
			'fintech_doverka_approve_url'   => sanitize_textarea_field( wp_unslash( $_POST['fintech_doverka_approve_url'] ?? '' ) ),
			'fintech_doverka_kyc_redirect_url' => sanitize_textarea_field( wp_unslash( $_POST['fintech_doverka_kyc_redirect_url'] ?? '' ) ),
			'fintech_kanyon_verify_signature'  => isset( $_POST['fintech_kanyon_verify_signature'] ) && wp_unslash( $_POST['fintech_kanyon_verify_signature'] ) === '1' ? '1' : '0',
			'fintech_kanyon_public_key_pem' => sanitize_textarea_field( wp_unslash( $_POST['fintech_kanyon_public_key_pem'] ?? '' ) ),
		];
		foreach ( $values as $key => $value ) {
			crm_set_setting( $key, $value, $org_id );
		}
		wp_send_json_success( _me_settings_fintech_status_message( 'Настройки провайдера сохранены.', $org_id ) );
		return;
	}

	// Telegram
	$token = isset( $_POST['telegram_bot_token'] )
		? sanitize_text_field( wp_unslash( $_POST['telegram_bot_token'] ) )
		: '';

	$ok = crm_set_setting( 'telegram_bot_token', $token, $org_id );

	if ( ! $ok ) {
		wp_send_json_error( [ 'message' => 'Ошибка сохранения в базе данных' ], 500 );
	}

	crm_log_entity( 'settings.telegram_saved', 'settings', 'update',
		'Обновлён токен Telegram-бота',
		'settings',
		0,
		[ 'context' => [ 'section' => 'telegram' ] ]
	);

	wp_send_json_success( [ 'message' => 'Настройки сохранены' ] );
}

add_action( 'wp_ajax_me_company_fintech_access_save', 'me_ajax_company_fintech_access_save' );

function me_ajax_company_fintech_access_save(): void {
	check_ajax_referer( 'me_company_fintech_access_save', 'nonce' );

	if ( ! crm_is_root( get_current_user_id() ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}

	global $wpdb;

	$company_id = isset( $_POST['company_id'] ) ? (int) $_POST['company_id'] : 0;
	$providers  = array_map(
		'sanitize_key',
		(array) wp_unslash( $_POST['providers'] ?? [] )
	);

	if ( $company_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Некорректный company_id.' ], 422 );
	}

	$company = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, code, name FROM crm_companies WHERE id = %d AND status = 'active' LIMIT 1",
		$company_id
	) );

	if ( ! $company ) {
		wp_send_json_error( [ 'message' => 'Компания не найдена.' ], 404 );
	}

	$allowed_providers = crm_fintech_normalize_allowed_providers( $providers );
	crm_set_setting(
		'fintech_allowed_providers',
		crm_fintech_serialize_allowed_providers( $allowed_providers ),
		$company_id
	);

	$current_active_provider = crm_fintech_normalize_provider_code(
		(string) crm_get_setting( 'fintech_active_provider', $company_id, '' )
	);
	$active_provider_cleared = false;

	if ( $current_active_provider !== '' && ! in_array( $current_active_provider, $allowed_providers, true ) ) {
		crm_set_setting( 'fintech_active_provider', '', $company_id );
		$active_provider_cleared = true;
	}

	$allowed_provider_labels = array_map( 'crm_fintech_provider_label', $allowed_providers );
	$message                 = empty( $allowed_provider_labels )
		? 'Для компании отключены все платёжные контуры.'
		: 'Настройки компании сохранены: доступны ' . implode( ', ', $allowed_provider_labels ) . '.';

	if ( $active_provider_cleared ) {
		$message .= ' Активный контур был сброшен, потому что он больше недоступен.';
	}

	crm_log_entity(
		'settings.company_fintech_access_saved',
		'settings',
		'update',
		sprintf( 'Обновлена доступность платёжных контуров для компании «%s»', $company->name ),
		'company',
		$company_id,
		[
			'org_id'   => $company_id,
			'context'  => [
				'company_code'            => (string) $company->code,
				'allowed_providers'       => $allowed_providers,
				'active_provider_cleared' => $active_provider_cleared,
			],
		]
	);

	wp_send_json_success( [
		'message'                 => $message,
		'company_id'              => $company_id,
		'allowed_providers'       => $allowed_providers,
		'allowed_provider_labels' => $allowed_provider_labels,
		'active_provider_cleared' => $active_provider_cleared,
	] );
}
