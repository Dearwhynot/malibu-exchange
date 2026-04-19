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
 * Получить объект DateTimeZone для настроенной таймзоны организации.
 * Таймзона хранится в crm_settings с ключом 'timezone'.
 *
 * @param int $org_id ID организации.
 * @return DateTimeZone
 */
function crm_get_timezone( int $org_id = CRM_DEFAULT_ORG_ID ): DateTimeZone {
	$tz_id = crm_get_setting( 'timezone', $org_id, 'UTC' );
	try {
		return new DateTimeZone( $tz_id ?: 'UTC' );
	} catch ( \Exception $e ) {
		return new DateTimeZone( 'UTC' );
	}
}

/**
 * Форматировать datetime-строку из БД с учётом настроенной таймзоны.
 * Предполагается, что в БД даты хранятся в UTC (DEFAULT CURRENT_TIMESTAMP при UTC-сервере).
 *
 * @param string|null $dt     Строка "Y-m-d H:i:s" (UTC).
 * @param int         $org_id ID организации.
 * @return string|null        Строка в таймзоне организации, или null.
 */
function crm_format_dt( ?string $dt, int $org_id = CRM_DEFAULT_ORG_ID ): ?string {
	if ( $dt === null || $dt === '' ) {
		return null;
	}
	try {
		$d = new DateTime( $dt, new DateTimeZone( 'UTC' ) );
		$d->setTimezone( crm_get_timezone( $org_id ) );
		return $d->format( 'Y-m-d H:i:s' );
	} catch ( \Exception $e ) {
		return $dt;
	}
}

/**
 * Получить метку таймзоны вида "UTC+07:00 (Asia/Bangkok)".
 *
 * @param int $org_id ID организации.
 * @return string
 */
function crm_get_timezone_label( int $org_id = CRM_DEFAULT_ORG_ID ): string {
	$tz_id = crm_get_setting( 'timezone', $org_id, 'UTC' );
	try {
		$dtz    = new DateTimeZone( $tz_id ?: 'UTC' );
		$offset = $dtz->getOffset( new DateTime( 'now', $dtz ) );
		$sign   = $offset >= 0 ? '+' : '-';
		$abs    = abs( $offset );
		$h      = (int) floor( $abs / 3600 );
		$m      = (int) floor( ( $abs % 3600 ) / 60 );
		return 'UTC' . $sign . str_pad( $h, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( $m, 2, '0', STR_PAD_LEFT ) . ' (' . $tz_id . ')';
	} catch ( \Exception $e ) {
		return 'UTC';
	}
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
