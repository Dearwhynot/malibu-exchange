<?php
/**
 * Malibu Exchange — Orders AJAX Handlers
 *
 * Действия:
 *   me_orders_list — постраничный список с фильтрами
 *   me_orders_get  — одна запись по ID (для модального окна)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Доступ ───────────────────────────────────────────────────────────────────

function _me_orders_check_access(): void {
	if ( ! is_user_logged_in() || ! crm_can_access( 'orders.view' ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}
}

// ─── 1. Список ордеров ────────────────────────────────────────────────────────

add_action( 'wp_ajax_me_orders_list', 'me_ajax_orders_list' );
function me_ajax_orders_list(): void {
	_me_orders_check_access();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_orders_list' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	global $wpdb;

	// ── Пагинация ─────────────────────────────────────────────────────────────
	$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
	$per_page = (int) ( $_POST['per_page'] ?? 25 );
	if ( ! in_array( $per_page, [ 25, 50, 100 ], true ) ) {
		$per_page = 25;
	}
	$offset = ( $page - 1 ) * $per_page;

	// ── Фильтры ───────────────────────────────────────────────────────────────
	$search     = sanitize_text_field( wp_unslash( $_POST['search']    ?? '' ) );
	$status     = sanitize_key( $_POST['status']     ?? '' );
	$provider   = sanitize_key( $_POST['provider']   ?? '' );
	$company_id = (int) ( $_POST['company_id']        ?? 0 );
	$date_from  = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
	$date_to    = sanitize_text_field( wp_unslash( $_POST['date_to']   ?? '' ) );

	// ── WHERE ─────────────────────────────────────────────────────────────────
	$where  = 'WHERE 1=1';
	$params = [];

	if ( $search !== '' ) {
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$where   .= ' AND (`merchant_order_id` LIKE %s OR `provider_order_id` LIKE %s OR `local_order_ref` LIKE %s OR `notes` LIKE %s)';
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
	}
	if ( $status !== '' ) {
		$where   .= ' AND `status_code` = %s';
		$params[] = $status;
	}
	if ( $provider !== '' ) {
		$where   .= ' AND `provider_code` = %s';
		$params[] = $provider;
	}
	if ( $company_id > 0 ) {
		$where   .= ' AND `company_id` = %d';
		$params[] = $company_id;
	}
	if ( $date_from !== '' && strtotime( $date_from ) ) {
		$where   .= ' AND `created_at` >= %s';
		$params[] = $date_from . ' 00:00:00';
	}
	if ( $date_to !== '' && strtotime( $date_to ) ) {
		$where   .= ' AND `created_at` <= %s';
		$params[] = $date_to . ' 23:59:59';
	}

	// ── Подсчёт ───────────────────────────────────────────────────────────────
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$count_sql = "SELECT COUNT(*) FROM `crm_fintech_payment_orders` {$where}";
	$total     = empty( $params )
		? (int) $wpdb->get_var( $count_sql )
		: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

	$data_sql   = "SELECT * FROM `crm_fintech_payment_orders` {$where} ORDER BY `id` DESC LIMIT %d OFFSET %d";
	$all_params = array_merge( $params, [ $per_page, $offset ] );
	$rows       = $wpdb->get_results( $wpdb->prepare( $data_sql, $all_params ) );
	// phpcs:enable

	$items = [];
	foreach ( (array) $rows as $row ) {
		$items[] = _me_orders_format_row( $row );
	}

	wp_send_json_success( [
		'rows'        => $items,
		'total'       => $total,
		'page'        => $page,
		'per_page'    => $per_page,
		'total_pages' => (int) ceil( $total / $per_page ),
	] );
}

// ─── 2. Одна запись ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_me_orders_get', 'me_ajax_orders_get' );
function me_ajax_orders_get(): void {
	_me_orders_check_access();

	if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'me_orders_list' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	global $wpdb;

	$id = (int) ( $_GET['id'] ?? 0 );
	if ( $id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Неверный ID.' ] );
	}

	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM `crm_fintech_payment_orders` WHERE `id` = %d',
		$id
	) );

	if ( ! $row ) {
		wp_send_json_error( [ 'message' => 'Ордер не найден.' ] );
	}

	wp_send_json_success( _me_orders_format_row( $row, true ) );
}

// ─── 3. Создание ордера ───────────────────────────────────────────────────────

add_action( 'wp_ajax_me_orders_create', 'me_ajax_orders_create' );
function me_ajax_orders_create(): void {
	if ( ! is_user_logged_in() || ! crm_can_access( 'orders.view' ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_orders_create' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	$amount_usdt = isset( $_POST['amount_usdt'] ) ? (float) $_POST['amount_usdt'] : 0;
	if ( $amount_usdt <= 0 ) {
		wp_send_json_error( [ 'message' => 'Укажите корректную сумму USDT (больше нуля).' ] );
	}

	$description = isset( $_POST['description'] )
		? sanitize_text_field( wp_unslash( $_POST['description'] ) )
		: '';

	$result = crm_fintech_create_order(
		$amount_usdt,
		0,                          // company_id — default
		'web',                      // source_channel
		get_current_user_id(),
		$description
	);

	if ( empty( $result['success'] ) ) {
		wp_send_json_error( [ 'message' => $result['error'] ?? 'Ошибка создания ордера.' ] );
	}

	wp_send_json_success( [
		'order_db_id'        => $result['order_db_id'],
		'merchant_order_id'  => $result['merchant_order_id'],
		'provider_order_id'  => $result['provider_order_id'],
		'payment_link'       => $result['payment_link'],
		'qrc_id'             => $result['qrc_id'],
		'qr_url'             => $result['qr_url'],
		'provider'           => $result['provider'],
		'payment_amount_rub' => $result['payment_amount_rub'],
	] );
}

// ─── 4. QR-чек существующего ордера ──────────────────────────────────────────

add_action( 'wp_ajax_me_orders_get_qr', 'me_ajax_orders_get_qr' );
function me_ajax_orders_get_qr(): void {
	_me_orders_check_access();

	if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'me_orders_list' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	global $wpdb;

	$id = (int) ( $_GET['id'] ?? 0 );
	if ( $id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Неверный ID.' ] );
	}

	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT id, merchant_order_id, provider_code, payment_amount_value, payment_currency_code, payment_link, qrc_id, created_at
		 FROM `crm_fintech_payment_orders` WHERE `id` = %d',
		$id
	) );

	if ( ! $row ) {
		wp_send_json_error( [ 'message' => 'Ордер не найден.' ] );
	}

	// Пробуем найти уже сгенерированный файл
	$qr_url = null;

	if ( ! empty( $row->qrc_id ) ) {
		$safe_qrc   = preg_replace( '/[^A-Za-z0-9_\-]/', '', $row->qrc_id );
		$safe_order = preg_replace( '/[^A-Za-z0-9_\-]/', '', $row->merchant_order_id );
		$file_name  = 'qr_' . $safe_qrc . '_' . $safe_order . '.png';
		$abs_path   = get_template_directory() . '/uploadbotfiles/qrcodes/' . $file_name;
		if ( file_exists( $abs_path ) ) {
			$qr_url = get_template_directory_uri() . '/uploadbotfiles/qrcodes/' . $file_name;
		}
	}

	// Fallback: перегенерировать из payment_link
	if ( ! $qr_url && ! empty( $row->payment_link ) ) {
		$qrc_id = ! empty( $row->qrc_id ) ? $row->qrc_id : 'regen_' . $id;
		$qr_url = crm_fintech_qr_url( $row->payment_link, $qrc_id, $row->merchant_order_id );
	}

	wp_send_json_success( [
		'id'                    => (int) $row->id,
		'merchant_order_id'     => $row->merchant_order_id,
		'provider_code'         => $row->provider_code,
		'payment_amount_value'  => $row->payment_amount_value !== null ? (float) $row->payment_amount_value : null,
		'payment_currency_code' => $row->payment_currency_code,
		'created_at'            => $row->created_at,
		'qr_url'                => $qr_url,
	] );
}

// ─── 5. Проверка / отмена статуса ─────────────────────────────────────────────

add_action( 'wp_ajax_me_orders_check_status', 'me_ajax_orders_check_status' );
function me_ajax_orders_check_status(): void {
	_me_orders_check_access();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_orders_list' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	global $wpdb;

	$id     = (int) ( $_POST['id'] ?? 0 );
	$intent = sanitize_key( $_POST['intent'] ?? 'check' ); // 'check' | 'cancel'

	if ( $id <= 0 ) {
		wp_send_json_error( [ 'message' => 'Неверный ID.' ] );
	}

	$order = $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM `crm_fintech_payment_orders` WHERE `id` = %d',
		$id
	) );

	if ( ! $order ) {
		wp_send_json_error( [ 'message' => 'Ордер не найден.' ] );
	}

	$current_status = (string) $order->status_code;
	$terminal       = [ 'paid', 'declined', 'cancelled', 'expired', 'error' ];
	$open           = [ 'created', 'pending' ];

	// Для отмены открытого ордера — нет API
	if ( $intent === 'cancel' && in_array( $current_status, $open, true ) ) {
		wp_send_json_success( [
			'changed'    => false,
			'old_status' => $current_status,
			'new_status' => $current_status,
			'message'    => 'Ручная отмена через API недоступна. Ордер истечёт автоматически по таймауту провайдера.',
			'error'      => null,
		] );
	}

	$poll = crm_fintech_poll_order_status( $order );

	if ( $poll['error'] ) {
		wp_send_json_error( [ 'message' => 'Ошибка проверки статуса: ' . $poll['error'] ] );
	}

	$new_status = $poll['new_status'];

	if ( $new_status === 'paid' ) {
		$rub     = $order->payment_amount_value !== null ? number_format( (float) $order->payment_amount_value, 2, ',', "\xc2\xa0" ) . "\xc2\xa0₽" : '—';
		$message = 'Платёж подтверждён! ' . $rub;
	} elseif ( $poll['changed'] ) {
		$message = 'Статус обновлён: ' . $new_status;
	} elseif ( in_array( $new_status, $terminal, true ) ) {
		$labels  = [ 'paid' => 'Оплачен', 'declined' => 'Отклонён', 'cancelled' => 'Отменён', 'expired' => 'Истёк', 'error' => 'Ошибка' ];
		$message = 'Ордер завершён: ' . ( $labels[ $new_status ] ?? $new_status );
	} else {
		$message = 'Оплата ещё не поступила. Статус: ' . $new_status;
	}

	wp_send_json_success( [
		'changed'    => $poll['changed'],
		'old_status' => $poll['old_status'],
		'new_status' => $poll['new_status'],
		'message'    => $message,
		'error'      => null,
	] );
}

// ─── Форматирование ───────────────────────────────────────────────────────────

function _me_orders_format_row( object $row, bool $full = false ): array {
	$out = [
		'id'                    => (int) $row->id,
		'company_id'            => (int) $row->company_id,
		'office_id'             => $row->office_id ? (int) $row->office_id : null,
		'provider_code'         => $row->provider_code,
		'source_channel'        => $row->source_channel,
		'merchant_order_id'     => $row->merchant_order_id,
		'provider_order_id'     => $row->provider_order_id,
		'status_code'           => $row->status_code,
		'provider_status_code'  => $row->provider_status_code,
		'amount_asset_code'     => $row->amount_asset_code,
		'amount_asset_value'    => (float) $row->amount_asset_value,
		'payment_currency_code' => $row->payment_currency_code,
		'payment_amount_value'  => $row->payment_amount_value !== null ? (float) $row->payment_amount_value : null,
		'expires_at'            => $row->expires_at,
		'paid_at'               => $row->paid_at,
		'created_at'            => $row->created_at,
		'updated_at'            => $row->updated_at,
	];

	if ( $full ) {
		$out['payment_link']                   = $row->payment_link;
		$out['qrc_id']                         = $row->qrc_id;
		$out['provider_public_link']           = $row->provider_public_link;
		$out['provider_requires_verification'] = (bool) $row->provider_requires_verification;
		$out['status_reason']                  = $row->status_reason;
		$out['local_order_ref']                = $row->local_order_ref;
		$out['provider_external_order_id']     = $row->provider_external_order_id;
		$out['callback_url']                   = $row->callback_url;
		$out['first_callback_at']              = $row->first_callback_at;
		$out['last_callback_at']               = $row->last_callback_at;
		$out['last_checked_at']                = $row->last_checked_at;
		$out['next_check_at']                  = $row->next_check_at;
		$out['declined_at']                    = $row->declined_at;
		$out['cancelled_at']                   = $row->cancelled_at;
		$out['expired_at']                     = $row->expired_at;
		$out['notes']                          = $row->notes;
		$out['created_by_user_id']             = $row->created_by_user_id ? (int) $row->created_by_user_id : null;
		$out['meta_json']                      = $row->meta_json;
	}

	return $out;
}
