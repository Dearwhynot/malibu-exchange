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
		$keys = [
			'fintech_company_name',
			'fintech_merchant_order_prefix',
			'fintech_active_provider',
			'fintech_debug',
		];
		foreach ( $keys as $key ) {
			crm_set_setting( $key, sanitize_textarea_field( wp_unslash( $_POST[ $key ] ?? '' ) ), $org_id );
		}
		crm_log_entity( 'settings.fintech_general_saved', 'settings', 'update',
			'Обновлены общие настройки Fintech',
			'settings', 0, [ 'context' => [ 'section' => 'fintech_general' ] ]
		);
		wp_send_json_success( [ 'message' => 'Общие настройки сохранены.' ] );
		return;
	}

	// Fintech: Kanyon / Pay2Day
	if ( $section === 'fintech_kanyon' ) {
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
		wp_send_json_success( [ 'message' => 'Настройки Kanyon сохранены.' ] );
		return;
	}

	// Fintech: Doverka
	if ( $section === 'fintech_doverka' ) {
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
		wp_send_json_success( [ 'message' => 'Настройки Doverka сохранены.' ] );
		return;
	}

	// Fintech: legacy section (backward compat — save all)
	if ( $section === 'fintech' ) {
		$keys = [
			'fintech_company_name', 'fintech_merchant_order_prefix', 'fintech_active_provider', 'fintech_debug',
			'fintech_pay2day_login', 'fintech_pay2day_password', 'fintech_pay2day_tsp_id', 'fintech_pay2day_order_currency',
			'fintech_doverka_api_key', 'fintech_doverka_currency_id', 'fintech_doverka_approve_url', 'fintech_doverka_kyc_redirect_url',
			'fintech_kanyon_verify_signature', 'fintech_kanyon_public_key_pem',
		];
		foreach ( $keys as $key ) {
			crm_set_setting( $key, sanitize_textarea_field( wp_unslash( $_POST[ $key ] ?? '' ) ), $org_id );
		}
		wp_send_json_success( [ 'message' => 'Настройки провайдера сохранены.' ] );
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
