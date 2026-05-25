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
				$access = function_exists( 'crm_operator_tg_access_context' )
					? crm_operator_tg_access_context( $company_id, $chat_id )
					: [ 'allowed' => false, 'account' => null ];
				if ( ! empty( $access['allowed'] ) && ! empty( $access['account'] ) && function_exists( 'crm_operator_tg_present_screen' ) ) {
					return crm_operator_tg_present_screen( $telegram, $company_id, $chat_id, $access['account'], $ctx, 'main', true );
				}

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

		if ( function_exists( 'crm_operator_tg_access_context' ) && function_exists( 'crm_operator_tg_present_screen' ) ) {
			$access = crm_operator_tg_access_context( $company_id, $chat_id );
			if ( ! empty( $access['allowed'] ) && ! empty( $access['account'] ) ) {
				crm_operator_tg_present_screen( $telegram, $company_id, $chat_id, $access['account'], $ctx, 'main', true );
			}
		}

		return true;
	}
}

if ( ! function_exists( 'crm_operator_tg_access_context' ) ) {
	function crm_operator_tg_access_context( int $company_id, string $chat_id ): array {
		$chat_id = crm_telegram_sanitize_chat_id_value( $chat_id );

		if ( $company_id <= 0 || $chat_id === '' ) {
			return [
				'allowed' => false,
				'message' => 'Операторский бот настроен некорректно: отсутствует company context.',
				'account' => null,
			];
		}

		global $wpdb;

		$account = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.*, u.user_login, u.display_name, u.user_email
				 FROM crm_user_telegram_accounts a
				 JOIN {$wpdb->users} u ON u.ID = a.user_id
				 WHERE a.company_id = %d
				   AND a.chat_id = %s
				 LIMIT 1",
				$company_id,
				$chat_id
			)
		);

		if ( ! $account ) {
			return [
				'allowed' => false,
				'message' => '⛔ Telegram не привязан к оператору. Используйте invite-ссылку из CRM.',
				'account' => null,
			];
		}

		$user_id = (int) ( $account->user_id ?? 0 );
		if ( $user_id <= 0 || crm_is_root( $user_id ) || ! crm_operator_telegram_user_belongs_to_company( $user_id, $company_id ) ) {
			return [
				'allowed' => false,
				'message' => '⛔ Аккаунт оператора больше недоступен в этой компании.',
				'account' => $account,
			];
		}

		$status = (string) ( $account->status ?? '' );
		if ( $status !== 'active' ) {
			$label = crm_operator_telegram_account_statuses()[ $status ] ?? $status;
			return [
				'allowed' => false,
				'message' => '⛔ Telegram-профиль оператора сейчас недоступен. Статус: ' . $label . '.',
				'account' => $account,
			];
		}

		return [
			'allowed' => true,
			'message' => '',
			'account' => $account,
		];
	}
}

if ( ! function_exists( 'crm_operator_tg_company_input_mode' ) ) {
	function crm_operator_tg_company_input_mode( int $company_id ): string {
		if ( $company_id <= 0 || ! function_exists( 'crm_fintech_company_web_create_order_input_mode' ) ) {
			return 'usdt';
		}

		return (string) crm_fintech_company_web_create_order_input_mode( $company_id );
	}
}

if ( ! function_exists( 'crm_operator_tg_supported_invoice_modes' ) ) {
	function crm_operator_tg_supported_invoice_modes(): array {
		return [
			'rub',
			'usdt',
			'rub_usdt',
			'rub_usdt_live',
			'rub_thb_rub_rapira',
			'rub_thb_rub_live',
			'rub_thb_thb_rapira',
			'rub_thb_thb_live',
		];
	}
}

if ( ! function_exists( 'crm_operator_tg_company_supported_invoice_modes' ) ) {
	function crm_operator_tg_company_supported_invoice_modes( int $company_id ): array {
		if ( $company_id <= 0 ) {
			return [ 'usdt' ];
		}

		if ( function_exists( 'crm_fintech_company_web_create_order_supported_modes' ) ) {
			$modes = array_values(
				array_filter(
					array_map(
						'sanitize_key',
						(array) crm_fintech_company_web_create_order_supported_modes( $company_id )
					)
				)
			);

			if ( ! empty( $modes ) ) {
				return $modes;
			}
		}

		return [ crm_operator_tg_company_input_mode( $company_id ) ];
	}
}

if ( ! function_exists( 'crm_operator_tg_company_default_invoice_mode' ) ) {
	function crm_operator_tg_company_default_invoice_mode( int $company_id ): string {
		$modes = crm_operator_tg_company_supported_invoice_modes( $company_id );

		return ! empty( $modes ) ? (string) $modes[0] : 'usdt';
	}
}

if ( ! function_exists( 'crm_operator_tg_normalize_invoice_mode' ) ) {
	function crm_operator_tg_normalize_invoice_mode( string $mode, int $company_id ): string {
		$mode = sanitize_key( $mode );
		$company_modes = crm_operator_tg_company_supported_invoice_modes( $company_id );

		if ( in_array( $mode, crm_operator_tg_supported_invoice_modes(), true ) && in_array( $mode, $company_modes, true ) ) {
			return $mode;
		}

		return crm_operator_tg_company_default_invoice_mode( $company_id );
	}
}

if ( ! function_exists( 'crm_operator_tg_invoice_mode_label' ) ) {
	function crm_operator_tg_invoice_mode_label( string $mode ): string {
		if ( function_exists( 'crm_fintech_create_order_mode_label' ) ) {
			return crm_fintech_create_order_mode_label( $mode );
		}

		return 'Legacy USDT / orderAmount';
	}
}

if ( ! function_exists( 'crm_operator_tg_invoice_mode_input_currency' ) ) {
	function crm_operator_tg_invoice_mode_input_currency( string $mode ): string {
		if ( function_exists( 'crm_fintech_create_order_mode_input_currency' ) ) {
			return crm_fintech_create_order_mode_input_currency( $mode );
		}

		return $mode === 'rub' ? 'RUB' : 'USDT';
	}
}

if ( ! function_exists( 'crm_operator_tg_invoice_mode_input_example' ) ) {
	function crm_operator_tg_invoice_mode_input_example( string $mode ): string {
		if ( function_exists( 'crm_fintech_create_order_mode_input_example' ) ) {
			return crm_fintech_create_order_mode_input_example( $mode );
		}

		return crm_operator_tg_invoice_mode_input_currency( $mode ) === 'RUB' ? '30000' : '150.50';
	}
}

if ( ! function_exists( 'crm_operator_tg_invoice_mode_button_label' ) ) {
	function crm_operator_tg_invoice_mode_button_label( string $mode ): string {
		$mode = sanitize_key( $mode );

		switch ( $mode ) {
			case 'rub':
				return 'RUB paymentAmount';
			case 'rub_usdt':
				return 'RUB -> USDT · Rapira';
			case 'rub_usdt_live':
				return 'RUB -> USDT · Live';
			case 'rub_thb_rub_rapira':
				return 'THB · RUB input · Rapira';
			case 'rub_thb_rub_live':
				return 'THB · RUB input · Live';
			case 'rub_thb_thb_rapira':
				return 'THB · THB input · Rapira';
			case 'rub_thb_thb_live':
				return 'THB · THB input · Live';
			case 'usdt':
			default:
				return 'Legacy USDT';
		}
	}
}

if ( ! function_exists( 'crm_operator_tg_default_payment_purpose' ) ) {
	function crm_operator_tg_default_payment_purpose( int $company_id ): string {
		if ( $company_id <= 0 || ! function_exists( 'crm_fintech_get_pay2day_default_payment_purpose' ) ) {
			return '';
		}

		return crm_fintech_get_pay2day_default_payment_purpose( $company_id );
	}
}

if ( ! function_exists( 'crm_operator_tg_session_key' ) ) {
	function crm_operator_tg_session_key( int $company_id, string $chat_id ): string {
		return 'crm_operator_tg_' . $company_id . '_' . md5( $chat_id );
	}
}

if ( ! function_exists( 'crm_operator_tg_session_defaults' ) ) {
	function crm_operator_tg_session_defaults( int $company_id ): array {
		return [
			'last_menu_message_id'   => 0,
			'last_screen'            => 'main',
			'invoice_awaiting'       => '',
			'invoice_custom_purpose' => '',
			'invoice_mode'           => crm_operator_tg_normalize_invoice_mode( '', $company_id ),
		];
	}
}

if ( ! function_exists( 'crm_operator_tg_session_get' ) ) {
	function crm_operator_tg_session_get( int $company_id, string $chat_id ): array {
		$chat_id = crm_telegram_sanitize_chat_id_value( $chat_id );
		if ( $company_id <= 0 || $chat_id === '' ) {
			return crm_operator_tg_session_defaults( $company_id );
		}

		$stored = get_transient( crm_operator_tg_session_key( $company_id, $chat_id ) );

		return array_merge(
			crm_operator_tg_session_defaults( $company_id ),
			is_array( $stored ) ? $stored : []
		);
	}
}

if ( ! function_exists( 'crm_operator_tg_session_save' ) ) {
	function crm_operator_tg_session_save( int $company_id, string $chat_id, array $session ): array {
		$chat_id = crm_telegram_sanitize_chat_id_value( $chat_id );
		if ( $company_id <= 0 || $chat_id === '' ) {
			return crm_operator_tg_session_defaults( $company_id );
		}

		$normalized = array_merge( crm_operator_tg_session_defaults( $company_id ), $session );
		$normalized['invoice_mode'] = crm_operator_tg_normalize_invoice_mode(
			(string) ( $normalized['invoice_mode'] ?? '' ),
			$company_id
		);
		set_transient( crm_operator_tg_session_key( $company_id, $chat_id ), $normalized, 30 * DAY_IN_SECONDS );

		return $normalized;
	}
}

if ( ! function_exists( 'crm_operator_tg_session_patch' ) ) {
	function crm_operator_tg_session_patch( int $company_id, string $chat_id, array $fields ): array {
		$session = crm_operator_tg_session_get( $company_id, $chat_id );

		return crm_operator_tg_session_save( $company_id, $chat_id, array_merge( $session, $fields ) );
	}
}

if ( ! function_exists( 'crm_operator_tg_effective_payment_purpose' ) ) {
	function crm_operator_tg_effective_payment_purpose( int $company_id, array $session = [] ): string {
		$custom  = function_exists( 'crm_fintech_normalize_payment_purpose' )
			? crm_fintech_normalize_payment_purpose( (string) ( $session['invoice_custom_purpose'] ?? '' ) )
			: sanitize_text_field( (string) ( $session['invoice_custom_purpose'] ?? '' ) );
		$default = crm_operator_tg_default_payment_purpose( $company_id );

		return $custom !== '' ? $custom : $default;
	}
}

if ( ! function_exists( 'crm_operator_tg_display_name' ) ) {
	function crm_operator_tg_display_name( object $account ): string {
		$name = trim( (string) ( $account->display_name ?? '' ) );
		if ( $name !== '' ) {
			return $name;
		}

		$name = trim( (string) ( $account->user_login ?? '' ) );
		if ( $name !== '' ) {
			return $name;
		}

		return 'Оператор';
	}
}

if ( ! function_exists( 'crm_operator_tg_rate_preview_context' ) ) {
	function crm_operator_tg_rate_preview_context( int $company_id, float $requested_rub = 0.0, bool $refresh_market = false ): array {
		$requested_rub = round( max( 0, $requested_rub ), 2 );
		$company_markup_percent = $company_id > 0 && function_exists( 'crm_fintech_get_kanyon_rapira_markup_percent' )
			? (float) crm_fintech_get_kanyon_rapira_markup_percent( $company_id )
			: 0.0;
		$context = [
			'success'                => false,
			'error'                  => 'Не удалось получить курс operator contour.',
			'warning'                => '',
			'rapira_ask'             => null,
			'company_markup_percent' => round( max( 0, $company_markup_percent ), 4 ),
			'current_rate'           => null,
			'checked_at'             => current_time( 'd.m.Y H:i' ),
			'payment_amount_rub'     => $requested_rub,
			'payable_usdt'           => 0.0,
			'sample_requested_rub'   => 30000.0,
			'sample_payable_usdt'    => 0.0,
		];

		if ( $company_id <= 0 ) {
			$context['error'] = 'Отсутствует company context.';
			return $context;
		}

		$market = $refresh_market ? rates_get_rapira() : rates_get_rapira_cached();
		if ( $refresh_market && ! empty( $market['ok'] ) ) {
			set_transient( 'me_rapira_rates', $market, RATES_MARKET_CACHE_TTL );
		}

		$rapira_ask = ( ! empty( $market['ok'] ) && ! empty( $market['ask'] ) && (float) $market['ask'] > 0 )
			? round( (float) $market['ask'], 8 )
			: null;

		if ( $rapira_ask !== null && function_exists( 'crm_merchant_calculate_rub_invoice_economics' ) ) {
			$economics = crm_merchant_calculate_rub_invoice_economics(
				$rapira_ask,
				$company_markup_percent,
				0.0,
				'acquirer_cost',
				max( $requested_rub, 30000.0 ),
				'none'
			);

			$current_rate = isset( $economics['merchant_rate_commercial'] )
				? (float) $economics['merchant_rate_commercial']
				: 0.0;

			if ( $current_rate > 0 ) {
				$context['success']              = true;
				$context['error']                = '';
				$context['rapira_ask']           = $rapira_ask;
				$context['current_rate']         = $current_rate;
				$context['sample_requested_rub'] = (float) ( $economics['requested_rub_input'] ?? 30000.0 );
				$context['sample_payable_usdt']  = (float) ( $economics['merchant_payable_usdt'] ?? 0.0 );
				$context['payment_amount_rub']   = $requested_rub;
				$context['payable_usdt']         = $requested_rub > 0
					? round( $requested_rub / $current_rate, 4 )
					: 0.0;

				return $context;
			}
		}

		if ( function_exists( 'rates_kanyon_get_last' ) ) {
			$last = rates_kanyon_get_last( $company_id );
			if ( is_array( $last ) && ! empty( $last['kanyon_rate'] ) && (float) $last['kanyon_rate'] > 0 ) {
				$current_rate = round( (float) $last['kanyon_rate'], 4 );
				$sample_rub   = 30000.0;

				$context['success']              = true;
				$context['error']                = '';
				$context['warning']              = 'Rapira временно недоступна, показываю последний сохранённый курс Kanyon.';
				$context['current_rate']         = $current_rate;
				$context['checked_at']           = ! empty( $last['created_at'] ) ? mysql2date( 'd.m.Y H:i', (string) $last['created_at'] ) : $context['checked_at'];
				$context['sample_requested_rub'] = $sample_rub;
				$context['sample_payable_usdt']  = round( $sample_rub / $current_rate, 4 );
				$context['payment_amount_rub']   = $requested_rub;
				$context['payable_usdt']         = $requested_rub > 0 ? round( $requested_rub / $current_rate, 4 ) : 0.0;
			}
		}

		return $context;
	}
}

if ( ! function_exists( 'crm_operator_tg_invoice_mode_help_text' ) ) {
	function crm_operator_tg_invoice_mode_help_text( string $mode ): string {
		$mode = sanitize_key( $mode );

		switch ( $mode ) {
			case 'rub':
				return 'Оператор вводит RUB, а ордер создаётся через paymentAmount в RUB.';
			case 'rub_usdt':
				return 'Оператор вводит RUB, а USDT рассчитываются по Rapira Ask + 4%.';
			case 'rub_usdt_live':
				return 'Оператор вводит RUB, перед выпуском счёта делаем live quote через test-order 100 USDT.';
			case 'rub_thb_rub_rapira':
				return 'Оператор вводит RUB отдачи, THB считаются по "Наш Sberbank", USDT quote идёт через Rapira + 4%.';
			case 'rub_thb_rub_live':
				return 'Оператор вводит RUB отдачи, THB считаются по "Наш Sberbank", USDT quote идёт через live Kanyon.';
			case 'rub_thb_thb_rapira':
				return 'Оператор вводит THB получения, счёт в RUB считается по "Наш Sberbank", USDT quote идёт через Rapira + 4%.';
			case 'rub_thb_thb_live':
				return 'Оператор вводит THB получения, счёт в RUB считается по "Наш Sberbank", USDT quote идёт через live Kanyon.';
			case 'usdt':
			default:
				return 'Оператор вводит USDT, а RUB сумму для клиента рассчитывает провайдер в момент выпуска счёта.';
		}
	}
}

if ( ! function_exists( 'crm_operator_tg_invoice_loading_text' ) ) {
	function crm_operator_tg_invoice_loading_text( string $mode, string $input_currency = '' ): string {
		$mode           = sanitize_key( $mode );
		$input_currency = trim( strtoupper( $input_currency ) );
		$line2          = 'Проверяем параметры и запрашиваем QR-код.';

		if ( $mode === 'rub_usdt_live' || $mode === 'rub_thb_rub_live' || $mode === 'rub_thb_thb_live' ) {
			$line2 = 'Делаем live quote и готовим QR-код.';
		} elseif ( $mode === 'rub_usdt' || $mode === 'rub_thb_rub_rapira' || $mode === 'rub_thb_thb_rapira' || $mode === 'rub' ) {
			$line2 = 'Считаем курс и готовим QR-код.';
		}

		$text = "⏳ <b>Готовим счёт</b>\n\n" . $line2;
		if ( $input_currency !== '' ) {
			$text .= "\nВвод: <code>" . htmlspecialchars( $input_currency, ENT_QUOTES, 'UTF-8' ) . '</code>';
		}

		return $text;
	}
}

if ( ! function_exists( 'crm_operator_tg_invoice_mode_preview_lines' ) ) {
	function crm_operator_tg_invoice_mode_preview_lines( int $company_id, string $mode, bool $refresh_market = false ): array {
		$mode  = crm_operator_tg_normalize_invoice_mode( $mode, $company_id );
		$lines = [];

		if ( $mode === 'rub' ) {
			$preview = crm_operator_tg_rate_preview_context( $company_id, 30000.0, $refresh_market );
			if ( empty( $preview['success'] ) ) {
				return [ htmlspecialchars( (string) $preview['error'], ENT_QUOTES, 'UTF-8' ) ];
			}

			if ( $preview['rapira_ask'] !== null ) {
				$lines[] = 'Rapira ask: <code>' . crm_merchant_tg_fmt_rate( (float) $preview['rapira_ask'], 4 ) . '</code> RUB';
			}
			$lines[] = 'Company markup: <code>+' . crm_merchant_tg_fmt_rate( (float) ( $preview['company_markup_percent'] ?? 0 ), 2 ) . '%</code>';
			$lines[] = 'Итоговый курс: <code>' . crm_merchant_tg_fmt_rate( (float) ( $preview['current_rate'] ?? 0 ), 4 ) . '</code> RUB за 1 USDT';
			$lines[] = 'Пример: <code>' . crm_merchant_tg_fmt_money( (float) ( $preview['sample_requested_rub'] ?? 30000.0 ), 2 ) . ' RUB</code> → <code>' . crm_tg_receipt_format_amount( (float) ( $preview['sample_payable_usdt'] ?? 0 ), 'USDT', 4, false ) . '</code>';
			if ( ! empty( $preview['warning'] ) ) {
				$lines[] = '⚠️ ' . htmlspecialchars( (string) $preview['warning'], ENT_QUOTES, 'UTF-8' );
			}

			return $lines;
		}

		if ( $mode === 'rub_usdt' && function_exists( 'crm_fintech_company_web_rub_usdt_preview_context' ) ) {
			$preview = crm_fintech_company_web_rub_usdt_preview_context( $company_id, 30000.0, $refresh_market );
			if ( empty( $preview['success'] ) ) {
				return [ htmlspecialchars( (string) $preview['error'], ENT_QUOTES, 'UTF-8' ) ];
			}

			if ( ! empty( $preview['rapira_ask'] ) ) {
				$lines[] = 'Rapira ask: <code>' . crm_merchant_tg_fmt_rate( (float) $preview['rapira_ask'], 4 ) . '</code> RUB';
			}
			$lines[] = 'Markup: <code>+' . crm_merchant_tg_fmt_rate( (float) ( $preview['markup_percent'] ?? 0 ), 2 ) . '%</code>';
			$lines[] = 'Итоговый курс: <code>' . crm_merchant_tg_fmt_rate( (float) ( $preview['effective_rate'] ?? 0 ), 4 ) . '</code> RUB за 1 USDT';
			$lines[] = 'Пример: <code>' . crm_merchant_tg_fmt_money( (float) ( $preview['sample_requested_rub'] ?? 30000.0 ), 2 ) . ' RUB</code> → <code>' . crm_tg_receipt_format_amount( (float) ( $preview['sample_amount_usdt'] ?? 0 ), 'USDT', 2, false ) . '</code>';

			return $lines;
		}

		if ( in_array( $mode, [ 'rub_usdt_live', 'usdt' ], true ) ) {
			$last = function_exists( 'rates_kanyon_get_last' ) ? rates_kanyon_get_last( $company_id ) : null;

			if ( is_array( $last ) && ! empty( $last['kanyon_rate'] ) && (float) $last['kanyon_rate'] > 0 ) {
				$lines[] = 'Последний Kanyon rate: <code>' . crm_merchant_tg_fmt_rate( (float) $last['kanyon_rate'], 4 ) . '</code> RUB за 1 USDT';
				if ( ! empty( $last['created_at'] ) ) {
					$lines[] = 'Обновлено: <code>' . htmlspecialchars( mysql2date( 'd.m.Y H:i', (string) $last['created_at'] ), ENT_QUOTES, 'UTF-8' ) . '</code>';
				}
			}

			$lines[] = $mode === 'rub_usdt_live'
				? 'Live quote: test-order <code>100 USDT</code> перед каждым новым счётом.'
				: 'RUB сумма появится у провайдера в момент выпуска счёта.';

			return $lines;
		}

		if ( in_array( $mode, [ 'rub_thb_rub_rapira', 'rub_thb_rub_live', 'rub_thb_thb_rapira', 'rub_thb_thb_live' ], true ) ) {
			$thb_context = function_exists( 'crm_fintech_company_web_rub_thb_context' )
				? crm_fintech_company_web_rub_thb_context( $company_id )
				: [ 'success' => false, 'error' => 'THB contour context недоступен.' ];

			if ( empty( $thb_context['success'] ) ) {
				return [ htmlspecialchars( (string) ( $thb_context['error'] ?? 'THB contour недоступен.' ), ENT_QUOTES, 'UTF-8' ) ];
			}

			$lines[] = 'Наш Sberbank: <code>' . crm_merchant_tg_fmt_rate( (float) ( $thb_context['rub_per_thb_rate'] ?? 0 ), 4 ) . '</code> RUB за 1 THB';
			if ( ! empty( $thb_context['rate_updated_at_utc'] ) ) {
				$lines[] = 'Снимок: <code>' . htmlspecialchars( mysql2date( 'd.m.Y H:i', (string) $thb_context['rate_updated_at_utc'] ), ENT_QUOTES, 'UTF-8' ) . '</code>';
			}

			$sample_currency = crm_operator_tg_invoice_mode_input_currency( $mode );
			$sample_amount   = $sample_currency === 'THB' ? 10000.0 : 50000.0;
			$sample_target   = function_exists( 'crm_fintech_company_web_rub_thb_target_context' )
				? crm_fintech_company_web_rub_thb_target_context( $company_id, $sample_currency, $sample_amount )
				: [ 'success' => false ];

			if ( ! empty( $sample_target['success'] ) ) {
				if ( $sample_currency === 'THB' ) {
					$lines[] = 'Пример: <code>' . crm_tg_receipt_format_amount( $sample_amount, 'THB', 2, false ) . '</code> → <code>' . crm_merchant_tg_fmt_money( (float) ( $sample_target['target_invoice_rub'] ?? 0 ), 2 ) . ' RUB</code>';
				} else {
					$lines[] = 'Пример: <code>' . crm_merchant_tg_fmt_money( $sample_amount, 2 ) . ' RUB</code> → <code>' . crm_tg_receipt_format_amount( (float) ( $sample_target['target_amount_thb'] ?? 0 ), 'THB', 2, false ) . '</code>';
				}
			}

			$lines[] = in_array( $mode, [ 'rub_thb_rub_live', 'rub_thb_thb_live' ], true )
				? 'USDT quote: live Kanyon через test-order <code>100 USDT</code>.'
				: 'USDT quote: Rapira Ask + <code>4%</code>.';

			return $lines;
		}

		return [ crm_operator_tg_invoice_mode_help_text( $mode ) ];
	}
}

if ( ! function_exists( 'crm_operator_tg_invoice_mode_rows' ) ) {
	function crm_operator_tg_invoice_mode_rows( int $company_id, string $selected_mode ): array {
		$modes = crm_operator_tg_company_supported_invoice_modes( $company_id );
		$rows  = [];

		foreach ( $modes as $mode ) {
			$mode  = crm_operator_tg_normalize_invoice_mode( (string) $mode, $company_id );
			$rows[] = [
				[
					'text'          => ( $mode === $selected_mode ? '✅ ' : '' ) . crm_operator_tg_invoice_mode_button_label( $mode ),
					'callback_data' => 'o:invoice:mode:' . $mode,
				],
			];
		}

		return $rows;
	}
}

if ( ! function_exists( 'crm_operator_tg_rate_text' ) ) {
	function crm_operator_tg_rate_text( int $company_id, bool $refresh_market = false ): string {
		$lines = [ '💹 <b>Курсы и контуры</b>', '' ];
		$modes = crm_operator_tg_company_supported_invoice_modes( $company_id );

		foreach ( $modes as $mode ) {
			$lines[] = '• <b>' . htmlspecialchars( crm_operator_tg_invoice_mode_label( $mode ), ENT_QUOTES, 'UTF-8' ) . '</b>';
			foreach ( crm_operator_tg_invoice_mode_preview_lines( $company_id, $mode, $refresh_market ) as $preview_line ) {
				$lines[] = $preview_line;
			}
			$lines[] = '';
		}

		$lines[] = 'Фактический provider result фиксируется в момент выпуска счёта.';

		return implode( "\n", $lines );
	}
}

if ( ! function_exists( 'crm_operator_tg_invoice_text' ) ) {
	function crm_operator_tg_invoice_text( int $company_id, string $chat_id, array $session = [], string $notice = '' ): string {
		$mode            = crm_operator_tg_normalize_invoice_mode( (string) ( $session['invoice_mode'] ?? '' ), $company_id );
		$payment_purpose = crm_operator_tg_effective_payment_purpose( $company_id, $session );
		$text            = '';
		$modes           = crm_operator_tg_company_supported_invoice_modes( $company_id );

		if ( $notice !== '' ) {
			$text .= '⚠️ ' . htmlspecialchars( $notice, ENT_QUOTES, 'UTF-8' ) . "\n\n";
		}

		$text .= "🧾 <b>Operator invoice</b>\n\n";
		$text .= 'Текущий контур: <b>' . htmlspecialchars( crm_operator_tg_invoice_mode_label( $mode ), ENT_QUOTES, 'UTF-8' ) . "</b>\n";
		$text .= 'Валюта ввода: <code>' . htmlspecialchars( crm_operator_tg_invoice_mode_input_currency( $mode ), ENT_QUOTES, 'UTF-8' ) . "</code>\n";
		$text .= htmlspecialchars( crm_operator_tg_invoice_mode_help_text( $mode ), ENT_QUOTES, 'UTF-8' ) . "\n\n";

		foreach ( crm_operator_tg_invoice_mode_preview_lines( $company_id, $mode ) as $preview_line ) {
			$text .= $preview_line . "\n";
		}

		if ( ! empty( $modes ) ) {
			$text .= "\nДоступные сценарии:\n";
			foreach ( $modes as $supported_mode ) {
				$text .= ( $supported_mode === $mode ? '• ✅ ' : '• ' )
					. '<b>' . htmlspecialchars( crm_operator_tg_invoice_mode_label( $supported_mode ), ENT_QUOTES, 'UTF-8' ) . "</b>\n";
			}
		}

		if ( $payment_purpose !== '' ) {
			$text .= "\nНазначение платежа:\n<code>" . htmlspecialchars( $payment_purpose, ENT_QUOTES, 'UTF-8' ) . "</code>\n";
		}

		$text .= "\nВыберите контур ниже, затем введите сумму или измените назначение платежа.";

		return $text;
	}
}

if ( ! function_exists( 'crm_operator_tg_invoice_keyboard' ) ) {
	function crm_operator_tg_invoice_keyboard( int $company_id, string $chat_id, array $session = [] ): array {
		$rows        = [];
		$mode        = crm_operator_tg_normalize_invoice_mode( (string) ( $session['invoice_mode'] ?? '' ), $company_id );
		$miniapp_url = $mode === 'rub' && function_exists( 'crm_tg_miniapp_url_for_operator_chat' )
			? crm_tg_miniapp_url_for_operator_chat( $company_id, $chat_id )
			: '';

		$rows = array_merge( $rows, crm_operator_tg_invoice_mode_rows( $company_id, $mode ) );

		if ( $miniapp_url !== '' ) {
			$rows[] = [
				[
					'text'    => '🪄 Приложение',
					'web_app' => [ 'url' => $miniapp_url ],
				],
			];
		}

		$rows[] = [
			[ 'text' => '✍️ Ввести сумму', 'callback_data' => 'o:invoice:start' ],
		];
		$rows[] = [
			[ 'text' => '✏️ Назначение', 'callback_data' => 'o:invoice:purpose' ],
		];
		$rows[] = [
			[ 'text' => '📂 Ордера', 'callback_data' => 'o:orders' ],
			[ 'text' => '↩️ Меню',   'callback_data' => 'o:main' ],
		];

		return [
			'inline_keyboard' => $rows,
		];
	}
}

if ( ! function_exists( 'crm_operator_tg_invoice_amount_prompt_text' ) ) {
	function crm_operator_tg_invoice_amount_prompt_text( int $company_id, array $session = [], string $warning = '' ): string {
		$mode           = crm_operator_tg_normalize_invoice_mode( (string) ( $session['invoice_mode'] ?? '' ), $company_id );
		$purpose        = crm_operator_tg_effective_payment_purpose( $company_id, $session );
		$input_currency = crm_operator_tg_invoice_mode_input_currency( $mode );
		$preview_lines  = crm_operator_tg_invoice_mode_preview_lines( $company_id, $mode );
		$text           = '';

		if ( $warning !== '' ) {
			$text .= '⚠️ ' . htmlspecialchars( $warning, ENT_QUOTES, 'UTF-8' ) . "\n\n";
		}

		$text .= "⌨️ <b>Введите сумму</b>\n\n";
		$text .= 'Контур: <b>' . htmlspecialchars( crm_operator_tg_invoice_mode_label( $mode ), ENT_QUOTES, 'UTF-8' ) . "</b>\n";
		$text .= 'Валюта ввода: <code>' . htmlspecialchars( $input_currency, ENT_QUOTES, 'UTF-8' ) . "</code>\n";
		$text .= 'Сейчас жду <b>одно сообщение</b> с суммой в <code>' . htmlspecialchars( $input_currency, ENT_QUOTES, 'UTF-8' ) . "</code>.\n";
		$text .= htmlspecialchars( crm_operator_tg_invoice_mode_help_text( $mode ), ENT_QUOTES, 'UTF-8' );

		if ( ! empty( $preview_lines ) ) {
			$text .= "\n\n";
			foreach ( $preview_lines as $preview_line ) {
				$text .= $preview_line . "\n";
			}
			$text = rtrim( $text );
		}

		$text .= "\n\nНапример:\n<code>" . htmlspecialchars( crm_operator_tg_invoice_mode_input_example( $mode ), ENT_QUOTES, 'UTF-8' ) . '</code>';

		if ( $purpose !== '' ) {
			$text .= "\n\nНазначение платежа:\n<code>" . htmlspecialchars( $purpose, ENT_QUOTES, 'UTF-8' ) . '</code>';
		}

		$text .= "\n\nЧтобы сменить контур или назначение, вернитесь к счёту.";

		return $text;
	}
}

if ( ! function_exists( 'crm_operator_tg_invoice_amount_prompt_keyboard' ) ) {
	function crm_operator_tg_invoice_amount_prompt_keyboard(): array {
		return [
			'inline_keyboard' => [
				[
					[ 'text' => '↩️ К счёту', 'callback_data' => 'o:invoice' ],
				],
				[
					[ 'text' => '📂 Ордера', 'callback_data' => 'o:orders' ],
					[ 'text' => '↩️ Меню',   'callback_data' => 'o:main' ],
				],
			],
		];
	}
}

if ( ! function_exists( 'crm_operator_tg_invoice_purpose_prompt_text' ) ) {
	function crm_operator_tg_invoice_purpose_prompt_text( int $company_id, array $session = [], string $warning = '' ): string {
		$current_purpose = crm_operator_tg_effective_payment_purpose( $company_id, $session );
		$text            = '';

		if ( $warning !== '' ) {
			$text .= '⚠️ ' . htmlspecialchars( $warning, ENT_QUOTES, 'UTF-8' ) . "\n\n";
		}

		$text .= "📝 <b>Назначение платежа</b>\n\n";
		if ( $current_purpose !== '' ) {
			$text .= "Текущее значение:\n<code>" . htmlspecialchars( $current_purpose, ENT_QUOTES, 'UTF-8' ) . "</code>\n\n";
		}
		$text .= "Введите новое назначение одним сообщением.";

		return $text;
	}
}

if ( ! function_exists( 'crm_operator_tg_invoice_purpose_prompt_keyboard' ) ) {
	function crm_operator_tg_invoice_purpose_prompt_keyboard(): array {
		return [
			'inline_keyboard' => [
				[
					[ 'text' => '↩️ К счёту', 'callback_data' => 'o:invoice' ],
				],
				[
					[ 'text' => '↩️ Меню', 'callback_data' => 'o:main' ],
				],
			],
		];
	}
}

if ( ! function_exists( 'crm_operator_tg_chat_order_like' ) ) {
	function crm_operator_tg_chat_order_like( string $chat_id ): string {
		return '%"tg_chat_id":"' . str_replace( [ '%', '_' ], [ '\%', '\_' ], $chat_id ) . '"%';
	}
}

if ( ! function_exists( 'crm_operator_tg_orders_counts' ) ) {
	function crm_operator_tg_orders_counts( int $company_id, string $chat_id ): array {
		global $wpdb;

		$chat_id = crm_telegram_sanitize_chat_id_value( $chat_id );
		$empty   = [
			'open'      => 0,
			'paid'      => 0,
			'cancelled' => 0,
		];

		if ( $company_id <= 0 || $chat_id === '' ) {
			return $empty;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN status_code IN ('created','pending') THEN 1 ELSE 0 END) AS open_count,
					SUM(CASE WHEN status_code = 'paid' THEN 1 ELSE 0 END) AS paid_count,
					SUM(CASE WHEN status_code IN ('declined','cancelled','expired','error') THEN 1 ELSE 0 END) AS cancelled_count
				 FROM crm_fintech_payment_orders
				 WHERE company_id = %d
				   AND created_for_type = 'company'
				   AND source_channel = 'telegram_operator'
				   AND meta_json LIKE %s",
				$company_id,
				crm_operator_tg_chat_order_like( $chat_id )
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return $empty;
		}

		return [
			'open'      => (int) ( $row['open_count'] ?? 0 ),
			'paid'      => (int) ( $row['paid_count'] ?? 0 ),
			'cancelled' => (int) ( $row['cancelled_count'] ?? 0 ),
		];
	}
}

if ( ! function_exists( 'crm_operator_tg_recent_orders' ) ) {
	function crm_operator_tg_recent_orders( int $company_id, string $chat_id, array $status_codes, int $limit = 10 ): array {
		global $wpdb;

		$chat_id = crm_telegram_sanitize_chat_id_value( $chat_id );
		$limit   = max( 1, min( 20, $limit ) );

		if ( $company_id <= 0 || $chat_id === '' || empty( $status_codes ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $status_codes ), '%s' ) );
		$params       = array_merge(
			[
				$company_id,
				crm_operator_tg_chat_order_like( $chat_id ),
			],
			array_values( array_map( 'strval', $status_codes ) ),
			[ $limit ]
		);

		$sql = "
			SELECT id, merchant_order_id, status_code, payment_amount_value, amount_asset_value, created_at, source_channel, created_for_type, meta_json
			FROM crm_fintech_payment_orders
			WHERE company_id = %d
			  AND created_for_type = 'company'
			  AND source_channel = 'telegram_operator'
			  AND meta_json LIKE %s
			  AND status_code IN ({$placeholders})
			ORDER BY id DESC
			LIMIT %d
		";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		return is_array( $rows ) ? $rows : [];
	}
}

if ( ! function_exists( 'crm_operator_tg_orders_text' ) ) {
	function crm_operator_tg_orders_text( int $company_id, string $chat_id, string $screen = 'orders' ): string {
		$counts = crm_operator_tg_orders_counts( $company_id, $chat_id );

		if ( $screen === 'orders' ) {
			$lines = [
				crm_merchant_tg_pad( '🟡 Активные', 14 ) . crm_merchant_tg_pad( (string) $counts['open'], 4, 'right' ),
				crm_merchant_tg_pad( '🟢 Оплаченные', 14 ) . crm_merchant_tg_pad( (string) $counts['paid'], 4, 'right' ),
				crm_merchant_tg_pad( '🔴 Завершённые', 14 ) . crm_merchant_tg_pad( (string) $counts['cancelled'], 4, 'right' ),
			];

			return "📂 <b>Мои ордера</b>\n\n"
				. "<pre>" . htmlspecialchars( implode( "\n", $lines ), ENT_QUOTES, 'UTF-8' ) . "</pre>\n"
				. "Видны только ордера, созданные из текущего Telegram-чата.";
		}

		$meta = [
			'orders_open' => [
				'title'    => '🟡 <b>Открытые ордера</b>',
				'statuses' => [ 'created', 'pending' ],
			],
			'orders_paid' => [
				'title'    => '🟢 <b>Оплаченные ордера</b>',
				'statuses' => [ 'paid' ],
			],
			'orders_cancelled' => [
				'title'    => '🔴 <b>Завершённые ордера</b>',
				'statuses' => [ 'declined', 'cancelled', 'expired', 'error' ],
			],
		];

		if ( ! isset( $meta[ $screen ] ) ) {
			return crm_operator_tg_orders_text( $company_id, $chat_id, 'orders' );
		}

		$orders = crm_operator_tg_recent_orders( $company_id, $chat_id, $meta[ $screen ]['statuses'], 10 );
		if ( empty( $orders ) ) {
			return $meta[ $screen ]['title'] . "\n\nСписок пока пуст.";
		}

		$lines = [ $meta[ $screen ]['title'], '' ];
		foreach ( $orders as $order ) {
			$lines[] = '#' . (int) $order->id . ' · ' . ( ! empty( $order->created_at ) ? mysql2date( 'd.m.Y H:i', (string) $order->created_at ) : '—' );
			$lines[] = '<code>' . htmlspecialchars( (string) ( $order->merchant_order_id ?? '' ), ENT_QUOTES, 'UTF-8' ) . '</code>';
			$lines[] = 'RUB: ' . crm_merchant_tg_fmt_money( (float) ( $order->payment_amount_value ?? 0 ), 2 )
				. ' · USDT: ' . crm_tg_receipt_format_amount( (float) ( $order->amount_asset_value ?? 0 ), 'USDT', 4, false )
				. ' · [' . strtoupper( (string) ( $order->status_code ?? '' ) ) . ']';
			$lines[] = '';
		}

		return trim( implode( "\n", $lines ) );
	}
}

if ( ! function_exists( 'crm_operator_tg_orders_keyboard' ) ) {
	function crm_operator_tg_orders_keyboard( int $company_id, string $chat_id, string $screen = 'orders' ): array {
		$rows = [];

		if ( $screen === 'orders_open' ) {
			$orders = crm_operator_tg_recent_orders( $company_id, $chat_id, [ 'created', 'pending' ], 5 );
			foreach ( $orders as $order ) {
				$rows[] = [
					[
						'text'          => '✅ Проверить #' . (int) $order->id,
						'callback_data' => 'kanyon_paid:' . (int) $order->id,
					],
				];
			}
		}

		if ( $screen === 'orders' ) {
			$rows[] = [
				[ 'text' => '🟡 Активные', 'callback_data' => 'o:orders:open' ],
			];
			$rows[] = [
				[ 'text' => '🟢 Оплаченные', 'callback_data' => 'o:orders:paid' ],
			];
			$rows[] = [
				[ 'text' => '🔴 Завершённые', 'callback_data' => 'o:orders:cancelled' ],
			];
		}

		$rows[] = [
			[
				'text'          => '🔄 Обновить',
				'callback_data' => $screen === 'orders' ? 'o:orders' : 'o:' . str_replace( '_', ':', $screen ),
			],
			[
				'text'          => $screen === 'orders' ? '↩️ Меню' : '↩️ К ордерам',
				'callback_data' => $screen === 'orders' ? 'o:main' : 'o:orders',
			],
		];

		if ( $screen !== 'orders' ) {
			$rows[] = [
				[ 'text' => '↩️ Меню', 'callback_data' => 'o:main' ],
			];
		}

		return [
			'inline_keyboard' => $rows,
		];
	}
}

if ( ! function_exists( 'crm_operator_tg_main_text' ) ) {
	function crm_operator_tg_main_text( object $account, int $company_id ): string {
		$modes = crm_operator_tg_company_supported_invoice_modes( $company_id );
		$list  = [];
		foreach ( $modes as $mode ) {
			$list[] = '• <b>' . htmlspecialchars( crm_operator_tg_invoice_mode_label( $mode ), ENT_QUOTES, 'UTF-8' ) . '</b>';
		}

		return "🌴 <b>Malibu Operator</b>\n\n"
			. 'Привет, ' . htmlspecialchars( crm_operator_tg_display_name( $account ), ENT_QUOTES, 'UTF-8' ) . "!\n"
			. "Доступные сценарии:\n"
			. implode( "\n", $list ) . "\n\n"
			. 'Этот бот показывает только ордера, созданные из текущего Telegram-чата.';
	}
}

if ( ! function_exists( 'crm_operator_tg_main_keyboard' ) ) {
	function crm_operator_tg_main_keyboard(): array {
		return [
			'inline_keyboard' => [
				[
					[ 'text' => '🧾 Выставить счёт', 'callback_data' => 'o:invoice' ],
				],
				[
					[ 'text' => '📂 Мои ордера', 'callback_data' => 'o:orders' ],
				],
				[
					[ 'text' => '💹 Курс', 'callback_data' => 'o:rates' ],
				],
			],
		];
	}
}

if ( ! function_exists( 'crm_operator_tg_screen_payload' ) ) {
	function crm_operator_tg_screen_payload( int $company_id, string $chat_id, object $account, array $session, string $screen = 'main', bool $refresh_market = false ): array {
		switch ( $screen ) {
			case 'invoice':
				return [
					'screen'   => 'invoice',
					'text'     => crm_operator_tg_invoice_text( $company_id, $chat_id, $session ),
					'keyboard' => crm_operator_tg_invoice_keyboard( $company_id, $chat_id, $session ),
				];
			case 'invoice_amount':
				return [
					'screen'   => 'invoice_amount',
					'text'     => crm_operator_tg_invoice_amount_prompt_text( $company_id, $session ),
					'keyboard' => crm_operator_tg_invoice_amount_prompt_keyboard(),
				];
			case 'invoice_purpose':
				return [
					'screen'   => 'invoice_purpose',
					'text'     => crm_operator_tg_invoice_purpose_prompt_text( $company_id, $session ),
					'keyboard' => crm_operator_tg_invoice_purpose_prompt_keyboard(),
				];
			case 'orders':
			case 'orders_open':
			case 'orders_paid':
			case 'orders_cancelled':
				return [
					'screen'   => $screen,
					'text'     => crm_operator_tg_orders_text( $company_id, $chat_id, $screen ),
					'keyboard' => crm_operator_tg_orders_keyboard( $company_id, $chat_id, $screen ),
				];
			case 'rates':
				return [
					'screen'   => 'rates',
					'text'     => crm_operator_tg_rate_text( $company_id, $refresh_market ),
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '🔄 Обновить', 'callback_data' => 'o:rates:refresh' ],
							],
							[
								[ 'text' => '🧾 Выставить счёт', 'callback_data' => 'o:invoice' ],
								[ 'text' => '↩️ Меню', 'callback_data' => 'o:main' ],
							],
						],
					],
				];
			case 'main':
			default:
				return [
					'screen'   => 'main',
					'text'     => crm_operator_tg_main_text( $account, $company_id ),
					'keyboard' => crm_operator_tg_main_keyboard(),
				];
		}
	}
}

if ( ! function_exists( 'crm_operator_tg_delete_message' ) ) {
	function crm_operator_tg_delete_message( $telegram, string $chat_id, int $message_id ): bool {
		if ( ! $telegram || $chat_id === '' || $message_id <= 0 ) {
			return false;
		}

		$result = $telegram->deleteMessage(
			[
				'chat_id'    => $chat_id,
				'message_id' => $message_id,
			]
		);

		return is_array( $result ) && ! empty( $result['ok'] );
	}
}

if ( ! function_exists( 'crm_operator_tg_close_menu_anchor' ) ) {
	function crm_operator_tg_close_menu_anchor( $telegram, int $company_id, string $chat_id, ?array $session = null ): void {
		$chat_id = crm_telegram_sanitize_chat_id_value( $chat_id );
		if ( $company_id <= 0 || $chat_id === '' ) {
			return;
		}

		$session           = is_array( $session ) ? $session : crm_operator_tg_session_get( $company_id, $chat_id );
		$stored_message_id = (int) ( $session['last_menu_message_id'] ?? 0 );
		if ( $stored_message_id > 0 ) {
			crm_operator_tg_delete_message( $telegram, $chat_id, $stored_message_id );
		}

		crm_operator_tg_session_patch(
			$company_id,
			$chat_id,
			[
				'last_menu_message_id' => 0,
				'last_screen'          => 'main',
			]
		);
	}
}

if ( ! function_exists( 'crm_operator_tg_receipt_keyboard' ) ) {
	function crm_operator_tg_receipt_keyboard( int $order_db_id ): array {
		$rows = [];

		if ( $order_db_id > 0 ) {
			$rows[] = [
				[
					'text'          => '✅ Проверить оплату',
					'callback_data' => 'kanyon_paid:' . $order_db_id,
				],
			];
		}

		$rows[] = [
			[ 'text' => '📂 Мои ордера', 'callback_data' => 'o:orders' ],
			[ 'text' => '↩️ Меню', 'callback_data' => 'o:main' ],
		];

		return [
			'inline_keyboard' => $rows,
		];
	}
}

if ( ! function_exists( 'crm_operator_tg_receipt_terminal_keyboard' ) ) {
	function crm_operator_tg_receipt_terminal_keyboard(): array {
		return [
			'inline_keyboard' => [
				[
					[ 'text' => '📂 Мои ордера', 'callback_data' => 'o:orders' ],
					[ 'text' => '↩️ Меню', 'callback_data' => 'o:main' ],
				],
			],
		];
	}
}

if ( ! function_exists( 'crm_operator_tg_receipt_status_descriptor' ) ) {
	function crm_operator_tg_receipt_status_descriptor( string $status_code ): array {
		$status_code = sanitize_key( $status_code );

		$map = [
			'paid' => [
				'title'        => '✅ <b>Оплата подтверждена</b>',
				'status_label' => 'Paid',
				'timestamp_key' => 'paid_at',
				'info_text'    => 'Счёт закрыт. QR и ссылка больше не активны.',
			],
			'declined' => [
				'title'        => '❌ <b>Ордер отклонён</b>',
				'status_label' => 'Declined',
				'timestamp_key' => 'declined_at',
				'info_text'    => 'Счёт закрыт. QR и ссылка больше не активны.',
			],
			'cancelled' => [
				'title'        => '🚫 <b>Ордер отменён</b>',
				'status_label' => 'Cancelled',
				'timestamp_key' => 'cancelled_at',
				'info_text'    => 'Счёт закрыт. QR и ссылка больше не активны.',
			],
			'expired' => [
				'title'        => '⏰ <b>Ордер истёк</b>',
				'status_label' => 'Expired',
				'timestamp_key' => 'expired_at',
				'info_text'    => 'Время оплаты истекло. Нужен новый счёт.',
			],
			'error' => [
				'title'        => '⛔ <b>Ошибка ордера</b>',
				'status_label' => 'Error',
				'timestamp_key' => 'updated_at',
				'info_text'    => 'Счёт закрыт из-за ошибки. Нужен новый ордер.',
			],
		];

		return $map[ $status_code ] ?? [
			'title'         => '',
			'status_label'  => 'Calculated',
			'timestamp_key' => 'updated_at',
			'info_text'     => '',
		];
	}
}

if ( ! function_exists( 'crm_operator_tg_receipt_text_from_order' ) ) {
	function crm_operator_tg_receipt_text_from_order( object $order, string $status_code = '', bool $include_payment_link = false ): string {
		$status_code = sanitize_key( $status_code !== '' ? $status_code : (string) ( $order->status_code ?? '' ) );
		$descriptor  = crm_operator_tg_receipt_status_descriptor( $status_code );
		$meta        = function_exists( 'crm_merchant_tg_safe_json_decode' )
			? crm_merchant_tg_safe_json_decode( (string) ( $order->meta_json ?? '' ) )
			: [];

		$amount_asset_code = trim( (string) ( $order->amount_asset_code ?? 'USDT' ) );
		if ( $amount_asset_code === '' ) {
			$amount_asset_code = 'USDT';
		}

		$amount_asset_value = isset( $order->amount_asset_value ) ? (float) $order->amount_asset_value : 0.0;
		$payment_rub        = isset( $order->payment_amount_value ) ? (float) $order->payment_amount_value : 0.0;
		$rate_value         = ( $amount_asset_value > 0 && $payment_rub > 0 ) ? round( $payment_rub / $amount_asset_value, 4 ) : 0.0;
		$receipt_id         = ! empty( $order->id ) ? '#' . (int) $order->id : trim( (string) ( $order->merchant_order_id ?? '' ) );
		$payment_purpose    = trim( (string) ( $meta['payment_purpose'] ?? '' ) );
		$invoice_mode       = sanitize_key( (string) ( $meta['operator_invoice_mode'] ?? '' ) );
		$warning            = trim( (string) ( $meta['warning'] ?? '' ) );
		$timestamp_key      = (string) ( $descriptor['timestamp_key'] ?? 'updated_at' );
		$timestamp_raw      = trim( (string) ( $order->{$timestamp_key} ?? $order->updated_at ?? $order->created_at ?? '' ) );
		$timestamp_label    = $timestamp_raw !== '' ? mysql2date( 'd.m.Y H:i', $timestamp_raw ) : current_time( 'd.m.Y H:i' );
		$footer_lines       = ! empty( $descriptor['info_text'] )
			? [ (string) $descriptor['info_text'] ]
			: [
				'Thank you for choosing us',
				'Always available for your operations',
			];

		$text = '';
		if ( ! empty( $descriptor['title'] ) ) {
			$text .= (string) $descriptor['title'] . "\n\n";
		}

		$text .= crm_tg_receipt_block(
			[
				[
					'label' => 'FROM:',
					'value' => $payment_rub > 0 ? crm_tg_receipt_format_amount( $payment_rub, 'RUB', 2, true ) : '—',
				],
				[
					'label' => 'RATE:',
					'value' => $rate_value > 0 ? crm_tg_receipt_format_number( $rate_value, 4, true ) : '—',
				],
				[
					'label' => 'TO:',
					'value' => $amount_asset_value > 0 ? crm_tg_receipt_format_amount( $amount_asset_value, $amount_asset_code, 4, true ) : '—',
				],
			],
			[
				[
					'label' => 'TIME:',
					'value' => $timestamp_label,
				],
				[
					'label' => 'ID:',
					'value' => $receipt_id !== '' ? $receipt_id : '—',
				],
				[
					'label' => 'STATUS:',
					'value' => (string) ( $descriptor['status_label'] ?? 'Calculated' ),
				],
				[
					'label' => in_array( $status_code, [ 'paid', 'declined', 'cancelled', 'expired', 'error' ], true ) ? 'NOTE:' : 'FEE:',
					'value' => in_array( $status_code, [ 'paid', 'declined', 'cancelled', 'expired', 'error' ], true ) ? 'inactive' : 'included',
				],
			],
			$footer_lines
		);

		if ( $include_payment_link && ! empty( $order->payment_link ) ) {
			$text .= "\n\nPayment link:\n<code>" . htmlspecialchars( (string) $order->payment_link, ENT_QUOTES, 'UTF-8' ) . '</code>';
		}

		if ( $payment_purpose !== '' ) {
			$text .= "\n\nPayment purpose:\n<code>" . htmlspecialchars( $payment_purpose, ENT_QUOTES, 'UTF-8' ) . '</code>';
		}

		if ( $invoice_mode !== '' && function_exists( 'crm_fintech_create_order_mode_label' ) ) {
			$text .= "\n\nContour:\n<code>" . htmlspecialchars( crm_fintech_create_order_mode_label( $invoice_mode ), ENT_QUOTES, 'UTF-8' ) . '</code>';
		}

		if ( ! empty( $meta['target_amount_thb'] ) ) {
			$thb_rate = isset( $meta['thb_rate'] ) ? (float) $meta['thb_rate'] : 0.0;
			$text    .= "\n\nTHB context:\n<code>"
				. htmlspecialchars( crm_tg_receipt_format_amount( (float) $meta['target_amount_thb'], 'THB', 2, false ), ENT_QUOTES, 'UTF-8' )
				. '</code>';
			if ( $thb_rate > 0 ) {
				$text .= ' @ <code>' . htmlspecialchars( crm_tg_receipt_format_number( $thb_rate, 4, false ), ENT_QUOTES, 'UTF-8' ) . ' RUB/THB</code>';
			}
		}

		if ( $warning !== '' ) {
			$text .= "\n\n⚠️ " . htmlspecialchars( $warning, ENT_QUOTES, 'UTF-8' );
		}

		return $text;
	}
}

if ( ! function_exists( 'crm_operator_tg_store_invoice_message_context' ) ) {
	function crm_operator_tg_store_invoice_message_context( int $order_id, int $company_id, string $chat_id, string $message_type, array $telegram_response ): array {
		$message_id   = (int) ( $telegram_response['result']['message_id'] ?? 0 );
		$message_type = sanitize_key( $message_type );
		$result       = [
			'ok'           => false,
			'message_id'   => $message_id,
			'message_type' => in_array( $message_type, [ 'photo', 'text' ], true ) ? $message_type : 'text',
		];

		if ( $order_id <= 0 || $company_id <= 0 || $chat_id === '' || $message_id <= 0 || ! function_exists( 'crm_merchant_tg_get_order_meta' ) || ! function_exists( 'crm_merchant_tg_merge_order_meta' ) || ! function_exists( 'crm_merchant_tg_append_message_id_list' ) ) {
			return $result;
		}

		$current_meta = crm_merchant_tg_get_order_meta( $order_id, $company_id );
		$meta_write   = crm_merchant_tg_merge_order_meta(
			$order_id,
			$company_id,
			[
				'operator_tg_receipt_chat_id'      => $chat_id,
				'operator_tg_receipt_message_id'   => $message_id,
				'operator_tg_receipt_message_type' => $result['message_type'],
				'operator_tg_receipt_message_ids'  => crm_merchant_tg_append_message_id_list( $current_meta['operator_tg_receipt_message_ids'] ?? [], $message_id ),
				'operator_tg_receipt_stored_at'    => current_time( 'mysql', true ),
			]
		);

		$result['ok'] = ! empty( $meta_write['ok'] );

		crm_log(
			$result['ok'] ? 'operator.telegram.invoice_message_bound' : 'operator.telegram.invoice_message_bind_failed',
			[
				'category'    => 'payments',
				'level'       => $result['ok'] ? 'info' : 'warning',
				'action'      => 'telegram_message_bind',
				'message'     => $result['ok']
					? 'Operator invoice message linked to payment order.'
					: 'Failed to link operator invoice message to payment order.',
				'target_type' => 'payment_order',
				'target_id'   => $order_id,
				'org_id'      => $company_id,
				'is_success'  => $result['ok'],
				'context'     => [
					'chat_id'      => $chat_id,
					'message_id'   => $message_id,
					'message_type' => $result['message_type'],
				],
			]
		);

		return $result;
	}
}

if ( ! function_exists( 'crm_operator_tg_sync_order_status' ) ) {
	function crm_operator_tg_sync_order_status( int $order_id, array $args = [] ): array {
		global $wpdb;

		$source_code = sanitize_key( (string) ( $args['source_code'] ?? 'system' ) );
		$result      = [
			'ok'               => false,
			'skipped'          => false,
			'skip_reason'      => '',
			'receipt_updated'  => false,
			'receipt_deleted'  => false,
			'receipt_replaced' => false,
			'errors'           => [],
			'status_code'      => '',
			'company_id'       => 0,
		];

		if ( $order_id <= 0 || ! function_exists( 'crm_merchant_tg_safe_json_decode' ) || ! function_exists( 'crm_merchant_tg_send_message' ) || ! function_exists( 'crm_merchant_tg_edit_message' ) || ! function_exists( 'crm_merchant_tg_edit_message_caption' ) || ! function_exists( 'crm_merchant_tg_telegram_response_ok' ) || ! function_exists( 'crm_merchant_tg_merge_order_meta' ) || ! function_exists( 'crm_merchant_tg_append_message_id_list' ) || ! function_exists( 'crm_merchant_tg_normalize_message_id_list' ) ) {
			$result['errors'][] = 'helpers_not_ready';
			return $result;
		}

		$order = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, company_id, created_for_type, source_channel, status_code, merchant_order_id,
				        payment_link, payment_amount_value, amount_asset_value, amount_asset_code, meta_json,
				        created_at, updated_at, paid_at, declined_at, cancelled_at, expired_at
				 FROM crm_fintech_payment_orders
				 WHERE id = %d
				 LIMIT 1",
				$order_id
			)
		);

		if ( ! $order ) {
			$result['errors'][] = 'order_not_found';
			return $result;
		}

		$result['company_id']  = (int) ( $order->company_id ?? 0 );
		$result['status_code'] = sanitize_key( (string) ( $order->status_code ?? '' ) );

		if ( (int) $order->company_id <= 0 ) {
			$result['errors'][] = 'invalid_company';
			return $result;
		}
		if ( (string) ( $order->created_for_type ?? '' ) !== 'company' ) {
			$result['skipped']     = true;
			$result['skip_reason'] = 'not_company_order';
			return $result;
		}
		if ( (string) ( $order->source_channel ?? '' ) !== 'telegram_operator' ) {
			$result['skipped']     = true;
			$result['skip_reason'] = 'not_operator_order';
			return $result;
		}
		if ( ! in_array( $result['status_code'], [ 'paid', 'declined', 'cancelled', 'expired', 'error' ], true ) ) {
			$result['skipped']     = true;
			$result['skip_reason'] = 'order_not_terminal';
			return $result;
		}

		$meta                 = crm_merchant_tg_safe_json_decode( (string) ( $order->meta_json ?? '' ) );
		$chat_id              = crm_telegram_sanitize_chat_id_value( (string) ( $meta['operator_tg_receipt_chat_id'] ?? $meta['tg_chat_id'] ?? '' ) );
		$receipt_message_id   = (int) ( $meta['operator_tg_receipt_message_id'] ?? 0 );
		$receipt_message_type = sanitize_key( (string) ( $meta['operator_tg_receipt_message_type'] ?? '' ) );
		$receipt_message_ids  = crm_merchant_tg_normalize_message_id_list( $meta['operator_tg_receipt_message_ids'] ?? [] );
		$deleted_message_ids  = crm_merchant_tg_normalize_message_id_list( $meta['operator_tg_receipt_deleted_message_ids'] ?? [] );

		if ( $chat_id === '' ) {
			$result['errors'][] = 'operator_chat_missing';
			return $result;
		}

		$telegram_settings = function_exists( 'crm_telegram_collect_settings' )
			? crm_telegram_collect_settings( (int) $order->company_id, 'operator' )
			: [];
		$bot_token = trim( (string) ( $telegram_settings['bot_token'] ?? '' ) );
		$telegram_ready = function_exists( 'crm_merchant_tg_require_telegram_class' )
			? crm_merchant_tg_require_telegram_class()
			: class_exists( 'Telegram' );
		if ( $bot_token === '' || ! $telegram_ready ) {
			$result['errors'][] = 'operator_bot_not_ready';
			return $result;
		}

		$telegram   = new Telegram( $bot_token );
		$meta_patch = [
			'operator_tg_receipt_last_status_code'        => $result['status_code'],
			'operator_tg_receipt_last_status_sync_at'     => current_time( 'mysql', true ),
			'operator_tg_receipt_last_status_sync_source' => $source_code !== '' ? $source_code : 'system',
		];
		$receipt_text = crm_operator_tg_receipt_text_from_order( $order, $result['status_code'], false );
		$keyboard     = crm_operator_tg_receipt_terminal_keyboard();

		if ( $receipt_message_id > 0 && $receipt_message_type === 'photo' ) {
			$deleted = crm_operator_tg_delete_message( $telegram, $chat_id, $receipt_message_id );
			if ( $deleted ) {
				$result['receipt_deleted'] = true;
				$deleted_message_ids = crm_merchant_tg_append_message_id_list( $deleted_message_ids, $receipt_message_id );
				$meta_patch['operator_tg_receipt_deleted_message_ids'] = $deleted_message_ids;
				$meta_patch['operator_tg_receipt_message_id'] = 0;
				$meta_patch['operator_tg_receipt_message_type'] = 'text';
			} else {
				$receipt_response = crm_merchant_tg_edit_message_caption( $telegram, $chat_id, $receipt_message_id, $receipt_text, $keyboard );
				if ( crm_merchant_tg_telegram_response_ok( $receipt_response ) ) {
					$result['receipt_updated'] = true;
					$meta_patch['operator_tg_receipt_message_ids'] = crm_merchant_tg_append_message_id_list( $receipt_message_ids, $receipt_message_id );
				} else {
					$result['errors'][] = 'receipt_delete_failed:' . trim( (string) ( $receipt_response['description'] ?? 'unknown' ) );
				}
			}
		}

		if ( ! $result['receipt_updated'] && ! $result['receipt_deleted'] && $receipt_message_id > 0 && $receipt_message_type !== 'photo' ) {
			$receipt_response = crm_merchant_tg_edit_message( $telegram, $chat_id, $receipt_message_id, $receipt_text, $keyboard );
			if ( ! crm_merchant_tg_telegram_response_ok( $receipt_response ) ) {
				$receipt_response = crm_merchant_tg_edit_message_caption( $telegram, $chat_id, $receipt_message_id, $receipt_text, $keyboard );
			}

			if ( crm_merchant_tg_telegram_response_ok( $receipt_response ) ) {
				$result['receipt_updated'] = true;
				$meta_patch['operator_tg_receipt_message_ids'] = crm_merchant_tg_append_message_id_list( $receipt_message_ids, $receipt_message_id );
			}
		}

		if ( ! $result['receipt_updated'] ) {
			if ( $receipt_message_id > 0 && ! $result['receipt_deleted'] ) {
				$deleted = crm_operator_tg_delete_message( $telegram, $chat_id, $receipt_message_id );
				if ( $deleted ) {
					$result['receipt_deleted'] = true;
					$deleted_message_ids = crm_merchant_tg_append_message_id_list( $deleted_message_ids, $receipt_message_id );
					$meta_patch['operator_tg_receipt_deleted_message_ids'] = $deleted_message_ids;
					$meta_patch['operator_tg_receipt_message_id'] = 0;
					$meta_patch['operator_tg_receipt_message_type'] = 'text';
				}
			}

			$replacement_response = crm_merchant_tg_send_message( $telegram, $chat_id, $receipt_text, $keyboard );
			if ( crm_merchant_tg_telegram_response_ok( $replacement_response ) ) {
				$new_message_id = (int) ( $replacement_response['result']['message_id'] ?? 0 );
				$result['receipt_replaced'] = true;
				$meta_patch['operator_tg_receipt_chat_id'] = $chat_id;
				$meta_patch['operator_tg_receipt_message_id'] = $new_message_id;
				$meta_patch['operator_tg_receipt_message_type'] = 'text';
				$meta_patch['operator_tg_receipt_message_ids'] = crm_merchant_tg_append_message_id_list( $receipt_message_ids, $new_message_id );
			} else {
				$result['errors'][] = 'replacement_send_failed:' . trim( (string) ( $replacement_response['description'] ?? 'unknown' ) );
			}
		}

		if ( ! empty( $deleted_message_ids ) ) {
			$meta_patch['operator_tg_receipt_deleted_message_ids'] = $deleted_message_ids;
		}

		$meta_write = crm_merchant_tg_merge_order_meta( $order_id, (int) $order->company_id, $meta_patch );
		if ( empty( $meta_write['ok'] ) ) {
			$result['errors'][] = 'meta_update_failed';
		}

		$result['ok'] = $result['receipt_updated'] || $result['receipt_replaced'] || $result['receipt_deleted'];

		crm_log(
			$result['ok'] ? 'operator.telegram.receipt_synced' : 'operator.telegram.receipt_sync_failed',
			[
				'category'    => 'payments',
				'level'       => $result['ok'] ? 'info' : 'warning',
				'action'      => 'telegram_receipt_sync',
				'message'     => $result['ok']
					? 'Operator receipt synced to terminal order status.'
					: 'Operator receipt sync failed.',
				'target_type' => 'payment_order',
				'target_id'   => $order_id,
				'org_id'      => (int) $order->company_id,
				'is_success'  => $result['ok'],
				'context'     => [
					'status_code'       => $result['status_code'],
					'source_code'       => $source_code,
					'receipt_updated'   => $result['receipt_updated'],
					'receipt_deleted'   => $result['receipt_deleted'],
					'receipt_replaced'  => $result['receipt_replaced'],
					'receipt_message_id'=> $receipt_message_id,
					'receipt_type'      => $receipt_message_type,
					'errors'            => $result['errors'],
				],
			]
		);

		return $result;
	}
}

if ( ! function_exists( 'crm_operator_tg_present_screen' ) ) {
	function crm_operator_tg_present_screen( $telegram, int $company_id, string $chat_id, object $account, array $ctx = [], string $screen = 'main', bool $force_new = false, bool $refresh_market = false ): bool {
		$chat_id = crm_telegram_sanitize_chat_id_value( $chat_id );
		if ( $company_id <= 0 || $chat_id === '' || ! $telegram ) {
			return false;
		}

		$session            = crm_operator_tg_session_get( $company_id, $chat_id );
		$payload            = crm_operator_tg_screen_payload( $company_id, $chat_id, $account, $session, $screen, $refresh_market );
		$stored_message_id  = (int) ( $session['last_menu_message_id'] ?? 0 );
		$current_message_id = ! empty( $ctx['message_id'] ) ? (int) $ctx['message_id'] : 0;
		$target_message_id  = 0;
		$response           = [ 'ok' => false ];

		// Operator bot keeps exactly one active menu at the bottom of the chat:
		// send a fresh message for every screen, then delete the previously tracked one.
		if ( function_exists( 'crm_merchant_tg_send_message' ) ) {
			$response = crm_merchant_tg_send_message( $telegram, $chat_id, $payload['text'], $payload['keyboard'] );
			if ( ! empty( $response['result']['message_id'] ) ) {
				$target_message_id = (int) $response['result']['message_id'];
			}
		}

		if ( empty( $response['ok'] ) || $target_message_id <= 0 ) {
			return false;
		}

		crm_operator_tg_session_patch(
			$company_id,
			$chat_id,
			[
				'last_menu_message_id' => $target_message_id,
				'last_screen'          => (string) $payload['screen'],
			]
		);

		$stale_candidates = array_filter( [ $stored_message_id ] );
		if ( $current_message_id > 0 && $current_message_id === $stored_message_id ) {
			$stale_candidates[] = $current_message_id;
		}
		$stale_candidates = array_unique( array_filter( $stale_candidates ) );
		foreach ( $stale_candidates as $stale_message_id ) {
			$stale_message_id = (int) $stale_message_id;
			if ( $stale_message_id > 0 && $stale_message_id !== $target_message_id ) {
				crm_operator_tg_delete_message( $telegram, $chat_id, $stale_message_id );
			}
		}

		return true;
	}
}

if ( ! function_exists( 'crm_operator_tg_open_orders_sync_cooldown_key' ) ) {
	function crm_operator_tg_open_orders_sync_cooldown_key( int $company_id, string $chat_id ): string {
		return 'crm_operator_orders_sync_' . $company_id . '_' . md5( $chat_id );
	}
}

if ( ! function_exists( 'crm_operator_tg_fetch_order_for_chat' ) ) {
	function crm_operator_tg_fetch_order_for_chat( int $company_id, string $chat_id, int $order_id ): ?object {
		global $wpdb;

		$chat_id = crm_telegram_sanitize_chat_id_value( $chat_id );
		if ( $company_id <= 0 || $chat_id === '' || $order_id <= 0 ) {
			return null;
		}

		$order = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				 FROM crm_fintech_payment_orders
				 WHERE id = %d
				   AND company_id = %d
				   AND created_for_type = 'company'
				   AND source_channel = 'telegram_operator'
				   AND meta_json LIKE %s
				 LIMIT 1",
				$order_id,
				$company_id,
				crm_operator_tg_chat_order_like( $chat_id )
			)
		);

		return $order ?: null;
	}
}

if ( ! function_exists( 'crm_operator_tg_sync_open_orders' ) ) {
	function crm_operator_tg_sync_open_orders( $telegram, int $company_id, string $chat_id, bool $force = false ): array {
		$chat_id = crm_telegram_sanitize_chat_id_value( $chat_id );
		$result  = [
			'checked'       => 0,
			'paid_notified' => 0,
		];

		if ( $company_id <= 0 || $chat_id === '' ) {
			return $result;
		}

		$cooldown_key = crm_operator_tg_open_orders_sync_cooldown_key( $company_id, $chat_id );
		if ( ! $force && get_transient( $cooldown_key ) ) {
			return $result;
		}

		set_transient( $cooldown_key, '1', 30 );

		$orders = crm_operator_tg_recent_orders( $company_id, $chat_id, [ 'created', 'pending' ], 15 );
		foreach ( $orders as $order ) {
			$result['checked']++;
			$poll = crm_fintech_poll_order_status( $order, 'telegram_operator_orders_sync' );
			if ( ! empty( $poll['error'] ) ) {
				continue;
			}

			if ( empty( $poll['changed'] ) || (string) ( $poll['new_status'] ?? '' ) !== 'paid' ) {
				continue;
			}

			$fresh_order = crm_operator_tg_fetch_order_for_chat( $company_id, $chat_id, (int) ( $order->id ?? 0 ) );
			if ( $fresh_order && function_exists( 'crm_fintech_cron_notify_telegram' ) && crm_fintech_cron_notify_telegram( $fresh_order ) ) {
				$result['paid_notified']++;
			}
		}

		return $result;
	}
}

if ( ! function_exists( 'crm_operator_tg_route_callback' ) ) {
	function crm_operator_tg_route_callback( string $callback_data, array $ctx, $telegram, array $data ): bool {
		if ( ! crm_operator_tg_is_operator_context() ) {
			return false;
		}

		$company_id = crm_telegram_get_callback_company_id();
		$chat_id    = crm_telegram_sanitize_chat_id_value( $ctx['chat_id'] ?? '' );
		if ( $company_id <= 0 || $chat_id === '' ) {
			return false;
		}

		$access = crm_operator_tg_access_context( $company_id, $chat_id );
		if ( empty( $access['allowed'] ) || ! is_object( $access['account'] ) ) {
			if ( function_exists( 'tg_safe_answer_callback' ) ) {
				tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? null, 'Нет доступа', true );
			}
			bot_send_message( $telegram, $chat_id, (string) ( $access['message'] ?? 'Нет доступа.' ) );
			return true;
		}

		$account = $access['account'];
		$session = crm_operator_tg_session_get( $company_id, $chat_id );

		$map = [
			'menu_main'        => 'main',
			'orders_new'       => 'invoice',
			'orders_open'      => 'orders_open',
			'orders_closed'    => 'orders_paid',
			'orders_canceled'  => 'orders_cancelled',
			'orders_refresh_rate' => 'rates',
			'o:main'           => 'main',
			'o:invoice'        => 'invoice',
			'o:orders'         => 'orders',
			'o:orders:open'    => 'orders_open',
			'o:orders:paid'    => 'orders_paid',
			'o:orders:cancelled' => 'orders_cancelled',
			'o:rates'          => 'rates',
		];

		if ( $callback_data === 'o:invoice:rub' || $callback_data === 'o:invoice:usdt' ) {
			$callback_data = $callback_data === 'o:invoice:rub' ? 'o:invoice:mode:rub' : 'o:invoice:mode:usdt';
		}

		if ( strpos( $callback_data, 'o:invoice:mode:' ) === 0 ) {
			$requested_mode = sanitize_key( substr( $callback_data, strlen( 'o:invoice:mode:' ) ) );
			$mode           = crm_operator_tg_normalize_invoice_mode( $requested_mode, $company_id );
			$input_currency = crm_operator_tg_invoice_mode_input_currency( $mode );

			crm_operator_tg_session_patch(
				$company_id,
				$chat_id,
				[
					'invoice_mode'     => $mode,
					'invoice_awaiting' => 'amount',
				]
			);
			if ( function_exists( 'tg_safe_answer_callback' ) ) {
				tg_safe_answer_callback(
					$telegram,
					$ctx['callback_query_id'] ?? null,
					$requested_mode === $mode
						? 'Контур выбран. Отправьте сумму в ' . $input_currency
						: 'Контур недоступен, открыт дефолтный режим ввода'
				);
			}

			return crm_operator_tg_present_screen( $telegram, $company_id, $chat_id, $account, $ctx, 'invoice_amount', true );
		}

		if ( $callback_data === 'o:invoice:start' ) {
			$mode           = crm_operator_tg_normalize_invoice_mode( (string) ( $session['invoice_mode'] ?? '' ), $company_id );
			$input_currency = crm_operator_tg_invoice_mode_input_currency( $mode );

			crm_operator_tg_session_patch(
				$company_id,
				$chat_id,
				[
					'invoice_mode'     => $mode,
					'invoice_awaiting' => 'amount',
				]
			);
			if ( function_exists( 'tg_safe_answer_callback' ) ) {
				tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? null, 'Введите сумму в ' . $input_currency );
			}

			return crm_operator_tg_present_screen( $telegram, $company_id, $chat_id, $account, $ctx, 'invoice_amount', true );
		}

		if ( $callback_data === 'o:invoice:purpose' ) {
			crm_operator_tg_session_patch(
				$company_id,
				$chat_id,
				[
					'invoice_mode'     => crm_operator_tg_normalize_invoice_mode( (string) ( $session['invoice_mode'] ?? '' ), $company_id ),
					'invoice_awaiting' => 'purpose',
				]
			);
			if ( function_exists( 'tg_safe_answer_callback' ) ) {
				tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? null, 'Жду назначение' );
			}

			return crm_operator_tg_present_screen( $telegram, $company_id, $chat_id, $account, $ctx, 'invoice_purpose' );
		}

		if ( $callback_data === 'o:rates:refresh' ) {
			if ( function_exists( 'tg_safe_answer_callback' ) ) {
				tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? null, 'Обновляю курс' );
			}

			return crm_operator_tg_present_screen( $telegram, $company_id, $chat_id, $account, $ctx, 'rates', false, true );
		}

		if ( ! isset( $map[ $callback_data ] ) ) {
			return false;
		}

		$screen = $map[ $callback_data ];
		if ( strpos( $screen, 'orders' ) === 0 ) {
			crm_operator_tg_sync_open_orders( $telegram, $company_id, $chat_id, false );
		}

		if ( function_exists( 'tg_safe_answer_callback' ) ) {
			tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? null, 'Готово' );
		}

		return crm_operator_tg_present_screen( $telegram, $company_id, $chat_id, $account, $ctx, $screen );
	}
}

if ( ! function_exists( 'crm_operator_tg_route_message' ) ) {
	function crm_operator_tg_route_message( string $text, array $ctx, $telegram, array $data ): bool {
		if ( ! crm_operator_tg_is_operator_context() ) {
			return false;
		}

		$text       = trim( $text );
		$company_id = crm_telegram_get_callback_company_id();
		$chat_id    = crm_telegram_sanitize_chat_id_value( $ctx['chat_id'] ?? '' );
		if ( $company_id <= 0 || $chat_id === '' || $text === '' ) {
			return false;
		}

		$access = crm_operator_tg_access_context( $company_id, $chat_id );
		if ( empty( $access['allowed'] ) || ! is_object( $access['account'] ) ) {
			bot_send_message( $telegram, $chat_id, (string) ( $access['message'] ?? 'Нет доступа.' ) );
			return true;
		}

		$account = $access['account'];
		$session = crm_operator_tg_session_get( $company_id, $chat_id );
		$awaiting = (string) ( $session['invoice_awaiting'] ?? '' );
		$last_screen = (string) ( $session['last_screen'] ?? '' );

		// Fallback recovery: if transient state lost explicit awaiting flag,
		// infer it from the last rendered operator screen.
		if ( $awaiting === '' ) {
			if ( $last_screen === 'invoice' || $last_screen === 'invoice_amount' ) {
				$awaiting = 'amount';
				crm_operator_tg_session_patch(
					$company_id,
					$chat_id,
					[
						'invoice_awaiting' => 'amount',
					]
				);
			} elseif ( $last_screen === 'invoice_purpose' ) {
				$awaiting = 'purpose';
				crm_operator_tg_session_patch(
					$company_id,
					$chat_id,
					[
						'invoice_awaiting' => 'purpose',
					]
				);
			}
		}

		if ( $awaiting === '' ) {
			return crm_operator_tg_present_screen(
				$telegram,
				$company_id,
				$chat_id,
				$account,
				$ctx,
				$last_screen !== '' ? $last_screen : 'main',
				true
			) || true;
		}

		if ( $awaiting === 'purpose' ) {
			$purpose = function_exists( 'crm_fintech_normalize_payment_purpose' )
				? crm_fintech_normalize_payment_purpose( $text )
				: sanitize_text_field( $text );

			if ( $purpose === '' ) {
				return crm_operator_tg_present_screen( $telegram, $company_id, $chat_id, $account, $ctx, 'invoice_purpose' )
					|| true;
			}

			crm_operator_tg_session_patch(
				$company_id,
				$chat_id,
				[
					'invoice_awaiting'       => '',
					'invoice_custom_purpose' => $purpose,
				]
			);

			return crm_operator_tg_present_screen( $telegram, $company_id, $chat_id, $account, $ctx, 'invoice' )
				|| true;
		}

		if ( $awaiting !== 'amount' ) {
			return false;
		}

		$mode            = crm_operator_tg_normalize_invoice_mode( (string) ( $session['invoice_mode'] ?? '' ), $company_id );
		$payment_purpose = crm_operator_tg_effective_payment_purpose( $company_id, $session );
		$input_currency  = crm_operator_tg_invoice_mode_input_currency( $mode );
		$cleaned_amount  = trim( str_replace( ',', '.', $text ) );
		$amount          = filter_var( $cleaned_amount, FILTER_VALIDATE_FLOAT );

		if ( $amount === false || $amount <= 0 ) {
			if ( function_exists( 'bot_send_message' ) ) {
				bot_send_message( $telegram, $chat_id, 'Нужна положительная сумма в ' . $input_currency . '.' );
			}

			return crm_operator_tg_present_screen( $telegram, $company_id, $chat_id, $account, $ctx, 'invoice_amount' )
				|| true;
		}

		crm_operator_tg_session_patch(
			$company_id,
			$chat_id,
			[
				'invoice_awaiting' => '',
				'invoice_mode'     => $mode,
			]
		);

		$loading_message_id = 0;
		if ( function_exists( 'crm_merchant_tg_send_message' ) ) {
			$loading_response   = crm_merchant_tg_send_message(
				$telegram,
				$chat_id,
				crm_operator_tg_invoice_loading_text( $mode, $input_currency )
			);
			$loading_message_id = (int) ( $loading_response['result']['message_id'] ?? 0 );
		}

		$description = $payment_purpose !== '' ? $payment_purpose : 'Telegram operator order';
		$result      = function_exists( 'crm_fintech_create_company_order_from_mode' )
			? crm_fintech_create_company_order_from_mode(
				$mode,
				(float) $amount,
				$company_id,
				'telegram_operator',
				null,
				$description
			)
			: ( $mode === 'rub'
				? crm_fintech_create_order_by_payment_amount( (float) $amount, 'RUB', $company_id, 'telegram_operator', null, $description )
				: crm_fintech_create_order( (float) $amount, $company_id, 'telegram_operator', null, $description ) );

			if ( empty( $result['success'] ) ) {
				crm_operator_tg_session_patch(
					$company_id,
					$chat_id,
				[
					'invoice_awaiting' => 'amount',
					'invoice_mode'     => $mode,
					]
				);

				$error_text = '❌ Не удалось подготовить счёт: ' . (string) ( $result['error'] ?? 'unknown error' );
				if ( $loading_message_id > 0 && function_exists( 'crm_merchant_tg_edit_message' ) ) {
					crm_merchant_tg_edit_message( $telegram, $chat_id, $loading_message_id, $error_text );
				} elseif ( function_exists( 'bot_send_message' ) ) {
					bot_send_message( $telegram, $chat_id, $error_text );
				}

				return crm_operator_tg_present_screen( $telegram, $company_id, $chat_id, $account, $ctx, 'invoice_amount' )
					|| true;
			}

		$result['payment_purpose']      = $payment_purpose;
		$result['operator_invoice_mode'] = $mode;
		$order_db_id = (int) ( $result['order_db_id'] ?? 0 );
		if ( $order_db_id > 0 && function_exists( 'crm_tg_miniapp_attach_order_telegram_meta' ) ) {
			crm_tg_miniapp_attach_order_telegram_meta(
				$order_db_id,
				$company_id,
				$chat_id,
				'operator',
				'telegram_operator',
				[
					'payment_purpose'      => $payment_purpose,
					'operator_invoice_mode' => $mode,
					'warning'               => (string) ( $result['warning'] ?? '' ),
					'target_amount_thb'     => isset( $result['target_amount_thb'] ) ? (float) $result['target_amount_thb'] : 0.0,
					'thb_rate'              => isset( $result['thb_rate'] ) ? (float) $result['thb_rate'] : 0.0,
				]
			);
		}

		$keyboard     = crm_operator_tg_receipt_keyboard( $order_db_id );
		$receipt_text = _tg_orders_success_message( $result );
		$send_result  = [
			'ok'           => false,
			'response'     => [],
			'message_type' => 'text',
		];

		crm_operator_tg_close_menu_anchor( $telegram, $company_id, $chat_id, $session );

		if ( ! empty( $result['qr_url'] ) ) {
			$send_result = _tg_orders_send_photo( $telegram, $chat_id, (string) $result['qr_url'], $receipt_text, $keyboard );
			if ( $loading_message_id > 0 && ! empty( $send_result['response'] ) ) {
				crm_operator_tg_delete_message( $telegram, $chat_id, $loading_message_id );
			}
		} elseif ( $loading_message_id > 0 && function_exists( 'crm_merchant_tg_edit_message' ) ) {
			$response = crm_merchant_tg_edit_message( $telegram, $chat_id, $loading_message_id, $receipt_text, $keyboard );
			if ( ! empty( $response['ok'] ) || ( function_exists( 'crm_merchant_tg_is_not_modified_response' ) && crm_merchant_tg_is_not_modified_response( $response ) ) ) {
				if ( empty( $response['result']['message_id'] ) ) {
					$response['result']['message_id'] = $loading_message_id;
				}
				$send_result = [
					'ok'           => true,
					'response'     => $response,
					'message_type' => 'text',
				];
			}
		}

		if ( empty( $send_result['ok'] ) && empty( $result['qr_url'] ) ) {
			if ( function_exists( 'crm_merchant_tg_send_message' ) ) {
				$response = crm_merchant_tg_send_message( $telegram, $chat_id, $receipt_text, $keyboard );
				$send_result = [
					'ok'           => ! empty( $response['ok'] ),
					'response'     => $response,
					'message_type' => 'text',
				];
			} else {
				bot_send_message( $telegram, $chat_id, $receipt_text, $keyboard );
			}
			if ( $loading_message_id > 0 && ! empty( $send_result['response'] ) ) {
				$sent_message_id = (int) ( $send_result['response']['result']['message_id'] ?? 0 );
				if ( $sent_message_id > 0 && $sent_message_id !== $loading_message_id ) {
					crm_operator_tg_delete_message( $telegram, $chat_id, $loading_message_id );
				}
			}
		}

		if ( $order_db_id > 0 && ! empty( $send_result['response'] ) ) {
			crm_operator_tg_store_invoice_message_context(
				$order_db_id,
				$company_id,
				$chat_id,
				(string) ( $send_result['message_type'] ?? 'text' ),
				(array) $send_result['response']
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

		if ( $command === '/chat_id' ) {
			bot_send_message( $telegram, $chat_id, 'chat_id: ' . $chat_id );
			return true;
		}

		if ( $command === '/ping' ) {
			bot_send_message( $telegram, $chat_id, 'pong ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC' );
			return true;
		}

		$access = crm_operator_tg_access_context( $company_id, $chat_id );
		if ( empty( $access['allowed'] ) || ! is_object( $access['account'] ) ) {
			if ( $chat_id !== '' ) {
				bot_send_message( $telegram, $chat_id, (string) ( $access['message'] ?? 'Нет доступа.' ) );
			}
			return true;
		}

		$account      = $access['account'];
		$definitions  = function_exists( 'crm_operator_tg_command_definitions' ) ? crm_operator_tg_command_definitions() : [];
		$command_meta = $definitions[ $command ] ?? null;

		if ( is_array( $command_meta ) ) {
			$screen = (string) ( $command_meta['screen'] ?? 'main' );
			if ( strpos( $screen, 'orders' ) === 0 ) {
				crm_operator_tg_sync_open_orders( $telegram, $company_id, $chat_id, false );
			}

			return crm_operator_tg_present_screen( $telegram, $company_id, $chat_id, $account, $ctx, $screen, true );
		}

		if ( $command === '/help' ) {
			return crm_operator_tg_present_screen( $telegram, $company_id, $chat_id, $account, $ctx, 'main', true );
		}

		bot_send_message( $telegram, $chat_id, 'Команда operator-бота не поддерживается. Используйте /menu.' );

		return true;
	}
}
