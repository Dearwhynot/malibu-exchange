<?php
/**
 * Malibu Exchange — Users Module
 *
 * Работает поверх стандартных таблиц WordPress (wp_users, wp_usermeta).
 * Бизнес-роли и статусы — в CRM RBAC (inc/rbac.php).
 *
 * Этот файл отвечает только за:
 *   - трекинг последнего входа (usermeta + crm_user_last_login)
 *   - вспомогательные функции для отображения (аватар, last_login)
 *   - проверку прав на управление пользователями через CRM
 *
 * ─────────────────────────────────────────────────────────
 * SQL таблица истории входов (создаётся один раз):
 * ─────────────────────────────────────────────────────────
 *
 * CREATE TABLE IF NOT EXISTS `crm_user_last_login` (
 *   `id`         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
 *   `user_id`    BIGINT(20) UNSIGNED NOT NULL,
 *   `login_at`   DATETIME            NOT NULL,
 *   `ip_address` VARCHAR(45)         DEFAULT NULL,
 *   `user_agent` VARCHAR(512)        DEFAULT NULL,
 *   PRIMARY KEY (`id`),
 *   KEY `idx_user_id`  (`user_id`),
 *   KEY `idx_login_at` (`login_at`)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── WP-хуки ────────────────────────────────────────────────────────────────

/**
 * Фиксируем время последнего визита авторизованного пользователя.
 * Срабатывает на каждый запрос, пишет в БД не чаще раза в 5 минут.
 */
add_action( 'init', 'me_users_track_last_login' );
function me_users_track_last_login(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}

	global $wpdb;

	$user_id = get_current_user_id();
	$now     = current_time( 'mysql' );
	$last    = (string) get_user_meta( $user_id, 'last_login_at', true );

	// Пишем не чаще раза в 5 минут
	if ( $last && ( strtotime( $now ) - strtotime( $last ) ) < 300 ) {
		return;
	}

	update_user_meta( $user_id, 'last_login_at', $now );

	$wpdb->insert(
		'crm_user_last_login',
		[
			'user_id'    => $user_id,
			'login_at'   => $now,
			'ip_address' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null,
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : null,
		],
		[ '%d', '%s', '%s', '%s' ]
	);
}

// ─── Проверка прав ───────────────────────────────────────────────────────────

/**
 * Может ли текущий пользователь управлять пользователями.
 * Делегирует в CRM RBAC (permission: users.view).
 * uid=1 всегда имеет доступ.
 */
function me_users_can_manage(): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	return crm_can_manage_users();
}

/**
 * Только user_id === 1 может физически удалять пользователей.
 */
function me_users_can_hard_delete(): bool {
	return is_user_logged_in() && get_current_user_id() === 1;
}

// ─── Вспомогательные функции ─────────────────────────────────────────────────

/**
 * Получить время последнего входа (MySQL datetime или '').
 * Сначала читает из crm_user_last_login, фолбэк — usermeta.
 */
function me_users_get_last_login( int $user_id ): string {
	global $wpdb;

	$row = $wpdb->get_var( $wpdb->prepare(
		'SELECT login_at FROM crm_user_last_login WHERE user_id = %d ORDER BY login_at DESC LIMIT 1',
		$user_id
	) );

	if ( $row ) {
		return (string) $row;
	}

	return (string) get_user_meta( $user_id, 'last_login_at', true );
}

/**
 * Инициальная буква для аватара пользователя.
 */
function me_users_avatar_letter( WP_User $user ): string {
	$name = $user->display_name ?: $user->user_login;
	return strtoupper( mb_substr( $name, 0, 1 ) );
}
