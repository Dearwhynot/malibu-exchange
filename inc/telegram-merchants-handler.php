<?php
/**
 * Malibu Exchange — Telegram merchants onboarding handler
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'crm_telegram_extract_start_payload_from_text' ) ) {
	function crm_telegram_extract_start_payload_from_text( string $text ): string {
		$text = trim( $text );
		if ( $text === '' || strpos( $text, '/start' ) !== 0 ) {
			return '';
		}

		$parts = preg_split( '/\s+/', $text, 2 );
		if ( empty( $parts[1] ) ) {
			return '';
		}

		return trim( (string) $parts[1] );
	}
}

if ( ! function_exists( 'crm_telegram_find_invite_by_start_payload' ) ) {
	function crm_telegram_find_invite_by_start_payload( int $company_id, string $payload ): ?object {
		global $wpdb;

		if ( $company_id <= 0 || $payload === '' ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT i.*,
				        c.name AS company_name,
				        c.code AS company_code
				 FROM crm_merchant_invites i
				 JOIN crm_companies c ON c.id = i.company_id
				 WHERE i.company_id = %d
				   AND i.telegram_start_payload = %s
				 LIMIT 1",
				$company_id,
				$payload
			)
		);
	}
}

if ( ! function_exists( 'crm_telegram_sanitize_chat_id_value' ) ) {
	function crm_telegram_sanitize_chat_id_value( $value ): string {
		$value = trim( (string) $value );
		return preg_match( '/^-?\d+$/', $value ) ? $value : '';
	}
}

if ( ! function_exists( 'tg_project_should_bypass_acl' ) ) {
	function tg_project_should_bypass_acl( array $data ): bool {
		$company_id = crm_telegram_get_callback_company_id();
		if ( $company_id <= 0 ) {
			return false;
		}

		$text = '';
		if ( isset( $data['message']['text'] ) ) {
			$text = (string) $data['message']['text'];
		}

		return crm_telegram_extract_start_payload_from_text( $text ) !== '';
	}
}

if ( ! function_exists( 'tg_project_handle_start_command' ) ) {
	function tg_project_handle_start_command( string $text, array $ctx, $telegram, array $data ): bool {
		global $wpdb;

		$company_id = crm_telegram_get_callback_company_id();
		if ( $company_id <= 0 ) {
			return false;
		}

		$payload = crm_telegram_extract_start_payload_from_text( $text );
		if ( $payload === '' ) {
			return false;
		}

		crm_expire_merchant_invites( $company_id );

		$invite = crm_telegram_find_invite_by_start_payload( $company_id, $payload );
		$chat_id = crm_telegram_sanitize_chat_id_value( $ctx['chat_id'] ?? '' );
		$actor_id = crm_telegram_sanitize_chat_id_value( $ctx['actor_id'] ?? '' );
		$target_chat_id = $chat_id !== '' ? $chat_id : $actor_id;

		if ( function_exists( 'tg_avatar_dbg' ) ) {
			tg_avatar_dbg(
				'merchant_start:received',
				[
					'company_id'      => $company_id,
					'payload'         => $payload,
					'invite_found'    => (bool) $invite,
					'invite_id'       => $invite ? (int) $invite->id : null,
					'chat_id'         => $chat_id,
					'actor_id'        => $actor_id,
					'target_chat_id'  => $target_chat_id,
					'username'        => (string) ( $ctx['username'] ?? '' ),
					'first_name'      => (string) ( $ctx['first_name'] ?? '' ),
					'last_name'       => (string) ( $ctx['last_name'] ?? '' ),
					'message_id'      => $ctx['message_id'] ?? null,
				]
			);
		}

		if ( ! $invite ) {
			if ( function_exists( 'bot_send_message' ) && $target_chat_id !== '' ) {
				bot_send_message( $telegram, $target_chat_id, 'Инвайт не найден. Попросите администратора компании выдать новую Telegram-ссылку.' );
			}

			crm_log_entity(
				'merchant.invite.invalid_start',
				'settings',
				'update',
				'Попытка активации несуществующего merchant invite',
				'merchant_invite',
				0,
				[
					'org_id'  => $company_id,
					'context' => [
						'telegram_start_payload' => $payload,
						'chat_id'                => $chat_id,
						'actor_id'               => $actor_id,
					],
				]
			);

			return true;
		}

		$status = (string) $invite->status;
		if ( $status === 'revoked' || $status === 'expired' || $status === 'used' ) {
			$message = 'Этот инвайт уже недействителен.';
			if ( $status === 'expired' ) {
				$message = 'Срок действия invite-ссылки истёк. Попросите администратора компании выдать новый инвайт.';
			} elseif ( $status === 'revoked' ) {
				$message = 'Этот инвайт был отозван администратором компании. Попросите новую ссылку.';
			} elseif ( $status === 'used' ) {
				$message = 'Этот инвайт уже использован и больше не действует.';
			}

			if ( function_exists( 'bot_send_message' ) && $target_chat_id !== '' ) {
				bot_send_message( $telegram, $target_chat_id, $message );
			}

			return true;
		}

		if ( $chat_id === '' ) {
			if ( function_exists( 'bot_send_message' ) && $target_chat_id !== '' ) {
				bot_send_message( $telegram, $target_chat_id, 'Не удалось определить ваш Telegram chat_id. Попробуйте снова или обратитесь к администратору.' );
			}
			return true;
		}

		$prefill = [];
		if ( ! empty( $invite->prefill_json ) ) {
			$prefill = json_decode( (string) $invite->prefill_json, true );
			if ( ! is_array( $prefill ) ) {
				$prefill = [];
			}
		}

		$markup_type = sanitize_key( (string) ( $prefill['base_markup_type'] ?? 'percent' ) );
		if ( ! isset( crm_merchant_markup_types()[ $markup_type ] ) ) {
			$markup_type = 'percent';
		}

		$markup_value = trim( (string) ( $prefill['base_markup_value'] ?? '0' ) );
		if ( $markup_value === '' || ! is_numeric( $markup_value ) ) {
			$markup_value = '0';
		}
		$markup_value = number_format( (float) $markup_value, 8, '.', '' );

		$display_name = trim( implode( ' ', array_filter( [
			(string) ( $ctx['first_name'] ?? '' ),
			(string) ( $ctx['last_name'] ?? '' ),
		] ) ) );
		if ( $display_name === '' && ! empty( $ctx['username'] ) ) {
			$display_name = '@' . ltrim( (string) $ctx['username'], '@' );
		}

		$avatar = [
			'file_id'    => null,
			'saved_path' => null,
		];
		if ( function_exists( 'tg_get_user_avatar' ) ) {
			$avatar = tg_get_user_avatar(
				$telegram,
				$actor_id !== '' ? $actor_id : $chat_id,
				(string) ( $ctx['username'] ?? 'merchant' ),
				$ctx['message_id'] ?? null
			);
		}

		if ( function_exists( 'tg_avatar_dbg' ) ) {
			tg_avatar_dbg(
				'merchant_start:avatar_helper_result',
				[
					'company_id'    => $company_id,
					'invite_id'     => (int) $invite->id,
					'chat_id'       => $chat_id,
					'actor_id'      => $actor_id,
					'file_id'       => ! empty( $avatar['file_id'] ) ? (string) $avatar['file_id'] : '',
					'saved'         => ! empty( $avatar['saved'] ),
					'saved_path'    => ! empty( $avatar['saved_path'] ) ? (string) $avatar['saved_path'] : '',
					'file_name'     => ! empty( $avatar['file_patch_avatar'] ) ? (string) $avatar['file_patch_avatar'] : '',
				]
			);
		}

		$avatar_path = '';
		$avatar_url  = '';
		if ( ! empty( $avatar['saved_path'] ) ) {
			$avatar_path = (string) $avatar['saved_path'];
			$theme_dir   = wp_normalize_path( get_template_directory() );
			$avatar_norm = wp_normalize_path( $avatar_path );
			if ( strpos( $avatar_norm, $theme_dir ) === 0 ) {
				$avatar_url = trailingslashit( get_template_directory_uri() ) . ltrim( str_replace( $theme_dir, '', $avatar_norm ), '/' );
			}
		}

		if ( function_exists( 'tg_avatar_dbg' ) ) {
			tg_avatar_dbg(
				'merchant_start:avatar_resolved',
				[
					'company_id'   => $company_id,
					'invite_id'    => (int) $invite->id,
					'chat_id'      => $chat_id,
					'avatar_path'  => $avatar_path,
					'avatar_url'   => $avatar_url,
					'path_exists'  => $avatar_path !== '' ? is_file( $avatar_path ) : false,
				]
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
				'message_id'    => $ctx['message_id'] ?? null,
				'payload'       => $payload,
			],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$duplicate = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id
				 FROM crm_merchants
				 WHERE company_id = %d
				   AND chat_id = %s
				 LIMIT 1",
				$company_id,
				$chat_id
			)
		);

		if ( $duplicate ) {
			$update_data = [
				'telegram_username'      => ! empty( $ctx['username'] ) ? ltrim( (string) $ctx['username'], '@' ) : null,
				'telegram_first_name'    => ! empty( $ctx['first_name'] ) ? (string) $ctx['first_name'] : null,
				'telegram_last_name'     => ! empty( $ctx['last_name'] ) ? (string) $ctx['last_name'] : null,
				'telegram_language_code' => ! empty( $ctx['language_code'] ) ? (string) $ctx['language_code'] : null,
				'telegram_profile_json'  => $profile_json !== false ? $profile_json : null,
			];
			$update_format = [ '%s', '%s', '%s', '%s', '%s' ];

			if ( ! empty( $avatar['file_id'] ) ) {
				$update_data['telegram_avatar_file_id'] = (string) $avatar['file_id'];
				$update_format[] = '%s';
			}
			if ( $avatar_path !== '' ) {
				$update_data['telegram_avatar_path'] = $avatar_path;
				$update_format[] = '%s';
			}
			if ( $avatar_url !== '' ) {
				$update_data['telegram_avatar_url'] = $avatar_url;
				$update_format[] = '%s';
			}

			$update_ok = $wpdb->update(
				'crm_merchants',
				$update_data,
				[
					'id' => (int) $duplicate->id,
				],
				$update_format,
				[ '%d' ]
			);

			if ( function_exists( 'tg_avatar_dbg' ) ) {
				tg_avatar_dbg(
					'merchant_start:duplicate_updated',
					[
						'company_id'      => $company_id,
						'invite_id'       => (int) $invite->id,
						'merchant_id'     => (int) $duplicate->id,
						'chat_id'         => $chat_id,
						'avatar_file_id'  => ! empty( $avatar['file_id'] ) ? (string) $avatar['file_id'] : '',
						'avatar_path'     => $avatar_path,
						'avatar_url'      => $avatar_url,
						'wpdb_result'     => $update_ok,
						'wpdb_last_error' => $wpdb->last_error,
					]
				);
			}

			if ( function_exists( 'bot_send_message' ) ) {
				bot_send_message(
					$telegram,
					$target_chat_id,
					'В этой компании уже есть мерчант с вашим chat_id. Профиль не создан заново, но Telegram-данные и аватар обновлены.'
				);
			}

			crm_log_entity(
				'merchant.invite.duplicate_chat',
				'users',
				'update',
				'Telegram invite упёрся в существующий chat_id мерчанта и обновил Telegram-профиль',
				'merchant_invite',
				(int) $invite->id,
				[
					'org_id'  => $company_id,
					'context' => [
						'merchant_id'      => (int) $duplicate->id,
						'chat_id'          => $chat_id,
						'avatar_refreshed' => $avatar_url !== '',
					],
				]
			);

			return true;
		}

		$created_at = current_time( 'mysql', true );
		$insert_ok = $wpdb->insert(
			'crm_merchants',
			[
				'company_id'             => $company_id,
				'chat_id'                => $chat_id,
				'telegram_username'      => ! empty( $ctx['username'] ) ? ltrim( (string) $ctx['username'], '@' ) : null,
				'telegram_first_name'    => ! empty( $ctx['first_name'] ) ? (string) $ctx['first_name'] : null,
				'telegram_last_name'     => ! empty( $ctx['last_name'] ) ? (string) $ctx['last_name'] : null,
				'telegram_language_code' => ! empty( $ctx['language_code'] ) ? (string) $ctx['language_code'] : null,
				'telegram_avatar_file_id'=> ! empty( $avatar['file_id'] ) ? (string) $avatar['file_id'] : null,
				'telegram_avatar_path'   => $avatar_path !== '' ? $avatar_path : null,
				'telegram_avatar_url'    => $avatar_url !== '' ? $avatar_url : null,
				'name'                   => $display_name !== '' ? $display_name : null,
				'status'                 => CRM_MERCHANT_STATUS_PENDING,
				'base_markup_type'       => $markup_type,
				'base_markup_value'      => $markup_value,
				'note'                   => ! empty( $prefill['note'] ) ? sanitize_textarea_field( (string) $prefill['note'] ) : null,
				'created_by_user_id'     => ! empty( $invite->created_by_user_id ) ? (int) $invite->created_by_user_id : null,
				'invited_via_invite_id'  => (int) $invite->id,
				'invited_at'             => $created_at,
				'activated_at'           => null,
				'telegram_profile_json'  => $profile_json !== false ? $profile_json : null,
			],
			[
				'%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
				'%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s',
			]
		);

		$merchant_id = (int) $wpdb->insert_id;

		if ( function_exists( 'tg_avatar_dbg' ) ) {
			tg_avatar_dbg(
				'merchant_start:merchant_insert',
				[
					'company_id'      => $company_id,
					'invite_id'       => (int) $invite->id,
					'merchant_id'     => $merchant_id,
					'chat_id'         => $chat_id,
					'avatar_file_id'  => ! empty( $avatar['file_id'] ) ? (string) $avatar['file_id'] : '',
					'avatar_path'     => $avatar_path,
					'avatar_url'      => $avatar_url,
					'wpdb_result'     => $insert_ok,
					'wpdb_last_error' => $wpdb->last_error,
				]
			);
		}

		if ( $merchant_id <= 0 ) {
			if ( function_exists( 'bot_send_message' ) ) {
				bot_send_message( $telegram, $target_chat_id, 'Не удалось создать профиль мерчанта. Попробуйте позже или обратитесь к администратору компании.' );
			}

			return true;
		}

		$wpdb->update(
			'crm_merchant_invites',
			[
				'merchant_id'      => $merchant_id,
				'chat_id'          => $chat_id,
				'status'           => 'used',
				'used_at'          => $created_at,
				'used_by_chat_id'  => $chat_id,
			],
			[
				'id' => (int) $invite->id,
			],
			[ '%d', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		crm_log_entity(
			'merchant.created.from_telegram',
			'users',
			'create',
			'Создан мерчант по Telegram invite',
			'merchant',
			$merchant_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'invite_id'            => (int) $invite->id,
					'telegram_start_payload'=> $payload,
					'chat_id'              => $chat_id,
					'telegram_username'    => ! empty( $ctx['username'] ) ? (string) $ctx['username'] : null,
				],
			]
		);

		crm_log_entity(
			'merchant.invite.activated',
			'users',
			'update',
			'Telegram invite активирован и привязал нового мерчанта',
			'merchant_invite',
			(int) $invite->id,
			[
				'org_id'  => $company_id,
				'context' => [
					'merchant_id' => $merchant_id,
					'chat_id'     => $chat_id,
				],
			]
		);

		if ( function_exists( 'bot_send_message' ) ) {
			bot_send_message(
				$telegram,
				$target_chat_id,
				"✅ <b>Профиль создан</b>\n\nВаш Telegram успешно привязан к мерчанту.\nСейчас профиль находится в статусе ожидания активации.\n\n⏳ Дождитесь подтверждения от администратора компании. После активации бот откроет вам рабочее меню."
			);
		}

		return true;
	}
}
