<?php
/**
 * Malibu Exchange — Merchant payouts AJAX handlers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function _me_merchant_payouts_check_view(): void {
	if ( ! is_user_logged_in() || ! crm_can_access( 'merchant_payouts.view' ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}
}

function _me_merchant_payouts_require_company(): int {
	$uid        = get_current_user_id();
	$company_id = crm_get_current_user_company_id( $uid );

	if ( $company_id <= 0 ) {
		crm_log_company_scope_violation(
			'merchant_payouts.scope.invalid_company',
			'Попытка доступа к выплатам мерчантам без валидной компании',
			[
				'user_id'            => $uid,
				'current_company_id' => $company_id,
			]
		);

		wp_send_json_error( [ 'message' => 'Аккаунт не привязан к компании.' ], 403 );
	}

	return $company_id;
}

function _me_merchant_payouts_networks(): array {
	return [
		'TRC20' => 'TRC20',
	];
}

function _me_merchant_payouts_normalize_network( string $network ): string {
	$network = strtoupper( sanitize_key( $network ) );
	return isset( _me_merchant_payouts_networks()[ $network ] ) ? $network : 'TRC20';
}

function _me_merchant_payouts_fetch_merchant( int $merchant_id, int $company_id ): ?object {
	global $wpdb;

	if ( $merchant_id <= 0 || $company_id <= 0 ) {
		return null;
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT *
			 FROM crm_merchants
			 WHERE id = %d
			   AND company_id = %d
			   AND status <> %s
			 LIMIT 1",
			$merchant_id,
			$company_id,
			CRM_MERCHANT_STATUS_ARCHIVED
		)
	);

	return $row ?: null;
}

function _me_merchant_payouts_zero_balance_message(): string {
	return '🚨 Выплата недоступна: у мерчанта сейчас 0 USDT к выплате. Сначала должны появиться начисления на основной баланс.';
}

function _me_merchant_payouts_upload_receipt( array $file ) {
	$allowed_mime = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
	$allowed_ext  = [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ];

	if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
		return new WP_Error( 'upload_error', 'Ошибка загрузки файла (код ' . (int) ( $file['error'] ?? 0 ) . ').' );
	}

	if ( (int) ( $file['size'] ?? 0 ) > 10 * 1024 * 1024 ) {
		return new WP_Error( 'upload_size', 'Файл слишком большой. Максимум 10 МБ.' );
	}

	$original_name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
	$ext           = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
	if ( ! in_array( $ext, $allowed_ext, true ) ) {
		return new WP_Error( 'upload_type', 'Допустимые форматы: JPG, PNG, GIF, WEBP.' );
	}

	$image_info = @getimagesize( (string) ( $file['tmp_name'] ?? '' ) );
	if ( ! $image_info || ! in_array( $image_info['mime'], $allowed_mime, true ) ) {
		return new WP_Error( 'upload_not_image', 'Файл не является изображением.' );
	}

	$dest_dir = get_template_directory() . '/uploadbotfiles/merchant-payout-receipts/';
	if ( ! is_dir( $dest_dir ) ) {
		if ( ! wp_mkdir_p( $dest_dir ) ) {
			return new WP_Error( 'upload_dir', 'Не удалось создать папку для скриншотов выплат.' );
		}
		@file_put_contents( $dest_dir . '.htaccess', "Options -Indexes\n" );
	}

	$filename  = 'merchant_payout_' . time() . '_' . wp_generate_password( 8, false ) . '.' . $ext;
	$dest_path = $dest_dir . $filename;

	if ( ! @move_uploaded_file( (string) $file['tmp_name'], $dest_path ) ) {
		return new WP_Error( 'upload_move', 'Не удалось сохранить файл на сервере.' );
	}

	return $filename;
}

function _me_merchant_payouts_receipt_url( ?string $filename ): string {
	$safe = preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) $filename );
	if ( ! $safe ) {
		return '';
	}

	return get_template_directory_uri() . '/uploadbotfiles/merchant-payout-receipts/' . $safe;
}

function _me_merchant_payouts_delete_receipt( ?string $filename ): void {
	$safe = preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) $filename );
	if ( ! $safe ) {
		return;
	}

	$path = get_template_directory() . '/uploadbotfiles/merchant-payout-receipts/' . $safe;
	if ( is_file( $path ) ) {
		wp_delete_file( $path );
	}
}

add_action( 'wp_ajax_me_merchant_payouts_list', 'me_ajax_merchant_payouts_list' );
function me_ajax_merchant_payouts_list(): void {
	_me_merchant_payouts_check_view();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_merchant_payouts' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	global $wpdb;

	$company_id = _me_merchant_payouts_require_company();
	$page       = max( 1, (int) ( $_POST['page'] ?? 1 ) );
	$per_page   = (int) ( $_POST['per_page'] ?? 25 );
	if ( ! in_array( $per_page, [ 25, 50, 100 ], true ) ) {
		$per_page = 25;
	}
	$offset = ( $page - 1 ) * $per_page;
	$search = trim( sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ) );

	$where  = 'WHERE m.company_id = %d AND m.status <> %s';
	$params = [ $company_id, CRM_MERCHANT_STATUS_ARCHIVED ];

	if ( $search !== '' ) {
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$where .= ' AND (m.name LIKE %s OR m.telegram_username LIKE %s OR CAST(m.chat_id AS CHAR) LIKE %s)';
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
	}

	$count_sql = "SELECT COUNT(*) FROM crm_merchants m {$where}";
	$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

	$data_sql = "
		SELECT m.*
		FROM crm_merchants m
		{$where}
		ORDER BY m.id DESC
		LIMIT %d OFFSET %d
	";
	$rows = $wpdb->get_results( $wpdb->prepare( $data_sql, array_merge( $params, [ $per_page, $offset ] ) ) ) ?: [];

	$merchant_ids = array_map( static fn( $row ) => (int) $row->id, $rows );
	$balances_map = crm_get_merchant_balance_summary_map( $merchant_ids );
	$payouts_map  = [];
	$ledger_map   = [];

	if ( ! empty( $merchant_ids ) ) {
		$sql_ids = implode( ',', array_map( 'intval', $merchant_ids ) );
		$payout_rows = $wpdb->get_results(
			"SELECT merchant_id,
			        COALESCE(SUM(CASE WHEN COALESCE(status_code, 'paid') <> 'cancelled' THEN amount ELSE 0 END), 0) AS paid_total,
			        MAX(CASE WHEN COALESCE(status_code, 'paid') <> 'cancelled' THEN paid_at ELSE NULL END) AS last_paid_at
			 FROM crm_merchant_payouts
			 WHERE company_id = " . (int) $company_id . "
			   AND merchant_id IN ({$sql_ids})
			 GROUP BY merchant_id",
			ARRAY_A
		) ?: [];

		foreach ( $payout_rows as $row ) {
			$payouts_map[ (int) $row['merchant_id'] ] = [
				'paid_total'   => (float) ( $row['paid_total'] ?? 0 ),
				'last_paid_at' => (string) ( $row['last_paid_at'] ?? '' ),
			];
		}

		$ledger_rows = $wpdb->get_results(
			"SELECT merchant_id, MAX(created_at) AS last_movement_at
			 FROM crm_merchant_wallet_ledger
			 WHERE company_id = " . (int) $company_id . "
			   AND merchant_id IN ({$sql_ids})
			 GROUP BY merchant_id",
			ARRAY_A
		) ?: [];

		foreach ( $ledger_rows as $row ) {
			$ledger_map[ (int) $row['merchant_id'] ] = (string) ( $row['last_movement_at'] ?? '' );
		}

		$latest_payout_rows = $wpdb->get_results(
			"SELECT p.id, p.merchant_id, p.status_code, p.paid_at
			 FROM crm_merchant_payouts p
			 INNER JOIN (
			   SELECT merchant_id, MAX(id) AS max_id
			   FROM crm_merchant_payouts
			   WHERE company_id = " . (int) $company_id . "
			     AND merchant_id IN ({$sql_ids})
			   GROUP BY merchant_id
			 ) latest ON latest.max_id = p.id",
			ARRAY_A
		) ?: [];

		foreach ( $latest_payout_rows as $row ) {
			$payouts_map[ (int) $row['merchant_id'] ]['last_payout'] = [
				'id'          => (int) ( $row['id'] ?? 0 ),
				'status_code' => crm_merchant_normalize_payout_status( (string) ( $row['status_code'] ?? '' ) ),
				'paid_at'     => (string) ( $row['paid_at'] ?? '' ),
			];
		}
	}

	$items = [];
	foreach ( $rows as $row ) {
		$merchant_id = (int) $row->id;
		$balance = $balances_map[ $merchant_id ] ?? [
			'main_balance'     => 0.0,
			'bonus_balance'    => 0.0,
			'referral_balance' => 0.0,
			'total_balance'    => 0.0,
		];
		$payout = $payouts_map[ $merchant_id ] ?? [ 'paid_total' => 0.0, 'last_paid_at' => '' ];
		$last_payout = is_array( $payout['last_payout'] ?? null ) ? $payout['last_payout'] : [];
		$last_payout_status = crm_merchant_normalize_payout_status( (string) ( $last_payout['status_code'] ?? '' ) );

		$items[] = [
			'id'                    => $merchant_id,
			'name'                  => (string) ( $row->name ?? '' ),
			'chat_id'               => (string) $row->chat_id,
			'telegram_username'     => (string) ( $row->telegram_username ?? '' ),
			'telegram_avatar_url'   => crm_get_merchant_avatar_url( $row ),
			'status'                => (string) $row->status,
			'status_label'          => crm_merchant_statuses()[ (string) $row->status ] ?? (string) $row->status,
			'status_badge'          => crm_merchant_status_badge_class( (string) $row->status ),
			'main_balance'          => (float) $balance['main_balance'],
			'main_balance_label'    => crm_merchant_format_amount( $balance['main_balance'] ),
			'bonus_balance'         => (float) $balance['bonus_balance'],
			'bonus_balance_label'   => crm_merchant_format_amount( $balance['bonus_balance'] ),
			'referral_balance'      => (float) $balance['referral_balance'],
			'referral_balance_label'=> crm_merchant_format_amount( $balance['referral_balance'] ),
			'total_balance'         => (float) $balance['total_balance'],
			'total_balance_label'   => crm_merchant_format_amount( $balance['total_balance'] ),
			'paid_total'            => (float) $payout['paid_total'],
			'paid_total_label'      => crm_merchant_format_amount( $payout['paid_total'] ),
			'last_paid_at'          => $payout['last_paid_at'] !== '' ? crm_format_dt( $payout['last_paid_at'], $company_id ) : '',
			'last_payout_id'        => (int) ( $last_payout['id'] ?? 0 ),
			'last_payout_status'    => ! empty( $last_payout ) ? $last_payout_status : '',
			'last_payout_status_label' => ! empty( $last_payout ) ? crm_merchant_payout_status_label( $last_payout_status ) : '',
			'last_payout_status_badge' => ! empty( $last_payout ) ? crm_merchant_payout_status_badge( $last_payout_status ) : 'default',
			'last_payout_at'        => ! empty( $last_payout['paid_at'] ) ? crm_format_dt( (string) $last_payout['paid_at'], $company_id ) : '',
			'last_movement_at'      => ! empty( $ledger_map[ $merchant_id ] ) ? crm_format_dt( $ledger_map[ $merchant_id ], $company_id ) : '',
		];
	}

	wp_send_json_success( [
		'rows'        => $items,
		'total'       => $total,
		'page'        => $page,
		'per_page'    => $per_page,
		'total_pages' => (int) ceil( $total / $per_page ),
	] );
}

add_action( 'wp_ajax_me_merchant_payouts_create', 'me_ajax_merchant_payouts_create' );
function me_ajax_merchant_payouts_create(): void {
	if ( ! is_user_logged_in() || ! crm_can_access( 'merchant_payouts.create' ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_merchant_payouts' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	global $wpdb;

	$company_id  = _me_merchant_payouts_require_company();
	$merchant_id = (int) ( $_POST['merchant_id'] ?? 0 );
	$merchant    = _me_merchant_payouts_fetch_merchant( $merchant_id, $company_id );

	if ( ! $merchant ) {
		wp_send_json_error( [ 'message' => 'Мерчант не найден в текущей компании.' ], 404 );
	}

	if ( (string) $merchant->status !== CRM_MERCHANT_STATUS_ACTIVE ) {
		wp_send_json_error( [ 'message' => 'Выплата доступна только активному мерчанту.' ], 422 );
	}

	$amount = isset( $_POST['amount'] ) ? (float) str_replace( ',', '.', (string) wp_unslash( $_POST['amount'] ) ) : 0.0;
	$amount = round( $amount, 8 );
	if ( $amount <= 0 ) {
		wp_send_json_error( [ 'message' => 'Укажите сумму выплаты больше нуля.' ], 422 );
	}

	$balance_map  = crm_get_merchant_balance_summary_map( [ $merchant_id ] );
	$main_balance = (float) ( $balance_map[ $merchant_id ]['main_balance'] ?? 0 );
	if ( $main_balance <= 0 ) {
		wp_send_json_error( [ 'message' => _me_merchant_payouts_zero_balance_message() ], 422 );
	}

	if ( $amount - $main_balance > 0.00000001 ) {
		wp_send_json_error( [
			'message' => 'Сумма выплаты больше текущего баланса к выплате: ' . crm_merchant_format_amount( $main_balance ),
		], 422 );
	}

	$network        = _me_merchant_payouts_normalize_network( (string) wp_unslash( $_POST['network'] ?? 'TRC20' ) );
	$wallet_address = sanitize_text_field( wp_unslash( $_POST['wallet_address'] ?? '' ) );
	$tx_hash        = sanitize_text_field( wp_unslash( $_POST['tx_hash'] ?? '' ) );
	$notes          = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
	$uid            = get_current_user_id();
	$now            = current_time( 'mysql' );

	$receipt_filename = null;
	if ( ! empty( $_FILES['receipt'] ) && (int) ( $_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_NO_FILE ) {
		$upload_result = _me_merchant_payouts_upload_receipt( $_FILES['receipt'] );
		if ( is_wp_error( $upload_result ) ) {
			wp_send_json_error( [ 'message' => $upload_result->get_error_message() ], 422 );
		}
		$receipt_filename = $upload_result;
	}

	$wpdb->query( 'START TRANSACTION' );

	$inserted = $wpdb->insert(
		'crm_merchant_payouts',
		[
			'company_id'          => $company_id,
			'merchant_id'         => $merchant_id,
			'amount'              => number_format( $amount, 8, '.', '' ),
			'currency_code'       => 'USDT',
			'network'             => $network,
			'wallet_address'      => $wallet_address !== '' ? $wallet_address : null,
			'tx_hash'             => $tx_hash !== '' ? $tx_hash : null,
			'receipt_filename'    => $receipt_filename,
			'notes'               => $notes !== '' ? $notes : null,
			'status_code'         => CRM_MERCHANT_PAYOUT_STATUS_PAID,
			'paid_by_user_id'     => $uid > 0 ? $uid : null,
			'paid_at'             => $now,
			'created_at'          => $now,
			'updated_at'          => $now,
		],
		[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
	);

	if ( $inserted === false ) {
		$wpdb->query( 'ROLLBACK' );
		_me_merchant_payouts_delete_receipt( $receipt_filename );
		wp_send_json_error( [ 'message' => 'Ошибка записи выплаты: ' . $wpdb->last_error ], 500 );
	}

	$payout_id = (int) $wpdb->insert_id;
	$comment_parts = [ 'Выплата мерчанту #' . $payout_id ];
	if ( $network !== '' ) {
		$comment_parts[] = 'network: ' . $network;
	}
	if ( $wallet_address !== '' ) {
		$comment_parts[] = 'wallet: ' . $wallet_address;
	}
	if ( $tx_hash !== '' ) {
		$comment_parts[] = 'tx: ' . $tx_hash;
	}
	if ( $notes !== '' ) {
		$comment_parts[] = $notes;
	}

	$ledger_inserted = $wpdb->insert(
		'crm_merchant_wallet_ledger',
		[
			'company_id'          => $company_id,
			'merchant_id'         => $merchant_id,
			'entry_type'          => CRM_MERCHANT_WALLET_ENTRY_MERCHANT_PAYOUT,
			'amount'              => number_format( $amount, 8, '.', '' ),
			'currency_code'       => 'USDT',
			'source_payout_id'    => $payout_id,
			'comment'             => implode( '; ', $comment_parts ),
			'created_by_user_id'  => $uid > 0 ? $uid : null,
			'created_at'          => $now,
		],
		[ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ]
	);

	if ( $ledger_inserted === false ) {
		$wpdb->query( 'ROLLBACK' );
		_me_merchant_payouts_delete_receipt( $receipt_filename );
		wp_send_json_error( [ 'message' => 'Ошибка записи движения баланса: ' . $wpdb->last_error ], 500 );
	}

	$ledger_id = (int) $wpdb->insert_id;
	$wpdb->query( 'COMMIT' );

	crm_log( 'merchant_payouts.create', [
		'category'    => 'payouts',
		'level'       => 'info',
		'action'      => 'create',
		'message'     => sprintf( 'Выплата мерчанту #%d внесена: %s USDT.', $payout_id, number_format( $amount, 8, '.', '' ) ),
		'target_type' => 'merchant_payout',
		'target_id'   => $payout_id,
		'org_id'      => $company_id,
		'is_success'  => true,
		'context'     => [
			'merchant_id' => $merchant_id,
			'amount'      => $amount,
			'network'     => $network,
			'ledger_id'   => $ledger_id,
		],
	] );

	$receipt_url    = _me_merchant_payouts_receipt_url( $receipt_filename );
	$notify_result  = function_exists( 'crm_merchant_tg_notify_payout' )
		? crm_merchant_tg_notify_payout(
			$merchant,
			[
				'payout_id'       => $payout_id,
				'amount'          => $amount,
				'network'         => $network,
				'wallet_address'  => $wallet_address,
				'tx_hash'         => $tx_hash,
				'notes'           => $notes,
				'paid_at'         => $now,
				'receipt_url'     => $receipt_url,
			]
		)
		: [
			'ok'             => false,
			'message_sent'   => false,
			'photo_sent'     => false,
			'receipt_shared' => false,
			'reason'         => 'helper_missing',
		];

	$updated_balance_map = crm_get_merchant_balance_summary_map( [ $merchant_id ] );
	$updated_balance     = $updated_balance_map[ $merchant_id ] ?? [
		'main_balance'     => 0.0,
		'bonus_balance'    => 0.0,
		'referral_balance' => 0.0,
		'total_balance'    => 0.0,
	];

	$toast_type = 'success';
	$message    = 'Выплата мерчанту внесена.';

	if ( ! empty( $notify_result['ok'] ) ) {
		if ( ! empty( $receipt_filename ) && empty( $notify_result['photo_sent'] ) ) {
			$toast_type = 'warning';
			$message    = 'Выплата мерчанту внесена. Уведомление отправлено, но скриншот передан ссылкой.';
		} else {
			$message = 'Выплата мерчанту внесена и отправлена в Telegram.';
		}
	} else {
		$toast_type = 'warning';
		$message    = 'Выплата мерчанту внесена, но уведомление в Telegram не отправлено.';
	}

	wp_send_json_success( [
		'message'              => $message,
		'toast_type'           => $toast_type,
		'payout_id'            => $payout_id,
		'ledger_id'            => $ledger_id,
		'amount'               => $amount,
		'amount_label'         => crm_merchant_format_amount( $amount ),
		'main_balance'         => $updated_balance['main_balance'],
		'main_balance_label'   => crm_merchant_format_amount( $updated_balance['main_balance'] ),
		'receipt_url'          => $receipt_url,
		'notify_sent'          => ! empty( $notify_result['ok'] ),
		'notify_message_sent'  => ! empty( $notify_result['message_sent'] ),
		'notify_photo_sent'    => ! empty( $notify_result['photo_sent'] ),
		'notify_reason'        => (string) ( $notify_result['reason'] ?? '' ),
	] );
}

add_action( 'wp_ajax_me_merchant_payouts_history', 'me_ajax_merchant_payouts_history' );
function me_ajax_merchant_payouts_history(): void {
	_me_merchant_payouts_check_view();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_merchant_payouts' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	global $wpdb;

	$company_id = _me_merchant_payouts_require_company();
	$merchant_id = (int) ( $_POST['merchant_id'] ?? 0 );
	$merchant = _me_merchant_payouts_fetch_merchant( $merchant_id, $company_id );

	if ( ! $merchant ) {
		wp_send_json_error( [ 'message' => 'Мерчант не найден в текущей компании.' ], 404 );
	}

	$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
	$per_page = (int) ( $_POST['per_page'] ?? 10 );
	if ( ! in_array( $per_page, [ 10, 25, 50 ], true ) ) {
		$per_page = 10;
	}
	$offset = ( $page - 1 ) * $per_page;
	$total  = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM crm_merchant_payouts WHERE company_id = %d AND merchant_id = %d',
			$company_id,
			$merchant_id
		)
	);

	$users_table = $wpdb->users;
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.*,
			        payer.display_name AS paid_by_name,
			        canceller.display_name AS cancelled_by_name
			 FROM crm_merchant_payouts p
			 LEFT JOIN {$users_table} payer ON payer.ID = p.paid_by_user_id
			 LEFT JOIN {$users_table} canceller ON canceller.ID = p.cancelled_by_user_id
			 WHERE p.company_id = %d
			   AND p.merchant_id = %d
			 ORDER BY p.id DESC
			 LIMIT %d OFFSET %d",
			$company_id,
			$merchant_id,
			$per_page,
			$offset
		),
		ARRAY_A
	) ?: [];

	$items = [];
	foreach ( $rows as $row ) {
		$status_code = crm_merchant_normalize_payout_status( (string) ( $row['status_code'] ?? '' ) );

		$items[] = [
			'id'                       => (int) ( $row['id'] ?? 0 ),
			'amount'                   => (float) ( $row['amount'] ?? 0 ),
			'amount_label'             => crm_merchant_format_amount( (float) ( $row['amount'] ?? 0 ), (string) ( $row['currency_code'] ?? 'USDT' ) ),
			'currency_code'            => (string) ( $row['currency_code'] ?? 'USDT' ),
			'network'                  => (string) ( $row['network'] ?? '' ),
			'wallet_address'           => (string) ( $row['wallet_address'] ?? '' ),
			'tx_hash'                  => (string) ( $row['tx_hash'] ?? '' ),
			'notes'                    => (string) ( $row['notes'] ?? '' ),
			'receipt_url'              => _me_merchant_payouts_receipt_url( (string) ( $row['receipt_filename'] ?? '' ) ),
			'status_code'              => $status_code,
			'status_label'             => crm_merchant_payout_status_label( $status_code ),
			'status_badge'             => crm_merchant_payout_status_badge( $status_code ),
			'paid_at'                  => ! empty( $row['paid_at'] ) ? crm_format_dt( (string) $row['paid_at'], $company_id ) : '',
			'confirmed_at'             => ! empty( $row['confirmed_at'] ) ? crm_format_dt( (string) $row['confirmed_at'], $company_id ) : '',
			'cancelled_at'             => ! empty( $row['cancelled_at'] ) ? crm_format_dt( (string) $row['cancelled_at'], $company_id ) : '',
			'paid_by_name'             => (string) ( $row['paid_by_name'] ?? '' ),
			'cancelled_by_name'        => (string) ( $row['cancelled_by_name'] ?? '' ),
			'cancellation_reason_code' => (string) ( $row['cancellation_reason_code'] ?? '' ),
			'cancellation_reason_label'=> ! empty( $row['cancellation_reason_code'] ) ? crm_merchant_payout_cancellation_reason_label( (string) $row['cancellation_reason_code'] ) : '',
			'cancellation_comment'     => (string) ( $row['cancellation_comment'] ?? '' ),
			'can_cancel'               => crm_can_access( 'merchant_payouts.create' ) && $status_code === CRM_MERCHANT_PAYOUT_STATUS_PAID,
		];
	}

	wp_send_json_success( [
		'merchant'    => [
			'id'   => (int) $merchant->id,
			'name' => (string) ( $merchant->name ?? '' ),
		],
		'rows'        => $items,
		'total'       => $total,
		'page'        => $page,
		'per_page'    => $per_page,
		'total_pages' => (int) ceil( max( 1, $total ) / $per_page ),
	] );
}

add_action( 'wp_ajax_me_merchant_payouts_cancel', 'me_ajax_merchant_payouts_cancel' );
function me_ajax_merchant_payouts_cancel(): void {
	if ( ! is_user_logged_in() || ! crm_can_access( 'merchant_payouts.create' ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_merchant_payouts' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	$company_id  = _me_merchant_payouts_require_company();
	$payout_id   = (int) ( $_POST['payout_id'] ?? 0 );
	$reason_code = (string) wp_unslash( $_POST['reason_code'] ?? '' );
	$comment     = (string) wp_unslash( $_POST['comment'] ?? '' );
	$user_id     = get_current_user_id();

	$result = crm_merchant_cancel_payout( $company_id, $payout_id, $user_id, $reason_code, $comment );
	if ( empty( $result['success'] ) ) {
		$http_code = 422;
		if ( in_array( (string) ( $result['code'] ?? '' ), [ 'not_found' ], true ) ) {
			$http_code = 404;
		} elseif ( in_array( (string) ( $result['code'] ?? '' ), [ 'already_confirmed' ], true ) ) {
			$http_code = 409;
		}
		wp_send_json_error( [ 'message' => (string) ( $result['error'] ?? 'Не удалось отменить выплату.' ) ], $http_code );
	}

	$payout      = $result['payout'] ?? null;
	$merchant_id = (int) ( $payout->merchant_id ?? 0 );
	$balance_map = crm_get_merchant_balance_summary_map( [ $merchant_id ] );
	$balance     = $balance_map[ $merchant_id ] ?? [
		'main_balance' => 0.0,
	];

	crm_log( 'merchant_payouts.cancel', [
		'category'    => 'payouts',
		'level'       => 'warning',
		'action'      => 'cancel',
		'message'     => sprintf( 'Выплата мерчанту #%d отменена.', $payout_id ),
		'target_type' => 'merchant_payout',
		'target_id'   => $payout_id,
		'org_id'      => $company_id,
		'is_success'  => true,
		'context'     => [
			'merchant_id'              => $merchant_id,
			'status_code'              => (string) ( $payout->status_code ?? CRM_MERCHANT_PAYOUT_STATUS_CANCELLED ),
			'cancellation_reason_code' => crm_merchant_normalize_payout_cancellation_reason( $reason_code ),
			'ledger_id'                => (int) ( $result['ledger_id'] ?? 0 ),
		],
	] );

	wp_send_json_success( [
		'message'            => (string) ( $result['message'] ?? 'Выплата отменена.' ),
		'toast_type'         => 'warning',
		'payout_id'          => $payout_id,
		'status_code'        => (string) ( $payout->status_code ?? CRM_MERCHANT_PAYOUT_STATUS_CANCELLED ),
		'status_label'       => crm_merchant_payout_status_label( (string) ( $payout->status_code ?? CRM_MERCHANT_PAYOUT_STATUS_CANCELLED ) ),
		'main_balance'       => (float) ( $balance['main_balance'] ?? 0 ),
		'main_balance_label' => crm_merchant_format_amount( (float) ( $balance['main_balance'] ?? 0 ) ),
	] );
}
