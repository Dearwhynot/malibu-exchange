<?php
/**
 * Malibu Exchange — Company withdrawals AJAX handlers.
 *
 * Вывод средств компании из собственного profit/wallet balance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function _me_company_withdrawals_check_view(): void {
	if ( ! is_user_logged_in() || ! crm_can_access( 'company_withdrawals.view' ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}
}

function _me_company_withdrawals_require_company(): int {
	$uid        = get_current_user_id();
	$company_id = crm_get_current_user_company_id( $uid );

	if ( crm_is_root( $uid ) || $company_id <= 0 ) {
		crm_log_company_scope_violation(
			'company_withdrawals.scope.invalid_company',
			'Попытка доступа к выводам компании без валидной компании',
			[
				'user_id'            => $uid,
				'current_company_id' => $company_id,
			]
		);

		wp_send_json_error( [ 'message' => 'Выводы доступны только внутри активной компании.' ], 403 );
	}

	return $company_id;
}

function _me_company_withdrawals_table_exists(): bool {
	if ( function_exists( 'malibu_migrations_table_exists' ) ) {
		return malibu_migrations_table_exists( 'crm_company_withdrawals' );
	}

	global $wpdb;
	return (bool) $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', 'crm_company_withdrawals' )
	);
}

function _me_company_withdrawals_networks(): array {
	return [
		'TRC20' => 'TRC20',
		'ERC20' => 'ERC20',
		'BEP20' => 'BEP20',
	];
}

function _me_company_withdrawals_normalize_network( string $network ): string {
	$network = strtoupper( sanitize_key( $network ) );
	return isset( _me_company_withdrawals_networks()[ $network ] ) ? $network : 'TRC20';
}

function crm_company_withdrawals_get_stats( int $company_id ): array {
	global $wpdb;

	if ( $company_id <= 0 ) {
		return [
			'platform_fee_total' => 0.0,
			'withdrawals_total'  => 0.0,
			'available_balance'  => 0.0,
		];
	}

	$company_cond = $wpdb->prepare( ' AND company_id = %d', $company_id );

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	$platform_fee_total = (float) $wpdb->get_var(
		"SELECT COALESCE(SUM(COALESCE(platform_fee_value, merchant_markup_value, 0)), 0)
		 FROM `crm_fintech_payment_orders`
		 WHERE status_code = 'paid'
		   AND created_for_type = 'merchant'" . $company_cond
	);

	$withdrawals_total = 0.0;
	if ( _me_company_withdrawals_table_exists() ) {
		$withdrawals_total = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(amount_usdt), 0)
			 FROM `crm_company_withdrawals`
			 WHERE 1=1" . $company_cond
		);
	}
	// phpcs:enable

	return [
		'platform_fee_total' => round( $platform_fee_total, 8 ),
		'withdrawals_total'  => round( $withdrawals_total, 8 ),
		'available_balance'  => round( $platform_fee_total - $withdrawals_total, 8 ),
	];
}

function _me_company_withdrawals_receipt_url( ?string $filename ): string {
	$filename = (string) $filename;
	if ( $filename === '' ) {
		return '';
	}

	$safe = preg_replace( '/[^A-Za-z0-9_\-.]/', '', $filename );
	return $safe ? get_template_directory_uri() . '/uploadbotfiles/company-withdrawal-receipts/' . $safe : '';
}

function _me_company_withdrawals_delete_receipt( ?string $filename ): void {
	$filename = (string) $filename;
	if ( $filename === '' ) {
		return;
	}

	$safe = preg_replace( '/[^A-Za-z0-9_\-.]/', '', $filename );
	if ( $safe === '' ) {
		return;
	}

	$path = get_template_directory() . '/uploadbotfiles/company-withdrawal-receipts/' . $safe;
	if ( file_exists( $path ) ) {
		wp_delete_file( $path );
	}
}

add_action( 'wp_ajax_me_company_withdrawals_list', 'me_ajax_company_withdrawals_list' );
function me_ajax_company_withdrawals_list(): void {
	_me_company_withdrawals_check_view();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_company_withdrawals' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	if ( ! _me_company_withdrawals_table_exists() ) {
		wp_send_json_error( [ 'message' => 'Таблица выводов компании ещё не создана.' ], 500 );
	}

	global $wpdb;

	$company_id = _me_company_withdrawals_require_company();
	$page       = max( 1, (int) ( $_POST['page'] ?? 1 ) );
	$per_page   = (int) ( $_POST['per_page'] ?? 25 );
	if ( ! in_array( $per_page, [ 25, 50, 100 ], true ) ) {
		$per_page = 25;
	}
	$offset = ( $page - 1 ) * $per_page;

	$where  = 'WHERE w.company_id = %d';
	$params = [ $company_id ];

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM `crm_company_withdrawals` w {$where}",
		$params
	) );

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT w.*, u.display_name AS recorder_name
		 FROM `crm_company_withdrawals` w
		 LEFT JOIN `{$wpdb->users}` u ON u.ID = w.created_by_user_id
		 {$where}
		 ORDER BY w.id DESC
		 LIMIT %d OFFSET %d",
		array_merge( $params, [ $per_page, $offset ] )
	) );
	// phpcs:enable

	$items = [];
	foreach ( (array) $rows as $row ) {
		$items[] = [
			'id'             => (int) $row->id,
			'company_id'     => (int) $row->company_id,
			'amount_usdt'    => (float) $row->amount_usdt,
			'network'        => (string) $row->network,
			'wallet_address' => (string) ( $row->wallet_address ?? '' ),
			'tx_hash'        => (string) ( $row->tx_hash ?? '' ),
			'notes'          => (string) ( $row->notes ?? '' ),
			'receipt_url'    => _me_company_withdrawals_receipt_url( $row->receipt_filename ?? '' ),
			'recorder_name'  => $row->recorder_name ?? '',
			'created_at'     => crm_format_dt( $row->created_at, $company_id ),
		];
	}

	wp_send_json_success( [
		'rows'        => $items,
		'total'       => $total,
		'page'        => $page,
		'per_page'    => $per_page,
		'total_pages' => (int) ceil( $total / $per_page ),
		'stats'       => crm_company_withdrawals_get_stats( $company_id ),
	] );
}

add_action( 'wp_ajax_me_company_withdrawals_create', 'me_ajax_company_withdrawals_create' );
function me_ajax_company_withdrawals_create(): void {
	if ( ! is_user_logged_in() || ! crm_can_access( 'company_withdrawals.create' ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_company_withdrawals' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	if ( ! _me_company_withdrawals_table_exists() ) {
		wp_send_json_error( [ 'message' => 'Таблица выводов компании ещё не создана.' ], 500 );
	}

	global $wpdb;

	$company_id = _me_company_withdrawals_require_company();
	$uid        = get_current_user_id();
	$amount     = isset( $_POST['amount'] ) ? (float) str_replace( ',', '.', (string) wp_unslash( $_POST['amount'] ) ) : 0.0;

	if ( $amount <= 0 ) {
		wp_send_json_error( [ 'message' => 'Укажите сумму вывода больше нуля.' ], 422 );
	}

	$stats             = crm_company_withdrawals_get_stats( $company_id );
	$available_balance = (float) $stats['available_balance'];
	if ( $available_balance <= 0 ) {
		wp_send_json_error( [ 'message' => 'Нет доступного profit/wallet баланса для вывода.' ], 422 );
	}
	if ( $amount > $available_balance + 0.00000001 ) {
		wp_send_json_error(
			[
				'message' => 'Сумма вывода больше доступного баланса: ' . crm_merchant_format_amount( $available_balance ),
			],
			422
		);
	}

	$network        = _me_company_withdrawals_normalize_network( (string) wp_unslash( $_POST['network'] ?? 'TRC20' ) );
	$wallet_address = substr( sanitize_text_field( wp_unslash( $_POST['wallet_address'] ?? '' ) ), 0, 255 );
	$tx_hash        = substr( sanitize_text_field( wp_unslash( $_POST['tx_hash'] ?? '' ) ), 0, 255 );
	$notes          = substr( sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ), 0, 1000 );

	$receipt_filename = null;
	if ( ! empty( $_FILES['receipt'] ) && (int) ( $_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_NO_FILE ) {
		$upload_result = _me_company_withdrawals_upload_receipt( $_FILES['receipt'] );
		if ( is_wp_error( $upload_result ) ) {
			wp_send_json_error( [ 'message' => $upload_result->get_error_message() ], 422 );
		}
		$receipt_filename = $upload_result;
	}

	$inserted = $wpdb->insert(
		'crm_company_withdrawals',
		[
			'company_id'         => $company_id,
			'amount_usdt'        => number_format( $amount, 8, '.', '' ),
			'network'            => $network,
			'wallet_address'     => $wallet_address ?: null,
			'tx_hash'            => $tx_hash ?: null,
			'receipt_filename'   => $receipt_filename,
			'notes'              => $notes ?: null,
			'created_by_user_id' => $uid,
		],
		[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
	);

	if ( ! $inserted ) {
		_me_company_withdrawals_delete_receipt( $receipt_filename );
		wp_send_json_error( [ 'message' => 'Ошибка записи вывода: ' . $wpdb->last_error ], 500 );
	}

	$withdrawal_id = (int) $wpdb->insert_id;

	crm_log( 'company_withdrawals.create', [
		'category'    => 'wallet',
		'action'      => 'create',
		'message'     => sprintf( 'Вывод компании #%d внесён: %s USDT.', $withdrawal_id, number_format( $amount, 8, '.', '' ) ),
		'org_id'      => $company_id,
		'target_type' => 'company_withdrawal',
		'target_id'   => $withdrawal_id,
		'context'     => [
			'withdrawal_id'  => $withdrawal_id,
			'amount_usdt'    => $amount,
			'network'        => $network,
			'wallet_address' => $wallet_address,
			'tx_hash'        => $tx_hash,
			'company_id'     => $company_id,
		],
	] );

	wp_send_json_success( [
		'withdrawal_id' => $withdrawal_id,
		'stats'         => crm_company_withdrawals_get_stats( $company_id ),
		'message'       => 'Вывод компании сохранён.',
	] );
}

function _me_company_withdrawals_upload_receipt( array $file ) {
	$allowed_mime = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
	$allowed_ext  = [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ];

	if ( (int) $file['error'] !== UPLOAD_ERR_OK ) {
		return new WP_Error( 'upload_error', 'Ошибка загрузки файла (код ' . (int) $file['error'] . ').' );
	}

	if ( (int) $file['size'] > 10 * 1024 * 1024 ) {
		return new WP_Error( 'upload_size', 'Файл слишком большой. Максимум 10 МБ.' );
	}

	$original_name = sanitize_file_name( (string) $file['name'] );
	$ext           = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
	if ( ! in_array( $ext, $allowed_ext, true ) ) {
		return new WP_Error( 'upload_type', 'Допустимые форматы: JPG, PNG, GIF, WEBP.' );
	}

	$image_info = @getimagesize( $file['tmp_name'] );
	if ( ! $image_info || ! in_array( $image_info['mime'], $allowed_mime, true ) ) {
		return new WP_Error( 'upload_not_image', 'Файл не является изображением.' );
	}

	$dest_dir = get_template_directory() . '/uploadbotfiles/company-withdrawal-receipts/';
	if ( ! is_dir( $dest_dir ) ) {
		if ( ! wp_mkdir_p( $dest_dir ) ) {
			return new WP_Error( 'upload_dir', 'Не удалось создать папку для скриншотов выводов.' );
		}
		@file_put_contents( $dest_dir . '.htaccess', "Options -Indexes\n" );
	}

	$filename  = 'company_withdrawal_' . time() . '_' . wp_generate_password( 8, false ) . '.' . $ext;
	$dest_path = $dest_dir . $filename;

	if ( ! @move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
		return new WP_Error( 'upload_move', 'Не удалось сохранить файл на сервере.' );
	}

	return $filename;
}
