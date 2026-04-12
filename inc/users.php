<?php
/**
 * Malibu Exchange — Users Module
 *
 * Работает поверх стандартных таблиц WordPress (wp_users, wp_usermeta).
 * Не создаёт отдельную сущность пользователей.
 *
 * Статусы хранятся в usermeta:
 *   account_status  → 'active' | 'blocked' | 'archived'   (по умолчанию active)
 *   last_login_at   → datetime MySQL (обновляется при каждом входе)
 *   me_notes        → служебные заметки администратора
 *   me_user_manager → '1' если пользователь имеет права управления
 *
 * ─────────────────────────────────────────────────────────
 * SQL ДЛЯ СОЗДАНИЯ ТАБЛИЦЫ ПОСЛЕДНЕГО ВХОДА (опционально):
 * ─────────────────────────────────────────────────────────
 * Если вы хотите хранить историю входов в отдельной таблице,
 * добавьте её в БД (замените wp_ на ваш реальный префикс):
 *
 * Все пользовательские таблицы проекта используют префикс `crm_`
 * (не стандартный WordPress-префикс wp_).
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
 *
 * При наличии этой таблицы в me_users_track_last_login() дополнительно
 * вставляйте строку через $wpdb.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Константы статусов ──────────────────────────────────────────────────────

define( 'ME_USER_STATUS_ACTIVE',   'active' );
define( 'ME_USER_STATUS_BLOCKED',  'blocked' );
define( 'ME_USER_STATUS_ARCHIVED', 'archived' );

// ─── WP-хуки ────────────────────────────────────────────────────────────────

/**
 * Блокируем вход для blocked/archived аккаунтов.
 * Приоритет 30 — после стандартной проверки пароля.
 */
add_filter( 'authenticate', 'me_users_check_account_status', 30, 3 );
function me_users_check_account_status( $user, $username, $password ) {
	if ( ! ( $user instanceof WP_User ) ) {
		return $user;
	}

	$status = get_user_meta( $user->ID, 'account_status', true );

	if ( $status === ME_USER_STATUS_BLOCKED ) {
		return new WP_Error(
			'account_blocked',
			'Ваш аккаунт заблокирован. Обратитесь к администратору.'
		);
	}

	if ( $status === ME_USER_STATUS_ARCHIVED ) {
		return new WP_Error(
			'account_archived',
			'Этот аккаунт деактивирован.'
		);
	}

	return $user;
}

/**
 * Фиксируем время последнего визита авторизованного пользователя.
 * Срабатывает на каждый запрос, но пишет в БД не чаще чем раз в 5 минут.
 */
add_action( 'init', 'me_users_track_last_login' );
function me_users_track_last_login(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}

	global $wpdb;

	$user_id = get_current_user_id();
	$now     = current_time( 'mysql' );

	// Читаем время последней записи из usermeta (быстро, без JOIN)
	$last = (string) get_user_meta( $user_id, 'last_login_at', true );

	// Пишем не чаще раз в 5 минут
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
 * user_id === 1 всегда имеет доступ.
 * Остальные — только при наличии usermeta me_user_manager = '1'.
 */
function me_users_can_manage(): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$uid = get_current_user_id();
	if ( $uid === 1 ) {
		return true;
	}
	return (bool) get_user_meta( $uid, 'me_user_manager', true );
}

/**
 * Только user_id === 1 может физически удалять пользователей
 * и выдавать/отзывать права менеджера.
 */
function me_users_can_hard_delete(): bool {
	return is_user_logged_in() && get_current_user_id() === 1;
}

// ─── Вспомогательные функции ─────────────────────────────────────────────────

/**
 * Получить статус пользователя (active / blocked / archived).
 * Если meta не установлена — считаем active.
 */
function me_users_get_status( int $user_id ): string {
	$status = (string) get_user_meta( $user_id, 'account_status', true );
	return in_array( $status, [ ME_USER_STATUS_BLOCKED, ME_USER_STATUS_ARCHIVED ], true )
		? $status
		: ME_USER_STATUS_ACTIVE;
}

/**
 * Получить время последнего входа (MySQL datetime или '').
 * Читаем из crm_user_last_login, фолбэк — usermeta last_login_at.
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
 * Получить первую роль пользователя.
 */
function me_users_get_primary_role( WP_User $user ): string {
	$roles = (array) $user->roles;
	return ! empty( $roles ) ? reset( $roles ) : '';
}

/**
 * Получить список всех ролей WordPress [slug => name].
 */
function me_users_get_all_roles(): array {
	global $wp_roles;
	$result = [];
	foreach ( $wp_roles->roles as $slug => $role ) {
		$result[ $slug ] = translate_user_role( $role['name'] );
	}
	return $result;
}

/**
 * Получить CSS-класс бейджа для статуса.
 */
function me_users_status_badge_class( string $status ): string {
	if ( $status === ME_USER_STATUS_BLOCKED ) {
		return 'danger';
	}
	if ( $status === ME_USER_STATUS_ARCHIVED ) {
		return 'secondary';
	}
	return 'success';
}

/**
 * Получить CSS-класс бейджа для роли.
 */
function me_users_role_badge_class( string $role ): string {
	if ( $role === 'administrator' ) {
		return 'danger';
	}
	if ( $role === 'editor' ) {
		return 'warning';
	}
	if ( $role === 'author' ) {
		return 'info';
	}
	if ( $role === 'contributor' ) {
		return 'primary';
	}
	return 'secondary';
}

/**
 * Инициальная буква для аватара пользователя.
 */
function me_users_avatar_letter( WP_User $user ): string {
	$name = $user->display_name ?: $user->user_login;
	return strtoupper( mb_substr( $name, 0, 1 ) );
}

/**
 * Установить статус пользователя.
 *
 * @return true|string  true при успехе, строку с ошибкой при неудаче.
 */
function me_users_set_status( int $user_id, string $status ) {
	if ( ! in_array( $status, [ ME_USER_STATUS_ACTIVE, ME_USER_STATUS_BLOCKED, ME_USER_STATUS_ARCHIVED ], true ) ) {
		return 'Недопустимый статус.';
	}

	$current_uid = get_current_user_id();

	// Нельзя менять статус самому себе
	if ( $user_id === $current_uid ) {
		return 'Нельзя изменить статус собственного аккаунта.';
	}

	// Нельзя менять статус user_id === 1 (если вы не он сам)
	if ( $user_id === 1 && $current_uid !== 1 ) {
		return 'Недостаточно прав для изменения этого аккаунта.';
	}

	update_user_meta( $user_id, 'account_status', $status );
	return true;
}
