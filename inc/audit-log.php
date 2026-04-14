<?php
/**
 * Malibu Exchange — Centralized Audit Log
 *
 * Единая точка записи событий системы и действий пользователей.
 *
 * Главная функция:
 *   crm_log( string $event_code, array $data = [] ): void
 *
 * Шорткаты:
 *   crm_log_auth()      — события аутентификации
 *   crm_log_user()      — действия над пользователями
 *   crm_log_entity()    — действия над бизнес-сущностями
 *   crm_log_security()  — события безопасности
 *   crm_log_system()    — системные события
 *
 * Параметры $data:
 *   category    string   auth|users|rates|orders|settings|system|security|api
 *   level       string   info|warning|error|security  (default: info)
 *   action      string   create|update|delete|login|logout|password_change|...
 *   message     string   Текстовое описание события
 *   target_type string   Тип сущности: user|rate|settings|order|...
 *   target_id   int      ID сущности (0 = нет)
 *   context     array    Доп. данные (JSON)
 *   user_id     int      Переопределить пользователя (0 = текущий)
 *   is_success  bool     Успешно ли (default: true)
 *   source      string   Источник (по умолчанию — SCRIPT_NAME)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Вспомогательные функции ─────────────────────────────────────────────────

/**
 * Получить IP-адрес клиента, учитывая прокси.
 */
function crm_audit_log_get_ip(): string {
	$candidates = [
		'HTTP_CF_CONNECTING_IP',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_REAL_IP',
		'REMOTE_ADDR',
	];

	foreach ( $candidates as $key ) {
		if ( empty( $_SERVER[ $key ] ) ) {
			continue;
		}
		$ip = trim( explode( ',', (string) $_SERVER[ $key ] )[0] );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}

	return '';
}

// ─── Главная функция ─────────────────────────────────────────────────────────

/**
 * Записать событие в журнал аудита.
 *
 * @param string $event_code  Машинный код события, например 'user.login.success'.
 * @param array  $data {
 *   @type string   $category    Категория события (auth|users|rates|settings|system|security)
 *   @type string   $level       Уровень: info|warning|error|security
 *   @type string   $action      Действие: create|update|delete|login|logout|...
 *   @type string   $message     Человекочитаемое описание
 *   @type string   $target_type Тип сущности
 *   @type int      $target_id   ID сущности
 *   @type array    $context     Доп. данные для JSON
 *   @type int      $user_id     Переопределить пользователя (0 = текущий)
 *   @type bool     $is_success  Успешно ли действие
 *   @type string   $source      Источник события
 *   @type int      $org_id      Организация (default: CRM_DEFAULT_ORG_ID = 1)
 * }
 */
function crm_log( string $event_code, array $data = [] ): void {
	global $wpdb;

	// Организация
	$org_id = isset( $data['org_id'] ) ? (int) $data['org_id']
		: ( defined( 'CRM_DEFAULT_ORG_ID' ) ? (int) CRM_DEFAULT_ORG_ID : 1 );

	// Пользователь
	$user_id = isset( $data['user_id'] ) ? (int) $data['user_id'] : get_current_user_id();
	$user_login = '';
	if ( $user_id > 0 ) {
		$u = get_userdata( $user_id );
		$user_login = $u instanceof WP_User ? $u->user_login : '';
	}

	// Уровень
	$level = (string) ( $data['level'] ?? 'info' );
	if ( ! in_array( $level, [ 'info', 'warning', 'error', 'security' ], true ) ) {
		$level = 'info';
	}

	// Nullable поля
	$db_user_id   = $user_id > 0   ? $user_id   : null;
	$target_id    = isset( $data['target_id'] ) ? (int) $data['target_id'] : null;
	$db_target_id = ( $target_id && $target_id > 0 ) ? $target_id : null;

	// Контекст
	$context_json = null;
	if ( ! empty( $data['context'] ) && is_array( $data['context'] ) ) {
		$encoded = wp_json_encode( $data['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$context_json = $encoded !== false ? $encoded : null;
	}

	// Технический контекст
	$ip         = crm_audit_log_get_ip();
	$user_agent = substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 512 );
	$request_uri = substr( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), 0, 1024 );
	$method     = (string) ( $_SERVER['REQUEST_METHOD'] ?? '' );
	$source     = (string) ( $data['source'] ?? substr( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ), 0, 128 ) );

	$is_success = isset( $data['is_success'] ) ? ( $data['is_success'] ? 1 : 0 ) : 1;

	// Подставляем NULL напрямую для nullable integer-полей (безопасно — только приведённые типы)
	$user_id_sql   = $db_user_id   !== null ? (int) $db_user_id   : 'NULL';
	$target_id_sql = $db_target_id !== null ? (int) $db_target_id : 'NULL';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO `crm_audit_log`
			 (`organization_id`, `event_code`, `category`, `level`, `user_id`, `user_login`,
			  `target_type`, `target_id`, `action`, `message`, `context_json`,
			  `ip_address`, `user_agent`, `request_uri`, `method`, `source`, `is_success`)
			 VALUES (%d, %s, %s, %s, {$user_id_sql}, %s, %s, {$target_id_sql}, %s, %s, %s, %s, %s, %s, %s, %s, %d)",
			$org_id,
			$event_code,
			(string) ( $data['category'] ?? 'system' ),
			$level,
			$user_login,
			(string) ( $data['target_type'] ?? '' ),
			(string) ( $data['action'] ?? '' ),
			(string) ( $data['message'] ?? '' ),
			$context_json,
			$ip,
			$user_agent,
			$request_uri,
			$method,
			$source,
			$is_success
		)
	);
	// phpcs:enable
}

// ─── Шорткаты ────────────────────────────────────────────────────────────────

/**
 * Логировать событие аутентификации.
 */
function crm_log_auth( string $event_code, string $action, string $message, array $opts = [] ): void {
	crm_log( $event_code, array_merge( [
		'category' => 'auth',
		'level'    => 'info',
		'action'   => $action,
		'message'  => $message,
	], $opts ) );
}

/**
 * Логировать действие над пользователем.
 */
function crm_log_user( string $event_code, string $action, string $message, int $target_user_id = 0, array $opts = [] ): void {
	crm_log( $event_code, array_merge( [
		'category'    => 'users',
		'level'       => 'info',
		'action'      => $action,
		'message'     => $message,
		'target_type' => 'user',
		'target_id'   => $target_user_id ?: null,
	], $opts ) );
}

/**
 * Логировать действие над бизнес-сущностью.
 */
function crm_log_entity( string $event_code, string $category, string $action, string $message, string $target_type = '', int $target_id = 0, array $opts = [] ): void {
	crm_log( $event_code, array_merge( [
		'category'    => $category,
		'level'       => 'info',
		'action'      => $action,
		'message'     => $message,
		'target_type' => $target_type,
		'target_id'   => $target_id ?: null,
	], $opts ) );
}

/**
 * Логировать событие безопасности (level=security).
 */
function crm_log_security( string $event_code, string $action, string $message, array $opts = [] ): void {
	crm_log( $event_code, array_merge( [
		'category'  => 'security',
		'level'     => 'security',
		'action'    => $action,
		'message'   => $message,
		'is_success' => false,
	], $opts ) );
}

/**
 * Логировать системное событие.
 */
function crm_log_system( string $event_code, string $action, string $message, array $opts = [] ): void {
	crm_log( $event_code, array_merge( [
		'category' => 'system',
		'level'    => 'info',
		'action'   => $action,
		'message'  => $message,
	], $opts ) );
}

// ─── Хуки аутентификации ─────────────────────────────────────────────────────

/**
 * Успешный вход в систему.
 */
add_action( 'wp_login', 'crm_audit_wp_login', 10, 2 );
function crm_audit_wp_login( string $user_login, WP_User $user ): void {
	crm_log( 'auth.login.success', [
		'category'    => 'auth',
		'level'       => 'info',
		'action'      => 'login',
		'message'     => "Вход в систему: {$user_login}",
		'user_id'     => $user->ID,
		'target_type' => 'user',
		'target_id'   => $user->ID,
		'is_success'  => true,
	] );
}

/**
 * Неуспешная попытка входа.
 * WP 5.4+: второй параметр — WP_Error с кодом ошибки.
 */
add_action( 'wp_login_failed', 'crm_audit_wp_login_failed', 10, 2 );
function crm_audit_wp_login_failed( string $username, $error = null ): void {
	$error_code = '';
	$level      = 'warning';
	$category   = 'auth';

	if ( $error instanceof WP_Error ) {
		$error_code = $error->get_error_code();
		// Заблокированный / архивированный / ожидающий аккаунт
		if ( in_array( $error_code, [ 'crm_blocked', 'crm_archived', 'crm_pending' ], true ) ) {
			$level    = 'security';
			$category = 'security';
		}
	}

	crm_log( 'auth.login.failed', [
		'category'   => $category,
		'level'      => $level,
		'action'     => 'login',
		'message'    => "Неуспешная попытка входа: {$username}",
		'is_success' => false,
		'context'    => array_filter( [
			'username'   => $username,
			'error_code' => $error_code,
		] ),
	] );
}

/**
 * Выход из системы.
 */
add_action( 'wp_logout', 'crm_audit_wp_logout', 10, 1 );
function crm_audit_wp_logout( int $user_id ): void {
	$user  = get_userdata( $user_id );
	$login = $user instanceof WP_User ? $user->user_login : "#{$user_id}";

	crm_log( 'auth.logout', [
		'category'    => 'auth',
		'level'       => 'info',
		'action'      => 'logout',
		'message'     => "Выход из системы: {$login}",
		'user_id'     => $user_id,
		'target_type' => 'user',
		'target_id'   => $user_id,
		'is_success'  => true,
	] );
}
