<?php
/**
 * Malibu Exchange — Audit Log AJAX Handlers
 *
 * Действия:
 *   me_logs_list — постраничный список с фильтрами
 *   me_logs_get  — одна запись по ID (для модального окна)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Доступ ───────────────────────────────────────────────────────────────────

function _me_logs_check_access(): void {
	if ( ! is_user_logged_in() || ! crm_can_access( 'logs.view' ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}
}

function _me_logs_require_current_org(): int {
	$uid     = get_current_user_id();
	$is_root = crm_is_root( $uid );

	$org_id = crm_get_current_user_company_id( $uid );

	if ( ! $is_root && $org_id <= 0 ) {
		crm_log_company_scope_violation(
			'logs.scope.user_without_company',
			'Попытка доступа к журналу без привязки к компании',
			[
				'user_id'            => $uid,
				'current_company_id' => $org_id,
			]
		);

		wp_send_json_error( [ 'message' => 'Аккаунт не привязан к компании.' ], 403 );
	}

	return $org_id;
}

function _me_logs_require_row_scope( object $row ): void {
	$uid        = get_current_user_id();
	$record_org = (int) ( $row->organization_id ?? 0 );

	if ( $record_org >= 0 && crm_user_can_access_company( $uid, $record_org ) ) {
		return;
	}

	crm_log_company_scope_violation(
		'logs.scope.cross_company_denied',
		'Попытка доступа к записи журнала другой компании',
		[
			'user_id'            => $uid,
			'current_company_id' => crm_get_current_user_company_id( $uid ),
			'record_company_id'  => $record_org,
			'log_id'             => (int) ( $row->id ?? 0 ),
		]
	);

	wp_send_json_error( [ 'message' => 'Доступ к данным другой компании запрещён.' ], 403 );
}

// ─── 1. Список логов с фильтрами и пагинацией ────────────────────────────────

add_action( 'wp_ajax_me_logs_list', 'me_ajax_logs_list' );
function me_ajax_logs_list(): void {
	_me_logs_check_access();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_logs_list' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	global $wpdb;

	// ── Параметры пагинации ───────────────────────────────────────────────────
	$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
	$per_page = (int) ( $_POST['per_page'] ?? 25 );
	if ( ! in_array( $per_page, [ 25, 50, 100 ], true ) ) {
		$per_page = 25;
	}
	$offset = ( $page - 1 ) * $per_page;

	// ── Фильтры ───────────────────────────────────────────────────────────────
	$search      = sanitize_text_field( wp_unslash( $_POST['search']      ?? '' ) );
	$category    = sanitize_key( $_POST['category']    ?? '' );
	$level       = sanitize_key( $_POST['level']       ?? '' );
	$action      = sanitize_key( $_POST['log_action']   ?? '' ); // 'action' зарезервирован WordPress AJAX
	$target_type = sanitize_key( $_POST['target_type'] ?? '' );
	$user_login  = sanitize_text_field( wp_unslash( $_POST['user_login'] ?? '' ) );
	$date_from   = sanitize_text_field( wp_unslash( $_POST['date_from']  ?? '' ) );
	$date_to     = sanitize_text_field( wp_unslash( $_POST['date_to']    ?? '' ) );
	$is_success  = $_POST['is_success'] ?? '';  // '', '0', '1'
	$event_code  = sanitize_text_field( wp_unslash( $_POST['event_code'] ?? '' ) );

	// ── Org scope ─────────────────────────────────────────────────────────────
	$_logs_org = _me_logs_require_current_org();

	// ── Построить WHERE ───────────────────────────────────────────────────────
	$where  = 'WHERE 1=1';
	$params = [];

	$where   .= ' AND `organization_id` = %d';
	$params[] = $_logs_org;

	if ( $search !== '' ) {
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$where   .= ' AND (`message` LIKE %s OR `user_login` LIKE %s OR `event_code` LIKE %s OR `ip_address` LIKE %s OR `target_type` LIKE %s)';
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
	}
	if ( $category !== '' ) {
		$where   .= ' AND `category` = %s';
		$params[] = $category;
	}
	if ( $level !== '' ) {
		$where   .= ' AND `level` = %s';
		$params[] = $level;
	}
	if ( $action !== '' ) {
		$where   .= ' AND `action` = %s';
		$params[] = $action;
	}
	if ( $target_type !== '' ) {
		$where   .= ' AND `target_type` = %s';
		$params[] = $target_type;
	}
	if ( $user_login !== '' ) {
		$where   .= ' AND `user_login` LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $user_login ) . '%';
	}
	if ( $event_code !== '' ) {
		$where   .= ' AND `event_code` LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $event_code ) . '%';
	}
	// Фильтр по дате: пользователь вводит дату в настроенной таймзоне — конвертируем в UTC для запроса
	$tz = crm_get_timezone( $_logs_org );
	if ( $date_from !== '' && strtotime( $date_from ) ) {
		$dt_from  = new DateTime( $date_from . ' 00:00:00', $tz );
		$dt_from->setTimezone( new DateTimeZone( 'UTC' ) );
		$where   .= ' AND `created_at` >= %s';
		$params[] = $dt_from->format( 'Y-m-d H:i:s' );
	}
	if ( $date_to !== '' && strtotime( $date_to ) ) {
		$dt_to    = new DateTime( $date_to . ' 23:59:59', $tz );
		$dt_to->setTimezone( new DateTimeZone( 'UTC' ) );
		$where   .= ' AND `created_at` <= %s';
		$params[] = $dt_to->format( 'Y-m-d H:i:s' );
	}
	if ( $is_success === '1' ) {
		$where .= ' AND `is_success` = 1';
	} elseif ( $is_success === '0' ) {
		$where .= ' AND `is_success` = 0';
	}

	// ── Итоговый подсчёт ──────────────────────────────────────────────────────
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$count_sql = "SELECT COUNT(*) FROM `crm_audit_log` {$where}";
	$total     = empty( $params )
		? (int) $wpdb->get_var( $count_sql )
		: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

	// ── Данные страницы ───────────────────────────────────────────────────────
	$data_sql = "SELECT * FROM `crm_audit_log` {$where} ORDER BY `id` DESC LIMIT %d OFFSET %d";
	$all_params = array_merge( $params, [ $per_page, $offset ] );
	$rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $all_params ) );
	// phpcs:enable

	// ── Форматирование ────────────────────────────────────────────────────────
	$items = [];
	foreach ( (array) $rows as $row ) {
		$items[] = _me_logs_format_row( $row );
	}

	wp_send_json_success( [
		'rows'       => $items,
		'total'      => $total,
		'page'       => $page,
		'per_page'   => $per_page,
		'total_pages' => (int) ceil( $total / $per_page ),
	] );
}

// ─── 2. Одна запись по ID ─────────────────────────────────────────────────────

add_action( 'wp_ajax_me_logs_get', 'me_ajax_logs_get' );
function me_ajax_logs_get(): void {
	_me_logs_check_access();

	if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'me_logs_list' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	global $wpdb;

	$id  = (int) ( $_GET['id'] ?? 0 );
	if ( $id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Неверный ID.' ] );
	}

	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM `crm_audit_log` WHERE `id` = %d',
		$id
	) );

	if ( ! $row ) {
		wp_send_json_error( [ 'message' => 'Запись не найдена.' ] );
	}

	_me_logs_require_row_scope( $row );

	wp_send_json_success( _me_logs_format_row( $row, true ) );
}

// ─── 3. Уникальные значения для фильтров ─────────────────────────────────────

add_action( 'wp_ajax_me_logs_meta', 'me_ajax_logs_meta' );
function me_ajax_logs_meta(): void {
	_me_logs_check_access();

	if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'me_logs_list' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	global $wpdb;

	$org_id = _me_logs_require_current_org();

	$actions = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT `action`
		 FROM `crm_audit_log`
		 WHERE `action` != '' AND `organization_id` = %d
		 ORDER BY `action` ASC",
		$org_id
	) ) ?: [];
	$target_types = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT `target_type`
		 FROM `crm_audit_log`
		 WHERE `target_type` != '' AND `organization_id` = %d
		 ORDER BY `target_type` ASC",
		$org_id
	) ) ?: [];

	wp_send_json_success( [
		'actions'      => $actions,
		'target_types' => $target_types,
	] );
}

// ─── Форматирование строки ────────────────────────────────────────────────────

function _me_logs_format_row( object $row, bool $full = false ): array {
	$org_id = (int) ( $row->organization_id ?? 0 );
	$format_dt = static function ( ?string $dt ) use ( $org_id ) {
		return crm_format_dt( $dt, max( 0, $org_id ) );
	};

	$out = [
		'id'          => (int) $row->id,
		'created_at'  => $format_dt( $row->created_at ),
		'event_code'  => $row->event_code,
		'category'    => $row->category,
		'level'       => $row->level,
		'user_id'     => $row->user_id ? (int) $row->user_id : null,
		'user_login'  => $row->user_login,
		'target_type' => $row->target_type,
		'target_id'   => $row->target_id ? (int) $row->target_id : null,
		'action'      => $row->action,
		'message'     => $row->message,
		'ip_address'  => $row->ip_address,
		'is_success'  => (bool) $row->is_success,
	];

	if ( $full ) {
		$out['user_agent']   = $row->user_agent;
		$out['request_uri']  = $row->request_uri;
		$out['method']       = $row->method;
		$out['source']       = $row->source;
		$out['context_json'] = $row->context_json;
	}

	return $out;
}
