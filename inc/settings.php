<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Константа: org_id по умолчанию ─────────────────────────────────────────
// Пока система однотенантная — используем 1.
// При добавлении мультиорганизационности сюда передаётся реальный org_id.
if ( ! defined( 'CRM_DEFAULT_ORG_ID' ) ) {
	define( 'CRM_DEFAULT_ORG_ID', 1 );
}

/**
 * Получить значение настройки по ключу.
 *
 * @param string   $key    Ключ настройки (напр. 'telegram_bot_token').
 * @param int      $org_id ID организации (по умолчанию CRM_DEFAULT_ORG_ID).
 * @param mixed    $default Значение, если настройка не найдена.
 * @return string|null
 */
function crm_get_setting( string $key, int $org_id = CRM_DEFAULT_ORG_ID, $default = null ) {
	global $wpdb;

	$value = $wpdb->get_var( $wpdb->prepare(
		'SELECT setting_value FROM crm_settings WHERE org_id = %d AND setting_key = %s LIMIT 1',
		$org_id,
		$key
	) );

	if ( $value === null ) {
		return $default;
	}

	return (string) $value;
}

/**
 * Сохранить значение настройки.
 * Если настройка уже существует — обновляет. Иначе — создаёт.
 *
 * @param string $key    Ключ настройки.
 * @param string $value  Значение.
 * @param int    $org_id ID организации.
 * @return bool  true при успехе, false при ошибке.
 */
function crm_set_setting( string $key, string $value, int $org_id = CRM_DEFAULT_ORG_ID ): bool {
	global $wpdb;

	$result = $wpdb->query( $wpdb->prepare(
		'INSERT INTO crm_settings (org_id, setting_key, setting_value)
		 VALUES (%d, %s, %s)
		 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
		$org_id,
		$key,
		$value
	) );

	return $result !== false;
}

/**
 * Получить все настройки организации в виде ассоциативного массива.
 *
 * @param int $org_id ID организации.
 * @return array<string, string>
 */
function crm_get_all_settings( int $org_id = CRM_DEFAULT_ORG_ID ): array {
	global $wpdb;

	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT setting_key, setting_value FROM crm_settings WHERE org_id = %d',
		$org_id
	), ARRAY_A );

	$settings = [];
	foreach ( $rows as $row ) {
		$settings[ $row['setting_key'] ] = (string) $row['setting_value'];
	}

	return $settings;
}
