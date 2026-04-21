<?php
/**
 * Malibu Exchange — Acquirer Payouts AJAX Handlers
 *
 * Выплаты от эквайринг-партнёра (ЭП).
 *
 * Действия:
 *   me_payouts_list   — постраничный список выплат
 *   me_payouts_create — внести новую выплату
 *   me_payouts_stats  — текущие агрегаты (долг ЭП и т.д.)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Доступ ───────────────────────────────────────────────────────────────────

function _me_payouts_check_view(): void {
	if ( ! is_user_logged_in() || ! crm_can_access( 'payouts.view' ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}
}

function _me_payouts_require_current_company(): int {
	$uid        = get_current_user_id();
	$is_root    = crm_is_root( $uid );

	$company_id = crm_get_current_user_company_id( $uid );

	if ( ! $is_root && $company_id <= 0 ) {
		crm_log_company_scope_violation(
			'payouts.scope.user_without_company',
			'Попытка доступа к выплатам без привязки к компании',
			[
				'user_id'            => $uid,
				'current_company_id' => $company_id,
			]
		);

		wp_send_json_error( [ 'message' => 'Аккаунт не привязан к компании.' ], 403 );
	}

	return $company_id;
}

// ─── 1. Список выплат ─────────────────────────────────────────────────────────

add_action( 'wp_ajax_me_payouts_list', 'me_ajax_payouts_list' );
function me_ajax_payouts_list(): void {
	_me_payouts_check_view();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_payouts' ) ) {
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

	// ── Company scope ─────────────────────────────────────────────────────────
	$company_id = _me_payouts_require_current_company();

	// ── WHERE ─────────────────────────────────────────────────────────────────
	$where  = 'WHERE 1=1';
	$params = [];

	$where   .= ' AND p.`company_id` = %d';
	$params[] = $company_id;

	// ── Запрос с именем пользователя ──────────────────────────────────────────
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$count_sql = "SELECT COUNT(*) FROM `crm_acquirer_payouts` p {$where}";
	$total     = empty( $params )
		? (int) $wpdb->get_var( $count_sql )
		: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

	$data_sql   = "
		SELECT p.*, u.display_name AS recorder_name
		FROM `crm_acquirer_payouts` p
		LEFT JOIN `{$wpdb->users}` u ON u.ID = p.recorded_by_user_id
		{$where}
		ORDER BY p.`id` DESC
		LIMIT %d OFFSET %d
	";
	$all_params = array_merge( $params, [ $per_page, $offset ] );
	$rows       = $wpdb->get_results( $wpdb->prepare( $data_sql, $all_params ) );
	// phpcs:enable

	$org_id = $company_id;
	$items  = [];
	foreach ( (array) $rows as $row ) {
		$items[] = _me_payouts_format_row( $row, $org_id );
	}

	wp_send_json_success( [
		'rows'        => $items,
		'total'       => $total,
		'page'        => $page,
		'per_page'    => $per_page,
		'total_pages' => (int) ceil( $total / $per_page ),
	] );
}

// ─── 2. Внести выплату ────────────────────────────────────────────────────────

add_action( 'wp_ajax_me_payouts_create', 'me_ajax_payouts_create' );
function me_ajax_payouts_create(): void {
	if ( ! is_user_logged_in() || ! crm_can_access( 'payouts.create' ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_payouts' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	global $wpdb;

	// ── Валидация суммы ───────────────────────────────────────────────────────
	$amount = isset( $_POST['amount'] ) ? (float) str_replace( ',', '.', $_POST['amount'] ) : 0;
	if ( $amount <= 0 ) {
		wp_send_json_error( [ 'message' => 'Укажите сумму выплаты больше нуля.' ] );
	}

	// ── Поля ─────────────────────────────────────────────────────────────────
	$currency    = 'USDT';
	$period_from = sanitize_text_field( wp_unslash( $_POST['period_from'] ?? '' ) );
	$period_to   = sanitize_text_field( wp_unslash( $_POST['period_to']   ?? '' ) );
	$reference   = sanitize_text_field( wp_unslash( $_POST['reference']   ?? '' ) );
	$notes       = sanitize_textarea_field( wp_unslash( $_POST['notes']   ?? '' ) );

	// Проверка дат
	if ( $period_from !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $period_from ) ) {
		wp_send_json_error( [ 'message' => 'Неверный формат даты «период с».' ] );
	}
	if ( $period_to !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $period_to ) ) {
		wp_send_json_error( [ 'message' => 'Неверный формат даты «период по».' ] );
	}
	if ( $period_from !== '' && $period_to !== '' && $period_from > $period_to ) {
		wp_send_json_error( [ 'message' => '«Период с» не может быть позже «период по».' ] );
	}

	// ── Company scope ─────────────────────────────────────────────────────────
	$uid        = get_current_user_id();
	$company_id = _me_payouts_require_current_company();
	// ── Загрузка квитанции (опционально) ─────────────────────────────────────
	$receipt_filename = null;

	if ( ! empty( $_FILES['receipt'] ) && (int) ( $_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_NO_FILE ) {
		$upload_result = _me_payouts_upload_receipt( $_FILES['receipt'] );
		if ( is_wp_error( $upload_result ) ) {
			wp_send_json_error( [ 'message' => $upload_result->get_error_message() ] );
		}
		$receipt_filename = $upload_result;
	}

	// ── INSERT ────────────────────────────────────────────────────────────────
	// Проверяем, существует ли колонка receipt_filename (миграция 0023 могла не применяться)
	static $_payout_cols_checked = null;
	if ( $_payout_cols_checked === null ) {
		$_payout_cols_checked = $wpdb->get_col( "SHOW COLUMNS FROM `crm_acquirer_payouts`", 0 );
	}
	$has_receipt_col = in_array( 'receipt_filename', $_payout_cols_checked, true );

	$row_data    = [
		'company_id'          => $company_id,
		'amount'              => number_format( $amount, 8, '.', '' ),
		'currency_code'       => $currency,
		'period_from'         => $period_from ?: null,
		'period_to'           => $period_to   ?: null,
		'reference'           => $reference   ?: null,
		'notes'               => $notes       ?: null,
		'recorded_by_user_id' => $uid,
	];
	$row_formats = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ];

	if ( $has_receipt_col ) {
		$row_data['receipt_filename'] = $receipt_filename;
		$row_formats[]               = '%s';
	}

	$inserted = $wpdb->insert( 'crm_acquirer_payouts', $row_data, $row_formats );

	if ( ! $inserted ) {
		// Если INSERT упал — удаляем уже загруженный файл
		if ( $receipt_filename ) {
			$path = get_template_directory() . '/uploadbotfiles/payout-receipts/' . $receipt_filename;
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		wp_send_json_error( [ 'message' => 'Ошибка записи в базу данных: ' . $wpdb->last_error ] );
	}

	$payout_id = (int) $wpdb->insert_id;

	// ── Аудит-лог ─────────────────────────────────────────────────────────────
	crm_log( 'payouts.create', [
		'category'  => 'payouts',
		'action'    => 'create',
		'message'   => sprintf(
			'Выплата ЭП #%d внесена: %s %s (ref: %s)',
			$payout_id,
			number_format( $amount, 2, '.', ' ' ),
			$currency,
			$reference ?: '—'
		),
		'org_id'    => $company_id,
		'target_type' => 'payout',
		'target_id'   => $payout_id,
		'context'   => [
			'payout_id'   => $payout_id,
			'amount'      => $amount,
			'currency'    => $currency,
			'period_from' => $period_from,
			'period_to'   => $period_to,
			'reference'   => $reference,
			'company_id'  => $company_id,
		],
	] );

	// ── Возвращаем текущий долг ───────────────────────────────────────────────
	$stats = _me_payouts_get_stats( $company_id );

	wp_send_json_success( [
		'payout_id'   => $payout_id,
		'amount'      => $amount,
		'ep_debt'     => $stats['ep_debt'],
		'total_paid'  => $stats['total_paid'],
		'total_out'   => $stats['total_out'],
		'message'     => sprintf(
			'Выплата %s USDT внесена. Остаток долга ЭП: %s USDT',
			number_format( $amount, 8, ',', "\xc2\xa0" ),
			number_format( $stats['ep_debt'], 8, ',', "\xc2\xa0" )
		),
	] );
}

// ─── 3. Статистика долга ──────────────────────────────────────────────────────

add_action( 'wp_ajax_me_payouts_stats', 'me_ajax_payouts_stats' );
function me_ajax_payouts_stats(): void {
	_me_payouts_check_view();

	if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'me_payouts' ) ) {
		wp_send_json_error( [ 'message' => 'Нарушена безопасность запроса.' ], 403 );
	}

	$company_id = _me_payouts_require_current_company();

	wp_send_json_success( _me_payouts_get_stats( $company_id ) );
}

// ─── Вспомогательные функции ──────────────────────────────────────────────────

/**
 * Агрегаты в USDT: total_paid (amount_asset_value paid-ордеров), total_out (выплаты), ep_debt (долг).
 * Выплаты ЭП всегда в USDT, поэтому считаем накопленный USDT по paid-ордерам.
 */
function _me_payouts_get_stats( int $company_id ): array {
	global $wpdb;

	if ( $company_id < 0 ) {
		return [
			'total_paid' => 0.0,
			'total_out'  => 0.0,
			'ep_debt'    => 0.0,
		];
	}

	$company_cond_orders  = $wpdb->prepare( ' AND company_id = %d', $company_id );
	$company_cond_payouts = $wpdb->prepare( ' AND company_id = %d', $company_id );

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	// Суммируем USDT (amount_asset_value) по paid-ордерам — именно это должен выплатить ЭП
	$total_paid = (float) $wpdb->get_var(
		"SELECT COALESCE(SUM(amount_asset_value), 0)
		 FROM `crm_fintech_payment_orders`
		 WHERE status_code = 'paid'" . $company_cond_orders
	);

	$total_out = (float) $wpdb->get_var(
		"SELECT COALESCE(SUM(amount), 0)
		 FROM `crm_acquirer_payouts`
		 WHERE 1=1" . $company_cond_payouts
	);
	// phpcs:enable

	$ep_debt = $total_paid - $total_out; // отрицательное = переплата

	return [
		'total_paid' => round( $total_paid, 2 ),
		'total_out'  => round( $total_out, 2 ),
		'ep_debt'    => round( $ep_debt, 2 ),
	];
}

/**
 * Форматировать строку выплаты для JSON-ответа.
 */
function _me_payouts_format_row( object $row, int $org_id = 0 ): array {
	$receipt_url = null;
	if ( ! empty( $row->receipt_filename ) ) {
		$safe = preg_replace( '/[^A-Za-z0-9_\-.]/', '', $row->receipt_filename );
		if ( $safe ) {
			$receipt_url = get_template_directory_uri() . '/uploadbotfiles/payout-receipts/' . $safe;
		}
	}

	return [
		'id'             => (int) $row->id,
		'company_id'     => (int) $row->company_id,
		'amount'         => (float) $row->amount,
		'currency_code'  => $row->currency_code,
		'period_from'    => $row->period_from,
		'period_to'      => $row->period_to,
		'reference'      => $row->reference,
		'notes'          => $row->notes,
		'receipt_url'    => $receipt_url,
		'recorder_name'  => $row->recorder_name ?? null,
		'created_at'     => crm_format_dt( $row->created_at, max( 0, $org_id ) ),
	];
}

/**
 * Загрузить квитанцию в uploadbotfiles/payout-receipts/.
 * Возвращает имя файла или WP_Error.
 *
 * @param array $file  Элемент $_FILES['receipt'].
 * @return string|WP_Error
 */
function _me_payouts_upload_receipt( array $file ) {
	// Разрешённые MIME-типы
	$allowed_mime = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
	$allowed_ext  = [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ];

	// Ошибка загрузки
	if ( $file['error'] !== UPLOAD_ERR_OK ) {
		return new WP_Error( 'upload_error', 'Ошибка загрузки файла (код ' . (int) $file['error'] . ').' );
	}

	// Размер (макс 10 МБ)
	if ( $file['size'] > 10 * 1024 * 1024 ) {
		return new WP_Error( 'upload_size', 'Файл слишком большой. Максимум 10 МБ.' );
	}

	// Расширение
	$original_name = sanitize_file_name( $file['name'] );
	$ext           = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
	if ( ! in_array( $ext, $allowed_ext, true ) ) {
		return new WP_Error( 'upload_type', 'Допустимые форматы: JPG, PNG, GIF, WEBP.' );
	}

	// Проверка реального MIME через getimagesize (не доверяем заголовку)
	$image_info = @getimagesize( $file['tmp_name'] );
	if ( ! $image_info || ! in_array( $image_info['mime'], $allowed_mime, true ) ) {
		return new WP_Error( 'upload_not_image', 'Файл не является изображением.' );
	}

	// Уникальное имя файла
	$dest_dir = get_template_directory() . '/uploadbotfiles/payout-receipts/';

	// Создаём папку если её нет (fallback — на случай если миграция не отработала)
	if ( ! is_dir( $dest_dir ) ) {
		if ( ! wp_mkdir_p( $dest_dir ) ) {
			return new WP_Error( 'upload_dir', 'Не удалось создать папку для квитанций на сервере.' );
		}
		// Запрещаем листинг
		@file_put_contents( $dest_dir . '.htaccess', "Options -Indexes\n" );
	}

	$filename  = 'receipt_' . time() . '_' . wp_generate_password( 8, false ) . '.' . $ext;
	$dest_path = $dest_dir . $filename;

	if ( ! @move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
		return new WP_Error( 'upload_move', 'Не удалось сохранить файл на сервере. Проверьте права на папку uploadbotfiles/payout-receipts/.' );
	}

	return $filename;
}
