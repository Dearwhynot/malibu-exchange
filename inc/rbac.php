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

// ─── Root guard ──────────────────────────────────────────────────────────────
//
// uid=1 — системный root. Он не является пользователем CRM-уровня.
// Root невидим в любом UI, не может быть целью никакой операции.
// Доступ к root — только через прямой сервер / WordPress admin.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Является ли пользователь системным root.
 * Root (uid=1) существует вне CRM-модели: он над ней, а не внутри.
 */
function crm_is_root( int $user_id ): bool {
	return $user_id === 1;
}

// ─── RBAC Constitution ────────────────────────────────────────────────────────
//
// Это ЕДИНСТВЕННЫЙ авторитетный источник для RBAC:
//   • какие права (permissions) существуют в системе
//   • какие роли получают какие права
//
// Как вносить изменения:
//   1. Правь crm_rbac_permissions() или crm_rbac_role_grants()
//   2. Создай новую миграцию, которая вызывает crm_rbac_sync()
//   3. Задеплой и открой любую страницу — миграция применится автоматически
//
// Никогда не правь rbac.sql для добавления новых прав — он теперь документация.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Полный манифест всех прав доступа системы.
 * Ключ = permission code. Используется как единственный источник для crm_rbac_sync().
 */
function crm_rbac_permissions(): array {
	return [
		// ── Dashboard ────────────────────────────────────────────────────────
		'dashboard.view'     => [ 'module' => 'dashboard', 'action' => 'view',         'name' => 'Просмотр дашборда' ],

		// ── Orders ───────────────────────────────────────────────────────────
		'orders.view'        => [ 'module' => 'orders',    'action' => 'view',         'name' => 'Просмотр ордеров' ],
		'orders.create'      => [ 'module' => 'orders',    'action' => 'create',       'name' => 'Создание ордеров' ],
		'orders.edit'        => [ 'module' => 'orders',    'action' => 'edit',         'name' => 'Редактирование ордеров' ],
		'orders.delete'      => [ 'module' => 'orders',    'action' => 'delete',       'name' => 'Удаление ордеров' ],

		// ── Users ────────────────────────────────────────────────────────────
		'users.view'         => [ 'module' => 'users',     'action' => 'view',         'name' => 'Просмотр пользователей' ],
		'users.create'       => [ 'module' => 'users',     'action' => 'create',       'name' => 'Создание пользователей' ],
		'users.edit'         => [ 'module' => 'users',     'action' => 'edit',         'name' => 'Редактирование пользователей' ],
		'users.block'        => [ 'module' => 'users',     'action' => 'block',        'name' => 'Блокировка пользователей' ],
		'users.delete'       => [ 'module' => 'users',     'action' => 'delete',       'name' => 'Удаление пользователей' ],
		'users.assign_roles' => [ 'module' => 'users',     'action' => 'assign_roles', 'name' => 'Назначение ролей' ],

		// ── Roles ────────────────────────────────────────────────────────────
		'roles.view'         => [ 'module' => 'roles',     'action' => 'view',         'name' => 'Просмотр ролей' ],
		'roles.edit'         => [ 'module' => 'roles',     'action' => 'edit',         'name' => 'Редактирование ролей' ],

		// ── Payments ─────────────────────────────────────────────────────────
		'payments.view'      => [ 'module' => 'payments',  'action' => 'view',         'name' => 'Просмотр платежей' ],
		'payments.confirm'   => [ 'module' => 'payments',  'action' => 'confirm',      'name' => 'Подтверждение платежей' ],
		'payments.reject'    => [ 'module' => 'payments',  'action' => 'reject',       'name' => 'Отклонение платежей' ],

		// ── Acquirer Payouts (выплаты от эквайринг-партнёра) ─────────────────
		'payouts.view'       => [ 'module' => 'payouts',   'action' => 'view',         'name' => 'Просмотр выплат ЭП' ],
		'payouts.create'     => [ 'module' => 'payouts',   'action' => 'create',       'name' => 'Внесение выплат ЭП' ],

		// ── Rates ────────────────────────────────────────────────────────────
		'rates.view'         => [ 'module' => 'rates',     'action' => 'view',         'name' => 'Просмотр курсов' ],
		'rates.edit'         => [ 'module' => 'rates',     'action' => 'edit',         'name' => 'Редактирование курсов' ],

		// ── KYC / AML ────────────────────────────────────────────────────────
		'kyc.view'           => [ 'module' => 'kyc',       'action' => 'view',         'name' => 'Просмотр KYC' ],
		'kyc.review'         => [ 'module' => 'kyc',       'action' => 'review',       'name' => 'Проверка KYC' ],
		'aml.view'           => [ 'module' => 'aml',       'action' => 'view',         'name' => 'Просмотр AML' ],
		'aml.review'         => [ 'module' => 'aml',       'action' => 'review',       'name' => 'Проверка AML' ],

		// ── Reports ──────────────────────────────────────────────────────────
		'reports.view'       => [ 'module' => 'reports',   'action' => 'view',         'name' => 'Просмотр отчётов' ],
		'reports.export'     => [ 'module' => 'reports',   'action' => 'export',       'name' => 'Экспорт отчётов' ],

		// ── Settings ─────────────────────────────────────────────────────────
		'settings.view'      => [ 'module' => 'settings',  'action' => 'view',         'name' => 'Просмотр настроек' ],
		'settings.edit'      => [ 'module' => 'settings',  'action' => 'edit',         'name' => 'Редактирование настроек' ],

		// ── Offices ──────────────────────────────────────────────────────────
		'offices.create'     => [ 'module' => 'offices',   'action' => 'create',       'name' => 'Создание офисов компаний' ],

		// ── Merchants ─────────────────────────────────────────────────────────
		'merchants.view'     => [ 'module' => 'merchants', 'action' => 'view',         'name' => 'Просмотр мерчантов' ],
		'merchants.create'   => [ 'module' => 'merchants', 'action' => 'create',       'name' => 'Создание мерчантов' ],
		'merchants.edit'     => [ 'module' => 'merchants', 'action' => 'edit',         'name' => 'Редактирование мерчантов' ],
		'merchants.block'    => [ 'module' => 'merchants', 'action' => 'block',        'name' => 'Блокировка мерчантов' ],
		'merchants.invite'   => [ 'module' => 'merchants', 'action' => 'invite',       'name' => 'Управление приглашениями мерчантов' ],
		'merchants.ledger'   => [ 'module' => 'merchants', 'action' => 'ledger',       'name' => 'Просмотр ledger мерчантов' ],

		// ── Logs (операционный журнал событий) ───────────────────────────────
		'logs.view'          => [ 'module' => 'logs',      'action' => 'view',         'name' => 'Просмотр журнала событий' ],

		// ── Audit (системный аудит-лог — ядро, только owner/auditor) ─────────
		'audit.view'         => [ 'module' => 'audit',     'action' => 'view',         'name' => 'Просмотр системного аудит-лога' ],
	];
}

/**
 * Декларативная матрица: роль → список прав.
 *
 * Специальные значения в списке:
 *   '*'     — все права из crm_rbac_permissions()
 *   '!code' — исключить конкретное право (работает только после '*')
 *
 * Конституция ядра:
 *   owner  = полный доступ (включая системный audit)
 *   admin  = всё, кроме audit.view (системный аудит — только ядро)
 *   остальные роли = конкретный набор по должности
 */
function crm_rbac_role_grants(): array {
	return [
		'owner' => [
			'*',            // Полный доступ — никаких исключений
		],
		'admin' => [
			'*',
			'!audit.view',  // Системный аудит-лог — только для owner
		],
		'senior_operator' => [
			'dashboard.view',
			'orders.view', 'orders.create', 'orders.edit',
			'users.view',
			'payments.view', 'payments.confirm',
			'payouts.view',
			'rates.view',
			'kyc.view',
			'aml.view',
			'reports.view',
		],
		'operator' => [
			'dashboard.view',
			'orders.view', 'orders.create', 'orders.edit',
			'payments.view',
			'rates.view',
		],
		'cashier' => [
			'dashboard.view',
			'orders.view',
			'payments.view', 'payments.confirm',
			'payouts.view',
			'rates.view',
		],
		'compliance' => [
			'dashboard.view',
			'orders.view',
			'kyc.view', 'kyc.review',
			'aml.view', 'aml.review',
			'reports.view', 'reports.export',
		],
		'accountant' => [
			'dashboard.view',
			'orders.view',
			'payments.view',
			'payouts.view', 'payouts.create',
			'reports.view', 'reports.export',
		],
		'support' => [
			'dashboard.view',
			'orders.view',
			'users.view',
		],
		'auditor' => [
			'dashboard.view',
			'orders.view',
			'audit.view',
			'logs.view',
			'reports.view',
		],
	];
}

/**
 * Синхронизирует БД с конституцией RBAC.
 *
 * Логика аддитивная (INSERT IGNORE):
 *   - добавляет отсутствующие permissions
 *   - добавляет отсутствующие grants роль→право
 *   - не удаляет то, что уже есть (не ломает ручные расширения через UI)
 *
 * Вызывается из миграций при любом изменении конституции.
 * Безопасно запускать повторно — idempotent.
 *
 * @return array Отчёт о применённых изменениях.
 */
function crm_rbac_sync(): array {
	global $wpdb;

	$report          = [];
	$all_permissions = crm_rbac_permissions();
	$all_codes       = array_keys( $all_permissions );

	// ── 1. Синхронизируем crm_permissions ────────────────────────────────────
	$perm_inserted = 0;
	foreach ( $all_permissions as $code => $meta ) {
		$rows = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO `crm_permissions` (`code`, `module`, `action`, `name`) VALUES (%s, %s, %s, %s)",
			$code, $meta['module'], $meta['action'], $meta['name']
		) );
		if ( $rows ) {
			$perm_inserted++;
		}
	}
	$report[] = "crm_permissions: $perm_inserted new rows inserted.";

	// ── 2. Синхронизируем crm_role_permissions ───────────────────────────────
	$grants_inserted = 0;

	foreach ( crm_rbac_role_grants() as $role_code => $grant_spec ) {

		$role_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `crm_roles` WHERE code = %s",
			$role_code
		) );

		if ( ! $role_id ) {
			$report[] = "WARNING: role '$role_code' not found — skipped.";
			continue;
		}

		// Разворачиваем '*' и '!exclusion'
		if ( in_array( '*', $grant_spec, true ) ) {
			$resolved = $all_codes;
			foreach ( $grant_spec as $entry ) {
				if ( strpos( $entry, '!' ) === 0 ) {
					$exclude  = substr( $entry, 1 );
					$resolved = array_values( array_filter( $resolved, fn( $c ) => $c !== $exclude ) );
				}
			}
		} else {
			$resolved = array_values( array_filter( $grant_spec, fn( $g ) => strpos( $g, '!' ) !== 0 ) );
		}

		foreach ( $resolved as $perm_code ) {
			$perm_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM `crm_permissions` WHERE code = %s",
				$perm_code
			) );
			if ( ! $perm_id ) {
				continue;
			}
			$rows = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO `crm_role_permissions` (`role_id`, `permission_id`) VALUES (%d, %d)",
				$role_id, $perm_id
			) );
			if ( $rows ) {
				$grants_inserted++;
			}
		}
	}
	$report[] = "crm_role_permissions: $grants_inserted new grants inserted.";

	return $report;
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

	if ( ! crm_is_root( (int) $user->ID ) && function_exists( 'crm_get_user_company_access_error' ) ) {
		$company_access_error = crm_get_user_company_access_error( (int) $user->ID );
		if ( $company_access_error !== null ) {
			$error_code = (string) ( $company_access_error['code'] ?? 'company_unavailable' );
			$message    = (string) ( $company_access_error['message'] ?? 'Доступ к компании ограничен.' );

			return new WP_Error( 'crm_' . $error_code, $message );
		}
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
	if ( crm_is_root( $user_id ) ) {
		return false; // root не управляется через CRM
	}
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
	if ( crm_is_root( $user_id ) ) {
		return; // root не управляется через CRM
	}
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
	if ( crm_is_root( $user_id ) ) {
		return; // root вне CRM-ролей
	}
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
	if ( $uid !== 1 && function_exists( 'crm_user_has_company_access_or_root' ) && ! crm_user_has_company_access_or_root( $uid ) ) {
		return false;
	}
	return crm_user_has_permission( $uid, $permission );
}

function crm_can_manage_users(): bool {
	return crm_can_access( 'users.view' );
}

function crm_can_manage_merchants(): bool {
	return crm_can_access( 'merchants.view' );
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
