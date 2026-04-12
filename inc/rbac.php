<?php
/**
 * Malibu Exchange — CRM RBAC
 *
 * Своя система ролей и прав поверх WordPress users.
 * WordPress roles не используются как бизнес-модель.
 *
 * Таблицы:
 *   crm_roles            — бизнес-роли
 *   crm_permissions      — права доступа
 *   crm_role_permissions — связь ролей и прав
 *   crm_user_roles       — назначенные роли пользователям
 *   crm_user_accounts    — статус и профиль пользователя
 *
 * SQL для создания таблиц и seed-данных: inc/sql/rbac.sql
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Константы статусов ──────────────────────────────────────────────────────

define( 'CRM_STATUS_ACTIVE',   'active' );
define( 'CRM_STATUS_BLOCKED',  'blocked' );
define( 'CRM_STATUS_ARCHIVED', 'archived' );
define( 'CRM_STATUS_PENDING',  'pending' );

// ─── Request-level cache ─────────────────────────────────────────────────────

$_crm_account_cache     = [];
$_crm_roles_cache       = [];
$_crm_permissions_cache = [];

// ─── WP-хуки ────────────────────────────────────────────────────────────────

/**
 * Блокируем вход для blocked / archived / pending аккаунтов.
 * Приоритет 40 — после стандартной проверки пароля.
 */
add_filter( 'authenticate', 'crm_authenticate_check_status', 40, 3 );
function crm_authenticate_check_status( $user, $username, $password ) {
	if ( ! ( $user instanceof WP_User ) ) {
		return $user;
	}

	$status = crm_get_user_status( $user->ID );

	if ( $status === CRM_STATUS_BLOCKED ) {
		return new WP_Error( 'crm_blocked', 'Ваш аккаунт заблокирован. Обратитесь к администратору.' );
	}
	if ( $status === CRM_STATUS_ARCHIVED ) {
		return new WP_Error( 'crm_archived', 'Этот аккаунт деактивирован.' );
	}
	if ( $status === CRM_STATUS_PENDING ) {
		return new WP_Error( 'crm_pending', 'Аккаунт ожидает активации.' );
	}

	return $user;
}

/**
 * При каждом запросе авторизованного пользователя —
 * убеждаемся что запись в crm_user_accounts существует.
 */
add_action( 'init', 'crm_init_user_account' );
function crm_init_user_account(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}
	crm_ensure_user_account( get_current_user_id() );
}

// ─── User Account ────────────────────────────────────────────────────────────

/**
 * Создать запись в crm_user_accounts если её нет.
 */
function crm_ensure_user_account( int $user_id ): void {
	global $wpdb, $_crm_account_cache;

	// Уже проверяли в этом запросе
	if ( array_key_exists( $user_id, $_crm_account_cache ) ) {
		return;
	}

	$exists = $wpdb->get_var( $wpdb->prepare(
		'SELECT id FROM crm_user_accounts WHERE user_id = %d',
		$user_id
	) );

	if ( ! $exists ) {
		$wpdb->insert(
			'crm_user_accounts',
			[ 'user_id' => $user_id, 'status' => CRM_STATUS_ACTIVE ],
			[ '%d', '%s' ]
		);
	}

	// Помечаем как проверенный (значение загрузим по требованию)
	$_crm_account_cache[ $user_id ] = null;
}

/**
 * Получить запись crm_user_accounts для пользователя.
 */
function crm_get_user_account( int $user_id ): ?object {
	global $wpdb, $_crm_account_cache;

	if ( array_key_exists( $user_id, $_crm_account_cache ) && $_crm_account_cache[ $user_id ] !== null ) {
		return $_crm_account_cache[ $user_id ];
	}

	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM crm_user_accounts WHERE user_id = %d',
		$user_id
	) );

	$_crm_account_cache[ $user_id ] = $row ?: null;
	return $_crm_account_cache[ $user_id ];
}

/**
 * Получить статус пользователя из crm_user_accounts.
 * Если записи нет — считаем active.
 */
function crm_get_user_status( int $user_id ): string {
	$account = crm_get_user_account( $user_id );
	if ( ! $account ) {
		return CRM_STATUS_ACTIVE;
	}
	$valid = [ CRM_STATUS_ACTIVE, CRM_STATUS_BLOCKED, CRM_STATUS_ARCHIVED, CRM_STATUS_PENDING ];
	return in_array( $account->status, $valid, true ) ? $account->status : CRM_STATUS_ACTIVE;
}

/**
 * Установить статус пользователя в crm_user_accounts.
 */
function crm_set_user_status( int $user_id, string $status ): bool {
	global $wpdb, $_crm_account_cache;

	$valid = [ CRM_STATUS_ACTIVE, CRM_STATUS_BLOCKED, CRM_STATUS_ARCHIVED, CRM_STATUS_PENDING ];
	if ( ! in_array( $status, $valid, true ) ) {
		return false;
	}

	crm_ensure_user_account( $user_id );

	$wpdb->update(
		'crm_user_accounts',
		[ 'status' => $status ],
		[ 'user_id' => $user_id ],
		[ '%s' ],
		[ '%d' ]
	);

	unset( $_crm_account_cache[ $user_id ] );
	return true;
}

/**
 * Обновить поля crm_user_accounts.
 * $data: ['phone', 'telegram_username', 'telegram_id', 'department', 'position_title', 'note', 'status']
 */
function crm_update_user_account( int $user_id, array $data ): void {
	global $wpdb, $_crm_account_cache;

	crm_ensure_user_account( $user_id );

	$allowed_str = [ 'status', 'phone', 'telegram_username', 'department', 'position_title', 'note' ];
	$update      = [];
	$formats     = [];

	foreach ( $allowed_str as $field ) {
		if ( array_key_exists( $field, $data ) ) {
			$update[ $field ] = $data[ $field ];
			$formats[]        = '%s';
		}
	}
	if ( array_key_exists( 'telegram_id', $data ) ) {
		$update['telegram_id'] = $data['telegram_id'] ? (int) $data['telegram_id'] : null;
		$formats[]             = $data['telegram_id'] ? '%d' : '%s'; // NULL via %s
	}

	if ( ! empty( $update ) ) {
		$wpdb->update( 'crm_user_accounts', $update, [ 'user_id' => $user_id ], $formats, [ '%d' ] );
		unset( $_crm_account_cache[ $user_id ] );
	}
}

/**
 * Batch-загрузка crm_user_accounts для списка user_id.
 * Возвращает [ user_id => stdObject ].
 */
function crm_get_accounts_for_users( array $user_ids ): array {
	global $wpdb;
	if ( empty( $user_ids ) ) {
		return [];
	}
	$ids  = implode( ',', array_map( 'intval', $user_ids ) );
	$rows = $wpdb->get_results( "SELECT * FROM crm_user_accounts WHERE user_id IN ($ids)" );
	$map  = [];
	foreach ( $rows as $row ) {
		$map[ (int) $row->user_id ] = $row;
	}
	return $map;
}

// ─── Roles ───────────────────────────────────────────────────────────────────

/**
 * Получить CRM-роли пользователя.
 * Возвращает массив объектов { id, code, name }.
 */
function crm_get_user_roles( int $user_id ): array {
	global $wpdb, $_crm_roles_cache;

	if ( isset( $_crm_roles_cache[ $user_id ] ) ) {
		return $_crm_roles_cache[ $user_id ];
	}

	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT r.id, r.code, r.name
		 FROM crm_user_roles ur
		 JOIN crm_roles r ON r.id = ur.role_id
		 WHERE ur.user_id = %d',
		$user_id
	) );

	$_crm_roles_cache[ $user_id ] = $rows ?: [];
	return $_crm_roles_cache[ $user_id ];
}

/**
 * Проверить наличие конкретной роли.
 * uid=1 считается owner автоматически.
 */
function crm_user_has_role( int $user_id, string $role_code ): bool {
	if ( $user_id === 1 ) {
		return true;
	}
	foreach ( crm_get_user_roles( $user_id ) as $role ) {
		if ( $role->code === $role_code ) {
			return true;
		}
	}
	return false;
}

/**
 * Назначить роли пользователю (заменяет текущие).
 * $role_ids — массив id из crm_roles.
 */
function crm_assign_roles( int $user_id, array $role_ids, int $assigned_by = 0 ): void {
	global $wpdb, $_crm_roles_cache;

	$wpdb->delete( 'crm_user_roles', [ 'user_id' => $user_id ], [ '%d' ] );

	foreach ( $role_ids as $role_id ) {
		$role_id = (int) $role_id;
		if ( $role_id <= 0 ) {
			continue;
		}
		$wpdb->insert(
			'crm_user_roles',
			[
				'user_id'     => $user_id,
				'role_id'     => $role_id,
				'assigned_by' => $assigned_by ?: null,
				'assigned_at' => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%d', '%s' ]
		);
	}

	unset( $_crm_roles_cache[ $user_id ] );
}

/**
 * Все CRM-роли для select-списков.
 */
function crm_get_all_roles(): array {
	global $wpdb;
	return $wpdb->get_results( 'SELECT id, code, name FROM crm_roles ORDER BY id ASC' ) ?: [];
}

/**
 * Batch-загрузка ролей для списка user_id.
 * Возвращает [ user_id => [ {id, code, name}, ... ] ].
 */
function crm_get_roles_for_users( array $user_ids ): array {
	global $wpdb;
	if ( empty( $user_ids ) ) {
		return [];
	}
	$ids  = implode( ',', array_map( 'intval', $user_ids ) );
	$rows = $wpdb->get_results(
		"SELECT ur.user_id, r.id, r.code, r.name
		 FROM crm_user_roles ur
		 JOIN crm_roles r ON r.id = ur.role_id
		 WHERE ur.user_id IN ($ids)"
	);
	$map  = [];
	foreach ( $rows as $row ) {
		$uid = (int) $row->user_id;
		if ( ! isset( $map[ $uid ] ) ) {
			$map[ $uid ] = [];
		}
		$map[ $uid ][] = $row;
	}
	return $map;
}

// ─── Permissions ─────────────────────────────────────────────────────────────

/**
 * Получить все permission-коды пользователя (кешируется на время запроса).
 * uid=1 получает все права без записей в таблице.
 */
function crm_get_user_permissions( int $user_id ): array {
	global $wpdb, $_crm_permissions_cache;

	if ( isset( $_crm_permissions_cache[ $user_id ] ) ) {
		return $_crm_permissions_cache[ $user_id ];
	}

	if ( $user_id === 1 ) {
		$perms = $wpdb->get_col( 'SELECT code FROM crm_permissions' );
		$_crm_permissions_cache[ $user_id ] = $perms ?: [];
		return $_crm_permissions_cache[ $user_id ];
	}

	$perms = $wpdb->get_col( $wpdb->prepare(
		'SELECT DISTINCT p.code
		 FROM crm_user_roles ur
		 JOIN crm_role_permissions rp ON rp.role_id = ur.role_id
		 JOIN crm_permissions p ON p.id = rp.permission_id
		 WHERE ur.user_id = %d',
		$user_id
	) );

	$_crm_permissions_cache[ $user_id ] = $perms ?: [];
	return $_crm_permissions_cache[ $user_id ];
}

/**
 * Проверить наличие права доступа.
 * uid=1 всегда имеет доступ.
 */
function crm_user_has_permission( int $user_id, string $permission_code ): bool {
	if ( $user_id === 1 ) {
		return true;
	}
	return in_array( $permission_code, crm_get_user_permissions( $user_id ), true );
}

// ─── Shortcut helpers ────────────────────────────────────────────────────────

/**
 * Текущий пользователь имеет доступ к разделу CRM.
 * Проверяет: залогинен + статус active + нужный permission.
 */
function crm_can_access( string $permission ): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$uid = get_current_user_id();
	if ( $uid !== 1 && crm_get_user_status( $uid ) !== CRM_STATUS_ACTIVE ) {
		return false;
	}
	return crm_user_has_permission( $uid, $permission );
}

function crm_can_manage_users(): bool {
	return crm_can_access( 'users.view' );
}

// ─── Badge helpers ───────────────────────────────────────────────────────────

function crm_status_badge_class( string $status ): string {
	if ( $status === CRM_STATUS_BLOCKED )  return 'danger';
	if ( $status === CRM_STATUS_ARCHIVED ) return 'secondary';
	if ( $status === CRM_STATUS_PENDING )  return 'warning';
	return 'success';
}

function crm_status_label( string $status ): string {
	$labels = [
		CRM_STATUS_ACTIVE   => 'Active',
		CRM_STATUS_BLOCKED  => 'Blocked',
		CRM_STATUS_ARCHIVED => 'Archived',
		CRM_STATUS_PENDING  => 'Pending',
	];
	return $labels[ $status ] ?? 'Active';
}

function crm_role_badge_class( string $role_code ): string {
	$map = [
		'owner'           => 'danger',
		'admin'           => 'danger',
		'senior_operator' => 'warning',
		'operator'        => 'info',
		'cashier'         => 'primary',
		'compliance'      => 'success',
		'accountant'      => 'success',
		'support'         => 'secondary',
		'auditor'         => 'secondary',
	];
	return $map[ $role_code ] ?? 'secondary';
}
