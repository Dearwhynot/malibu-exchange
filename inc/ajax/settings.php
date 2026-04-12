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

	if ( ! crm_user_has_permission( get_current_user_id(), 'settings.edit' ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав' ], 403 );
	}

	$org_id = CRM_DEFAULT_ORG_ID;

	// ─── Telegram ────────────────────────────────────────────────────────────
	$token = isset( $_POST['telegram_bot_token'] )
		? sanitize_text_field( wp_unslash( $_POST['telegram_bot_token'] ) )
		: '';

	$ok = crm_set_setting( 'telegram_bot_token', $token, $org_id );

	if ( ! $ok ) {
		wp_send_json_error( [ 'message' => 'Ошибка сохранения в базе данных' ], 500 );
	}

	wp_send_json_success( [ 'message' => 'Настройки сохранены' ] );
}
