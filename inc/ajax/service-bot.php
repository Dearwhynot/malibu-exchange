<?php
/**
 * Malibu Exchange — Service Telegram AJAX
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function _me_service_telegram_error( string $message, int $status = 400 ): void {
	wp_send_json_error( [ 'message' => $message ], $status );
}

function _me_service_telegram_scope_company_id(): int {
	$uid = get_current_user_id();

	if ( ! is_user_logged_in() || crm_is_root( $uid ) || ! crm_can_manage_service_telegram() ) {
		_me_service_telegram_error( 'Недостаточно прав.', 403 );
	}

	$company_id = crm_get_current_user_company_id( $uid );
	if ( $company_id <= 0 ) {
		_me_service_telegram_error( 'Аккаунт не привязан к компании.', 403 );
	}

	return $company_id;
}

function _me_service_telegram_nonce_from_request( string $method = 'post' ): string {
	$source = strtolower( $method ) === 'get' ? $_GET : $_POST;

	return isset( $source['_nonce'] ) ? sanitize_text_field( wp_unslash( $source['_nonce'] ) ) : '';
}

function _me_service_telegram_verify_nonce( string $method = 'post' ): void {
	if ( ! wp_verify_nonce( _me_service_telegram_nonce_from_request( $method ), 'me_service_telegram_invite' ) ) {
		_me_service_telegram_error( 'Нарушена безопасность запроса.', 403 );
	}
}

function _me_service_telegram_settings_url(): string {
	return home_url( '/settings/#telegram_service_settings_form' );
}

add_action( 'wp_ajax_me_service_telegram_invite_create', 'me_ajax_service_telegram_invite_create' );
function me_ajax_service_telegram_invite_create(): void {
	$company_id = _me_service_telegram_scope_company_id();

	if ( ! crm_can_invite_service_telegram() ) {
		_me_service_telegram_error( 'Недостаточно прав на выдачу service invite.', 403 );
	}

	_me_service_telegram_verify_nonce();

	$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
	$check   = crm_service_telegram_validate_target_user( $user_id, $company_id );
	if ( empty( $check['ok'] ) ) {
		_me_service_telegram_error( (string) $check['message'], 422 );
	}

	$telegram_status = crm_telegram_get_configuration_status( $company_id, 'service' );
	if ( empty( $telegram_status['is_configured'] ) ) {
		$message = 'Service invite недоступен. Откройте Settings -> Сервисный бот и заполните имя и токен бота.';
		if ( ! empty( $telegram_status['blocked_reason'] ) ) {
			$message = 'Service invite недоступен. ' . (string) $telegram_status['blocked_reason'] . ' Откройте Settings -> Сервисный бот и завершите настройку.';
		}
		wp_send_json_error( [
			'message'          => $message,
			'settings_url'     => _me_service_telegram_settings_url(),
			'telegram_status'  => $telegram_status,
		], 422 );
	}

	global $wpdb;

	crm_service_telegram_expire_invites( $company_id );
	$wpdb->update(
		'crm_service_telegram_invites',
		[ 'status' => 'revoked' ],
		[
			'company_id' => $company_id,
			'user_id'    => $user_id,
			'status'     => 'new',
		],
		[ '%s' ],
		[ '%d', '%d', '%s' ]
	);

	$token         = crm_service_telegram_generate_token();
	$start_payload = crm_service_telegram_generate_start_payload();
	$ttl           = crm_service_telegram_invite_ttl_minutes();
	$expires_at    = gmdate( 'Y-m-d H:i:s', time() + ( $ttl * MINUTE_IN_SECONDS ) );
	$created_by    = get_current_user_id();
	$bot_username  = (string) ( $telegram_status['bot_username'] ?? '' );
	$created_at    = current_time( 'mysql', true );

	$inserted = $wpdb->insert(
		'crm_service_telegram_invites',
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
		_me_service_telegram_error( 'Не удалось создать service invite.', 500 );
	}

	crm_log_entity(
		'service.telegram.invite_created',
		'users',
		'create',
		'Создан service Telegram invite',
		'service_telegram_invite',
		$invite_id,
		[
			'org_id'  => $company_id,
			'context' => [
				'user_id'     => $user_id,
				'expires_at'  => $expires_at,
				'ttl_minutes' => $ttl,
			],
		]
	);

	$user    = $check['user'];
	$creator = get_userdata( $created_by );

	wp_send_json_success( [
		'message' => 'Service invite создан.',
		'invite'  => crm_service_telegram_format_invite(
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
				'user_display_name'      => $user instanceof WP_User ? ( $user->display_name ?: $user->user_login ) : '',
				'user_login'             => $user instanceof WP_User ? $user->user_login : '',
				'user_email'             => $user instanceof WP_User ? $user->user_email : '',
				'creator_display_name'   => $creator instanceof WP_User ? ( $creator->display_name ?: $creator->user_login ) : '',
				'creator_login'          => $creator instanceof WP_User ? $creator->user_login : '',
			]
		),
		'acl'            => crm_service_telegram_get_user_acl_map( $company_id, [ $user_id ] )[ $user_id ] ?? crm_service_telegram_default_acl_summary(),
		'telegram_status'=> $telegram_status,
	] );
}

add_action( 'wp_ajax_me_service_telegram_invites_history', 'me_ajax_service_telegram_invites_history' );
function me_ajax_service_telegram_invites_history(): void {
	$company_id = _me_service_telegram_scope_company_id();
	_me_service_telegram_verify_nonce( 'get' );

	global $wpdb;

	crm_service_telegram_expire_invites( $company_id );

	$page      = max( 1, (int) ( $_GET['page'] ?? 1 ) );
	$per_page  = (int) ( $_GET['per_page'] ?? 25 );
	$per_page  = in_array( $per_page, [ 10, 25, 50, 100 ], true ) ? $per_page : 25;
	$offset    = ( $page - 1 ) * $per_page;
	$user_id   = (int) ( $_GET['user_id'] ?? 0 );
	$search    = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
	$status    = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
	$where     = 'WHERE i.company_id = %d';
	$params    = [ $company_id ];

	if ( $user_id > 0 ) {
		if ( ! crm_service_telegram_user_belongs_to_company( $user_id, $company_id ) ) {
			_me_service_telegram_error( 'Пользователь не найден в текущей компании.', 404 );
		}
		$where   .= ' AND i.user_id = %d';
		$params[] = $user_id;
	}

	if ( $search !== '' ) {
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$where .= ' AND (i.telegram_start_payload LIKE %s OR i.used_by_chat_id LIKE %s OR i.chat_id LIKE %s OR target.user_login LIKE %s OR target.display_name LIKE %s OR target.user_email LIKE %s OR a.chat_id LIKE %s OR a.telegram_username LIKE %s OR a.telegram_first_name LIKE %s OR a.telegram_last_name LIKE %s)';
		for ( $i = 0; $i < 10; $i++ ) {
			$params[] = $like;
		}
	}

	if ( $status !== '' && isset( crm_service_telegram_invite_statuses()[ $status ] ) ) {
		$where   .= ' AND i.status = %s';
		$params[] = $status;
	}

	$from_sql = "
		FROM crm_service_telegram_invites i
		JOIN {$wpdb->users} target ON target.ID = i.user_id
		LEFT JOIN {$wpdb->users} creator ON creator.ID = i.created_by_user_id
		LEFT JOIN crm_user_telegram_accounts a ON a.company_id = i.company_id AND a.user_id = i.user_id
		LEFT JOIN crm_service_telegram_access sa ON sa.company_id = i.company_id AND sa.user_id = i.user_id
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
		       a.status AS linked_telegram_status,
		       a.linked_at AS linked_at,
		       sa.status AS linked_access_status
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
		$items[] = crm_service_telegram_format_invite( $row );
	}

	wp_send_json_success( [
		'rows'            => $items,
		'total'           => $total,
		'page'            => $page,
		'per_page'        => $per_page,
		'total_pages'     => (int) ceil( $total / $per_page ),
		'server_now_ts'   => time(),
		'acl'             => $user_id > 0 ? ( crm_service_telegram_get_user_acl_map( $company_id, [ $user_id ] )[ $user_id ] ?? crm_service_telegram_default_acl_summary() ) : crm_service_telegram_default_acl_summary(),
		'telegram_status' => crm_telegram_get_configuration_status( $company_id, 'service' ),
	] );
}

add_action( 'wp_ajax_me_service_telegram_access_revoke', 'me_ajax_service_telegram_access_revoke' );
function me_ajax_service_telegram_access_revoke(): void {
	$company_id = _me_service_telegram_scope_company_id();

	if ( ! crm_can_invite_service_telegram() ) {
		_me_service_telegram_error( 'Недостаточно прав на отзыв service access.', 403 );
	}

	_me_service_telegram_verify_nonce();

	global $wpdb;

	$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
	if ( ! crm_service_telegram_user_belongs_to_company( $user_id, $company_id ) ) {
		_me_service_telegram_error( 'Пользователь не найден в текущей компании.', 404 );
	}

	$access = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT *
			 FROM crm_service_telegram_access
			 WHERE company_id = %d
			   AND user_id = %d
			 LIMIT 1",
			$company_id,
			$user_id
		)
	);

	if ( ! $access ) {
		_me_service_telegram_error( 'Active service access не найден.', 404 );
	}

	if ( (string) $access->status === 'revoked' ) {
		_me_service_telegram_error( 'Service access уже отозван.', 422 );
	}

	$now = current_time( 'mysql', true );
	$updated = $wpdb->update(
		'crm_service_telegram_access',
		[
			'status'             => 'revoked',
			'revoked_at'         => $now,
			'revoked_by_user_id' => get_current_user_id(),
			'revoke_reason'      => 'revoked_from_users_page',
		],
		[ 'id' => (int) $access->id ],
		[ '%s', '%s', '%d', '%s' ],
		[ '%d' ]
	);

	if ( false === $updated ) {
		_me_service_telegram_error( 'Не удалось отозвать service access.', 500 );
	}

	$wpdb->update(
		'crm_service_telegram_invites',
		[ 'status' => 'revoked' ],
		[
			'company_id' => $company_id,
			'user_id'    => $user_id,
			'status'     => 'new',
		],
		[ '%s' ],
		[ '%d', '%d', '%s' ]
	);

	crm_log_entity(
		'service.telegram.access_revoked',
		'users',
		'update',
		'Service access пользователя отозван',
		'service_telegram_access',
		(int) $access->id,
		[
			'org_id'  => $company_id,
			'context' => [
				'user_id'    => $user_id,
				'old_status' => (string) $access->status,
			],
		]
	);

	wp_send_json_success( [
		'message' => 'Service access отозван.',
		'acl'     => crm_service_telegram_get_user_acl_map( $company_id, [ $user_id ] )[ $user_id ] ?? crm_service_telegram_default_acl_summary(),
	] );
}
