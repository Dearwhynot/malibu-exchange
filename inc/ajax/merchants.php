<?php
/**
 * Malibu Exchange — Merchants AJAX Handlers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function _me_merchants_error( string $message, int $status = 400 ): void {
	wp_send_json_error( [ 'message' => $message ], $status );
}

function _me_merchants_check_access(): void {
	if ( ! is_user_logged_in() || ! crm_can_manage_merchants() ) {
		_me_merchants_error( 'Недостаточно прав.', 403 );
	}
}

function _me_merchants_requested_company_id(): int {
	$value = $_POST['company_id'] ?? $_GET['company_id'] ?? 0;

	return (int) $value;
}

function _me_merchants_validate_company( int $company_id ): ?object {
	global $wpdb;

	if ( $company_id <= 0 ) {
		return null;
	}

	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, code, name, status
			 FROM crm_companies
			 WHERE id = %d
			 LIMIT 1",
			$company_id
		)
	);
}

/**
 * Root может смотреть все компании на отдельной aggregate-странице merchants.
 * Обычный пользователь всегда ограничен своей компанией.
 */
function _me_merchants_resolve_scope_company_id( bool $allow_root_all = false ): int {
	$uid = get_current_user_id();

	if ( crm_is_root( $uid ) ) {
		$requested_company_id = _me_merchants_requested_company_id();
		if ( $requested_company_id > 0 ) {
			$company = _me_merchants_validate_company( $requested_company_id );
			if ( ! $company || $company->status === 'archived' ) {
				_me_merchants_error( 'Компания не найдена.', 404 );
			}

			return (int) $company->id;
		}

		return $allow_root_all ? 0 : 0;
	}

	$company_id = crm_get_current_user_company_id( $uid );
	if ( $company_id <= 0 ) {
		crm_log_company_scope_violation(
			'merchants.scope.user_without_company',
			'Попытка доступа к мерчантам без привязки к компании',
			[
				'user_id'            => $uid,
				'current_company_id' => $company_id,
			]
		);
		_me_merchants_error( 'Аккаунт не привязан к компании.', 403 );
	}

	$requested_company_id = _me_merchants_requested_company_id();
	if ( $requested_company_id > 0 && $requested_company_id !== $company_id ) {
		crm_log_company_scope_violation(
			'merchants.scope.cross_company_denied',
			'Попытка запроса мерчантов другой компании',
			[
				'user_id'            => $uid,
				'current_company_id' => $company_id,
				'record_company_id'  => $requested_company_id,
			]
		);
		_me_merchants_error( 'Доступ к другой компании запрещён.', 403 );
	}

	return $company_id;
}

function _me_merchants_fetch_row( int $merchant_id ): ?object {
	global $wpdb;

	if ( $merchant_id <= 0 ) {
		return null;
	}

	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT m.*,
			        c.name AS company_name,
			        c.code AS company_code,
			        ref.name AS referred_by_name,
			        ref.chat_id AS referred_by_chat_id
			 FROM crm_merchants m
			 JOIN crm_companies c ON c.id = m.company_id
			 LEFT JOIN crm_merchants ref ON ref.id = m.referred_by_merchant_id
			 WHERE m.id = %d
			 LIMIT 1",
			$merchant_id
		)
	);
}

function _me_merchants_require_row_scope( object $row ): void {
	$uid               = get_current_user_id();
	$record_company_id = (int) ( $row->company_id ?? 0 );

	if ( crm_merchant_viewer_can_access_company( $uid, $record_company_id ) ) {
		return;
	}

	crm_log_company_scope_violation(
		'merchants.scope.cross_company_denied',
		'Попытка доступа к мерчанту другой компании',
		[
			'user_id'            => $uid,
			'current_company_id' => crm_get_current_user_company_id( $uid ),
			'record_company_id'  => $record_company_id,
			'merchant_id'        => (int) ( $row->id ?? 0 ),
		]
	);

	_me_merchants_error( 'Доступ к данным другой компании запрещён.', 403 );
}

function _me_merchants_sanitize_chat_id( $raw_value ): string {
	$value = trim( (string) wp_unslash( $raw_value ) );
	if ( $value === '' ) {
		return '';
	}

	return preg_match( '/^-?\d+$/', $value ) ? $value : '';
}

function _me_merchants_validate_referred_by( int $company_id, int $merchant_id, int $referred_by_merchant_id ): ?int {
	global $wpdb;

	if ( $referred_by_merchant_id <= 0 ) {
		return null;
	}

	if ( $merchant_id > 0 && $merchant_id === $referred_by_merchant_id ) {
		_me_merchants_error( 'Мерчант не может ссылаться сам на себя.', 422 );
	}

	$exists = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id
			 FROM crm_merchants
			 WHERE id = %d
			   AND company_id = %d
			 LIMIT 1",
			$referred_by_merchant_id,
			$company_id
		)
	);

	if ( $exists <= 0 ) {
		_me_merchants_error( 'Реферер не найден в этой компании.', 422 );
	}

	return $referred_by_merchant_id;
}

function _me_merchants_decode_prefill_json( $raw_value ): array {
	$decoded = json_decode( (string) $raw_value, true );

	return is_array( $decoded ) ? $decoded : [];
}

function _me_merchants_prepare_invite_prefill_from_request(): array {
	$markup_type = sanitize_key( wp_unslash( $_POST['base_markup_type'] ?? 'percent' ) );
	$markup_value = trim( (string) wp_unslash( $_POST['base_markup_value'] ?? '0' ) );
	$note = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

	if ( ! isset( crm_merchant_markup_types()[ $markup_type ] ) ) {
		$markup_type = 'percent';
	}
	if ( $markup_value === '' || ! is_numeric( $markup_value ) ) {
		$markup_value = '0';
	}

	return [
		'base_markup_type'  => $markup_type,
		'base_markup_value' => number_format( (float) $markup_value, 8, '.', '' ),
		'note'              => $note !== '' ? $note : '',
	];
}

function _me_merchants_format_invite_row( object $row ): array {
	$status       = (string) $row->status;
	$prefill      = _me_merchants_decode_prefill_json( $row->prefill_json ?? '' );
	$invite_url   = crm_build_merchant_invite_url_from_row( $row );
	$qr_url       = $invite_url !== ''
		? crm_get_merchant_invite_qr_url( $invite_url, (int) $row->company_id, (string) ( $row->telegram_start_payload ?? '' ) )
		: '';
	$created_at_ts = ! empty( $row->created_at ) ? strtotime( (string) $row->created_at . ' UTC' ) : false;
	$expires_at_ts = ! empty( $row->expires_at ) ? strtotime( (string) $row->expires_at . ' UTC' ) : false;
	$used_at_ts    = ! empty( $row->used_at ) ? strtotime( (string) $row->used_at . ' UTC' ) : false;
	$creator_name = '';
	if ( ! empty( $row->creator_display_name ) ) {
		$creator_name = (string) $row->creator_display_name;
	} elseif ( ! empty( $row->creator_login ) ) {
		$creator_name = (string) $row->creator_login;
	}

	$ttl_minutes = 0;
	if ( false !== $created_at_ts && false !== $expires_at_ts ) {
		$ttl_minutes = max( 0, (int) round( ( $expires_at_ts - $created_at_ts ) / MINUTE_IN_SECONDS ) );
	}

	return [
		'id'                    => (int) $row->id,
		'merchant_id'           => ! empty( $row->merchant_id ) ? (int) $row->merchant_id : null,
		'merchant_name'         => (string) ( $row->merchant_name ?? '' ),
		'merchant_chat_id'      => isset( $row->merchant_chat_id ) ? (string) $row->merchant_chat_id : '',
		'merchant_avatar_url'   => (string) ( $row->merchant_avatar_url ?? '' ),
		'invite_token'          => (string) $row->invite_token,
		'telegram_start_payload'=> (string) ( $row->telegram_start_payload ?? '' ),
		'invite_url'            => $invite_url,
		'qr_url'                => $qr_url,
		'chat_id'               => isset( $row->chat_id ) ? (string) $row->chat_id : '',
		'status'                => $status,
		'status_label'          => crm_merchant_invite_statuses()[ $status ] ?? $status,
		'status_badge'          => crm_merchant_invite_badge_class( $status ),
		'expires_at'            => (string) ( $row->expires_at ?? '' ),
		'expires_at_ts'         => false !== $expires_at_ts ? (int) $expires_at_ts : null,
		'used_at'               => (string) ( $row->used_at ?? '' ),
		'used_at_ts'            => false !== $used_at_ts ? (int) $used_at_ts : null,
		'used_by_chat_id'       => isset( $row->used_by_chat_id ) ? (string) $row->used_by_chat_id : '',
		'created_at'            => (string) ( $row->created_at ?? '' ),
		'created_at_ts'         => false !== $created_at_ts ? (int) $created_at_ts : null,
		'created_by_user_id'    => ! empty( $row->created_by_user_id ) ? (int) $row->created_by_user_id : null,
		'created_by_name'       => $creator_name,
		'bot_username_snapshot' => (string) ( $row->bot_username_snapshot ?? '' ),
		'ttl_minutes'           => $ttl_minutes,
		'base_markup_type'      => (string) ( $prefill['base_markup_type'] ?? 'percent' ),
		'base_markup_value'     => (string) ( $prefill['base_markup_value'] ?? '0' ),
		'note'                  => (string) ( $prefill['note'] ?? '' ),
	];
}

function _me_merchants_format_row( object $row, array $balance = [] ): array {
	$status             = (string) $row->status;
	$bonus_balance      = (float) ( $balance['bonus_balance'] ?? 0 );
	$referral_balance   = (float) ( $balance['referral_balance'] ?? 0 );
	$total_balance      = (float) ( $balance['total_balance'] ?? 0 );
	$markup_value       = (float) ( $row->base_markup_value ?? 0 );
	$markup_type        = (string) ( $row->base_markup_type ?? 'percent' );
	$markup_value_label = number_format( $markup_value, 8, '.', '' );
	$markup_value_label = rtrim( rtrim( $markup_value_label, '0' ), '.' );
	if ( $markup_value_label === '' ) {
		$markup_value_label = '0';
	}
	if ( $markup_type === 'percent' ) {
		$markup_value_label .= '%';
	}

	return [
		'id'                    => (int) $row->id,
		'company_id'            => (int) $row->company_id,
		'company_name'          => (string) ( $row->company_name ?? '' ),
		'company_code'          => (string) ( $row->company_code ?? '' ),
		'chat_id'               => (string) $row->chat_id,
		'telegram_username'     => (string) ( $row->telegram_username ?? '' ),
		'telegram_first_name'   => (string) ( $row->telegram_first_name ?? '' ),
		'telegram_last_name'    => (string) ( $row->telegram_last_name ?? '' ),
		'telegram_language_code'=> (string) ( $row->telegram_language_code ?? '' ),
		'telegram_avatar_url'   => crm_get_merchant_avatar_url( $row ),
		'name'                  => (string) ( $row->name ?? '' ),
		'status'                => $status,
		'status_label'          => crm_merchant_statuses()[ $status ] ?? $status,
		'status_badge'          => crm_merchant_status_badge_class( $status ),
		'base_markup_type'      => $markup_type,
		'base_markup_type_label'=> crm_merchant_markup_type_label( $markup_type ),
		'base_markup_value'     => number_format( $markup_value, 8, '.', '' ),
		'base_markup_label'     => $markup_value_label,
		'ref_code'              => (string) ( $row->ref_code ?? '' ),
		'referred_by_merchant_id' => $row->referred_by_merchant_id ? (int) $row->referred_by_merchant_id : null,
		'referred_by_name'      => (string) ( $row->referred_by_name ?? '' ),
		'referred_by_chat_id'   => isset( $row->referred_by_chat_id ) ? (string) $row->referred_by_chat_id : '',
		'note'                  => (string) ( $row->note ?? '' ),
		'created_at'            => (string) ( $row->created_at ?? '' ),
		'updated_at'            => (string) ( $row->updated_at ?? '' ),
		'invited_via_invite_id' => ! empty( $row->invited_via_invite_id ) ? (int) $row->invited_via_invite_id : null,
		'invited_at'            => (string) ( $row->invited_at ?? '' ),
		'activated_at'          => (string) ( $row->activated_at ?? '' ),
		'bonus_balance'         => $bonus_balance,
		'bonus_balance_label'   => crm_merchant_format_amount( $bonus_balance ),
		'referral_balance'      => $referral_balance,
		'referral_balance_label'=> crm_merchant_format_amount( $referral_balance ),
		'total_balance'         => $total_balance,
		'total_balance_label'   => crm_merchant_format_amount( $total_balance ),
	];
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Список мерчантов
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_me_merchants_list', 'me_ajax_merchants_list' );
function me_ajax_merchants_list(): void {
	_me_merchants_check_access();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_merchants_list' ) ) {
		_me_merchants_error( 'Нарушена безопасность запроса.', 403 );
	}

	global $wpdb;

	$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
	$per_page = (int) ( $_POST['per_page'] ?? 25 );
	if ( ! in_array( $per_page, [ 25, 50, 100 ], true ) ) {
		$per_page = 25;
	}
	$offset = ( $page - 1 ) * $per_page;

	$search      = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
	$status      = sanitize_key( $_POST['status'] ?? '' );
	$date_from   = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
	$date_to     = sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) );
	$scope_company_id = _me_merchants_resolve_scope_company_id( true );

	$where  = 'WHERE 1=1';
	$params = [];

	if ( $search !== '' ) {
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$where   .= ' AND (m.chat_id LIKE %s OR m.telegram_username LIKE %s OR m.name LIKE %s)';
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
	}

	if ( $status !== '' && isset( crm_merchant_statuses()[ $status ] ) ) {
		$where   .= ' AND m.status = %s';
		$params[] = $status;
	}

	if ( $scope_company_id > 0 ) {
		$where   .= ' AND m.company_id = %d';
		$params[] = $scope_company_id;
	}

	$tz = new DateTimeZone( 'UTC' );
	if ( $scope_company_id > 0 ) {
		$tz = crm_get_timezone( $scope_company_id );
	}

	if ( $date_from !== '' && strtotime( $date_from ) ) {
		$dt_from = new DateTime( $date_from . ' 00:00:00', $tz );
		$dt_from->setTimezone( new DateTimeZone( 'UTC' ) );
		$where   .= ' AND m.created_at >= %s';
		$params[] = $dt_from->format( 'Y-m-d H:i:s' );
	}

	if ( $date_to !== '' && strtotime( $date_to ) ) {
		$dt_to = new DateTime( $date_to . ' 23:59:59', $tz );
		$dt_to->setTimezone( new DateTimeZone( 'UTC' ) );
		$where   .= ' AND m.created_at <= %s';
		$params[] = $dt_to->format( 'Y-m-d H:i:s' );
	}

	$from_sql = "
		FROM crm_merchants m
		JOIN crm_companies c ON c.id = m.company_id
		LEFT JOIN crm_merchants ref ON ref.id = m.referred_by_merchant_id
	";

	$count_sql = "SELECT COUNT(*) {$from_sql} {$where}";
	$total     = empty( $params )
		? (int) $wpdb->get_var( $count_sql )
		: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

	$data_sql = "
		SELECT m.*,
		       c.name AS company_name,
		       c.code AS company_code,
		       ref.name AS referred_by_name,
		       ref.chat_id AS referred_by_chat_id
		{$from_sql}
		{$where}
		ORDER BY m.id DESC
		LIMIT %d OFFSET %d
	";

	$rows = $wpdb->get_results(
		$wpdb->prepare( $data_sql, array_merge( $params, [ $per_page, $offset ] ) )
	) ?: [];

	$merchant_ids = array_map( static fn( $row ) => (int) $row->id, $rows );
	$balances_map = crm_get_merchant_balance_summary_map( $merchant_ids );

	$items = [];
	foreach ( $rows as $row ) {
		$items[] = _me_merchants_format_row( $row, $balances_map[ (int) $row->id ] ?? [] );
	}

	wp_send_json_success( [
		'rows'        => $items,
		'total'       => $total,
		'page'        => $page,
		'per_page'    => $per_page,
		'total_pages' => (int) ceil( $total / $per_page ),
	] );
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. Одна карточка мерчанта
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_me_merchants_get', 'me_ajax_merchants_get' );
function me_ajax_merchants_get(): void {
	_me_merchants_check_access();

	if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'me_merchants_save' ) ) {
		_me_merchants_error( 'Нарушена безопасность запроса.', 403 );
	}

	$merchant_id = (int) ( $_GET['id'] ?? 0 );
	if ( $merchant_id <= 0 ) {
		_me_merchants_error( 'Неверный ID мерчанта.', 422 );
	}

	$row = _me_merchants_fetch_row( $merchant_id );
	if ( ! $row ) {
		_me_merchants_error( 'Мерчант не найден.', 404 );
	}

	_me_merchants_require_row_scope( $row );

	$balances = crm_get_merchant_balance_summary_map( [ $merchant_id ] );
	wp_send_json_success( _me_merchants_format_row( $row, $balances[ $merchant_id ] ?? [] ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. Создать / обновить мерчанта
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_me_merchants_save', 'me_ajax_merchants_save' );
function me_ajax_merchants_save(): void {
	_me_merchants_check_access();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_merchants_save' ) ) {
		_me_merchants_error( 'Нарушена безопасность запроса.', 403 );
	}

	global $wpdb;

	$current_uid   = get_current_user_id();
	$merchant_id   = (int) ( $_POST['merchant_id'] ?? 0 );
	$is_new        = $merchant_id <= 0;
	$chat_id       = _me_merchants_sanitize_chat_id( $_POST['chat_id'] ?? '' );
	$username      = ltrim( sanitize_text_field( wp_unslash( $_POST['telegram_username'] ?? '' ) ), '@' );
	$first_name    = sanitize_text_field( wp_unslash( $_POST['telegram_first_name'] ?? '' ) );
	$last_name     = sanitize_text_field( wp_unslash( $_POST['telegram_last_name'] ?? '' ) );
	$language_code = sanitize_text_field( wp_unslash( $_POST['telegram_language_code'] ?? '' ) );
	$name          = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	$status        = sanitize_key( $_POST['status'] ?? CRM_MERCHANT_STATUS_ACTIVE );
	$markup_type   = sanitize_key( $_POST['base_markup_type'] ?? 'percent' );
	$markup_value  = trim( (string) wp_unslash( $_POST['base_markup_value'] ?? '0' ) );
	$ref_code      = sanitize_text_field( wp_unslash( $_POST['ref_code'] ?? '' ) );
	$note          = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
	$referred_by   = (int) ( $_POST['referred_by_merchant_id'] ?? 0 );

	if ( $is_new && ! crm_can_access( 'merchants.create' ) ) {
		_me_merchants_error( 'Недостаточно прав на создание мерчантов.', 403 );
	}
	if ( ! $is_new && ! crm_can_access( 'merchants.edit' ) ) {
		_me_merchants_error( 'Недостаточно прав на редактирование мерчантов.', 403 );
	}

	if ( $chat_id === '' ) {
		_me_merchants_error( 'Укажите корректный chat_id.', 422 );
	}

	if ( ! isset( crm_merchant_statuses()[ $status ] ) ) {
		$status = CRM_MERCHANT_STATUS_ACTIVE;
	}

	if ( ! isset( crm_merchant_markup_types()[ $markup_type ] ) ) {
		$markup_type = 'percent';
	}

	if ( $markup_value === '' || ! is_numeric( $markup_value ) ) {
		_me_merchants_error( 'Наценка должна быть числом.', 422 );
	}
	$markup_value = number_format( (float) $markup_value, 8, '.', '' );

	if ( $is_new ) {
		$company_id = crm_is_root( $current_uid )
			? (int) ( $_POST['company_id'] ?? 0 )
			: crm_get_current_user_company_id( $current_uid );

		$company = _me_merchants_validate_company( $company_id );
		if ( ! $company || $company_id <= 0 || $company->status === 'archived' ) {
			_me_merchants_error( 'Компания не найдена.', 422 );
		}
	} else {
		$existing = _me_merchants_fetch_row( $merchant_id );
		if ( ! $existing ) {
			_me_merchants_error( 'Мерчант не найден.', 404 );
		}
		_me_merchants_require_row_scope( $existing );
		$company_id = (int) $existing->company_id;
	}

	$referred_by = _me_merchants_validate_referred_by( $company_id, $merchant_id, $referred_by );

	$duplicate_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id
			 FROM crm_merchants
			 WHERE company_id = %d
			   AND chat_id = %s
			   AND id != %d
			 LIMIT 1",
			$company_id,
			$chat_id,
			$merchant_id
		)
	);
	if ( $duplicate_id > 0 ) {
		_me_merchants_error( 'Мерчант с таким chat_id уже существует в этой компании.', 409 );
	}

	$data = [
		'company_id'             => $company_id,
		'chat_id'                => $chat_id,
		'telegram_username'      => $username !== '' ? $username : null,
		'telegram_first_name'    => $first_name !== '' ? $first_name : null,
		'telegram_last_name'     => $last_name !== '' ? $last_name : null,
		'telegram_language_code' => $language_code !== '' ? strtolower( $language_code ) : null,
		'name'                   => $name !== '' ? $name : null,
		'status'                 => $status,
		'base_markup_type'       => $markup_type,
		'base_markup_value'      => $markup_value,
		'ref_code'               => $ref_code !== '' ? $ref_code : null,
		'referred_by_merchant_id'=> $referred_by,
		'note'                   => $note !== '' ? $note : null,
		'updated_by_user_id'     => $current_uid,
	];

	if ( $is_new ) {
		$data['created_by_user_id'] = $current_uid;
		$wpdb->insert(
			'crm_merchants',
			$data,
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d' ]
		);

		$merchant_id = (int) $wpdb->insert_id;
		if ( $merchant_id <= 0 ) {
			_me_merchants_error( 'Не удалось создать мерчанта.', 500 );
		}

		crm_sync_merchant_referral_link( $company_id, $merchant_id, (int) $referred_by );

		crm_log_entity(
			'merchant.created',
			'users',
			'create',
			'Создан новый мерчант',
			'merchant',
			$merchant_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'chat_id'            => $chat_id,
					'telegram_username'  => $username !== '' ? $username : null,
					'telegram_first_name'=> $first_name !== '' ? $first_name : null,
					'telegram_last_name' => $last_name !== '' ? $last_name : null,
					'base_markup_type'   => $markup_type,
					'base_markup_value'  => $markup_value,
					'referred_by_id'     => $referred_by ?: null,
				],
			]
		);
	} else {
		$wpdb->update(
			'crm_merchants',
			$data,
			[ 'id' => $merchant_id ],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' ],
			[ '%d' ]
		);

		crm_sync_merchant_referral_link( $company_id, $merchant_id, (int) $referred_by );

		crm_log_entity(
			'merchant.updated',
			'users',
			'update',
			'Обновлён мерчант',
			'merchant',
			$merchant_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'chat_id'            => $chat_id,
					'telegram_username'  => $username !== '' ? $username : null,
					'telegram_first_name'=> $first_name !== '' ? $first_name : null,
					'telegram_last_name' => $last_name !== '' ? $last_name : null,
					'status'             => $status,
					'base_markup_type'   => $markup_type,
					'base_markup_value'  => $markup_value,
					'referred_by_id'     => $referred_by ?: null,
				],
			]
		);
	}

	$row      = _me_merchants_fetch_row( $merchant_id );
	$balances = crm_get_merchant_balance_summary_map( [ $merchant_id ] );

	wp_send_json_success( [
		'message'  => $is_new ? 'Мерчант создан.' : 'Мерчант обновлён.',
		'merchant' => _me_merchants_format_row( $row, $balances[ $merchant_id ] ?? [] ),
	] );
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. Смена статуса мерчанта
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_me_merchants_status', 'me_ajax_merchants_status' );
function me_ajax_merchants_status(): void {
	_me_merchants_check_access();

	if ( ! crm_can_access( 'merchants.block' ) ) {
		_me_merchants_error( 'Недостаточно прав.', 403 );
	}

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_merchants_status' ) ) {
		_me_merchants_error( 'Нарушена безопасность запроса.', 403 );
	}

	global $wpdb;

	$merchant_id = (int) ( $_POST['merchant_id'] ?? 0 );
	$status      = sanitize_key( $_POST['status'] ?? '' );

	if ( $merchant_id <= 0 || ! isset( crm_merchant_statuses()[ $status ] ) ) {
		_me_merchants_error( 'Некорректные параметры.', 422 );
	}

	$row = _me_merchants_fetch_row( $merchant_id );
	if ( ! $row ) {
		_me_merchants_error( 'Мерчант не найден.', 404 );
	}

	_me_merchants_require_row_scope( $row );

	$activation_transition = $status === CRM_MERCHANT_STATUS_ACTIVE && (string) $row->status !== CRM_MERCHANT_STATUS_ACTIVE;
	$update_data = [
		'status'             => $status,
		'updated_by_user_id' => get_current_user_id(),
	];
	$update_format = [ '%s', '%d' ];
	if ( $activation_transition && empty( $row->activated_at ) ) {
		$update_data['activated_at'] = current_time( 'mysql', true );
		$update_format[] = '%s';
	}

	$wpdb->update(
		'crm_merchants',
		$update_data,
		[ 'id' => $merchant_id ],
		$update_format,
		[ '%d' ]
	);

	crm_log_entity(
		'merchant.status_changed',
		'users',
		'update',
		'Изменён статус мерчанта',
		'merchant',
		$merchant_id,
		[
			'org_id'  => (int) $row->company_id,
			'context' => [
				'old_status' => (string) $row->status,
				'new_status' => $status,
			],
		]
	);

	$fresh    = _me_merchants_fetch_row( $merchant_id );
	$balances = crm_get_merchant_balance_summary_map( [ $merchant_id ] );

	if ( $activation_transition && $fresh && function_exists( 'crm_merchant_tg_notify_activation' ) ) {
		crm_merchant_tg_notify_activation( $fresh );
	}

wp_send_json_success( [
		'message'  => 'Статус обновлён.',
		'merchant' => _me_merchants_format_row( $fresh, $balances[ $merchant_id ] ?? [] ),
	] );
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. Hard-delete мерчанта (root only)
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_me_merchants_delete', 'me_ajax_merchants_delete' );
function me_ajax_merchants_delete(): void {
	_me_merchants_check_access();

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_merchants_delete' ) ) {
		_me_merchants_error( 'Нарушена безопасность запроса.', 403 );
	}

	$current_uid = get_current_user_id();
	if ( ! crm_merchants_can_hard_delete() ) {
		crm_log_security(
			'merchant.delete.forbidden',
			'delete',
			'Попытка физического удаления мерчанта без root-доступа',
			[
				'target_type' => 'merchant',
				'target_id'   => (int) ( $_POST['merchant_id'] ?? 0 ),
				'context'     => [ 'user_id' => $current_uid ],
			]
		);
		_me_merchants_error( 'Недостаточно прав.', 403 );
	}

	$merchant_id = (int) ( $_POST['merchant_id'] ?? 0 );
	if ( $merchant_id <= 0 ) {
		_me_merchants_error( 'Неверный ID мерчанта.', 422 );
	}

	$row = _me_merchants_fetch_row( $merchant_id );
	if ( ! $row ) {
		_me_merchants_error( 'Мерчант не найден.', 404 );
	}

	$result = crm_hard_delete_merchant( $merchant_id, $current_uid );
	if ( is_wp_error( $result ) ) {
		$code       = $result->get_error_code();
		$message    = $result->get_error_message();
		$error_data = $result->get_error_data();
		$blockers   = is_array( $error_data ) ? (array) ( $error_data['blockers'] ?? [] ) : [];

		if ( $code === 'merchant_has_history' ) {
			crm_log_entity(
				'merchant.delete_blocked_history',
				'users',
				'delete',
				'Удаление мерчанта заблокировано связанными данными',
				'merchant',
				$merchant_id,
				[
					'org_id'  => (int) $row->company_id,
					'context' => [
						'attempted_by' => $current_uid,
						'blockers'     => $blockers,
					],
				]
			);
			_me_merchants_error( $message !== '' ? $message : 'Удаление запрещено: у мерчанта есть связанные данные.', 409 );
		}

		_me_merchants_error( $message !== '' ? $message : 'Не удалось удалить мерчанта.', 500 );
	}

	wp_send_json_success( [
		'message'    => 'Мерчант удалён физически.',
		'merchant_id'=> (int) $result['id'],
	] );
}

// ─────────────────────────────────────────────────────────────────────────────
// 6. Company-level Telegram invites
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_me_merchants_telegram_status', 'me_ajax_merchants_telegram_status' );
function me_ajax_merchants_telegram_status(): void {
	_me_merchants_check_access();

	if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'me_merchants_invite' ) ) {
		_me_merchants_error( 'Нарушена безопасность запроса.', 403 );
	}

	$company_id = _me_merchants_resolve_scope_company_id( false );
	if ( $company_id <= 0 ) {
		_me_merchants_error( 'Нужен контекст компании.', 422 );
	}

	wp_send_json_success( [
		'telegram_status' => crm_telegram_get_configuration_status( $company_id ),
	] );
}

add_action( 'wp_ajax_me_merchants_telegram_invite_create', 'me_ajax_merchants_telegram_invite_create' );
function me_ajax_merchants_telegram_invite_create(): void {
	_me_merchants_check_access();

	if ( ! crm_can_access( 'merchants.invite' ) ) {
		_me_merchants_error( 'Недостаточно прав.', 403 );
	}

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_merchants_invite' ) ) {
		_me_merchants_error( 'Нарушена безопасность запроса.', 403 );
	}

	global $wpdb;

	$company_id = _me_merchants_resolve_scope_company_id( false );
	if ( $company_id <= 0 ) {
		_me_merchants_error( 'Нужен контекст компании.', 422 );
	}

	$telegram_status = crm_telegram_get_configuration_status( $company_id );
	if ( empty( $telegram_status['invite_ready'] ) ) {
		$message = 'Инвайт в Telegram недоступен. Для создания ссылки заполните настройки Telegram-бота: имя бота и токен. Затем подключите callback в настройках компании.';
		if ( ! empty( $telegram_status['blocked_reason'] ) ) {
			$message = 'Инвайт в Telegram недоступен. ' . (string) $telegram_status['blocked_reason'];
		}
		_me_merchants_error( $message, 422 );
	}

	$settings       = crm_merchants_get_settings( $company_id );
	$ttl            = max( 1, (int) $settings['invite_ttl_minutes'] );
	$invite_token   = crm_generate_merchant_invite_token();
	$start_payload  = crm_generate_merchant_invite_start_payload();
	$expires_at     = gmdate( 'Y-m-d H:i:s', time() + ( $ttl * MINUTE_IN_SECONDS ) );
	$prefill        = _me_merchants_prepare_invite_prefill_from_request();
	$prefill_json   = wp_json_encode( $prefill, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$created_by_uid = get_current_user_id();

	$wpdb->insert(
		'crm_merchant_invites',
		[
			'company_id'             => $company_id,
			'merchant_id'            => null,
			'invite_token'           => $invite_token,
			'telegram_start_payload' => $start_payload,
			'bot_username_snapshot'  => (string) ( $telegram_status['bot_username'] ?? '' ),
			'prefill_json'           => $prefill_json !== false ? $prefill_json : null,
			'chat_id'                => null,
			'status'                 => 'new',
			'expires_at'             => $expires_at,
			'created_by_user_id'     => $created_by_uid,
			'created_at'             => current_time( 'mysql', true ),
		],
		[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
	);

	$invite_id = (int) $wpdb->insert_id;
	if ( $invite_id <= 0 ) {
		_me_merchants_error( 'Не удалось создать Telegram-инвайт.', 500 );
	}

	$user         = get_userdata( $created_by_uid );
	$creator_name = $user instanceof WP_User ? ( $user->display_name ?: $user->user_login ) : '';

	crm_log_entity(
		'merchant.invite.created',
		'users',
		'create',
		'Создан Telegram-инвайт мерчанта',
		'merchant_invite',
		$invite_id,
		[
			'org_id'  => $company_id,
			'context' => [
				'ttl_minutes'       => $ttl,
				'base_markup_type'  => $prefill['base_markup_type'] ?? 'percent',
				'base_markup_value' => $prefill['base_markup_value'] ?? '0',
			],
		]
	);

	wp_send_json_success( [
		'message' => 'Telegram-инвайт создан.',
		'invite'  => _me_merchants_format_invite_row(
			(object) [
				'id'                    => $invite_id,
				'company_id'            => $company_id,
				'merchant_id'           => null,
				'merchant_name'         => '',
				'merchant_chat_id'      => '',
				'merchant_avatar_url'   => '',
				'invite_token'          => $invite_token,
				'telegram_start_payload'=> $start_payload,
				'bot_username_snapshot' => (string) ( $telegram_status['bot_username'] ?? '' ),
				'prefill_json'          => $prefill_json !== false ? $prefill_json : '',
				'chat_id'               => '',
				'status'                => 'new',
				'expires_at'            => $expires_at,
				'created_at'            => current_time( 'mysql', true ),
				'created_by_user_id'    => $created_by_uid,
				'creator_display_name'  => $creator_name,
				'creator_login'         => $user instanceof WP_User ? $user->user_login : '',
			]
		),
		'telegram_status' => $telegram_status,
	] );
}

add_action( 'wp_ajax_me_merchants_telegram_invites_history', 'me_ajax_merchants_telegram_invites_history' );
function me_ajax_merchants_telegram_invites_history(): void {
	_me_merchants_check_access();

	if ( ! crm_can_access( 'merchants.invite' ) ) {
		_me_merchants_error( 'Недостаточно прав.', 403 );
	}

	if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'me_merchants_invite' ) ) {
		_me_merchants_error( 'Нарушена безопасность запроса.', 403 );
	}

	global $wpdb;

	$company_id = _me_merchants_resolve_scope_company_id( false );
	if ( $company_id <= 0 ) {
		_me_merchants_error( 'Нужен контекст компании.', 422 );
	}

	crm_expire_merchant_invites( $company_id );

	$page       = max( 1, (int) ( $_GET['page'] ?? 1 ) );
	$per_page   = (int) ( $_GET['per_page'] ?? 25 );
	$per_page   = in_array( $per_page, [ 25, 50, 100 ], true ) ? $per_page : 25;
	$offset     = ( $page - 1 ) * $per_page;
	$merchant_id = (int) ( $_GET['merchant_id'] ?? 0 );
	$search     = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
	$status     = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
	$date_from  = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
	$date_to    = sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) );
	$where      = 'WHERE i.company_id = %d';
	$params     = [ $company_id ];

	if ( $merchant_id > 0 ) {
		$merchant_row = _me_merchants_fetch_row( $merchant_id );
		if ( ! $merchant_row ) {
			_me_merchants_error( 'Мерчант не найден.', 404 );
		}
		_me_merchants_require_row_scope( $merchant_row );
		if ( (int) $merchant_row->company_id !== $company_id ) {
			_me_merchants_error( 'Доступ к данным другой компании запрещён.', 403 );
		}

		$where   .= ' AND i.merchant_id = %d';
		$params[] = $merchant_id;
	}

	if ( $search !== '' ) {
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$where .= ' AND (i.telegram_start_payload LIKE %s OR i.used_by_chat_id LIKE %s OR m.chat_id LIKE %s OR m.telegram_username LIKE %s OR m.name LIKE %s)';
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
	}

	if ( $status !== '' && isset( crm_merchant_invite_statuses()[ $status ] ) ) {
		$where .= ' AND i.status = %s';
		$params[] = $status;
	}

	if ( $date_from !== '' && strtotime( $date_from ) ) {
		$where .= ' AND i.created_at >= %s';
		$params[] = gmdate( 'Y-m-d 00:00:00', strtotime( $date_from ) );
	}
	if ( $date_to !== '' && strtotime( $date_to ) ) {
		$where .= ' AND i.created_at <= %s';
		$params[] = gmdate( 'Y-m-d 23:59:59', strtotime( $date_to ) );
	}

	$from_sql = "
		FROM crm_merchant_invites i
		LEFT JOIN crm_merchants m ON m.id = i.merchant_id
		LEFT JOIN {$wpdb->users} u ON u.ID = i.created_by_user_id
	";

	$count_sql = "SELECT COUNT(*) {$from_sql} {$where}";
	$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

	$data_sql = "
		SELECT i.*,
		       m.name AS merchant_name,
		       m.chat_id AS merchant_chat_id,
		       m.telegram_avatar_url AS merchant_avatar_url,
		       u.display_name AS creator_display_name,
		       u.user_login AS creator_login
		{$from_sql}
		{$where}
		ORDER BY i.id DESC
		LIMIT %d OFFSET %d
	";
	$rows = $wpdb->get_results(
		$wpdb->prepare( $data_sql, array_merge( $params, [ $per_page, $offset ] ) )
	) ?: [];

	$items = [];
	foreach ( $rows as $row ) {
		$items[] = _me_merchants_format_invite_row( $row );
	}

	wp_send_json_success( [
		'rows'           => $items,
		'total'          => $total,
		'page'           => $page,
		'per_page'       => $per_page,
		'total_pages'    => (int) ceil( $total / $per_page ),
		'server_now_ts'  => time(),
		'telegram_status'=> crm_telegram_get_configuration_status( $company_id ),
	] );
}

// ─────────────────────────────────────────────────────────────────────────────
// 6. Приглашения мерчанта
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_me_merchants_invites', 'me_ajax_merchants_invites' );
function me_ajax_merchants_invites(): void {
	_me_merchants_check_access();

	if ( ! crm_can_access( 'merchants.invite' ) ) {
		_me_merchants_error( 'Недостаточно прав.', 403 );
	}

	if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'me_merchants_invite' ) ) {
		_me_merchants_error( 'Нарушена безопасность запроса.', 403 );
	}

	global $wpdb;

	$merchant_id = (int) ( $_GET['merchant_id'] ?? 0 );
	if ( $merchant_id <= 0 ) {
		_me_merchants_error( 'Неверный ID мерчанта.', 422 );
	}

	$merchant = _me_merchants_fetch_row( $merchant_id );
	if ( ! $merchant ) {
		_me_merchants_error( 'Мерчант не найден.', 404 );
	}
	_me_merchants_require_row_scope( $merchant );

	crm_expire_merchant_invites( (int) $merchant->company_id );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT i.*,
			        m.name AS merchant_name,
			        m.chat_id AS merchant_chat_id,
			        m.telegram_avatar_url AS merchant_avatar_url,
			        u.display_name AS creator_display_name,
			        u.user_login AS creator_login
			 FROM crm_merchant_invites i
			 LEFT JOIN crm_merchants m ON m.id = i.merchant_id
			 LEFT JOIN {$wpdb->users} u ON u.ID = i.created_by_user_id
			 WHERE i.merchant_id = %d
			 ORDER BY i.id DESC
			 LIMIT 100",
			$merchant_id
		)
	) ?: [];

	$items = [];
	foreach ( $rows as $row ) {
		$items[] = _me_merchants_format_invite_row( $row );
	}

	wp_send_json_success( [ 'rows' => $items ] );
}

add_action( 'wp_ajax_me_merchants_invite_create', 'me_ajax_merchants_invite_create' );
function me_ajax_merchants_invite_create(): void {
	_me_merchants_check_access();

	if ( ! crm_can_access( 'merchants.invite' ) ) {
		_me_merchants_error( 'Недостаточно прав.', 403 );
	}

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_merchants_invite' ) ) {
		_me_merchants_error( 'Нарушена безопасность запроса.', 403 );
	}

	global $wpdb;

	$merchant_id = (int) ( $_POST['merchant_id'] ?? 0 );
	if ( $merchant_id <= 0 ) {
		_me_merchants_error( 'Неверный ID мерчанта.', 422 );
	}

	$merchant = _me_merchants_fetch_row( $merchant_id );
	if ( ! $merchant ) {
		_me_merchants_error( 'Мерчант не найден.', 404 );
	}
	_me_merchants_require_row_scope( $merchant );

	$company_id = (int) $merchant->company_id;
	$settings   = crm_merchants_get_settings( $company_id );
	$telegram_status = crm_telegram_get_configuration_status( $company_id );
	if ( empty( $telegram_status['invite_ready'] ) ) {
		_me_merchants_error( 'Инвайт в Telegram недоступен. Сначала заполните имя бота, токен и подключите callback в настройках компании.', 422 );
	}
	$ttl        = max( 1, (int) $settings['invite_ttl_minutes'] );
	$token      = crm_generate_merchant_invite_token();
	$start_payload = crm_generate_merchant_invite_start_payload();
	$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $ttl * MINUTE_IN_SECONDS ) );
	$prefill_json = wp_json_encode(
		[
			'base_markup_type'  => (string) ( $merchant->base_markup_type ?? 'percent' ),
			'base_markup_value' => number_format( (float) ( $merchant->base_markup_value ?? 0 ), 8, '.', '' ),
			'note'              => (string) ( $merchant->note ?? '' ),
		],
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);

	$wpdb->insert(
		'crm_merchant_invites',
		[
			'company_id'         => $company_id,
			'merchant_id'        => $merchant_id,
			'invite_token'       => $token,
			'telegram_start_payload' => $start_payload,
			'bot_username_snapshot'  => (string) ( $telegram_status['bot_username'] ?? '' ),
			'prefill_json'           => $prefill_json !== false ? $prefill_json : null,
			'chat_id'            => (string) $merchant->chat_id,
			'status'             => 'new',
			'expires_at'         => $expires_at,
			'created_by_user_id' => get_current_user_id(),
			'created_at'         => current_time( 'mysql', true ),
		],
		[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
	);

	$invite_id = (int) $wpdb->insert_id;
	if ( $invite_id <= 0 ) {
		_me_merchants_error( 'Не удалось создать приглашение.', 500 );
	}

	crm_log_entity(
		'merchant.invite.created',
		'users',
		'create',
		'Создано приглашение мерчанта',
		'merchant_invite',
		$invite_id,
		[
			'org_id'  => $company_id,
			'context' => [
				'merchant_id' => $merchant_id,
				'expires_at'  => $expires_at,
				'ttl_minutes' => $ttl,
			],
		]
	);

	wp_send_json_success( [
		'message' => 'Приглашение создано.',
		'invite'  => _me_merchants_format_invite_row(
			(object) [
				'id'                    => $invite_id,
				'company_id'            => $company_id,
				'merchant_id'           => $merchant_id,
				'merchant_name'         => (string) ( $merchant->name ?? '' ),
				'merchant_chat_id'      => (string) ( $merchant->chat_id ?? '' ),
				'merchant_avatar_url'   => (string) ( $merchant->telegram_avatar_url ?? '' ),
				'invite_token'          => $token,
				'telegram_start_payload'=> $start_payload,
				'bot_username_snapshot' => (string) ( $telegram_status['bot_username'] ?? '' ),
				'prefill_json'          => $prefill_json !== false ? $prefill_json : '',
				'chat_id'               => (string) ( $merchant->chat_id ?? '' ),
				'status'                => 'new',
				'expires_at'            => $expires_at,
				'created_at'            => current_time( 'mysql', true ),
				'created_by_user_id'    => get_current_user_id(),
			]
		),
	] );
}

add_action( 'wp_ajax_me_merchants_invite_status', 'me_ajax_merchants_invite_status' );
function me_ajax_merchants_invite_status(): void {
	_me_merchants_check_access();

	if ( ! crm_can_access( 'merchants.invite' ) ) {
		_me_merchants_error( 'Недостаточно прав.', 403 );
	}

	if ( ! isset( $_POST['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ), 'me_merchants_invite' ) ) {
		_me_merchants_error( 'Нарушена безопасность запроса.', 403 );
	}

	global $wpdb;

	$invite_id        = (int) ( $_POST['invite_id'] ?? 0 );
	$new_status       = sanitize_key( $_POST['status'] ?? '' );
	$used_by_chat_id  = _me_merchants_sanitize_chat_id( $_POST['used_by_chat_id'] ?? '' );

	if ( $invite_id <= 0 || ! in_array( $new_status, [ 'used', 'expired', 'revoked' ], true ) ) {
		_me_merchants_error( 'Некорректные параметры.', 422 );
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT i.*, m.company_id AS merchant_company_id
			 FROM crm_merchant_invites i
			 LEFT JOIN crm_merchants m ON m.id = i.merchant_id
			 WHERE i.id = %d
			 LIMIT 1",
			$invite_id
		)
	);

	if ( ! $row ) {
		_me_merchants_error( 'Приглашение не найдено.', 404 );
	}

	if ( ! crm_merchant_viewer_can_access_company( get_current_user_id(), (int) $row->company_id ) ) {
		crm_log_company_scope_violation(
			'merchants.invite.cross_company_denied',
			'Попытка изменить приглашение другой компании',
			[
				'user_id'            => get_current_user_id(),
				'current_company_id' => crm_get_current_user_company_id( get_current_user_id() ),
				'record_company_id'  => (int) $row->company_id,
				'invite_id'          => $invite_id,
			]
		);
		_me_merchants_error( 'Доступ к другой компании запрещён.', 403 );
	}

	$update = [ 'status' => $new_status ];
	if ( $new_status === 'used' ) {
		$update['used_at']         = current_time( 'mysql', true );
		$update['used_by_chat_id'] = $used_by_chat_id !== '' ? $used_by_chat_id : ( $row->chat_id ?: null );
	}

	$wpdb->update(
		'crm_merchant_invites',
		$update,
		[ 'id' => $invite_id ],
		array_map(
			static fn( $key ) => $key === 'used_at' ? '%s' : '%s',
			array_keys( $update )
		),
		[ '%d' ]
	);

	crm_log_entity(
		'merchant.invite.status_changed',
		'users',
		'update',
		'Обновлён статус приглашения мерчанта',
		'merchant_invite',
		$invite_id,
		[
			'org_id'  => (int) $row->company_id,
			'context' => [
				'old_status'      => (string) $row->status,
				'new_status'      => $new_status,
				'merchant_id'     => $row->merchant_id ? (int) $row->merchant_id : null,
				'used_by_chat_id' => $used_by_chat_id !== '' ? $used_by_chat_id : null,
			],
		]
	);

	wp_send_json_success( [ 'message' => 'Статус приглашения обновлён.' ] );
}

// ─────────────────────────────────────────────────────────────────────────────
// 6. Ledger мерчанта
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_me_merchants_ledger', 'me_ajax_merchants_ledger' );
function me_ajax_merchants_ledger(): void {
	_me_merchants_check_access();

	if ( ! crm_can_access( 'merchants.ledger' ) ) {
		_me_merchants_error( 'Недостаточно прав.', 403 );
	}

	if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'me_merchants_ledger' ) ) {
		_me_merchants_error( 'Нарушена безопасность запроса.', 403 );
	}

	global $wpdb;

	$merchant_id = (int) ( $_GET['merchant_id'] ?? 0 );
	if ( $merchant_id <= 0 ) {
		_me_merchants_error( 'Неверный ID мерчанта.', 422 );
	}

	$merchant = _me_merchants_fetch_row( $merchant_id );
	if ( ! $merchant ) {
		_me_merchants_error( 'Мерчант не найден.', 404 );
	}
	_me_merchants_require_row_scope( $merchant );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT l.*,
			        o.merchant_order_id,
			        o.status_code AS order_status_code,
			        src.name AS source_merchant_name,
			        src.chat_id AS source_merchant_chat_id
			 FROM crm_merchant_wallet_ledger l
			 LEFT JOIN crm_fintech_payment_orders o ON o.id = l.source_order_id
			 LEFT JOIN crm_merchants src ON src.id = l.source_merchant_id
			 WHERE l.merchant_id = %d
			 ORDER BY l.id DESC
			 LIMIT 200",
			$merchant_id
		)
	) ?: [];

	$items = [];
	foreach ( $rows as $row ) {
		$items[] = [
			'id'                       => (int) $row->id,
			'entry_type'               => (string) $row->entry_type,
			'entry_type_label'         => crm_merchant_wallet_entry_type_label( (string) $row->entry_type ),
			'amount'                   => (float) $row->amount,
			'amount_label'             => crm_merchant_format_amount( $row->amount, (string) $row->currency_code ),
			'currency_code'            => (string) $row->currency_code,
			'source_order_id'          => $row->source_order_id ? (int) $row->source_order_id : null,
			'source_order_ref'         => (string) ( $row->merchant_order_id ?? '' ),
			'source_order_status_code' => (string) ( $row->order_status_code ?? '' ),
			'source_merchant_id'       => $row->source_merchant_id ? (int) $row->source_merchant_id : null,
			'source_merchant_name'     => (string) ( $row->source_merchant_name ?? '' ),
			'source_merchant_chat_id'  => isset( $row->source_merchant_chat_id ) ? (string) $row->source_merchant_chat_id : '',
			'comment'                  => (string) ( $row->comment ?? '' ),
			'created_at'               => (string) $row->created_at,
		];
	}

	$summary_map = crm_get_merchant_balance_summary_map( [ $merchant_id ] );
	$summary     = $summary_map[ $merchant_id ] ?? [
		'bonus_balance'    => 0.0,
		'referral_balance' => 0.0,
		'total_balance'    => 0.0,
	];

	wp_send_json_success( [
		'summary' => [
			'bonus_balance'           => $summary['bonus_balance'],
			'bonus_balance_label'     => crm_merchant_format_amount( $summary['bonus_balance'] ),
			'referral_balance'        => $summary['referral_balance'],
			'referral_balance_label'  => crm_merchant_format_amount( $summary['referral_balance'] ),
			'total_balance'           => $summary['total_balance'],
			'total_balance_label'     => crm_merchant_format_amount( $summary['total_balance'] ),
		],
		'rows'    => $items,
	] );
}

// ─────────────────────────────────────────────────────────────────────────────
// 7. Ордера мерчанта
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_me_merchants_orders', 'me_ajax_merchants_orders' );
function me_ajax_merchants_orders(): void {
	_me_merchants_check_access();

	if ( ! crm_can_access( 'orders.view' ) ) {
		_me_merchants_error( 'Недостаточно прав на просмотр ордеров.', 403 );
	}

	if ( ! isset( $_GET['_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_nonce'] ) ), 'me_merchants_orders' ) ) {
		_me_merchants_error( 'Нарушена безопасность запроса.', 403 );
	}

	global $wpdb;

	$merchant_id = (int) ( $_GET['merchant_id'] ?? 0 );
	if ( $merchant_id <= 0 ) {
		_me_merchants_error( 'Неверный ID мерчанта.', 422 );
	}

	$merchant = _me_merchants_fetch_row( $merchant_id );
	if ( ! $merchant ) {
		_me_merchants_error( 'Мерчант не найден.', 404 );
	}
	_me_merchants_require_row_scope( $merchant );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, company_id, provider_code, source_channel, merchant_order_id, provider_order_id,
			        status_code, amount_asset_code, amount_asset_value, payment_currency_code, payment_amount_value,
			        created_for_type, merchant_markup_value, platform_fee_value, merchant_profit_value,
			        referral_reward_value, created_at, paid_at
			 FROM crm_fintech_payment_orders
			 WHERE merchant_id = %d
			 ORDER BY id DESC
			 LIMIT 200",
			$merchant_id
		)
	) ?: [];

	$items = [];
	foreach ( $rows as $row ) {
		$items[] = [
			'id'                     => (int) $row->id,
			'provider_code'          => (string) $row->provider_code,
			'source_channel'         => (string) ( $row->source_channel ?? '' ),
			'merchant_order_id'      => (string) $row->merchant_order_id,
			'provider_order_id'      => (string) ( $row->provider_order_id ?? '' ),
			'status_code'            => (string) $row->status_code,
			'created_for_type'       => (string) ( $row->created_for_type ?? 'company' ),
			'amount_asset_label'     => crm_merchant_format_amount( $row->amount_asset_value, (string) $row->amount_asset_code ),
			'payment_amount_label'   => $row->payment_amount_value !== null
				? crm_merchant_format_amount( $row->payment_amount_value, (string) $row->payment_currency_code )
				: '—',
			'merchant_markup_label'  => $row->merchant_markup_value !== null
				? crm_merchant_format_amount( $row->merchant_markup_value, (string) $row->amount_asset_code )
				: '—',
			'platform_fee_label'     => $row->platform_fee_value !== null
				? crm_merchant_format_amount( $row->platform_fee_value, (string) $row->amount_asset_code )
				: '—',
			'merchant_profit_label'  => $row->merchant_profit_value !== null
				? crm_merchant_format_amount( $row->merchant_profit_value, (string) $row->amount_asset_code )
				: '—',
			'referral_reward_label'  => $row->referral_reward_value !== null
				? crm_merchant_format_amount( $row->referral_reward_value, (string) $row->amount_asset_code )
				: '—',
			'created_at'             => (string) $row->created_at,
			'paid_at'                => (string) ( $row->paid_at ?? '' ),
		];
	}

	wp_send_json_success( [ 'rows' => $items ] );
}
