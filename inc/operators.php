<?php
/**
 * Malibu Exchange — Operator Telegram bindings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'crm_operator_telegram_invite_statuses' ) ) {
function crm_operator_telegram_invite_statuses(): array {
	return [
		'new'     => 'Новый',
		'used'    => 'Использован',
		'expired' => 'Просрочен',
		'revoked' => 'Отозван',
	];
}
}

if ( ! function_exists( 'crm_operator_telegram_account_statuses' ) ) {
	function crm_operator_telegram_account_statuses(): array {
		return [
			'active'   => 'Привязан',
			'blocked'  => 'Заблокирован',
			'unlinked' => 'Отвязан',
		];
	}
}

if ( ! function_exists( 'crm_operator_telegram_user_list_statuses' ) ) {
	function crm_operator_telegram_user_list_statuses(): array {
		return [
			'active'     => 'Привязан',
			'blocked'    => 'Заблокирован',
			'unlinked'   => 'Отвязан',
			'not_linked' => 'Не привязан',
		];
	}
}

if ( ! function_exists( 'crm_can_manage_operator_telegram' ) ) {
	function crm_can_manage_operator_telegram(): bool {
		return is_user_logged_in() && crm_can_access( 'operators.telegram.view' );
	}
}

if ( ! function_exists( 'crm_can_invite_operator_telegram' ) ) {
	function crm_can_invite_operator_telegram(): bool {
		return is_user_logged_in() && crm_can_access( 'operators.telegram.invite' );
	}
}

if ( ! function_exists( 'crm_operator_telegram_badge_class' ) ) {
function crm_operator_telegram_badge_class( string $status ): string {
	if ( $status === 'active' || $status === 'used' ) {
		return 'success';
	}
	if ( $status === 'new' ) {
		return 'primary';
	}
	if ( $status === 'expired' ) {
		return 'warning';
	}
	if ( $status === 'revoked' || $status === 'unlinked' ) {
		return 'secondary';
	}
	if ( $status === 'blocked' ) {
		return 'danger';
	}

	return 'secondary';
}
}

if ( ! function_exists( 'crm_operator_telegram_generate_token' ) ) {
	function crm_operator_telegram_generate_token(): string {
		global $wpdb;

		for ( $i = 0; $i < 10; $i++ ) {
			$token = bin2hex( random_bytes( 24 ) );
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM crm_operator_telegram_invites WHERE invite_token = %s LIMIT 1',
					$token
				)
			);
			if ( $exists <= 0 ) {
				return $token;
			}
		}

		return bin2hex( random_bytes( 32 ) );
	}
}

if ( ! function_exists( 'crm_operator_telegram_generate_start_payload' ) ) {
	function crm_operator_telegram_generate_start_payload(): string {
		global $wpdb;

		for ( $i = 0; $i < 10; $i++ ) {
			$payload = 'op_' . bin2hex( random_bytes( 16 ) );
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM crm_operator_telegram_invites WHERE telegram_start_payload = %s LIMIT 1',
					$payload
				)
			);
			if ( $exists <= 0 ) {
				return $payload;
			}
		}

		return 'op_' . bin2hex( random_bytes( 20 ) );
	}
}

if ( ! function_exists( 'crm_operator_telegram_build_invite_url' ) ) {
function crm_operator_telegram_build_invite_url( string $bot_username, string $start_payload ): string {
	$bot_username  = crm_telegram_sanitize_bot_username( $bot_username );
	$start_payload = trim( $start_payload );

		if ( $bot_username === '' || $start_payload === '' ) {
			return '';
		}

	return 'https://t.me/' . rawurlencode( $bot_username ) . '?start=' . rawurlencode( $start_payload );
}

if ( ! function_exists( 'crm_operator_telegram_build_invite_url_from_row' ) ) {
	function crm_operator_telegram_build_invite_url_from_row( $invite ): string {
		$row = is_object( $invite ) ? get_object_vars( $invite ) : (array) $invite;

		$company_id   = isset( $row['company_id'] ) ? (int) $row['company_id'] : 0;
		$bot_username = trim( (string) ( $row['bot_username_snapshot'] ?? '' ) );
		if ( $bot_username === '' && $company_id > 0 ) {
			$bot_username = crm_telegram_collect_settings( $company_id, 'operator' )['bot_username'] ?? '';
		}

		return crm_operator_telegram_build_invite_url(
			$bot_username,
			(string) ( $row['telegram_start_payload'] ?? '' )
		);
	}
}

if ( ! function_exists( 'crm_operator_telegram_avatar_url_from_path' ) ) {
	function crm_operator_telegram_avatar_url_from_path( string $avatar_path ): string {
		$avatar_path = trim( $avatar_path );
		if ( $avatar_path === '' ) {
			return '';
		}

		$theme_dir   = wp_normalize_path( get_template_directory() );
		$avatar_norm = wp_normalize_path( $avatar_path );
		if ( strpos( $avatar_norm, $theme_dir ) !== 0 ) {
			return '';
		}

		return trailingslashit( get_template_directory_uri() ) . ltrim( str_replace( $theme_dir, '', $avatar_norm ), '/' );
	}
}

if ( ! function_exists( 'crm_operator_telegram_fetch_avatar' ) ) {
	function crm_operator_telegram_fetch_avatar( int $company_id, string $telegram_user_id, string $username = '', string $suffix = '' ): array {
		$result = [
			'file_id'    => '',
			'avatar_path'=> '',
			'avatar_url' => '',
			'error'      => '',
		];

		$telegram_user_id = trim( $telegram_user_id );
		if ( $company_id <= 0 || $telegram_user_id === '' ) {
			$result['error'] = 'Не указан Telegram user_id.';
			return $result;
		}

		$settings = crm_telegram_collect_settings( $company_id, 'operator' );
		$token    = trim( (string) ( $settings['bot_token'] ?? '' ) );
		if ( $token === '' ) {
			$result['error'] = 'Не настроен токен операторского Telegram-бота.';
			return $result;
		}

		$photos = crm_telegram_bot_api_request(
			$token,
			'getUserProfilePhotos',
			[
				'user_id' => $telegram_user_id,
				'offset'  => 0,
				'limit'   => 1,
			]
		);
		if ( empty( $photos['ok'] ) || empty( $photos['result']['photos'][0] ) || ! is_array( $photos['result']['photos'][0] ) ) {
			$result['error'] = ! empty( $photos['description'] ) ? (string) $photos['description'] : 'У пользователя нет доступной Telegram-аватарки.';
			return $result;
		}

		$variants = array_values( $photos['result']['photos'][0] );
		$photo    = end( $variants );
		$file_id  = ! empty( $photo['file_id'] ) ? (string) $photo['file_id'] : '';
		if ( $file_id === '' ) {
			$result['error'] = 'Telegram не вернул file_id аватарки.';
			return $result;
		}

		$file = crm_telegram_bot_api_request( $token, 'getFile', [ 'file_id' => $file_id ] );
		$file_path = ! empty( $file['result']['file_path'] ) ? (string) $file['result']['file_path'] : '';
		if ( empty( $file['ok'] ) || $file_path === '' ) {
			$result['error'] = ! empty( $file['description'] ) ? (string) $file['description'] : 'Не удалось получить файл аватарки Telegram.';
			return $result;
		}

		$extension = strtolower( (string) pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( $extension === '' || ! preg_match( '/^[a-z0-9]{2,5}$/', $extension ) ) {
			$extension = 'jpg';
		}

		$safe_username = preg_replace( '/[^A-Za-z0-9_\-]/', '', $username !== '' ? $username : $telegram_user_id );
		if ( $safe_username === '' ) {
			$safe_username = 'operator';
		}
		$suffix = preg_replace( '/[^A-Za-z0-9_\-]/', '', $suffix !== '' ? $suffix : (string) time() );
		if ( $suffix === '' ) {
			$suffix = (string) time();
		}

		$dir = trailingslashit( get_template_directory() ) . 'uploadbotfiles/operator-avatars/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			$result['error'] = 'Папка operator-avatars недоступна для записи.';
			return $result;
		}

		$url      = 'https://api.telegram.org/file/bot' . $token . '/' . ltrim( $file_path, '/' );
		$response = wp_remote_get( $url, [ 'timeout' => 20 ] );
		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			return $result;
		}
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$result['error'] = 'Telegram file API вернул HTTP ' . $status_code . '.';
			return $result;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( $body === '' ) {
			$result['error'] = 'Telegram вернул пустой файл аватарки.';
			return $result;
		}

		$avatar_path = $dir . 'operator_avatar_' . $company_id . '_' . $safe_username . '_' . $suffix . '.' . $extension;
		$saved       = file_put_contents( $avatar_path, $body );
		if ( ! $saved || ! is_file( $avatar_path ) ) {
			$result['error'] = 'Не удалось сохранить avatar-файл.';
			return $result;
		}

		$result['file_id']     = $file_id;
		$result['avatar_path'] = $avatar_path;
		$result['avatar_url']  = crm_operator_telegram_avatar_url_from_path( $avatar_path );

		return $result;
	}
}

if ( ! function_exists( 'crm_get_operator_telegram_invite_qr_url' ) ) {
	function crm_get_operator_telegram_invite_qr_url( string $invite_url, int $company_id, string $start_payload ): string {
		$invite_url    = trim( $invite_url );
		$start_payload = preg_replace( '/[^A-Za-z0-9_-]/', '', $start_payload );

		if ( $invite_url === '' || $company_id <= 0 || $start_payload === '' ) {
			return '';
		}

		if ( function_exists( 'crm_merchant_require_qrcode_lib' ) && ! crm_merchant_require_qrcode_lib() ) {
			return '';
		}
		if ( ! class_exists( 'QRcode' ) ) {
			return '';
		}

		$dir = trailingslashit( get_template_directory() ) . 'uploadbotfiles/operator-invites/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$file_name = 'operator_tg_invite_' . $company_id . '_' . $start_payload . '.png';
		$abs_path  = $dir . $file_name;
		if ( ! is_file( $abs_path ) ) {
			QRcode::png( $invite_url, $abs_path, QR_ECLEVEL_M, 8, 1 );
		}

		return trailingslashit( get_template_directory_uri() ) . 'uploadbotfiles/operator-invites/' . rawurlencode( $file_name );
	}
}
}

if ( ! function_exists( 'crm_operator_telegram_user_belongs_to_company' ) ) {
	function crm_operator_telegram_user_belongs_to_company( int $user_id, int $company_id ): bool {
		if ( $user_id <= 0 || $company_id <= 0 || crm_is_root( $user_id ) ) {
			return false;
		}

		global $wpdb;

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				 FROM crm_user_companies
				 WHERE user_id = %d
				   AND company_id = %d
				   AND is_primary = 1
				   AND status = 'active'
				 LIMIT 1",
				$user_id,
				$company_id
			)
		);

		return $exists > 0;
	}
}

if ( ! function_exists( 'crm_operator_telegram_expire_invites' ) ) {
function crm_operator_telegram_expire_invites( int $company_id = 0 ): int {
	global $wpdb;

	$where = "WHERE status = 'new' AND expires_at IS NOT NULL AND expires_at <= %s";
	$args  = [ current_time( 'mysql', true ) ];

	if ( $company_id > 0 ) {
		$where .= ' AND company_id = %d';
		$args[] = $company_id;
	}

	$sql = "UPDATE crm_operator_telegram_invites SET status = 'expired' {$where}";
	$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );

	return is_numeric( $result ) ? (int) $result : 0;
}
}

if ( ! function_exists( 'crm_operator_telegram_get_company_user_rows' ) ) {
	function crm_operator_telegram_get_company_user_rows( int $company_id ): array {
		if ( $company_id <= 0 ) {
			return [];
		}

		global $wpdb;

		$user_ids = array_values( array_filter(
			crm_get_company_user_ids( $company_id ),
			static fn( $user_id ) => ! crm_is_root( (int) $user_id )
		) );

		if ( empty( $user_ids ) ) {
			return [];
		}

		$users = get_users( [
			'include' => $user_ids,
			'orderby' => 'display_name',
			'order'   => 'ASC',
		] );
		$users = array_values( array_filter(
			$users,
			static fn( $user ) => $user instanceof WP_User && ! crm_is_root( (int) $user->ID )
		) );

		$user_ids = array_map( static fn( $user ) => (int) $user->ID, $users );
		$accounts = crm_get_accounts_for_users( $user_ids );
		$roles    = crm_get_roles_for_users( $user_ids );
		$ids_sql  = implode( ',', array_map( 'intval', $user_ids ) );

		$telegram_rows = $wpdb->get_results(
			"SELECT *
			 FROM crm_user_telegram_accounts
			 WHERE company_id = " . (int) $company_id . "
			   AND user_id IN ({$ids_sql})"
		) ?: [];
		$telegram_by_user = [];
		foreach ( $telegram_rows as $row ) {
			$telegram_by_user[ (int) $row->user_id ] = $row;
		}

		$invite_rows = $wpdb->get_results(
			"SELECT i.*
			 FROM crm_operator_telegram_invites i
			 JOIN (
			   SELECT user_id, MAX(id) AS max_id
			   FROM crm_operator_telegram_invites
			   WHERE company_id = " . (int) $company_id . "
			     AND user_id IN ({$ids_sql})
			   GROUP BY user_id
			 ) latest ON latest.max_id = i.id"
		) ?: [];
		$invite_by_user = [];
		foreach ( $invite_rows as $row ) {
			$invite_by_user[ (int) $row->user_id ] = $row;
		}

		$rows = [];
		foreach ( $users as $user ) {
			$user_id = (int) $user->ID;
			$rows[] = [
				'user'             => $user,
				'account'          => $accounts[ $user_id ] ?? null,
				'roles'            => $roles[ $user_id ] ?? [],
				'telegram_account' => $telegram_by_user[ $user_id ] ?? null,
				'last_invite'      => $invite_by_user[ $user_id ] ?? null,
			];
		}

		return $rows;
	}
}

if ( ! function_exists( 'crm_operator_telegram_format_user_row' ) ) {
	function crm_operator_telegram_format_user_row( object $row, array $roles = [] ): array {
		$user_id         = (int) ( $row->ID ?? $row->user_id ?? 0 );
		$display_name    = trim( (string) ( $row->display_name ?? '' ) );
		$user_login      = trim( (string) ( $row->user_login ?? '' ) );
		$telegram_status = ! empty( $row->telegram_account_id )
			? (string) ( $row->telegram_status ?? 'active' )
			: 'not_linked';
		$role_names      = array_values( array_filter( array_map(
			static fn( $role ) => (string) ( $role->name ?? '' ),
			$roles
		) ) );
		$role_codes      = array_values( array_filter( array_map(
			static fn( $role ) => (string) ( $role->code ?? '' ),
			$roles
		) ) );
		$crm_status      = (string) ( $row->crm_status ?? CRM_STATUS_ACTIVE );
		$last_status     = (string) ( $row->last_invite_status ?? '' );
		$profile_name    = trim(
			trim( (string) ( $row->telegram_first_name ?? '' ) ) . ' ' . trim( (string) ( $row->telegram_last_name ?? '' ) )
		);

		return [
			'id'                         => $user_id,
			'user_id'                    => $user_id,
			'name'                       => $display_name !== '' ? $display_name : $user_login,
			'user_login'                 => $user_login,
			'user_email'                 => (string) ( $row->user_email ?? '' ),
			'user_registered'            => (string) ( $row->user_registered ?? '' ),
			'crm_status'                 => $crm_status,
			'crm_status_label'           => crm_status_label( $crm_status ),
			'crm_status_badge'           => crm_status_badge_class( $crm_status ),
			'role_names'                 => $role_names,
			'role_codes'                 => $role_codes,
			'roles_label'                => ! empty( $role_names ) ? implode( ', ', $role_names ) : 'Без CRM-роли',
			'telegram_account_id'        => ! empty( $row->telegram_account_id ) ? (int) $row->telegram_account_id : null,
			'chat_id'                    => (string) ( $row->chat_id ?? '' ),
			'telegram_user_id'           => (string) ( $row->telegram_user_id ?? '' ),
			'telegram_username'          => (string) ( $row->telegram_username ?? '' ),
			'telegram_first_name'        => (string) ( $row->telegram_first_name ?? '' ),
			'telegram_last_name'         => (string) ( $row->telegram_last_name ?? '' ),
			'telegram_profile_name'      => $profile_name,
			'telegram_language_code'     => (string) ( $row->telegram_language_code ?? '' ),
			'telegram_avatar_url'        => (string) ( $row->telegram_avatar_url ?? '' ),
			'telegram_status'            => $telegram_status,
			'telegram_status_label'      => crm_operator_telegram_user_list_statuses()[ $telegram_status ] ?? $telegram_status,
			'telegram_status_badge'      => $telegram_status === 'not_linked' ? 'secondary' : crm_operator_telegram_badge_class( $telegram_status ),
			'linked_at'                  => (string) ( $row->linked_at ?? '' ),
			'last_seen_at'               => (string) ( $row->last_seen_at ?? '' ),
			'last_invite_id'             => ! empty( $row->last_invite_id ) ? (int) $row->last_invite_id : null,
			'last_invite_status'         => $last_status,
			'last_invite_status_label'   => $last_status !== '' ? ( crm_operator_telegram_invite_statuses()[ $last_status ] ?? $last_status ) : '',
			'last_invite_status_badge'   => $last_status !== '' ? crm_operator_telegram_badge_class( $last_status ) : '',
			'last_invite_expires_at'     => (string) ( $row->last_invite_expires_at ?? '' ),
			'last_invite_used_at'        => (string) ( $row->last_invite_used_at ?? '' ),
			'last_invite_created_at'     => (string) ( $row->last_invite_created_at ?? '' ),
			'last_invite_payload'        => (string) ( $row->last_invite_payload ?? '' ),
		];
	}
}

if ( ! function_exists( 'crm_operator_telegram_format_invite' ) ) {
function crm_operator_telegram_format_invite( object $invite ): array {
	$status        = (string) ( $invite->status ?? 'new' );
	$invite_url    = crm_operator_telegram_build_invite_url_from_row( $invite );
	$qr_url        = $invite_url !== ''
		? crm_get_operator_telegram_invite_qr_url( $invite_url, (int) $invite->company_id, (string) ( $invite->telegram_start_payload ?? '' ) )
		: '';
	$created_at_ts = ! empty( $invite->created_at ) ? strtotime( (string) $invite->created_at . ' UTC' ) : false;
	$expires_at_ts = ! empty( $invite->expires_at ) ? strtotime( (string) $invite->expires_at . ' UTC' ) : false;
	$used_at_ts    = ! empty( $invite->used_at ) ? strtotime( (string) $invite->used_at . ' UTC' ) : false;
	$creator_name  = '';
	if ( ! empty( $invite->creator_display_name ) ) {
		$creator_name = (string) $invite->creator_display_name;
	} elseif ( ! empty( $invite->creator_login ) ) {
		$creator_name = (string) $invite->creator_login;
	}
	$user_name = '';
	if ( ! empty( $invite->user_display_name ) ) {
		$user_name = (string) $invite->user_display_name;
	} elseif ( ! empty( $invite->user_login ) ) {
		$user_name = (string) $invite->user_login;
	}
	$ttl_minutes = 0;
	if ( false !== $created_at_ts && false !== $expires_at_ts ) {
		$ttl_minutes = max( 0, (int) round( ( $expires_at_ts - $created_at_ts ) / MINUTE_IN_SECONDS ) );
	}
	$linked_profile_name = trim(
		trim( (string) ( $invite->linked_telegram_first_name ?? '' ) ) . ' ' . trim( (string) ( $invite->linked_telegram_last_name ?? '' ) )
	);

	return [
		'id'                     => (int) $invite->id,
		'company_id'             => (int) $invite->company_id,
		'user_id'                => (int) $invite->user_id,
		'user_name'              => $user_name,
		'user_login'             => (string) ( $invite->user_login ?? '' ),
		'user_email'             => (string) ( $invite->user_email ?? '' ),
		'telegram_start_payload' => (string) ( $invite->telegram_start_payload ?? '' ),
		'bot_username_snapshot'  => (string) ( $invite->bot_username_snapshot ?? '' ),
		'invite_url'             => $invite_url,
		'qr_url'                 => $qr_url,
		'chat_id'                => (string) ( $invite->chat_id ?? '' ),
		'status'                 => $status,
		'status_label'           => crm_operator_telegram_invite_statuses()[ $status ] ?? $status,
		'status_badge'           => crm_operator_telegram_badge_class( $status ),
		'expires_at'             => (string) ( $invite->expires_at ?? '' ),
		'expires_at_ts'          => false !== $expires_at_ts ? (int) $expires_at_ts : null,
		'used_at'                => (string) ( $invite->used_at ?? '' ),
		'used_at_ts'             => false !== $used_at_ts ? (int) $used_at_ts : null,
		'used_by_chat_id'        => (string) ( $invite->used_by_chat_id ?? '' ),
		'linked_chat_id'         => (string) ( $invite->linked_chat_id ?? '' ),
		'linked_telegram_username' => (string) ( $invite->linked_telegram_username ?? '' ),
		'linked_telegram_first_name' => (string) ( $invite->linked_telegram_first_name ?? '' ),
		'linked_telegram_last_name' => (string) ( $invite->linked_telegram_last_name ?? '' ),
		'linked_telegram_profile_name' => $linked_profile_name,
		'linked_telegram_avatar_url' => (string) ( $invite->linked_telegram_avatar_url ?? '' ),
		'linked_telegram_status' => (string) ( $invite->linked_telegram_status ?? '' ),
		'linked_at'              => (string) ( $invite->linked_at ?? '' ),
		'created_at'             => (string) ( $invite->created_at ?? '' ),
		'created_at_ts'          => false !== $created_at_ts ? (int) $created_at_ts : null,
		'created_by_user_id'     => ! empty( $invite->created_by_user_id ) ? (int) $invite->created_by_user_id : null,
		'created_by_name'        => $creator_name,
		'ttl_minutes'            => $ttl_minutes,
	];
}
}
