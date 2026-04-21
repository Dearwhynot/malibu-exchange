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
 * Диагностическая запись в PHP error log по company scope.
 */
function crm_company_scope_error_log( string $label, array $context = [] ): void {
	$payload = '';
	if ( ! empty( $context ) ) {
		$encoded = wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( is_string( $encoded ) && $encoded !== '' ) {
			$payload = ' ' . $encoded;
		}
	}

	error_log( '[CRM_SCOPE] ' . $label . $payload );
}

/**
 * Логирует нарушение company scope в error_log и crm_audit_log.
 */
function crm_log_company_scope_violation( string $event_code, string $message, array $context = [] ): void {
	$user_id = isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id();
	$org_id  = isset( $context['org_id'] ) ? (int) $context['org_id'] : 0;

	if ( $org_id <= 0 ) {
		$org_id = isset( $context['record_company_id'] ) ? (int) $context['record_company_id'] : 0;
	}
	if ( $org_id <= 0 ) {
		$org_id = isset( $context['current_company_id'] ) ? (int) $context['current_company_id'] : 0;
	}

	crm_company_scope_error_log( $event_code, array_merge( [ 'message' => $message ], $context ) );

	crm_log( $event_code, [
		'category'   => 'security',
		'level'      => 'warning',
		'action'     => 'scope',
		'message'    => $message,
		'user_id'    => $user_id,
		'org_id'     => $org_id,
		'is_success' => false,
		'context'    => $context,
	] );
}

/**
 * All active companies, suitable for dropdowns.
 * Returns array of { id, code, name }.
 */
function crm_get_all_companies_list(): array {
	global $wpdb;
	return $wpdb->get_results(
		"SELECT id, code, name
		 FROM crm_companies
		 WHERE status = 'active'
		   AND id > 0
		 ORDER BY id ASC"
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
 * Ordinary CRM user must always belong to exactly one active company (> 0).
 *
 * @param  int $user_id
 * @param  int $company_id  Target company; must be > 0 for non-root users.
 * @param  int $assigned_by uid of operator performing the action.
 * @return bool False when company is invalid/not found/inactive.
 */
function crm_assign_user_to_company( int $user_id, int $company_id, int $assigned_by = 0 ): bool {
	if ( crm_is_root( $user_id ) ) {
		return false;
	}
	global $wpdb;

	crm_ensure_user_account( $user_id );

	if ( $company_id <= 0 ) {
		return false;
	}

	// Verify the company exists and is active.
	$exists = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM crm_companies WHERE id = %d AND status = 'active'",
		$company_id
	) );
	if ( ! $exists ) {
		return false;
	}

	// Clear any current primary for this user only after validation succeeded.
	$wpdb->update(
		'crm_user_companies',
		[ 'is_primary' => 0 ],
		[ 'user_id' => $user_id, 'is_primary' => 1 ],
		[ '%d' ],
		[ '%d', '%d' ]
	);

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

	return true;
}

// ─── Контекст компании (org_id) ──────────────────────────────────────────────

/**
 * Возвращает company_id (= org_id) активного пользователя.
 *
 * uid=1 (root) — возвращает 0: это специальная системная компания root.
 * Обычный пользователь — возвращает только primary company из crm_user_companies.
 * Никаких fallback-ов на default_company_id здесь нет и быть не должно.
 * Нет primary-привязки — возвращает 0 (неконсистентное состояние, доступ должен блокироваться).
 *
 * @param int $user_id  0 = текущий пользователь
 */
function crm_get_current_user_company_id( int $user_id = 0 ): int {
	if ( $user_id === 0 ) {
		$user_id = get_current_user_id();
	}
	// Root (uid=1) — мастер-аккаунт со специальной системной компанией 0.
	// company_id = 0 здесь не означает «без компании», а означает отдельный root-контур.
	// Код, использующий это значение как org_id, должен явно обрабатывать root через crm_is_root().
	if ( crm_is_root( $user_id ) ) {
		return 0;
	}
	$company = crm_get_user_primary_company( $user_id );
	if ( $company ) {
		return (int) $company->id;
	}

	return 0;
}

/**
 * Требует валидный company-context для company-scoped страницы.
 * Root работает в стандартном company-scoped контуре с company_id = 0.
 */
function crm_require_company_page_context(): int {
	$uid = get_current_user_id();
	$company_id = crm_get_current_user_company_id( $uid );

	if ( crm_is_root( $uid ) ) {
		return 0;
	}

	if ( $company_id > 0 ) {
		return $company_id;
	}

	crm_log_company_scope_violation(
		'company.scope.user_without_company',
		'Попытка открыть company-scoped страницу без привязки к компании',
		[
			'user_id'            => $uid,
			'current_company_id' => 0,
			'request_uri'        => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '',
		]
	);

	wp_die(
		'<p style="font-size:16px">Ваш аккаунт ещё не привязан к компании.</p>'
		. '<p>Обратитесь к администратору для получения доступа.</p>',
		'Доступ ограничен — нет компании',
		[ 'response' => 403 ]
	);
}

/**
 * Может ли пользователь видеть данные компании.
 */
function crm_user_can_access_company( int $viewer_user_id, int $company_id ): bool {
	if ( crm_is_root( $viewer_user_id ) ) {
		return $company_id === 0;
	}

	$current_company_id = crm_get_current_user_company_id( $viewer_user_id );

	return $current_company_id > 0 && $company_id > 0 && $current_company_id === $company_id;
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
		crm_log_company_scope_violation(
			'company.scope.user_without_company',
			'Попытка доступа без привязки к компании',
			[
				'user_id'            => $uid,
				'current_company_id' => 0,
				'request_uri'        => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '',
			]
		);

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
		 WHERE c.id > 0
		 GROUP BY c.id
		 ORDER BY c.id ASC"
	) ?: [];
}
