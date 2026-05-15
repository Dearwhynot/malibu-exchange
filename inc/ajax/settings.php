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

function _me_settings_telegram_status_payload( int $org_id, string $context = 'merchant', bool $auto_lock_connected = false ): array {
	$context = crm_telegram_normalize_bot_context( $context );
	$labels  = crm_telegram_bot_context_labels();

	if ( $org_id <= 0 ) {
		return [
			'context'              => $context,
			'context_label'        => (string) ( $labels[ $context ] ?? '' ),
			'is_configured'        => false,
			'webhook_ready'        => false,
			'invite_ready'         => false,
			'operator_ready'       => false,
			'blocked_reason'       => 'Telegram-настройки доступны только в контексте компании.',
			'missing_fields'       => [],
			'duplicate_fields'     => [],
			'callback_url'         => '',
			'legacy_callback_url'  => '',
			'bot_handle'           => '',
			'webhook_connected_at' => '',
			'webhook_last_error'   => '',
			'webhook_lock'         => false,
		];
	}

	$status  = crm_telegram_get_configuration_status( $org_id, $context, $auto_lock_connected );

	return [
		'context'              => (string) ( $status['context'] ?? $context ),
		'context_label'        => (string) ( $status['context_label'] ?? '' ),
		'is_configured'        => ! empty( $status['is_configured'] ),
		'webhook_ready'        => ! empty( $status['webhook_ready'] ),
		'invite_ready'         => ! empty( $status['invite_ready'] ),
		'operator_ready'       => ! empty( $status['operator_ready'] ),
		'blocked_reason'       => (string) ( $status['blocked_reason'] ?? '' ),
		'missing_fields'       => array_values( $status['missing_fields'] ?? [] ),
		'duplicate_fields'     => array_values( $status['duplicate_fields'] ?? [] ),
		'callback_url'         => (string) ( $status['callback_url'] ?? '' ),
		'legacy_callback_url'  => (string) ( $status['legacy_callback_url'] ?? '' ),
		'bot_handle'           => (string) ( $status['bot_handle'] ?? '' ),
		'webhook_connected_at' => (string) ( $status['webhook_connected_at'] ?? '' ),
		'webhook_last_error'   => (string) ( $status['webhook_last_error'] ?? '' ),
		'webhook_lock'         => ! empty( $status['webhook_lock'] ),
	];
}

function _me_settings_require_company_org_id( int $org_id, string $message = 'Настройки доступны только в контексте компании.' ): void {
	if ( $org_id <= 0 ) {
		wp_send_json_error( [ 'message' => $message ], 403 );
	}
}

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
		$pair_code = sanitize_text_field( wp_unslash( $_POST['pair_code'] ?? '' ) );
		if ( $pair_code === '' ) {
			$pair_code = RATES_PAIR_CODE;
		}

		$pair_def = function_exists( 'crm_root_get_pair_definition' ) ? crm_root_get_pair_definition( $pair_code ) : null;
		if ( ! $pair_def ) {
			wp_send_json_error( [ 'message' => 'Неизвестная пара: ' . $pair_code ], 400 );
		}

		$pair = function_exists( 'rates_get_any_pair' ) ? rates_get_any_pair( $pair_code, $org_id ) : null;
		if ( ! $pair ) {
			wp_send_json_error( [ 'message' => 'Пара не активирована для компании. Активацию производит root.' ], 409 );
		}
		if ( (int) $pair->is_active !== 1 ) {
			wp_send_json_error( [ 'message' => 'Пара отключена root\'ом. Изменение наценки недоступно.' ], 409 );
		}

		$coeff_type = sanitize_key( wp_unslash( $_POST['coefficient_type'] ?? 'absolute' ) );
		if ( ! in_array( $coeff_type, [ 'absolute', 'percent' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Некорректный тип наценки.' ], 422 );
		}

		$coeff = isset( $_POST['rates_coefficient'] ) ? (float) $_POST['rates_coefficient'] : 0.0;

		if ( $coeff < 0 ) {
			wp_send_json_error( [ 'message' => 'Значение наценки не может быть отрицательным.' ], 422 );
		}
		if ( $coeff_type === 'percent' && $coeff > 100 ) {
			wp_send_json_error( [ 'message' => 'Процент не может превышать 100.' ], 422 );
		}
		if ( $coeff_type === 'absolute' && $coeff > 1000000 ) {
			wp_send_json_error( [ 'message' => 'Сдвиг слишком большой.' ], 422 );
		}

		$ok = rates_update_coefficient( (int) $pair->id, $coeff, $coeff_type );

		if ( ! $ok ) {
			wp_send_json_error( [ 'message' => 'Ошибка сохранения наценки.' ], 500 );
		}

		crm_log_entity(
			'settings.rates_coefficient_saved',
			'settings',
			'update',
			sprintf( 'Обновлена наценка пары %s: %.4f (%s)', $pair_code, $coeff, $coeff_type ),
			'rate_pair',
			(int) $pair->id,
			[ 'context' => [
				'section'          => 'rates_coefficient',
				'pair_code'        => $pair_code,
				'coefficient'      => $coeff,
				'coefficient_type' => $coeff_type,
			] ]
		);

		wp_send_json_success( [
			'message'          => 'Наценка сохранена.',
			'pair_code'        => $pair_code,
			'coefficient'      => $coeff,
			'coefficient_type' => $coeff_type,
		] );
		return;
	}

	if ( $section === 'rates_market_source' ) {
		global $wpdb;

		$pair_code           = sanitize_text_field( wp_unslash( $_POST['pair_code'] ?? '' ) );
		$allowed_source_pairs = [ 'USDT_THB', 'RUB_USDT' ];
		if ( ! in_array( $pair_code, $allowed_source_pairs, true ) ) {
			wp_send_json_error( [ 'message' => 'Источник котировки не применим для пары: ' . $pair_code ], 400 );
		}

		$pair = function_exists( 'rates_get_any_pair' ) ? rates_get_any_pair( $pair_code, $org_id ) : null;
		if ( ! $pair ) {
			wp_send_json_error( [ 'message' => 'Пара не найдена для компании.' ], 404 );
		}
		if ( (int) $pair->is_active !== 1 ) {
			wp_send_json_error( [ 'message' => 'Пара отключена root\'ом. Изменение источника недоступно.' ], 409 );
		}

		$source          = sanitize_key( wp_unslash( $_POST['market_source'] ?? '' ) );
		$allowed_sources = [ 'bitkub', 'binance_th' ];
		if ( ! in_array( $source, $allowed_sources, true ) ) {
			wp_send_json_error( [ 'message' => 'Неизвестный источник котировки: ' . $source ], 422 );
		}

		$updated = $wpdb->update(
			'crm_rate_pairs',
			[ 'market_source' => $source ],
			[ 'id' => (int) $pair->id ],
			[ '%s' ],
			[ '%d' ]
		);
		if ( $updated === false ) {
			wp_send_json_error( [ 'message' => 'Ошибка сохранения источника.' ], 500 );
		}

		crm_log_entity(
			'settings.rates_market_source_saved',
			'settings',
			'update',
			sprintf( 'Изменён источник котировки пары %s: %s', $pair_code, $source ),
			'rate_pair',
			(int) $pair->id,
			[ 'context' => [ 'pair_code' => $pair_code, 'market_source' => $source ] ]
		);

		wp_send_json_success( [
			'message'       => 'Источник котировки сохранён.',
			'pair_code'     => $pair_code,
			'market_source' => $source,
		] );
		return;
	}

	if ( $section === 'merchant_settings' ) {
		if ( $org_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Merchant-настройки доступны только в контексте компании.' ], 403 );
		}

		$invite_ttl_minutes = (int) ( $_POST['merchant_invite_ttl_minutes'] ?? 0 );
		$platform_fee_type  = sanitize_key( wp_unslash( $_POST['merchant_default_platform_fee_type'] ?? 'percent' ) );
		$platform_fee_value = trim( (string) wp_unslash( $_POST['merchant_default_platform_fee_value'] ?? '0' ) );
		$bonus_enabled      = isset( $_POST['merchant_bonus_enabled'] ) && wp_unslash( $_POST['merchant_bonus_enabled'] ) === '1' ? '1' : '0';
		$referral_enabled   = isset( $_POST['merchant_referral_enabled'] ) && wp_unslash( $_POST['merchant_referral_enabled'] ) === '1' ? '1' : '0';
		$reward_type        = sanitize_key( wp_unslash( $_POST['merchant_referral_reward_type'] ?? 'percent' ) );
		$reward_value       = trim( (string) wp_unslash( $_POST['merchant_referral_reward_value'] ?? '0' ) );

		if ( $invite_ttl_minutes <= 0 ) {
			wp_send_json_error( [ 'message' => 'TTL приглашения должен быть больше нуля.' ], 422 );
		}

		$markup_types = crm_merchant_markup_types();
		if ( ! isset( $markup_types[ $platform_fee_type ] ) ) {
			wp_send_json_error( [ 'message' => 'Некорректный тип комиссии платформы.' ], 422 );
		}
		if ( ! isset( $markup_types[ $reward_type ] ) ) {
			wp_send_json_error( [ 'message' => 'Некорректный тип реферального вознаграждения.' ], 422 );
		}
		if ( $platform_fee_value === '' || ! is_numeric( $platform_fee_value ) ) {
			wp_send_json_error( [ 'message' => 'Значение комиссии платформы должно быть числом.' ], 422 );
		}
		if ( $reward_value === '' || ! is_numeric( $reward_value ) ) {
			wp_send_json_error( [ 'message' => 'Значение реферального вознаграждения должно быть числом.' ], 422 );
		}

		$values = [
			'merchant_invite_ttl_minutes'         => (string) $invite_ttl_minutes,
			'merchant_default_platform_fee_type'  => $platform_fee_type,
			'merchant_default_platform_fee_value' => number_format( (float) $platform_fee_value, 8, '.', '' ),
			'merchant_bonus_enabled'              => $bonus_enabled,
			'merchant_referral_enabled'           => $referral_enabled,
			'merchant_referral_reward_type'       => $reward_type,
			'merchant_referral_reward_value'      => number_format( (float) $reward_value, 8, '.', '' ),
		];

		foreach ( $values as $key => $value ) {
			crm_set_setting( $key, $value, $org_id );
		}

		crm_log_entity(
			'settings.merchant_saved',
			'settings',
			'update',
			'Обновлены настройки merchant-контура',
			'settings',
			0,
			[
				'org_id'  => $org_id,
				'context' => [
					'section'                  => 'merchant_settings',
					'invite_ttl_minutes'       => $invite_ttl_minutes,
					'platform_fee_type'        => $platform_fee_type,
					'platform_fee_value'       => $values['merchant_default_platform_fee_value'],
					'bonus_enabled'            => $bonus_enabled === '1',
					'referral_enabled'         => $referral_enabled === '1',
					'referral_reward_type'     => $reward_type,
					'referral_reward_value'    => $values['merchant_referral_reward_value'],
				],
			]
		);

		wp_send_json_success( [ 'message' => 'Настройки merchant-контура сохранены.' ] );
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
	_me_settings_require_company_org_id( $org_id, 'Telegram-настройки доступны только в контексте компании.' );

	$telegram_context = crm_telegram_normalize_bot_context(
		isset( $_POST['telegram_context'] ) ? (string) wp_unslash( $_POST['telegram_context'] ) : 'merchant'
	);
	$token_key        = crm_telegram_setting_key( $telegram_context, 'bot_token' );
	$username_key     = crm_telegram_setting_key( $telegram_context, 'bot_username' );
	$context_label    = crm_telegram_bot_context_labels()[ $telegram_context ];
	$token = isset( $_POST['telegram_bot_token'] )
		? trim( (string) wp_unslash( $_POST['telegram_bot_token'] ) )
		: trim( (string) wp_unslash( $_POST[ $token_key ] ?? '' ) );
	$username = isset( $_POST['telegram_bot_username'] )
		? crm_telegram_sanitize_bot_username( (string) wp_unslash( $_POST['telegram_bot_username'] ) )
		: crm_telegram_sanitize_bot_username( (string) wp_unslash( $_POST[ $username_key ] ?? '' ) );
	$current_telegram = crm_telegram_collect_settings( $org_id, $telegram_context );
	$settings_changed = $current_telegram['bot_token'] !== $token || $current_telegram['bot_username'] !== $username;
	$duplicate        = crm_telegram_find_duplicate_bot_setting( $org_id, $telegram_context, $username, $token );

	if ( ! empty( $duplicate['has_duplicate'] ) ) {
		wp_send_json_error( [
			'message'          => (string) $duplicate['message'],
			'duplicate_fields' => array_values( $duplicate['fields'] ?? [] ),
			'telegram_status'  => _me_settings_telegram_status_payload( $org_id, $telegram_context ),
			'telegram_context' => $telegram_context,
		], 422 );
	}

	$ok = crm_set_setting( $token_key, $token, $org_id );
	$ok = crm_set_setting( $username_key, $username, $org_id ) && $ok;
	if ( $settings_changed ) {
		$ok = crm_set_setting( crm_telegram_setting_key( $telegram_context, 'webhook_url' ), '', $org_id ) && $ok;
		$ok = crm_set_setting( crm_telegram_setting_key( $telegram_context, 'webhook_connected_at' ), '', $org_id ) && $ok;
		$ok = crm_set_setting( crm_telegram_setting_key( $telegram_context, 'webhook_last_error' ), '', $org_id ) && $ok;
		$ok = crm_set_setting( crm_telegram_setting_key( $telegram_context, 'webhook_lock' ), '0', $org_id ) && $ok;
	} else {
		$current_telegram_status = crm_telegram_get_configuration_status( $org_id, $telegram_context );
		if ( ! empty( $current_telegram_status['webhook_ready'] ) ) {
			$ok = crm_set_setting( crm_telegram_setting_key( $telegram_context, 'webhook_lock' ), '1', $org_id ) && $ok;
		}
	}

	if ( $telegram_context === 'merchant' ) {
		$ok = crm_set_setting( 'telegram_bot_token', $token, $org_id ) && $ok;
		$ok = crm_set_setting( 'telegram_bot_username', $username, $org_id ) && $ok;
	}

	if ( ! $ok ) {
		wp_send_json_error( [ 'message' => 'Ошибка сохранения в базе данных' ], 500 );
	}

	crm_log_entity( 'settings.telegram_saved', 'settings', 'update',
		'Обновлены настройки Telegram-бота компании',
		'settings',
		0,
		[
			'org_id'  => $org_id,
			'context' => [
				'section'      => 'telegram',
				'bot_context'  => $telegram_context,
				'bot_username' => $username !== '' ? '@' . $username : '',
			],
		]
	);

	$telegram_status = _me_settings_telegram_status_payload( $org_id, $telegram_context );
	$message = 'Telegram-настройки «' . $context_label . '» сохранены.';
	if ( ! empty( $telegram_status['is_configured'] ) && empty( $telegram_status['webhook_ready'] ) ) {
		$message .= ' Теперь нажмите «Подключить callback».';
	}

	wp_send_json_success( [
		'message'         => $message,
		'telegram_status' => $telegram_status,
		'telegram_context' => $telegram_context,
	] );
}

add_action( 'wp_ajax_me_settings_telegram_connect', 'me_ajax_settings_telegram_connect' );

function me_ajax_settings_telegram_connect(): void {
	check_ajax_referer( 'me_settings_save', 'nonce' );

	$current_uid = get_current_user_id();
	if ( ! crm_user_has_permission( $current_uid, 'settings.edit' ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав' ], 403 );
	}

	$org_id = crm_is_root( $current_uid ) ? 0 : crm_get_current_user_company_id( $current_uid );
	_me_settings_require_company_org_id( $org_id, 'Telegram callback можно подключать только в контексте компании.' );

	$telegram_context = crm_telegram_normalize_bot_context(
		isset( $_POST['telegram_context'] ) ? (string) wp_unslash( $_POST['telegram_context'] ) : 'merchant'
	);
	$saved_settings = crm_telegram_collect_settings( $org_id, $telegram_context );
	$token          = trim( (string) ( $saved_settings['bot_token'] ?? '' ) );
	$username       = crm_telegram_sanitize_bot_username( (string) ( $saved_settings['bot_username'] ?? '' ) );

	if ( $token === '' || $username === '' ) {
		wp_send_json_error( [
			'message'         => 'Сначала сохраните имя и токен Telegram-бота, затем подключите callback.',
			'telegram_status' => _me_settings_telegram_status_payload( $org_id, $telegram_context ),
			'telegram_context' => $telegram_context,
		], 422 );
	}

	$result = crm_telegram_register_webhook( $org_id, $username, $token, $telegram_context );
	$telegram_status = _me_settings_telegram_status_payload( $org_id, $telegram_context );

	if ( empty( $result['success'] ) ) {
		crm_log_entity(
			'settings.telegram_connect_failed',
			'settings',
			'update',
			'Ошибка подключения Telegram callback',
			'settings',
			0,
			[
				'org_id'  => $org_id,
				'context' => [
					'bot_context'  => $telegram_context,
					'bot_username' => $username !== '' ? '@' . $username : '',
					'error'        => (string) ( $result['message'] ?? '' ),
				],
			]
		);

		wp_send_json_error( [
			'message'         => (string) ( $result['message'] ?? 'Не удалось подключить callback.' ),
			'telegram_status' => $telegram_status,
		], 422 );
	}

	crm_log_entity(
		'settings.telegram_connected',
		'settings',
		'update',
		'Telegram callback подключён для компании',
		'settings',
		0,
		[
			'org_id'  => $org_id,
			'context' => [
				'bot_context'  => $telegram_context,
				'bot_username' => $username !== '' ? '@' . $username : '',
				'callback_url' => (string) ( $result['callback_url'] ?? '' ),
			],
		]
	);

	wp_send_json_success( [
		'message'         => $telegram_context === 'merchant'
			? 'Telegram callback подключён. Инвайты готовы к работе.'
			: 'Telegram callback подключён. Операторский бот готов к работе.',
		'telegram_status' => $telegram_status,
		'telegram_context' => $telegram_context,
	] );
}

add_action( 'wp_ajax_me_settings_telegram_unlock', 'me_ajax_settings_telegram_unlock' );

function me_ajax_settings_telegram_unlock(): void {
	check_ajax_referer( 'me_settings_save', 'nonce' );

	$current_uid = get_current_user_id();
	if ( ! crm_user_has_permission( $current_uid, 'settings.edit' ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав' ], 403 );
	}

	$org_id = crm_is_root( $current_uid ) ? 0 : crm_get_current_user_company_id( $current_uid );
	_me_settings_require_company_org_id( $org_id, 'Telegram-настройки доступны только в контексте компании.' );

	$telegram_context = crm_telegram_normalize_bot_context(
		isset( $_POST['telegram_context'] ) ? (string) wp_unslash( $_POST['telegram_context'] ) : 'merchant'
	);

	crm_telegram_set_config_lock( $org_id, false, $telegram_context );

	crm_log_entity(
		'settings.telegram_unlocked',
		'settings',
		'update',
		'Telegram-блок разблокирован для редактирования',
		'settings',
		0,
		[
			'org_id'  => $org_id,
			'context' => [
				'section'     => 'telegram',
				'bot_context' => $telegram_context,
			],
		]
	);

	wp_send_json_success( [
		'message'         => 'Редактирование Telegram-настроек разблокировано.',
		'telegram_status' => _me_settings_telegram_status_payload( $org_id, $telegram_context ),
		'telegram_context' => $telegram_context,
	] );
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
