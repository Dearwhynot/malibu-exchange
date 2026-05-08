<?php
/**
 * Malibu Exchange — Root Rate Pairs AJAX Handlers
 *
 * Root активирует валютные пары и задаёт коэффициент для каждой компании.
 * Страница: page-root-rate-pairs.php
 *
 * Actions:
 *   me_root_rate_pair_save — UPSERT (organization_id, code) пары + коэффициента
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guard: только root.
 */
function _me_root_rate_pairs_root_only(): void {
	if ( ! is_user_logged_in() || ! crm_is_root( get_current_user_id() ) ) {
		error_log( '[root-rate-pairs.ajax] 403 Недостаточно прав. | uid=' . get_current_user_id() );
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}
}

/**
 * Список доступных пар, которые root может активировать для компаний.
 * Расширять через добавление миграции в crm_currencies + строки сюда.
 *
 * @return array<int, array{code:string, title:string, from_code:string, to_code:string, default_coefficient:float}>
 */
function crm_root_available_rate_pairs(): array {
	return [
		[
			'code'                => 'THB_RUB',
			'title'               => 'RUB/THB',
			'from_code'           => 'THB',
			'to_code'             => 'RUB',
			'default_coefficient' => 0.05,
		],
		[
			'code'                => 'USDT_THB',
			'title'               => 'USDT/THB',
			'from_code'           => 'USDT',
			'to_code'             => 'THB',
			'default_coefficient' => 0.05,
		],
		[
			'code'                => 'RUB_USDT',
			'title'               => 'USDT/RUB',
			'from_code'           => 'RUB',
			'to_code'             => 'USDT',
			'default_coefficient' => 0.05,
		],
	];
}

/**
 * Хелпер: получить определение пары по коду.
 *
 * @param string $code
 * @return array<string,mixed>|null
 */
function crm_root_get_pair_definition( string $code ): ?array {
	foreach ( crm_root_available_rate_pairs() as $pair ) {
		if ( $pair['code'] === $code ) {
			return $pair;
		}
	}
	return null;
}

/**
 * Снимок матрицы «компания × пара» для отображения на root-странице.
 *
 * @return array<int, array{
 *   company: object,
 *   pairs: array<int, array{
 *     code:string, title:string, pair_id:?int, is_active:bool, coefficient:?float
 *   }>
 * }>
 */
function crm_root_get_company_pairs_matrix(): array {
	global $wpdb;

	$companies        = crm_get_all_companies_full();
	$available_pairs  = crm_root_available_rate_pairs();
	$rows             = [];

	$existing = $wpdb->get_results(
		"SELECT p.id AS pair_id, p.organization_id, p.code, p.is_active,
		        c.coefficient, c.coefficient_type
		 FROM crm_rate_pairs p
		 LEFT JOIN crm_pair_coefficients c
		        ON c.pair_id = p.id AND c.provider = 'ex24' AND c.source_param = 'phuket'"
	);

	$by_org_code = [];
	foreach ( $existing as $row ) {
		$by_org_code[ (int) $row->organization_id ][ $row->code ] = $row;
	}

	foreach ( $companies as $company ) {
		$company_id = (int) $company->id;
		$pairs_view = [];

		foreach ( $available_pairs as $pair ) {
			$existing_row = $by_org_code[ $company_id ][ $pair['code'] ] ?? null;

			$pairs_view[] = [
				'code'             => $pair['code'],
				'title'            => $pair['title'],
				'from_code'        => $pair['from_code'],
				'to_code'          => $pair['to_code'],
				'pair_id'          => $existing_row ? (int) $existing_row->pair_id : null,
				'is_active'        => $existing_row ? (int) $existing_row->is_active === 1 : false,
				'coefficient'      => $existing_row && $existing_row->coefficient !== null
					? (float) $existing_row->coefficient
					: null,
				'coefficient_type' => $existing_row && ! empty( $existing_row->coefficient_type )
					? (string) $existing_row->coefficient_type
					: 'absolute',
			];
		}

		$rows[] = [
			'company' => $company,
			'pairs'   => $pairs_view,
		];
	}

	return $rows;
}

// ════════════════════════════════════════════════════════════════════════════
// SAVE pair (UPSERT pair + UPSERT coefficient)
// ════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_me_root_rate_pair_save', 'me_ajax_root_rate_pair_save' );
function me_ajax_root_rate_pair_save(): void {
	_me_root_rate_pairs_root_only();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce(
		sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ),
		'me_root_rate_pair_save'
	) ) {
		error_log( '[root-rate-pairs.ajax] 403 nonce | uid=' . get_current_user_id() );
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	$company_id = (int) ( $_POST['company_id'] ?? 0 );
	$code       = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
	$is_active  = ! empty( $_POST['is_active'] ) && $_POST['is_active'] !== '0';

	if ( $company_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Не указана компания.' ], 400 );
	}

	$pair_def = crm_root_get_pair_definition( $code );
	if ( ! $pair_def ) {
		wp_send_json_error( [ 'message' => 'Неизвестная пара: ' . $code ], 400 );
	}

	global $wpdb;

	$company_exists = $wpdb->get_var( $wpdb->prepare(
		'SELECT id FROM crm_companies WHERE id = %d',
		$company_id
	) );
	if ( ! $company_exists ) {
		wp_send_json_error( [ 'message' => 'Компания не найдена.' ], 404 );
	}

	$from_id = (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT id FROM crm_currencies WHERE code = %s',
		$pair_def['from_code']
	) );
	$to_id = (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT id FROM crm_currencies WHERE code = %s',
		$pair_def['to_code']
	) );
	if ( $from_id <= 0 || $to_id <= 0 ) {
		error_log( '[root-rate-pairs.ajax] 500 currencies missing | from=' . $pair_def['from_code'] . ' to=' . $pair_def['to_code'] );
		wp_send_json_error( [ 'message' => 'Справочник валют не содержит ' . $pair_def['from_code'] . '→' . $pair_def['to_code'] . '.' ], 500 );
	}

	// UPSERT pair row.
	$existing_pair_id = (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT id FROM crm_rate_pairs WHERE organization_id = %d AND code = %s LIMIT 1',
		$company_id,
		$code
	) );

	if ( $existing_pair_id > 0 ) {
		$updated = $wpdb->update(
			'crm_rate_pairs',
			[
				'is_active' => $is_active ? 1 : 0,
				'title'     => $pair_def['title'],
			],
			[ 'id' => $existing_pair_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);
		if ( $updated === false ) {
			error_log( '[root-rate-pairs.ajax] 500 pair update failed | db=' . $wpdb->last_error );
			wp_send_json_error( [ 'message' => 'Не удалось обновить пару.' ], 500 );
		}
		$pair_id = $existing_pair_id;
	} else {
		$inserted = $wpdb->insert(
			'crm_rate_pairs',
			[
				'organization_id'  => $company_id,
				'from_currency_id' => $from_id,
				'to_currency_id'   => $to_id,
				'code'             => $code,
				'title'            => $pair_def['title'],
				'is_active'        => $is_active ? 1 : 0,
				'sort_order'       => 10,
			],
			[ '%d', '%d', '%d', '%s', '%s', '%d', '%d' ]
		);
		if ( $inserted === false ) {
			error_log( '[root-rate-pairs.ajax] 500 pair insert failed | db=' . $wpdb->last_error );
			wp_send_json_error( [ 'message' => 'Не удалось создать пару.' ], 500 );
		}
		$pair_id = (int) $wpdb->insert_id;
	}

	// При первой активации создаём пустую запись наценки (значение 0, тип absolute).
	// Дальнейшее редактирование значения/типа — на странице /settings/ компании.
	$existing_coeff_id = (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT id FROM crm_pair_coefficients
		 WHERE pair_id = %d AND provider = %s AND source_param = %s
		 LIMIT 1',
		$pair_id,
		'ex24',
		'phuket'
	) );

	if ( $existing_coeff_id <= 0 ) {
		$wpdb->insert(
			'crm_pair_coefficients',
			[
				'pair_id'          => $pair_id,
				'provider'         => 'ex24',
				'source_param'     => 'phuket',
				'coefficient'      => 0.0,
				'coefficient_type' => 'absolute',
			],
			[ '%d', '%s', '%s', '%f', '%s' ]
		);
	}

	$coeff_full = rates_get_coefficient_full( $pair_id, 'ex24', 'phuket' );

	if ( function_exists( 'crm_log_entity' ) ) {
		crm_log_entity(
			'rate.pair_admin_saved',
			'rates',
			'pair_admin',
			sprintf( 'Root изменил активность пары %s для компании #%d → active=%d', $code, $company_id, $is_active ? 1 : 0 ),
			'rate_pair',
			$pair_id,
			[ 'context' => [
				'company_id' => $company_id,
				'pair_code'  => $code,
				'is_active'  => $is_active,
			] ]
		);
	}

	wp_send_json_success( [
		'message'          => 'Пара сохранена.',
		'pair_id'          => $pair_id,
		'company_id'       => $company_id,
		'code'             => $code,
		'is_active'        => $is_active,
		'coefficient'      => (float) $coeff_full['value'],
		'coefficient_type' => (string) $coeff_full['type'],
	] );
}
