<?php
/**
 * Malibu Exchange — Companies Module
 *
 * Helper functions for crm_companies + crm_user_companies operations.
 * Company management is restricted to uid=1 (root).
 * Regular users can only read their assigned company.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All active companies, suitable for dropdowns.
 * Returns array of { id, code, name }.
 */
function crm_get_all_companies_list(): array {
	global $wpdb;
	return $wpdb->get_results(
		"SELECT id, code, name FROM crm_companies WHERE status = 'active' ORDER BY id ASC"
	) ?: [];
}

/**
 * Get primary company assigned to a user.
 * Uses crm_user_companies.is_primary = 1.
 * Returns null if no active primary company is assigned.
 */
function crm_get_user_primary_company( int $user_id ): ?object {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT c.id, c.code, c.name
		 FROM crm_user_companies uc
		 JOIN crm_companies c ON c.id = uc.company_id
		 WHERE uc.user_id = %d AND uc.is_primary = 1 AND uc.status = 'active'
		 LIMIT 1",
		$user_id
	) ) ?: null;
}

/**
 * Batch-load primary companies for a list of user_ids.
 * Returns [ user_id => { id, code, name } ].
 */
function crm_get_companies_for_users( array $user_ids ): array {
	global $wpdb;
	if ( empty( $user_ids ) ) {
		return [];
	}
	$ids  = implode( ',', array_map( 'intval', $user_ids ) );
	$rows = $wpdb->get_results(
		"SELECT uc.user_id, c.id, c.code, c.name
		 FROM crm_user_companies uc
		 JOIN crm_companies c ON c.id = uc.company_id
		 WHERE uc.user_id IN ($ids) AND uc.is_primary = 1 AND uc.status = 'active'"
	);
	$map = [];
	foreach ( $rows as $row ) {
		$map[ (int) $row->user_id ] = $row;
	}
	return $map;
}

/**
 * Assign user to a company (sets as primary, clears previous primary).
 * Also updates crm_user_accounts.default_company_id.
 * Pass $company_id = 0 to remove any company assignment.
 *
 * @param  int $user_id
 * @param  int $company_id  Target company; 0 = remove assignment.
 * @param  int $assigned_by uid of operator performing the action.
 * @return bool False only when $company_id > 0 and company not found/inactive.
 */
function crm_assign_user_to_company( int $user_id, int $company_id, int $assigned_by = 0 ): bool {
	if ( crm_is_root( $user_id ) ) {
		return false;
	}
	global $wpdb;

	// Clear any current primary for this user.
	$wpdb->update(
		'crm_user_companies',
		[ 'is_primary' => 0 ],
		[ 'user_id' => $user_id, 'is_primary' => 1 ],
		[ '%d' ],
		[ '%d', '%d' ]
	);

	if ( $company_id > 0 ) {
		// Verify the company exists and is active.
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM crm_companies WHERE id = %d AND status = 'active'",
			$company_id
		) );
		if ( ! $exists ) {
			return false;
		}

		// Upsert row in crm_user_companies.
		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM crm_user_companies WHERE user_id = %d AND company_id = %d",
			$user_id, $company_id
		) );

		if ( $existing_id ) {
			$wpdb->update(
				'crm_user_companies',
				[
					'is_primary'          => 1,
					'status'              => 'active',
					'assigned_by_user_id' => $assigned_by ?: null,
				],
				[ 'id' => (int) $existing_id ],
				[ '%d', '%s', '%d' ],
				[ '%d' ]
			);
		} else {
			$wpdb->insert(
				'crm_user_companies',
				[
					'user_id'             => $user_id,
					'company_id'          => $company_id,
					'is_primary'          => 1,
					'is_company_admin'    => 0,
					'status'              => 'active',
					'assigned_by_user_id' => $assigned_by ?: null,
				],
				[ '%d', '%d', '%d', '%d', '%s', '%d' ]
			);
		}

		// Update the shortcut column in crm_user_accounts.
		$wpdb->update(
			'crm_user_accounts',
			[ 'default_company_id' => $company_id ],
			[ 'user_id' => $user_id ],
			[ '%d' ],
			[ '%d' ]
		);
	} else {
		// Remove assignment — reset default_company_id to 1 (seed company).
		$wpdb->update(
			'crm_user_accounts',
			[ 'default_company_id' => 1 ],
			[ 'user_id' => $user_id ],
			[ '%d' ],
			[ '%d' ]
		);
	}

	return true;
}

// ─── Контекст компании (org_id) ──────────────────────────────────────────────

/**
 * Возвращает company_id (= org_id) активного пользователя.
 *
 * uid=1 (root) — возвращает CRM_DEFAULT_ORG_ID (1): root управляет дефолтной компанией.
 * Обычный пользователь — возвращает primary company из crm_user_companies.
 * Нет привязки — возвращает 0 (признак "не назначен", операции должны блокироваться).
 *
 * @param int $user_id  0 = текущий пользователь
 */
function crm_get_current_user_company_id( int $user_id = 0 ): int {
	if ( $user_id === 0 ) {
		$user_id = get_current_user_id();
	}
	// Root (uid=1) — мастер-аккаунт, не является участником ни одной компании.
	// company_id = 0 означает «системный уровень, выше компаний».
	// Код, использующий это значение как org_id, должен явно обрабатывать root через crm_is_root().
	if ( crm_is_root( $user_id ) ) {
		return 0;
	}
	$company = crm_get_user_primary_company( $user_id );
	if ( $company ) {
		return (int) $company->id;
	}
	// Fallback: crm_user_companies строка могла отсутствовать (пользователь создан до внедрения
	// company-flow или default_company_id выставлен напрямую). Читаем shortcut-колонку.
	global $wpdb;
	$default = (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT default_company_id FROM crm_user_accounts WHERE user_id = %d',
		$user_id
	) );
	return $default;
}

/**
 * Есть ли у пользователя активная компания (или он root)?
 *
 * @param int $user_id  0 = текущий пользователь
 */
function crm_user_has_company_or_root( int $user_id = 0 ): bool {
	if ( $user_id === 0 ) {
		$user_id = get_current_user_id();
	}
	if ( crm_is_root( $user_id ) ) {
		return true;
	}
	return crm_get_current_user_company_id( $user_id ) > 0;
}

// ─── Gate: блокировка пользователей без компании ──────────────────────────────

/**
 * template_redirect (priority 15) — срабатывает после проверки логина.
 * Если пользователь залогинен, но не имеет компании — выводит 403 с инструкцией.
 * root (uid=1) и страница логина — всегда пропускаются.
 */
add_action( 'template_redirect', 'crm_companies_require_assignment', 15 );
function crm_companies_require_assignment(): void {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		return; // проверку логина делает security.php
	}
	if ( is_page_template( 'page-login.php' ) ) {
		return;
	}

	$uid = get_current_user_id();
	if ( crm_is_root( $uid ) ) {
		return; // root всегда проходит
	}

	if ( ! crm_user_has_company_or_root( $uid ) ) {
		wp_die(
			'<p style="font-size:16px">Ваш аккаунт ещё не привязан к компании.</p>'
			. '<p>Обратитесь к администратору для получения доступа.</p>',
			'Доступ ограничен — нет компании',
			[ 'response' => 403 ]
		);
	}
}

// ─── Список всех компаний (admin) ────────────────────────────────────────────

/**
 * Get all user IDs that belong to a given company (primary assignment, active).
 * Used to scope user queries to a single org.
 */
function crm_get_company_user_ids( int $company_id ): array {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT user_id FROM crm_user_companies WHERE company_id = %d AND is_primary = 1 AND status = 'active'",
		$company_id
	) );
	return array_map( 'intval', $ids ?: [] );
}

/**
 * Get all companies with user counts, for the admin Companies tab.
 * Only uid=1 should call this.
 */
function crm_get_all_companies_full(): array {
	global $wpdb;
	return $wpdb->get_results(
		"SELECT c.id, c.code, c.name, c.status, c.note,
		        c.phone, c.address,
		        COUNT(uc.id) as user_count
		 FROM crm_companies c
		 LEFT JOIN crm_user_companies uc
		        ON uc.company_id = c.id AND uc.is_primary = 1 AND uc.status = 'active'
		 GROUP BY c.id
		 ORDER BY c.id ASC"
	) ?: [];
}
