<?php
/**
 * Malibu Exchange — Operator Telegram AJAX
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function _me_operator_telegram_error( string $message, int $status = 400 ): void {
	wp_send_json_error( [ 'message' => $message ], $status );
}

function _me_operator_telegram_scope_company_id(): int {
	$uid = get_current_user_id();

	if ( ! is_user_logged_in() || crm_is_root( $uid ) || ! crm_can_manage_operator_telegram() ) {
		_me_operator_telegram_error( 'Недостаточно прав.', 403 );
	}

	$company_id = crm_get_current_user_company_id( $uid );
	if ( $company_id <= 0 ) {
		_me_operator_telegram_error( 'Аккаунт не привязан к компании.', 403 );
	}

	return $company_id;
}

function _me_operator_telegram_nonce_from_request( string $method = 'post' ): string {
	$source = strtolower( $method ) === 'get' ? $_GET : $_POST;

	return isset( $source['_nonce'] ) ? sanitize_text_field( wp_unslash( $source['_nonce'] ) ) : '';
}

function _me_operator_telegram_verify_nonce( string $method = 'post' ): void {
	if ( ! wp_verify_nonce( _me_operator_telegram_nonce_from_request( $method ), 'me_operator_telegram_invite' ) ) {
		_me_operator_telegram_error( 'Нарушена безопасность запроса.', 403 );
	}
}

function _me_operator_telegram_invite_ttl_minutes( int $company_id ): int {
	$ttl = 60;
	if ( function_exists( 'crm_merchants_get_settings' ) ) {
		$settings = crm_merchants_get_settings( $company_id );
		$ttl      = (int) ( $settings['invite_ttl_minutes'] ?? $ttl );
	}

	return max( 1, $ttl );
}

add_action( 'wp_ajax_me_operator_telegram_status', 'me_ajax_operator_telegram_status' );
function me_ajax_operator_telegram_status(): void {
	$company_id = _me_operator_telegram_scope_company_id();
	_me_operator_telegram_verify_nonce( 'get' );

	wp_send_json_success( [
		'telegram_status' => crm_telegram_get_configuration_status( $company_id, 'operator' ),
	] );
}

add_action( 'wp_ajax_me_operator_telegram_users_list', 'me_ajax_operator_telegram_users_list' );
function me_ajax_operator_telegram_users_list(): void {
	$company_id = _me_operator_telegram_scope_company_id();
	_me_operator_telegram_verify_nonce();

	global $wpdb;

	$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
	$per_page = (int) ( $_POST['per_page'] ?? 25 );
	if ( ! in_array( $per_page, [ 25, 50, 100 ], true ) ) {
		$per_page = 25;
	}
	$offset = ( $page - 1 ) * $per_page;

	$user_ids = array_values( array_filter(
		crm_get_company_user_ids( $company_id ),
		static fn( $user_id ) => ! crm_is_root( (int) $user_id )
	) );

	if ( empty( $user_ids ) ) {
		wp_send_json_success( [
			'rows'            => [],
			'total'           => 0,
			'page'            => $page,
			'per_page'        => $per_page,
			'total_pages'     => 0,
			'telegram_status' => crm_telegram_get_configuration_status( $company_id, 'operator' ),
		] );
	}

	$ids_sql    = implode( ',', array_map( 'intval', $user_ids ) );
	$search    = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
	$status    = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
	$date_from = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
	$date_to   = sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) );
	$where     = "WHERE u.ID IN ({$ids_sql})";
	$params    = [];

	if ( $search !== '' ) {
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$where .= ' AND (CAST(u.ID AS CHAR) LIKE %s OR u.user_login LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s OR a.chat_id LIKE %s OR a.telegram_username LIKE %s OR a.telegram_first_name LIKE %s OR a.telegram_last_name LIKE %s)';
		for ( $i = 0; $i < 8; $i++ ) {
			$params[] = $like;
		}
	}

	if ( $status !== '' && isset( crm_operator_telegram_user_list_statuses()[ $status ] ) ) {
		if ( $status === 'not_linked' ) {
			$where .= ' AND a.id IS NULL';
		} else {
			$where   .= ' AND a.status = %s';
			$params[] = $status;
		}
	}

	$tz = crm_get_timezone( $company_id );
	if ( $date_from !== '' && strtotime( $date_from ) ) {
		$dt_from = new DateTime( $date_from . ' 00:00:00', $tz );
		$dt_from->setTimezone( new DateTimeZone( 'UTC' ) );
		$where   .= ' AND a.linked_at >= %s';
		$params[] = $dt_from->format( 'Y-m-d H:i:s' );
	}
	if ( $date_to !== '' && strtotime( $date_to ) ) {
		$dt_to = new DateTime( $date_to . ' 23:59:59', $tz );
		$dt_to->setTimezone( new DateTimeZone( 'UTC' ) );
		$where   .= ' AND a.linked_at <= %s';
		$params[] = $dt_to->format( 'Y-m-d H:i:s' );
	}

	$from_sql = "
		FROM {$wpdb->users} u
		LEFT JOIN crm_user_accounts ua ON ua.user_id = u.ID
		LEFT JOIN crm_user_telegram_accounts a ON a.company_id = %d AND a.user_id = u.ID
		LEFT JOIN crm_operator_telegram_invites i ON i.id = (
			SELECT MAX(i2.id)
			FROM crm_operator_telegram_invites i2
			WHERE i2.company_id = %d
			  AND i2.user_id = u.ID
		)
	";
	$from_params = [ $company_id, $company_id ];

	$count_params = array_merge( $from_params, $params );
	$total = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) {$from_sql} {$where}", $count_params )
	);

	$data_sql = "
		SELECT u.ID,
		       u.display_name,
		       u.user_login,
		       u.user_email,
		       u.user_registered,
		       ua.status AS crm_status,
		       a.id AS telegram_account_id,
		       a.chat_id,
		       a.telegram_user_id,
		       a.telegram_username,
		       a.telegram_first_name,
		       a.telegram_last_name,
		       a.telegram_language_code,
		       a.telegram_avatar_url,
		       a.status AS telegram_status,
		       a.linked_at,
		       a.last_seen_at,
		       i.id AS last_invite_id,
		       i.status AS last_invite_status,
		       i.expires_at AS last_invite_expires_at,
		       i.used_at AS last_invite_used_at,
		       i.created_at AS last_invite_created_at,
		       i.telegram_start_payload AS last_invite_payload
		{$from_sql}
		{$where}
		ORDER BY CASE WHEN a.id IS NULL THEN 1 ELSE 0 END ASC,
		         COALESCE(a.linked_at, i.created_at, u.user_registered) DESC,
		         u.display_name ASC
		LIMIT %d OFFSET %d
	";
	$rows = $wpdb->get_results(
		$wpdb->prepare( $data_sql, array_merge( $from_params, $params, [ $per_page, $offset ] ) )
	) ?: [];

	$row_user_ids = array_map( static fn( $row ) => (int) $row->ID, $rows );
	$roles_map    = crm_get_roles_for_users( $row_user_ids );
	$items        = [];
	foreach ( $rows as $row ) {
		$items[] = crm_operator_telegram_format_user_row( $row, $roles_map[ (int) $row->ID ] ?? [] );
	}

	wp_send_json_success( [
		'rows'            => $items,
		'total'           => $total,
		'page'            => $page,
		'per_page'        => $per_page,
		'total_pages'     => (int) ceil( $total / $per_page ),
		'telegram_status' => crm_telegram_get_configuration_status( $company_id, 'operator' ),
	] );
}

add_action( 'wp_ajax_me_operator_telegram_profile_refresh', 'me_ajax_operator_telegram_profile_refresh' );
function me_ajax_operator_telegram_profile_refresh(): void {
	$company_id = _me_operator_telegram_scope_company_id();
	_me_operator_telegram_verify_nonce();

	global $wpdb;

	$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
	if ( $user_id <= 0 || ! crm_operator_telegram_user_belongs_to_company( $user_id, $company_id ) ) {
		_me_operator_telegram_error( 'Пользователь не найден в текущей компании.', 404 );
	}

	$account = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT *
			 FROM crm_user_telegram_accounts
			 WHERE company_id = %d
			   AND user_id = %d
			 LIMIT 1",
			$company_id,
			$user_id
		)
	);
	if ( ! $account ) {
		_me_operator_telegram_error( 'Telegram-профиль ещё не привязан.', 404 );
	}

	$telegram_user_id = trim( (string) ( $account->telegram_user_id ?: $account->chat_id ) );
	$username         = trim( (string) ( $account->telegram_username ?: 'operator' ) );
	$avatar           = crm_operator_telegram_fetch_avatar( $company_id, $telegram_user_id, $username, (string) time() );
	if ( empty( $avatar['avatar_url'] ) ) {
		_me_operator_telegram_error( ! empty( $avatar['error'] ) ? (string) $avatar['error'] : 'Не удалось обновить Telegram-аватар.', 422 );
	}

	$updated = $wpdb->update(
		'crm_user_telegram_accounts',
		[
			'telegram_avatar_file_id' => (string) $avatar['file_id'],
			'telegram_avatar_path'    => (string) $avatar['avatar_path'],
			'telegram_avatar_url'     => (string) $avatar['avatar_url'],
			'last_seen_at'            => current_time( 'mysql', true ),
		],
		[ 'id' => (int) $account->id ],
		[ '%s', '%s', '%s', '%s' ],
		[ '%d' ]
	);

	if ( $updated === false ) {
		_me_operator_telegram_error( 'Не удалось сохранить Telegram-аватар.', 500 );
	}

	crm_log_entity(
		'operator.telegram.profile_refreshed',
		'users',
		'update',
		'Обновлён Telegram-профиль оператора',
		'operator_telegram_account',
		$user_id,
		[
			'org_id'  => $company_id,
			'context' => [
				'user_id'    => $user_id,
				'chat_id'    => (string) $account->chat_id,
				'avatar_url' => (string) $avatar['avatar_url'],
			],
		]
	);

	wp_send_json_success( [
		'message'    => 'Telegram-аватар обновлён.',
		'avatar_url' => (string) $avatar['avatar_url'],
	] );
}

add_action( 'wp_ajax_me_operator_telegram_invite_create', 'me_ajax_operator_telegram_invite_create' );
function me_ajax_operator_telegram_invite_create(): void {
	$company_id = _me_operator_telegram_scope_company_id();

	if ( ! crm_can_invite_operator_telegram() ) {
		_me_operator_telegram_error( 'Недостаточно прав на выдачу инвайтов.', 403 );
	}

	_me_operator_telegram_verify_nonce();

	$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
	if ( $user_id <= 0 || ! crm_operator_telegram_user_belongs_to_company( $user_id, $company_id ) ) {
		_me_operator_telegram_error( 'Пользователь не найден в текущей компании.', 404 );
	}

	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User || crm_is_root( $user_id ) ) {
		_me_operator_telegram_error( 'Пользователь недоступен для Telegram-привязки.', 404 );
	}

	$telegram_status = crm_telegram_get_configuration_status( $company_id, 'operator' );
	if ( empty( $telegram_status['operator_ready'] ) ) {
		$message = 'Инвайт оператора недоступен. Сначала настройте и подключите операторский Telegram-бот.';
		if ( ! empty( $telegram_status['blocked_reason'] ) ) {
			$message = 'Инвайт оператора недоступен. ' . (string) $telegram_status['blocked_reason'];
		}
		_me_operator_telegram_error( $message, 422 );
	}

	global $wpdb;

	crm_operator_telegram_expire_invites( $company_id );

	$token         = crm_operator_telegram_generate_token();
	$start_payload = crm_operator_telegram_generate_start_payload();
	$ttl           = _me_operator_telegram_invite_ttl_minutes( $company_id );
	$expires_at    = gmdate( 'Y-m-d H:i:s', time() + ( $ttl * MINUTE_IN_SECONDS ) );
	$created_by    = get_current_user_id();
	$bot_username  = (string) ( $telegram_status['bot_username'] ?? '' );
	$created_at    = current_time( 'mysql', true );

	$inserted = $wpdb->insert(
		'crm_operator_telegram_invites',
		[
			'company_id'             => $company_id,
			'user_id'                => $user_id,
			'invite_token'           => $token,
			'telegram_start_payload' => $start_payload,
			'bot_username_snapshot'  => $bot_username,
			'chat_id'                => null,
			'status'                 => 'new',
			'expires_at'             => $expires_at,
			'created_by_user_id'     => $created_by,
			'created_at'             => $created_at,
		],
		[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
	);

	$invite_id = (int) $wpdb->insert_id;
	if ( ! $inserted || $invite_id <= 0 ) {
		_me_operator_telegram_error( 'Не удалось создать Telegram-инвайт оператора.', 500 );
	}

	crm_log_entity(
		'operator.telegram.invite_created',
		'users',
		'create',
		'Создан Telegram-инвайт оператора',
		'operator_telegram_invite',
		$invite_id,
		[
			'org_id'  => $company_id,
			'context' => [
				'user_id'    => $user_id,
				'expires_at' => $expires_at,
				'ttl_minutes'=> $ttl,
			],
		]
	);

	$creator = get_userdata( $created_by );

	wp_send_json_success( [
		'message' => 'Telegram-инвайт оператора создан.',
		'invite'  => crm_operator_telegram_format_invite(
			(object) [
				'id'                     => $invite_id,
				'company_id'             => $company_id,
				'user_id'                => $user_id,
				'telegram_start_payload' => $start_payload,
				'bot_username_snapshot'  => $bot_username,
				'status'                 => 'new',
				'expires_at'             => $expires_at,
				'created_at'             => $created_at,
				'created_by_user_id'     => $created_by,
				'user_display_name'      => $user->display_name ?: $user->user_login,
				'user_login'             => $user->user_login,
				'user_email'             => $user->user_email,
				'creator_display_name'   => $creator instanceof WP_User ? ( $creator->display_name ?: $creator->user_login ) : '',
				'creator_login'          => $creator instanceof WP_User ? $creator->user_login : '',
			]
		),
		'telegram_status' => $telegram_status,
	] );
}

add_action( 'wp_ajax_me_operator_telegram_invites_history', 'me_ajax_operator_telegram_invites_history' );
function me_ajax_operator_telegram_invites_history(): void {
	$company_id = _me_operator_telegram_scope_company_id();

	if ( ! crm_can_invite_operator_telegram() ) {
		_me_operator_telegram_error( 'Недостаточно прав на просмотр инвайтов.', 403 );
	}

	_me_operator_telegram_verify_nonce( 'get' );

	global $wpdb;

	crm_operator_telegram_expire_invites( $company_id );

	$page      = max( 1, (int) ( $_GET['page'] ?? 1 ) );
	$per_page  = (int) ( $_GET['per_page'] ?? 25 );
	$per_page  = in_array( $per_page, [ 25, 50, 100 ], true ) ? $per_page : 25;
	$offset    = ( $page - 1 ) * $per_page;
	$user_id   = (int) ( $_GET['user_id'] ?? 0 );
	$search    = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
	$status    = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
	$date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
	$date_to   = sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) );
	$where     = 'WHERE i.company_id = %d';
	$params    = [ $company_id ];

	if ( $user_id > 0 ) {
		if ( ! crm_operator_telegram_user_belongs_to_company( $user_id, $company_id ) ) {
			_me_operator_telegram_error( 'Пользователь не найден в текущей компании.', 404 );
		}
		$where   .= ' AND i.user_id = %d';
		$params[] = $user_id;
	}

	if ( $search !== '' ) {
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$where .= ' AND (i.telegram_start_payload LIKE %s OR i.used_by_chat_id LIKE %s OR i.chat_id LIKE %s OR target.user_login LIKE %s OR target.display_name LIKE %s OR target.user_email LIKE %s OR a.chat_id LIKE %s OR a.telegram_username LIKE %s OR a.telegram_first_name LIKE %s OR a.telegram_last_name LIKE %s)';
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
	}

	if ( $status !== '' && isset( crm_operator_telegram_invite_statuses()[ $status ] ) ) {
		$where   .= ' AND i.status = %s';
		$params[] = $status;
	}

	if ( $date_from !== '' && strtotime( $date_from ) ) {
		$where   .= ' AND i.created_at >= %s';
		$params[] = gmdate( 'Y-m-d 00:00:00', strtotime( $date_from ) );
	}
	if ( $date_to !== '' && strtotime( $date_to ) ) {
		$where   .= ' AND i.created_at <= %s';
		$params[] = gmdate( 'Y-m-d 23:59:59', strtotime( $date_to ) );
	}

	$from_sql = "
		FROM crm_operator_telegram_invites i
		JOIN {$wpdb->users} target ON target.ID = i.user_id
		LEFT JOIN {$wpdb->users} creator ON creator.ID = i.created_by_user_id
		LEFT JOIN crm_user_telegram_accounts a ON a.company_id = i.company_id AND a.user_id = i.user_id
	";

	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from_sql} {$where}", $params ) );

	$data_sql = "
		SELECT i.*,
		       target.display_name AS user_display_name,
		       target.user_login AS user_login,
		       target.user_email AS user_email,
		       creator.display_name AS creator_display_name,
		       creator.user_login AS creator_login,
		       a.chat_id AS linked_chat_id,
		       a.telegram_username AS linked_telegram_username,
		       a.telegram_first_name AS linked_telegram_first_name,
		       a.telegram_last_name AS linked_telegram_last_name,
		       a.telegram_avatar_url AS linked_telegram_avatar_url,
		       a.status AS linked_telegram_status,
		       a.linked_at AS linked_at
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
		$items[] = crm_operator_telegram_format_invite( $row );
	}

	wp_send_json_success( [
		'rows'            => $items,
		'total'           => $total,
		'page'            => $page,
		'per_page'        => $per_page,
		'total_pages'     => (int) ceil( $total / $per_page ),
		'server_now_ts'   => time(),
		'telegram_status' => crm_telegram_get_configuration_status( $company_id, 'operator' ),
	] );
}

add_action( 'wp_ajax_me_operator_telegram_invite_status', 'me_ajax_operator_telegram_invite_status' );
function me_ajax_operator_telegram_invite_status(): void {
	$company_id = _me_operator_telegram_scope_company_id();

	if ( ! crm_can_invite_operator_telegram() ) {
		_me_operator_telegram_error( 'Недостаточно прав на изменение инвайтов.', 403 );
	}

	_me_operator_telegram_verify_nonce();

	global $wpdb;

	$invite_id       = (int) ( $_POST['invite_id'] ?? 0 );
	$new_status      = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
	$used_by_chat_id = isset( $_POST['used_by_chat_id'] ) ? sanitize_text_field( wp_unslash( $_POST['used_by_chat_id'] ) ) : '';

	if ( $invite_id <= 0 || ! in_array( $new_status, [ 'used', 'expired', 'revoked' ], true ) ) {
		_me_operator_telegram_error( 'Некорректные параметры.', 422 );
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT * FROM crm_operator_telegram_invites WHERE id = %d AND company_id = %d LIMIT 1',
			$invite_id,
			$company_id
		)
	);

	if ( ! $row ) {
		_me_operator_telegram_error( 'Инвайт не найден.', 404 );
	}

	$update = [ 'status' => $new_status ];
	if ( $new_status === 'used' ) {
		$update['used_at']         = current_time( 'mysql', true );
		$update['used_by_chat_id'] = $used_by_chat_id !== '' ? $used_by_chat_id : ( (string) ( $row->chat_id ?? '' ) ?: null );
	}

	$wpdb->update(
		'crm_operator_telegram_invites',
		$update,
		[ 'id' => $invite_id ],
		array_fill( 0, count( $update ), '%s' ),
		[ '%d' ]
	);

	crm_log_entity(
		'operator.telegram.invite_status_changed',
		'users',
		'update',
		'Обновлён статус Telegram-инвайта оператора',
		'operator_telegram_invite',
		$invite_id,
		[
			'org_id'  => $company_id,
			'context' => [
				'old_status'      => (string) $row->status,
				'new_status'      => $new_status,
				'user_id'         => (int) $row->user_id,
				'used_by_chat_id' => $used_by_chat_id !== '' ? $used_by_chat_id : null,
			],
		]
	);

	wp_send_json_success( [ 'message' => 'Статус инвайта обновлён.' ] );
}
