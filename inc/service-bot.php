<?php
/**
 * Malibu Exchange — Service Telegram ACL and invite layer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'crm_service_telegram_invite_statuses' ) ) {
	function crm_service_telegram_invite_statuses(): array {
		return [
			'new'     => 'Новый',
			'used'    => 'Использован',
			'expired' => 'Просрочен',
			'revoked' => 'Отозван',
		];
	}
}

if ( ! function_exists( 'crm_service_telegram_access_statuses' ) ) {
	function crm_service_telegram_access_statuses(): array {
		return [
			'active'  => 'Активен',
			'blocked' => 'Заблокирован',
			'revoked' => 'Отозван',
		];
	}
}

if ( ! function_exists( 'crm_service_telegram_user_list_statuses' ) ) {
	function crm_service_telegram_user_list_statuses(): array {
		return [
			'active'       => 'Привязан',
			'invite_issued'=> 'Invite выдан',
			'revoked'      => 'Доступ отозван',
			'blocked'      => 'Доступ заблокирован',
			'profile_only' => 'Telegram есть, доступа нет',
			'not_linked'   => 'Не привязан',
		];
	}
}

if ( ! function_exists( 'crm_service_telegram_badge_class' ) ) {
	function crm_service_telegram_badge_class( string $status ): string {
		if ( in_array( $status, [ 'active', 'used' ], true ) ) {
			return 'success';
		}
		if ( in_array( $status, [ 'new', 'invite_issued' ], true ) ) {
			return 'primary';
		}
		if ( $status === 'expired' ) {
			return 'warning';
		}
		if ( in_array( $status, [ 'revoked', 'profile_only' ], true ) ) {
			return 'secondary';
		}
		if ( $status === 'blocked' ) {
			return 'danger';
		}

		return 'secondary';
	}
}

if ( ! function_exists( 'crm_can_manage_service_telegram' ) ) {
	function crm_can_manage_service_telegram(): bool {
		return is_user_logged_in() && crm_can_access( 'service.telegram.view' );
	}
}

if ( ! function_exists( 'crm_can_invite_service_telegram' ) ) {
	function crm_can_invite_service_telegram(): bool {
		return is_user_logged_in() && crm_can_access( 'service.telegram.invite' );
	}
}

if ( ! function_exists( 'crm_service_telegram_generate_token' ) ) {
	function crm_service_telegram_generate_token(): string {
		global $wpdb;

		for ( $i = 0; $i < 10; $i++ ) {
			$token = bin2hex( random_bytes( 24 ) );
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM crm_service_telegram_invites WHERE invite_token = %s LIMIT 1',
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

if ( ! function_exists( 'crm_service_telegram_generate_start_payload' ) ) {
	function crm_service_telegram_generate_start_payload(): string {
		global $wpdb;

		for ( $i = 0; $i < 10; $i++ ) {
			$payload = 'svc_' . bin2hex( random_bytes( 16 ) );
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM crm_service_telegram_invites WHERE telegram_start_payload = %s LIMIT 1',
					$payload
				)
			);
			if ( $exists <= 0 ) {
				return $payload;
			}
		}

		return 'svc_' . bin2hex( random_bytes( 20 ) );
	}
}

if ( ! function_exists( 'crm_service_telegram_invite_ttl_minutes' ) ) {
	function crm_service_telegram_invite_ttl_minutes(): int {
		return 60;
	}
}

if ( ! function_exists( 'crm_service_telegram_build_invite_url' ) ) {
	function crm_service_telegram_build_invite_url( string $bot_username, string $start_payload ): string {
		$bot_username  = crm_telegram_sanitize_bot_username( $bot_username );
		$start_payload = trim( $start_payload );

		if ( $bot_username === '' || $start_payload === '' ) {
			return '';
		}

		return 'https://t.me/' . rawurlencode( $bot_username ) . '?start=' . rawurlencode( $start_payload );
	}
}

if ( ! function_exists( 'crm_service_telegram_build_invite_url_from_row' ) ) {
	function crm_service_telegram_build_invite_url_from_row( $invite ): string {
		$row = is_object( $invite ) ? get_object_vars( $invite ) : (array) $invite;

		$company_id   = isset( $row['company_id'] ) ? (int) $row['company_id'] : 0;
		$bot_username = trim( (string) ( $row['bot_username_snapshot'] ?? '' ) );
		if ( $bot_username === '' && $company_id > 0 ) {
			$bot_username = crm_telegram_collect_settings( $company_id, 'service' )['bot_username'] ?? '';
		}

		return crm_service_telegram_build_invite_url(
			$bot_username,
			(string) ( $row['telegram_start_payload'] ?? '' )
		);
	}
}

if ( ! function_exists( 'crm_service_telegram_user_belongs_to_company' ) ) {
	function crm_service_telegram_user_belongs_to_company( int $user_id, int $company_id ): bool {
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

if ( ! function_exists( 'crm_service_telegram_validate_target_user' ) ) {
	function crm_service_telegram_validate_target_user( int $user_id, int $company_id ): array {
		if ( $user_id <= 0 || $company_id <= 0 ) {
			return [
				'ok'      => false,
				'message' => 'Некорректный пользователь или company context.',
				'user'    => null,
			];
		}

		if ( crm_is_root( $user_id ) ) {
			return [
				'ok'      => false,
				'message' => 'Root не участвует в service bot.',
				'user'    => null,
			];
		}

		if ( ! crm_service_telegram_user_belongs_to_company( $user_id, $company_id ) ) {
			return [
				'ok'      => false,
				'message' => 'Пользователь не найден в текущей компании.',
				'user'    => null,
			];
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return [
				'ok'      => false,
				'message' => 'Пользователь не найден.',
				'user'    => null,
			];
		}

		$status = crm_get_user_status( $user_id );
		if ( $status !== CRM_STATUS_ACTIVE ) {
			return [
				'ok'      => false,
				'message' => 'У пользователя неактивный CRM-статус. Service bot доступен только для active-пользователей.',
				'user'    => $user,
			];
		}

		if ( ! crm_user_has_permission( $user_id, 'service.telegram.view' ) ) {
			return [
				'ok'      => false,
				'message' => 'У пользователя нет CRM-permission service.telegram.view.',
				'user'    => $user,
			];
		}

		return [
			'ok'      => true,
			'message' => '',
			'user'    => $user,
		];
	}
}

if ( ! function_exists( 'crm_service_telegram_find_invite_by_start_payload' ) ) {
	function crm_service_telegram_find_invite_by_start_payload( int $company_id, string $payload ): ?object {
		if ( $company_id <= 0 || $payload === '' ) {
			return null;
		}

		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT i.*, u.user_login, u.display_name, u.user_email
				 FROM crm_service_telegram_invites i
				 JOIN {$wpdb->users} u ON u.ID = i.user_id
				 WHERE i.company_id = %d
				   AND i.telegram_start_payload = %s
				 LIMIT 1",
				$company_id,
				$payload
			)
		) ?: null;
	}
}

if ( ! function_exists( 'crm_service_telegram_expire_invites' ) ) {
	function crm_service_telegram_expire_invites( int $company_id = 0 ): int {
		global $wpdb;

		$where = "WHERE status = 'new' AND expires_at IS NOT NULL AND expires_at <= %s";
		$args  = [ current_time( 'mysql', true ) ];

		if ( $company_id > 0 ) {
			$where .= ' AND company_id = %d';
			$args[] = $company_id;
		}

		$sql = "UPDATE crm_service_telegram_invites SET status = 'expired' {$where}";
		$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );

		return is_numeric( $result ) ? (int) $result : 0;
	}
}

if ( ! function_exists( 'crm_service_telegram_default_acl_summary' ) ) {
	function crm_service_telegram_default_acl_summary(): array {
		return [
			'service_status'           => 'not_linked',
			'service_status_label'     => crm_service_telegram_user_list_statuses()['not_linked'],
			'service_status_badge'     => crm_service_telegram_badge_class( 'not_linked' ),
			'service_access_id'        => null,
			'service_access_status'    => '',
			'service_access_status_label' => '',
			'service_access_status_badge' => '',
			'service_access_granted_at'=> '',
			'service_access_last_seen_at' => '',
			'service_invite_id'        => null,
			'service_invite_status'    => '',
			'service_invite_status_label' => '',
			'service_invite_status_badge' => '',
			'service_invite_expires_at'=> '',
			'service_invite_created_at'=> '',
			'service_invite_used_at'   => '',
			'service_invite_url'       => '',
			'service_chat_id'          => '',
			'service_telegram_username'=> '',
			'service_profile_name'     => '',
			'has_active_service_access'=> false,
		];
	}
}

if ( ! function_exists( 'crm_service_telegram_determine_user_status' ) ) {
	function crm_service_telegram_determine_user_status( ?object $access, ?object $invite, ?object $profile ): string {
		if ( $access && ! empty( $access->status ) ) {
			$status = (string) $access->status;
			if ( isset( crm_service_telegram_user_list_statuses()[ $status ] ) ) {
				return $status;
			}
		}

		if ( $invite && (string) ( $invite->status ?? '' ) === 'new' ) {
			return 'invite_issued';
		}

		if ( $profile ) {
			return 'profile_only';
		}

		return 'not_linked';
	}
}

if ( ! function_exists( 'crm_service_telegram_get_user_acl_map' ) ) {
	function crm_service_telegram_get_user_acl_map( int $company_id, array $user_ids ): array {
		if ( $company_id <= 0 || empty( $user_ids ) ) {
			return [];
		}

		global $wpdb;

		$user_ids = array_values( array_unique( array_map( 'intval', $user_ids ) ) );
		$user_ids = array_values( array_filter( $user_ids, static fn( int $user_id ): bool => $user_id > 0 && ! crm_is_root( $user_id ) ) );
		if ( empty( $user_ids ) ) {
			return [];
		}

		$ids_sql = implode( ',', $user_ids );

		$profile_rows = $wpdb->get_results(
			"SELECT *
			 FROM crm_user_telegram_accounts
			 WHERE company_id = " . (int) $company_id . "
			   AND user_id IN ({$ids_sql})"
		) ?: [];
		$profiles_by_user = [];
		foreach ( $profile_rows as $row ) {
			$profiles_by_user[ (int) $row->user_id ] = $row;
		}

		$access_rows = $wpdb->get_results(
			"SELECT *
			 FROM crm_service_telegram_access
			 WHERE company_id = " . (int) $company_id . "
			   AND user_id IN ({$ids_sql})"
		) ?: [];
		$access_by_user = [];
		foreach ( $access_rows as $row ) {
			$access_by_user[ (int) $row->user_id ] = $row;
		}

		$invite_rows = $wpdb->get_results(
			"SELECT i.*
			 FROM crm_service_telegram_invites i
			 JOIN (
			   SELECT user_id, MAX(id) AS max_id
			   FROM crm_service_telegram_invites
			   WHERE company_id = " . (int) $company_id . "
			     AND user_id IN ({$ids_sql})
			   GROUP BY user_id
			 ) latest ON latest.max_id = i.id"
		) ?: [];
		$invite_by_user = [];
		foreach ( $invite_rows as $row ) {
			$invite_by_user[ (int) $row->user_id ] = $row;
		}

		$map = [];
		foreach ( $user_ids as $user_id ) {
			$profile = $profiles_by_user[ $user_id ] ?? null;
			$access  = $access_by_user[ $user_id ] ?? null;
			$invite  = $invite_by_user[ $user_id ] ?? null;
			$status  = crm_service_telegram_determine_user_status( $access, $invite, $profile );
			$profile_name = '';
			if ( $profile ) {
				$profile_name = trim(
					trim( (string) ( $profile->telegram_first_name ?? '' ) ) . ' ' . trim( (string) ( $profile->telegram_last_name ?? '' ) )
				);
			}

			$item = crm_service_telegram_default_acl_summary();
			$item['service_status']       = $status;
			$item['service_status_label'] = crm_service_telegram_user_list_statuses()[ $status ] ?? $status;
			$item['service_status_badge'] = crm_service_telegram_badge_class( $status );

			if ( $access ) {
				$item['service_access_id']           = (int) $access->id;
				$item['service_access_status']       = (string) $access->status;
				$item['service_access_status_label'] = crm_service_telegram_access_statuses()[ (string) $access->status ] ?? (string) $access->status;
				$item['service_access_status_badge'] = crm_service_telegram_badge_class( (string) $access->status );
				$item['service_access_granted_at']   = (string) ( $access->granted_at ?? '' );
				$item['service_access_last_seen_at'] = (string) ( $access->last_seen_at ?? '' );
				$item['has_active_service_access']   = (string) $access->status === 'active';
			}

			if ( $invite ) {
				$item['service_invite_id']            = (int) $invite->id;
				$item['service_invite_status']        = (string) $invite->status;
				$item['service_invite_status_label']  = crm_service_telegram_invite_statuses()[ (string) $invite->status ] ?? (string) $invite->status;
				$item['service_invite_status_badge']  = crm_service_telegram_badge_class( (string) $invite->status );
				$item['service_invite_expires_at']    = (string) ( $invite->expires_at ?? '' );
				$item['service_invite_created_at']    = (string) ( $invite->created_at ?? '' );
				$item['service_invite_used_at']       = (string) ( $invite->used_at ?? '' );
				$item['service_invite_url']           = crm_service_telegram_build_invite_url_from_row( $invite );
			}

			if ( $profile ) {
				$item['service_chat_id']           = (string) ( $profile->chat_id ?? '' );
				$item['service_telegram_username'] = (string) ( $profile->telegram_username ?? '' );
				$item['service_profile_name']      = $profile_name;
			}

			$map[ $user_id ] = $item;
		}

		return $map;
	}
}

if ( ! function_exists( 'crm_service_telegram_format_invite' ) ) {
	function crm_service_telegram_format_invite( object $invite ): array {
		$status        = (string) ( $invite->status ?? 'new' );
		$invite_url    = crm_service_telegram_build_invite_url_from_row( $invite );
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
		$linked_profile_name = trim(
			trim( (string) ( $invite->linked_telegram_first_name ?? '' ) ) . ' ' . trim( (string) ( $invite->linked_telegram_last_name ?? '' ) )
		);

		return [
			'id'                         => (int) $invite->id,
			'company_id'                 => (int) $invite->company_id,
			'user_id'                    => (int) $invite->user_id,
			'user_name'                  => $user_name,
			'user_login'                 => (string) ( $invite->user_login ?? '' ),
			'user_email'                 => (string) ( $invite->user_email ?? '' ),
			'telegram_start_payload'     => (string) ( $invite->telegram_start_payload ?? '' ),
			'bot_username_snapshot'      => (string) ( $invite->bot_username_snapshot ?? '' ),
			'invite_url'                 => $invite_url,
			'chat_id'                    => (string) ( $invite->chat_id ?? '' ),
			'status'                     => $status,
			'status_label'               => crm_service_telegram_invite_statuses()[ $status ] ?? $status,
			'status_badge'               => crm_service_telegram_badge_class( $status ),
			'expires_at'                 => (string) ( $invite->expires_at ?? '' ),
			'expires_at_ts'              => false !== $expires_at_ts ? (int) $expires_at_ts : null,
			'used_at'                    => (string) ( $invite->used_at ?? '' ),
			'used_at_ts'                 => false !== $used_at_ts ? (int) $used_at_ts : null,
			'used_by_chat_id'            => (string) ( $invite->used_by_chat_id ?? '' ),
			'linked_chat_id'             => (string) ( $invite->linked_chat_id ?? '' ),
			'linked_telegram_username'   => (string) ( $invite->linked_telegram_username ?? '' ),
			'linked_telegram_first_name' => (string) ( $invite->linked_telegram_first_name ?? '' ),
			'linked_telegram_last_name'  => (string) ( $invite->linked_telegram_last_name ?? '' ),
			'linked_telegram_profile_name' => $linked_profile_name,
			'linked_telegram_status'     => (string) ( $invite->linked_telegram_status ?? '' ),
			'linked_access_status'       => (string) ( $invite->linked_access_status ?? '' ),
			'linked_access_status_label' => ! empty( $invite->linked_access_status )
				? ( crm_service_telegram_access_statuses()[ (string) $invite->linked_access_status ] ?? (string) $invite->linked_access_status )
				: '',
			'linked_at'                  => (string) ( $invite->linked_at ?? '' ),
			'created_at'                 => (string) ( $invite->created_at ?? '' ),
			'created_at_ts'              => false !== $created_at_ts ? (int) $created_at_ts : null,
			'created_by_user_id'         => ! empty( $invite->created_by_user_id ) ? (int) $invite->created_by_user_id : null,
			'created_by_name'            => $creator_name,
		];
	}
}

