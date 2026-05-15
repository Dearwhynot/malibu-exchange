<?php
/**
 * Malibu Exchange — Telegram operators binding handler
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'crm_operator_tg_find_invite_by_start_payload' ) ) {
	function crm_operator_tg_find_invite_by_start_payload( int $company_id, string $payload ): ?object {
		if ( $company_id <= 0 || $payload === '' ) {
			return null;
		}

		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT i.*, u.user_login, u.display_name, u.user_email
				 FROM crm_operator_telegram_invites i
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

if ( ! function_exists( 'crm_operator_tg_is_operator_context' ) ) {
	function crm_operator_tg_is_operator_context(): bool {
		return function_exists( 'crm_telegram_get_callback_bot_context' )
			&& crm_telegram_get_callback_bot_context() === 'operator';
	}
}

if ( ! function_exists( 'crm_operator_tg_is_linked_chat' ) ) {
	function crm_operator_tg_is_linked_chat( int $company_id, string $chat_id ): bool {
		if ( $company_id <= 0 || $chat_id === '' ) {
			return false;
		}

		global $wpdb;

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				 FROM crm_user_telegram_accounts
				 WHERE company_id = %d
				   AND chat_id = %s
				   AND status = 'active'
				 LIMIT 1",
				$company_id,
				$chat_id
			)
		);

		return $exists > 0;
	}
}

if ( ! function_exists( 'crm_operator_tg_should_bypass_acl' ) ) {
	function crm_operator_tg_should_bypass_acl( array $data, array $ctx = [] ): bool {
		if ( ! crm_operator_tg_is_operator_context() ) {
			return false;
		}

		$company_id = crm_telegram_get_callback_company_id();
		if ( $company_id <= 0 ) {
			return false;
		}

		$text = '';
		if ( isset( $data['message']['text'] ) ) {
			$text = (string) $data['message']['text'];
		}

		if ( strpos( trim( $text ), '/start' ) === 0 ) {
			return true;
		}

		if ( function_exists( 'crm_telegram_extract_start_payload_from_text' ) ) {
			$payload = crm_telegram_extract_start_payload_from_text( $text );
			if ( strpos( $payload, 'op_' ) === 0 ) {
				return true;
			}
		}

		$chat_id = isset( $ctx['chat_id'] ) ? crm_telegram_sanitize_chat_id_value( $ctx['chat_id'] ) : '';
		if ( $chat_id === '' && function_exists( 'tg_extract_ids' ) ) {
			$ids     = tg_extract_ids( $data );
			$chat_id = isset( $ids[0] ) ? crm_telegram_sanitize_chat_id_value( $ids[0] ) : '';
		}

		return $chat_id !== '' && crm_operator_tg_is_linked_chat( $company_id, $chat_id );
	}
}

if ( ! function_exists( 'crm_operator_tg_handle_start_command' ) ) {
	function crm_operator_tg_handle_start_command( string $text, array $ctx, $telegram, array $data ): bool {
		if ( ! crm_operator_tg_is_operator_context() ) {
			return false;
		}

		$company_id = crm_telegram_get_callback_company_id();
		if ( $company_id <= 0 ) {
			return false;
		}

		$payload = function_exists( 'crm_telegram_extract_start_payload_from_text' )
			? crm_telegram_extract_start_payload_from_text( $text )
			: '';

		$chat_id = crm_telegram_sanitize_chat_id_value( $ctx['chat_id'] ?? '' );
		$actor_id = crm_telegram_sanitize_chat_id_value( $ctx['actor_id'] ?? '' );
		$target_chat_id = $chat_id !== '' ? $chat_id : $actor_id;

		if ( $payload === '' ) {
			if ( $chat_id !== '' && crm_operator_tg_is_linked_chat( $company_id, $chat_id ) ) {
				bot_send_message( $telegram, $target_chat_id, "✅ <b>Операторский бот привязан</b>\n\nВаш Telegram уже связан с аккаунтом оператора." );
			} elseif ( $target_chat_id !== '' ) {
				bot_send_message( $telegram, $target_chat_id, 'Для привязки операторского бота используйте invite-ссылку из CRM.' );
			}
			return true;
		}

		if ( strpos( $payload, 'op_' ) !== 0 ) {
			return false;
		}

		crm_operator_telegram_expire_invites( $company_id );
		$invite = crm_operator_tg_find_invite_by_start_payload( $company_id, $payload );

		if ( ! $invite ) {
			if ( $target_chat_id !== '' ) {
				bot_send_message( $telegram, $target_chat_id, 'Операторский инвайт не найден. Попросите администратора компании выдать новую ссылку.' );
			}
			return true;
		}

		$status = (string) $invite->status;
		if ( $status !== 'new' ) {
			$message = 'Этот операторский инвайт уже недействителен.';
			if ( $status === 'expired' ) {
				$message = 'Срок действия invite-ссылки истёк. Попросите администратора выдать новую ссылку.';
			} elseif ( $status === 'revoked' ) {
				$message = 'Этот invite был отозван администратором.';
			} elseif ( $status === 'used' ) {
				$message = 'Этот invite уже использован.';
			}
			if ( $target_chat_id !== '' ) {
				bot_send_message( $telegram, $target_chat_id, $message );
			}
			return true;
		}

		if ( $chat_id === '' ) {
			if ( $target_chat_id !== '' ) {
				bot_send_message( $telegram, $target_chat_id, 'Не удалось определить Telegram chat_id. Попробуйте снова.' );
			}
			return true;
		}

		$user_id = (int) $invite->user_id;
		if ( ! crm_operator_telegram_user_belongs_to_company( $user_id, $company_id ) ) {
			if ( $target_chat_id !== '' ) {
				bot_send_message( $telegram, $target_chat_id, 'Аккаунт больше не принадлежит этой компании. Привязка отменена.' );
			}
			return true;
		}

		global $wpdb;

		$chat_owner_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id
				 FROM crm_user_telegram_accounts
				 WHERE company_id = %d
				   AND chat_id = %s
				   AND user_id <> %d
				   AND status = 'active'
				 LIMIT 1",
				$company_id,
				$chat_id,
				$user_id
			)
		);

		if ( $chat_owner_id > 0 ) {
			if ( $target_chat_id !== '' ) {
				bot_send_message( $telegram, $target_chat_id, 'Этот Telegram уже привязан к другому оператору компании.' );
			}
			return true;
		}

		$now = current_time( 'mysql', true );
		$avatar = [
			'file_id'     => '',
			'avatar_path' => '',
			'avatar_url'  => '',
		];
		if ( function_exists( 'crm_operator_telegram_fetch_avatar' ) ) {
			$avatar = crm_operator_telegram_fetch_avatar(
				$company_id,
				$actor_id !== '' ? $actor_id : $chat_id,
				(string) ( $ctx['username'] ?? 'operator' ),
				(string) ( $ctx['message_id'] ?? time() )
			);
		}
		$profile_json = wp_json_encode(
			[
				'chat_id'       => $chat_id,
				'actor_id'      => $actor_id,
				'first_name'    => (string) ( $ctx['first_name'] ?? '' ),
				'last_name'     => (string) ( $ctx['last_name'] ?? '' ),
				'username'      => (string) ( $ctx['username'] ?? '' ),
				'language_code' => (string) ( $ctx['language_code'] ?? '' ),
				'payload'       => $payload,
			],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM crm_user_telegram_accounts WHERE company_id = %d AND user_id = %d LIMIT 1',
				$company_id,
				$user_id
			)
		);

		$data_row = [
			'company_id'            => $company_id,
			'user_id'               => $user_id,
			'chat_id'               => $chat_id,
			'telegram_user_id'      => $actor_id !== '' ? $actor_id : $chat_id,
			'telegram_username'     => ! empty( $ctx['username'] ) ? ltrim( (string) $ctx['username'], '@' ) : null,
			'telegram_first_name'   => ! empty( $ctx['first_name'] ) ? (string) $ctx['first_name'] : null,
			'telegram_last_name'    => ! empty( $ctx['last_name'] ) ? (string) $ctx['last_name'] : null,
			'telegram_language_code'=> ! empty( $ctx['language_code'] ) ? (string) $ctx['language_code'] : null,
			'telegram_avatar_file_id'=> ! empty( $avatar['file_id'] ) ? (string) $avatar['file_id'] : null,
			'telegram_avatar_path'   => ! empty( $avatar['avatar_path'] ) ? (string) $avatar['avatar_path'] : null,
			'telegram_avatar_url'    => ! empty( $avatar['avatar_url'] ) ? (string) $avatar['avatar_url'] : null,
			'status'                => 'active',
			'linked_at'             => $now,
			'last_seen_at'          => $now,
			'profile_json'          => $profile_json !== false ? $profile_json : null,
		];

		if ( $existing_id > 0 ) {
			$wpdb->update(
				'crm_user_telegram_accounts',
				$data_row,
				[ 'id' => $existing_id ],
				[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
		} else {
			$data_row['created_at'] = $now;
			$wpdb->insert(
				'crm_user_telegram_accounts',
				$data_row,
				[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
		}

		$wpdb->update(
			'crm_operator_telegram_invites',
			[
				'chat_id'          => $chat_id,
				'status'           => 'used',
				'used_at'          => $now,
				'used_by_chat_id'  => $chat_id,
			],
			[ 'id' => (int) $invite->id ],
			[ '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		crm_log_entity(
			'operator.telegram.linked',
			'users',
			'update',
			'Telegram оператора привязан к CRM-пользователю',
			'operator_telegram_account',
			$user_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'invite_id'         => (int) $invite->id,
					'user_id'           => $user_id,
					'chat_id'           => $chat_id,
					'telegram_username' => ! empty( $ctx['username'] ) ? (string) $ctx['username'] : null,
					'avatar_refreshed'  => ! empty( $avatar['avatar_url'] ),
				],
			]
		);

		$display_name = trim( (string) ( $invite->display_name ?: $invite->user_login ) );
		if ( $target_chat_id !== '' ) {
			bot_send_message(
				$telegram,
				$target_chat_id,
				"✅ <b>Telegram привязан</b>\n\nАккаунт: " . esc_html( $display_name ) . "\nОператорский контур активирован."
			);
		}

		return true;
	}
}

if ( ! function_exists( 'crm_operator_tg_route_command' ) ) {
	function crm_operator_tg_route_command( string $command, string $text, array $ctx, $telegram, array $data ): bool {
		if ( ! crm_operator_tg_is_operator_context() ) {
			return false;
		}

		$company_id = crm_telegram_get_callback_company_id();
		$chat_id    = crm_telegram_sanitize_chat_id_value( $ctx['chat_id'] ?? '' );

		if ( $command === '/start' ) {
			return crm_operator_tg_handle_start_command( $text, $ctx, $telegram, $data );
		}

		if ( $company_id <= 0 || $chat_id === '' || ! crm_operator_tg_is_linked_chat( $company_id, $chat_id ) ) {
			if ( $chat_id !== '' ) {
				bot_send_message( $telegram, $chat_id, '⛔ Telegram не привязан к оператору. Используйте invite-ссылку из CRM.' );
			}
			return true;
		}

		if ( in_array( $command, [ '/menu', '/help' ], true ) ) {
			bot_send_message(
				$telegram,
				$chat_id,
				"✅ <b>Операторский бот активен</b>\n\nРабочие команды оператора будут добавлены следующим этапом."
			);
			return true;
		}

		if ( $command === '/chat_id' ) {
			bot_send_message( $telegram, $chat_id, 'chat_id: ' . $chat_id );
			return true;
		}

		if ( $command === '/ping' ) {
			bot_send_message( $telegram, $chat_id, 'pong ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC' );
			return true;
		}

		bot_send_message(
			$telegram,
			$chat_id,
			'Команда пока не настроена в операторском контуре. Используйте /menu или /help.'
		);

		return true;
	}
}
