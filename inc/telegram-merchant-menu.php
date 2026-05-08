<?php
/**
 * Malibu Exchange — Merchant Telegram Menu Layer
 *
 * Отдельный UI/session-контур для merchant-бота:
 * - company-scoped access check по (company_id, chat_id)
 * - один "живой" anchor-message меню на чат
 * - screen-shell навигация через editMessageText
 * - уведомление об активации мерчанта из CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'crm_merchant_tg_is_company_bot_context' ) ) {
	function crm_merchant_tg_is_company_bot_context(): bool {
		return function_exists( 'crm_telegram_get_callback_company_id' ) && crm_telegram_get_callback_company_id() > 0;
	}
}

if ( ! function_exists( 'crm_merchant_tg_company_id' ) ) {
	function crm_merchant_tg_company_id(): int {
		return crm_merchant_tg_is_company_bot_context() ? (int) crm_telegram_get_callback_company_id() : 0;
	}
}

if ( ! function_exists( 'crm_merchant_tg_escape' ) ) {
	function crm_merchant_tg_escape( $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'crm_merchant_tg_require_telegram_class' ) ) {
	function crm_merchant_tg_require_telegram_class(): bool {
		if ( class_exists( 'Telegram' ) ) {
			return true;
		}

		$path = get_template_directory() . '/callbacks/telegram/Telegram.php';
		if ( is_file( $path ) ) {
			require_once $path;
		}

		return class_exists( 'Telegram' );
	}
}

if ( ! function_exists( 'crm_merchant_tg_get_by_chat_id' ) ) {
	function crm_merchant_tg_get_by_chat_id( int $company_id, string $chat_id ): ?object {
		global $wpdb;

		$chat_id = trim( $chat_id );
		if ( $company_id <= 0 || $chat_id === '' ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.*,
				        c.name AS company_name,
				        c.code AS company_code,
				        o.name AS office_name
				 FROM crm_merchants m
				 JOIN crm_companies c ON c.id = m.company_id
				 LEFT JOIN crm_company_offices o ON o.id = m.office_id
				 WHERE m.company_id = %d
				   AND m.chat_id = %s
				 LIMIT 1",
				$company_id,
				$chat_id
			)
		);
	}
}

if ( ! function_exists( 'crm_merchant_tg_access_denied_text' ) ) {
	function crm_merchant_tg_access_denied_text( string $code ): string {
		switch ( $code ) {
			case CRM_MERCHANT_STATUS_PENDING:
				return "⏳ <b>Профиль ещё не активирован</b>\n\nВаш Telegram уже привязан к мерчанту, но доступ к рабочему меню пока не открыт.\nДождитесь активации от администратора компании.";
			case CRM_MERCHANT_STATUS_BLOCKED:
				return "🚫 <b>Доступ приостановлен</b>\n\nВаш профиль мерчанта заблокирован.\nЕсли это ошибка, свяжитесь с администратором компании.";
			case CRM_MERCHANT_STATUS_ARCHIVED:
				return "📦 <b>Профиль недоступен</b>\n\nЭтот профиль мерчанта архивирован и больше не может работать в боте.";
			case 'not_found':
			default:
				return "⛔️ <b>Доступ закрыт</b>\n\nЭтот Telegram ещё не привязан к мерчанту в системе.\nПопросите администратора компании выдать вам invite-ссылку.";
		}
	}
}

if ( ! function_exists( 'crm_merchant_tg_access_context' ) ) {
	function crm_merchant_tg_access_context( int $company_id, string $chat_id ): array {
		$merchant = crm_merchant_tg_get_by_chat_id( $company_id, $chat_id );
		if ( ! $merchant ) {
			return [
				'allowed' => false,
				'code'    => 'not_found',
				'message' => crm_merchant_tg_access_denied_text( 'not_found' ),
				'merchant'=> null,
			];
		}

		$status = (string) ( $merchant->status ?? '' );
		$allowed_statuses = [ CRM_MERCHANT_STATUS_ACTIVE ];
		if ( in_array( $status, $allowed_statuses, true ) ) {
			return [
				'allowed' => true,
				'code'    => $status,
				'message' => '',
				'merchant'=> $merchant,
			];
		}

		return [
			'allowed' => false,
			'code'    => $status !== '' ? $status : 'not_found',
			'message' => crm_merchant_tg_access_denied_text( $status !== '' ? $status : 'not_found' ),
			'merchant'=> $merchant,
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_session_get' ) ) {
	function crm_merchant_tg_session_get( int $company_id, string $chat_id ): ?array {
		global $wpdb;

		$chat_id = trim( $chat_id );
		if ( $company_id <= 0 || $chat_id === '' ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				 FROM crm_merchant_telegram_sessions
				 WHERE company_id = %d
				   AND chat_id = %s
				 LIMIT 1",
				$company_id,
				$chat_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}
}

if ( ! function_exists( 'crm_merchant_tg_session_upsert' ) ) {
	function crm_merchant_tg_session_upsert( int $company_id, int $merchant_id, string $chat_id, array $fields = [] ): void {
		global $wpdb;

		$chat_id = trim( $chat_id );
		if ( $company_id <= 0 || $merchant_id <= 0 || $chat_id === '' ) {
			return;
		}

		$existing = crm_merchant_tg_session_get( $company_id, $chat_id );
		$data = array_merge(
			[
				'company_id'           => $company_id,
				'merchant_id'          => $merchant_id,
				'chat_id'              => $chat_id,
				'last_menu_screen'     => 'main',
				'active_pipeline_code' => null,
				'pipeline_state_json'  => null,
				'last_seen_at'         => current_time( 'mysql', true ),
			],
			$fields
		);

		$format_map = [
			'company_id'           => '%d',
			'merchant_id'          => '%d',
			'chat_id'              => '%s',
			'last_menu_message_id' => '%d',
			'last_menu_screen'     => '%s',
			'active_pipeline_code' => '%s',
			'pipeline_state_json'  => '%s',
			'last_seen_at'         => '%s',
		];

		$format = [];
		foreach ( array_keys( $data ) as $key ) {
			$format[] = $format_map[ $key ] ?? '%s';
		}

		if ( $existing ) {
			$wpdb->update(
				'crm_merchant_telegram_sessions',
				$data,
				[
					'id' => (int) $existing['id'],
				],
				$format,
				[ '%d' ]
			);
			return;
		}

		$wpdb->insert( 'crm_merchant_telegram_sessions', $data, $format );
	}
}

if ( ! function_exists( 'crm_merchant_tg_delete_message' ) ) {
	function crm_merchant_tg_delete_message( $telegram, string $chat_id, int $message_id ): bool {
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

if ( ! function_exists( 'crm_merchant_tg_send_message' ) ) {
	function crm_merchant_tg_send_message( $telegram, string $chat_id, string $text, ?array $keyboard = null ): array {
		if ( ! $telegram || $chat_id === '' ) {
			return [ 'ok' => false ];
		}

		$payload = [
			'chat_id'                  => $chat_id,
			'text'                     => $text,
			'parse_mode'               => 'HTML',
			'disable_web_page_preview' => true,
		];
		if ( $keyboard !== null ) {
			$payload['reply_markup'] = wp_json_encode( $keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		return (array) $telegram->sendMessage( $payload );
	}
}

if ( ! function_exists( 'crm_merchant_tg_edit_message' ) ) {
	function crm_merchant_tg_edit_message( $telegram, string $chat_id, int $message_id, string $text, ?array $keyboard = null ): array {
		if ( ! $telegram || $chat_id === '' || $message_id <= 0 ) {
			return [ 'ok' => false ];
		}

		$payload = [
			'chat_id'                  => $chat_id,
			'message_id'               => $message_id,
			'text'                     => $text,
			'parse_mode'               => 'HTML',
			'disable_web_page_preview' => true,
		];
		if ( $keyboard !== null ) {
			$payload['reply_markup'] = wp_json_encode( $keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		return (array) $telegram->editMessageText( $payload );
	}
}

if ( ! function_exists( 'crm_merchant_tg_is_not_modified_response' ) ) {
	function crm_merchant_tg_is_not_modified_response( array $response ): bool {
		$description = strtolower( (string) ( $response['description'] ?? '' ) );
		return strpos( $description, 'message is not modified' ) !== false;
	}
}

if ( ! function_exists( 'crm_merchant_tg_display_name' ) ) {
	function crm_merchant_tg_display_name( object $merchant ): string {
		$name = trim( (string) ( $merchant->name ?? '' ) );
		if ( $name !== '' ) {
			return $name;
		}

		$parts = array_filter(
			[
				trim( (string) ( $merchant->telegram_first_name ?? '' ) ),
				trim( (string) ( $merchant->telegram_last_name ?? '' ) ),
			]
		);
		if ( ! empty( $parts ) ) {
			return implode( ' ', $parts );
		}

		$username = trim( (string) ( $merchant->telegram_username ?? '' ) );
		if ( $username !== '' ) {
			return '@' . ltrim( $username, '@' );
		}

		return 'Merchant #' . (int) ( $merchant->id ?? 0 );
	}
}

if ( ! function_exists( 'crm_merchant_tg_normalize_screen' ) ) {
	function crm_merchant_tg_normalize_screen( string $screen ): string {
		$screen = trim( strtolower( $screen ) );
		$allowed = [
			'main',
			'rates',
			'rates_rub_thb',
			'rates_usdt_thb',
			'rates_rub_usdt',
			'rates_rub_usdt_check',
			'balances',
			'invoice',
			'invoice_rub_thb',
			'invoice_usdt_thb',
			'invoice_rub_usdt',
			'orders',
			'orders_open',
			'orders_paid',
			'orders_cancelled',
			'profile',
			'help',
		];

		return in_array( $screen, $allowed, true ) ? $screen : 'main';
	}
}

if ( ! function_exists( 'crm_merchant_tg_pad' ) ) {
	function crm_merchant_tg_pad( string $s, int $width, string $align = 'left' ): string {
		$len = mb_strlen( $s );
		if ( $len >= $width ) {
			return $s;
		}
		$pad = str_repeat( ' ', $width - $len );
		return $align === 'right' ? $pad . $s : $s . $pad;
	}
}

if ( ! function_exists( 'crm_merchant_tg_fmt_rate' ) ) {
	function crm_merchant_tg_fmt_rate( ?float $value, int $decimals = 4 ): string {
		if ( $value === null ) {
			return '—';
		}
		return number_format( $value, $decimals, '.', '' );
	}
}

if ( ! function_exists( 'crm_merchant_tg_fmt_money' ) ) {
	function crm_merchant_tg_fmt_money( float $value, int $decimals = 2 ): string {
		return number_format( $value, $decimals, '.', ' ' );
	}
}

if ( ! function_exists( 'crm_merchant_tg_now_label' ) ) {
	function crm_merchant_tg_now_label( int $company_id ): string {
		try {
			$tz = function_exists( 'crm_get_timezone' ) ? crm_get_timezone( $company_id ) : new DateTimeZone( wp_timezone_string() );
			$now = ( new DateTime( 'now', $tz ) )->format( 'H:i' );
			$tz_label = function_exists( 'crm_get_timezone_label' ) ? crm_get_timezone_label( $company_id ) : '';
			return trim( $now . ' ' . $tz_label );
		} catch ( \Throwable $e ) {
			return gmdate( 'H:i' ) . ' UTC';
		}
	}
}

if ( ! function_exists( 'crm_merchant_tg_rates_snapshot' ) ) {
	function crm_merchant_tg_rates_snapshot( int $company_id, bool $auto_fetch_missing_rub_usdt = true, bool $refresh_rub_thb = false ): array {
		global $wpdb;

		$pair = function_exists( 'rates_get_pair' ) ? rates_get_pair( RATES_PAIR_CODE, $company_id ) : null;

		$rub_thb_rate       = null;
		$rub_thb_updated_at = null;
		$rub_thb_checked_at = null;
		$rub_thb_saved      = false;
		$rub_thb_unchanged  = false;
		$rub_thb_error      = '';
		$rub_thb_refresh_attempted = false;
		$rub_thb_refresh_failed    = false;
		if ( $pair ) {
			if ( $refresh_rub_thb && function_exists( 'rates_refresh_ex24_snapshot' ) ) {
				$rub_thb_refresh_attempted = true;
				$refresh = rates_refresh_ex24_snapshot( $company_id, 'telegram', RATES_PAIR_CODE, RATES_PROVIDER_SOURCE );
				if ( ! empty( $refresh['ok'] ) ) {
					$rub_thb_rate       = isset( $refresh['our_sberbank'] ) && $refresh['our_sberbank'] !== null ? (float) $refresh['our_sberbank'] : null;
					$rub_thb_updated_at = (string) ( $refresh['created_at'] ?? '' );
					$rub_thb_checked_at = (string) ( $refresh['checked_at'] ?? '' );
					$rub_thb_saved      = ! empty( $refresh['saved'] );
					$rub_thb_unchanged  = ! empty( $refresh['unchanged'] );
					if ( $rub_thb_rate === null ) {
						$rub_thb_error = 'Ex24 не вернул курс Sberbank.';
					}
				} else {
					$rub_thb_refresh_failed = true;
					$rub_thb_error = (string) ( $refresh['message'] ?? 'Не удалось обновить курс Ex24.' );
				}
			}

			$row = ( ! $rub_thb_refresh_attempted || $rub_thb_refresh_failed ) && $rub_thb_rate === null ? $wpdb->get_row(
				$wpdb->prepare(
					"SELECT our_sberbank_rate, created_at
					 FROM crm_rate_history
					 WHERE organization_id = %d
					   AND pair_id = %d
					   AND provider = %s
					   AND source_param = %s
					   AND our_sberbank_rate IS NOT NULL
					 ORDER BY created_at DESC, id DESC
					 LIMIT 1",
					$company_id,
					(int) $pair->id,
					RATES_PROVIDER_EX24,
					RATES_PROVIDER_SOURCE
				),
				ARRAY_A
			) : null;
			if ( is_array( $row ) ) {
				$rub_thb_rate       = (float) $row['our_sberbank_rate'];
				$rub_thb_updated_at = (string) $row['created_at'];
			}
		}

		// USDT_THB: bitkub mid с коэффициентом пары.
		$usdt_thb_rate       = null;
		$usdt_thb_updated_at = null;
		$usdt_thb_has_pair   = false;
		$usdt_src            = 'bitkub';

		$usdt_thb_pair = function_exists( 'rates_get_pair' ) ? rates_get_pair( 'USDT_THB', $company_id ) : null;
		if ( $usdt_thb_pair ) {
			$usdt_thb_has_pair = true;
			$usdt_src    = in_array( (string) ( $usdt_thb_pair->market_source ?? '' ), [ 'bitkub', 'binance_th' ], true )
				? (string) $usdt_thb_pair->market_source
				: 'bitkub';
			$bk_snapshot = function_exists( 'rates_get_last_market_snapshot' )
				? rates_get_last_market_snapshot( $usdt_src, $company_id )
				: null;
			if ( is_array( $bk_snapshot ) && isset( $bk_snapshot['mid'] ) && $bk_snapshot['mid'] !== null ) {
				$coeff = function_exists( 'rates_get_coefficient_full' )
					? rates_get_coefficient_full( (int) $usdt_thb_pair->id, RATES_PROVIDER_EX24, RATES_PROVIDER_SOURCE )
					: [ 'value' => 0.0, 'type' => 'absolute' ];
				$bk_mid = (float) $bk_snapshot['mid'];
				if ( function_exists( 'rates_apply_payout_margin' ) ) {
					$usdt_thb_rate = rates_apply_payout_margin( $bk_mid, (float) $coeff['value'], (string) $coeff['type'] );
				}
				$usdt_thb_updated_at = (string) $bk_snapshot['created_at'];
			}
		}

		$rub_usdt_pair    = function_exists( 'rates_get_pair' ) ? rates_get_pair( 'RUB_USDT', $company_id ) : null;
		$rub_usdt_rate    = null;
		$rub_usdt_updated = null;
		$rub_usdt_raw     = null;
		$rub_usdt_error   = '';
		$rub_usdt_source  = '';

		if ( $rub_usdt_pair && function_exists( 'rates_kanyon_get_last' ) ) {
			$last_kanyon = rates_kanyon_get_last( $company_id );

			if ( ! $last_kanyon && $auto_fetch_missing_rub_usdt && function_exists( 'rates_kanyon_fetch_and_record' ) ) {
				$fallback = rates_kanyon_fetch_and_record( $company_id, 'telegram' );
				if ( ! empty( $fallback['ok'] ) ) {
					$last_kanyon = rates_kanyon_get_last( $company_id );
				} else {
					$rub_usdt_error = (string) ( $fallback['error'] ?? 'Не удалось получить курс Kanyon.' );
				}
			}

			if ( $last_kanyon ) {
				$ru_coeff_full = rates_get_coefficient_full( (int) $rub_usdt_pair->id, RATES_PROVIDER_EX24, RATES_PROVIDER_SOURCE );
				$rub_usdt_raw  = (float) $last_kanyon['kanyon_rate'];
				$rub_usdt_rate = rates_apply_margin(
					$rub_usdt_raw,
					(float) $ru_coeff_full['value'],
					(string) $ru_coeff_full['type']
				);
				$rub_usdt_updated = $last_kanyon['created_at'];
				$rub_usdt_source  = (string) ( $last_kanyon['source'] ?? '' );
			}
		}

		return [
			'rub_thb' => [
				'rate'       => $rub_thb_rate,
				'updated_at' => $rub_thb_updated_at,
				'has_pair'   => (bool) $pair,
				'checked_at' => $rub_thb_checked_at,
				'saved'      => $rub_thb_saved,
				'unchanged'  => $rub_thb_unchanged,
				'error'      => $rub_thb_error,
			],
			'usdt_thb' => [
				'rate'          => $usdt_thb_rate,
				'updated_at'    => $usdt_thb_updated_at,
				'has_pair'      => $usdt_thb_has_pair,
				'market_source' => $usdt_thb_has_pair ? $usdt_src : 'bitkub',
			],
			'rub_usdt' => [
				'rate'       => $rub_usdt_rate,
				'raw_rate'   => $rub_usdt_raw,
				'updated_at' => $rub_usdt_updated,
				'has_pair'   => (bool) $rub_usdt_pair,
				'source'     => $rub_usdt_source,
				'error'      => $rub_usdt_error,
			],
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_orders_counts' ) ) {
	function crm_merchant_tg_orders_counts( int $merchant_id ): array {
		global $wpdb;

		if ( $merchant_id <= 0 ) {
			return [
				'open'      => 0,
				'paid'      => 0,
				'cancelled' => 0,
			];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status_code, COUNT(*) AS cnt
				 FROM crm_fintech_payment_orders
				 WHERE merchant_id = %d
				 GROUP BY status_code",
				$merchant_id
			),
			ARRAY_A
		) ?: [];

		$map = [
			'open'      => 0,
			'paid'      => 0,
			'cancelled' => 0,
		];

		foreach ( $rows as $row ) {
			$status = (string) ( $row['status_code'] ?? '' );
			$count  = (int) ( $row['cnt'] ?? 0 );
			if ( in_array( $status, [ 'created', 'pending' ], true ) ) {
				$map['open'] += $count;
			} elseif ( $status === 'paid' ) {
				$map['paid'] += $count;
			} elseif ( in_array( $status, [ 'declined', 'cancelled', 'expired', 'error' ], true ) ) {
				$map['cancelled'] += $count;
			}
		}

		return $map;
	}
}

if ( ! function_exists( 'crm_merchant_tg_screen_payload' ) ) {
	function crm_merchant_tg_screen_payload( string $screen, object $merchant ): array {
		$screen        = crm_merchant_tg_normalize_screen( $screen );
		$display_name  = crm_merchant_tg_escape( crm_merchant_tg_display_name( $merchant ) );
		$company_name  = crm_merchant_tg_escape( (string) ( $merchant->company_name ?? '' ) );
		$office_name   = trim( (string) ( $merchant->office_name ?? '' ) );
		$office_name   = $office_name !== '' ? crm_merchant_tg_escape( $office_name ) : 'Без офиса';
		$markup_label  = crm_merchant_tg_escape( crm_merchant_markup_type_label( (string) ( $merchant->base_markup_type ?? 'percent' ) ) );
		$markup_value  = crm_merchant_tg_escape( crm_merchant_format_amount( (float) ( $merchant->base_markup_value ?? 0 ), '' ) );
		$balance_map   = crm_get_merchant_balance_summary_map( [ (int) $merchant->id ] );
		$balance       = $balance_map[ (int) $merchant->id ] ?? [ 'bonus_balance' => 0, 'referral_balance' => 0, 'total_balance' => 0 ];
		$order_counts  = crm_merchant_tg_orders_counts( (int) $merchant->id );

		$main_keyboard = [
			'inline_keyboard' => [
				[
					[ 'text' => '💱 Узнать курс', 'callback_data' => 'm:rates' ],
					[ 'text' => '💼 Балансы', 'callback_data' => 'm:balances' ],
				],
				[
					[ 'text' => '🧾 Выставить счёт', 'callback_data' => 'm:invoice' ],
					[ 'text' => '📂 Мои счета', 'callback_data' => 'm:orders' ],
				],
				[
					[ 'text' => '👤 Профиль', 'callback_data' => 'm:profile' ],
					[ 'text' => 'ℹ️ Помощь', 'callback_data' => 'm:help' ],
				],
			],
		];

		$company_id_for_rates = (int) ( $merchant->company_id ?? 0 );
		$rates_snapshot       = in_array( $screen, [ 'rates', 'rates_rub_thb', 'rates_usdt_thb', 'rates_rub_usdt', 'rates_rub_usdt_check' ], true )
			? crm_merchant_tg_rates_snapshot(
				$company_id_for_rates,
				$screen !== 'rates_rub_usdt_check',
				in_array( $screen, [ 'rates', 'rates_rub_thb' ], true )
			)
			: [];
		$markup_pct_label     = (string) ( $merchant->base_markup_type ?? 'percent' ) === 'percent'
			? crm_merchant_tg_fmt_rate( (float) ( $merchant->base_markup_value ?? 0 ), 2 ) . '%'
			: crm_merchant_tg_fmt_rate( (float) ( $merchant->base_markup_value ?? 0 ), 2 ) . ' (фикс.)';

		switch ( $screen ) {
			case 'rates':
				$rub_thb  = $rates_snapshot['rub_thb']  ?? [];
				$usdt_thb = $rates_snapshot['usdt_thb'] ?? [];
				$rub_usdt = $rates_snapshot['rub_usdt'] ?? [];

				// UI rule: стрелка в курсах означает "отдаём → получаем".
				$show_rub_thb  = ! empty( $rub_thb['has_pair'] );
				$show_usdt_thb = ! empty( $usdt_thb['has_pair'] );
				$show_rub_usdt = ! empty( $rub_usdt['has_pair'] );

				$rows   = [];
				$rows[] = crm_merchant_tg_pad( 'Направление', 14 ) . crm_merchant_tg_pad( 'Курс', 12, 'right' );
				if ( $show_rub_thb ) {
					$lbl    = ( $rub_thb['rate'] ?? null ) !== null ? crm_merchant_tg_fmt_rate( (float) $rub_thb['rate'], 4 ) : '—';
					$rows[] = crm_merchant_tg_pad( '₽ → ฿', 14 ) . crm_merchant_tg_pad( $lbl, 12, 'right' );
				}
				if ( $show_usdt_thb ) {
					$lbl    = ( $usdt_thb['rate'] ?? null ) !== null ? crm_merchant_tg_fmt_rate( (float) $usdt_thb['rate'], 2 ) : '—';
					$rows[] = crm_merchant_tg_pad( '₮ → ฿', 14 ) . crm_merchant_tg_pad( $lbl, 12, 'right' );
				}
				if ( $show_rub_usdt ) {
					$lbl    = ( $rub_usdt['rate'] ?? null ) !== null ? crm_merchant_tg_fmt_rate( (float) $rub_usdt['rate'], 2 ) : '—';
					$rows[] = crm_merchant_tg_pad( '₽ → ₮', 14 ) . crm_merchant_tg_pad( $lbl, 12, 'right' );
				}

				$table = count( $rows ) > 1
					? "<pre>" . crm_merchant_tg_escape( implode( "\n", $rows ) ) . "</pre>"
					: "<i>Курсы пока не настроены. Обратитесь к администратору.</i>\n";

				$updated_line = '';
				if ( $show_rub_thb && ! empty( $rub_thb['checked_at'] ) ) {
					$updated_line = ! empty( $rub_thb['unchanged'] )
						? "🕒 Проверен ₽ → ฿: <b>" . crm_merchant_tg_escape( (string) $rub_thb['checked_at'] ) . "</b> · без изменений\n"
						: "🕒 Обновлён ₽ → ฿: <b>" . crm_merchant_tg_escape( (string) $rub_thb['updated_at'] ) . "</b>\n";
				} elseif ( $show_rub_thb && ! empty( $rub_thb['updated_at'] ) ) {
					$updated_line = "🕒 Обновлён ₽ → ฿: <b>" . crm_merchant_tg_escape( (string) $rub_thb['updated_at'] ) . "</b>\n";
				} elseif ( $show_usdt_thb && ! empty( $usdt_thb['updated_at'] ) ) {
					$updated_line = "🕒 Обновлён ₮ → ฿: <b>" . crm_merchant_tg_escape( (string) $usdt_thb['updated_at'] ) . "</b>\n";
				} elseif ( $show_rub_usdt && ! empty( $rub_usdt['updated_at'] ) ) {
					$updated_line = "🕒 Обновлён ₽ → ₮: <b>" . crm_merchant_tg_escape( (string) $rub_usdt['updated_at'] ) . "</b>\n";
				}
				if ( $show_rub_thb && ! empty( $rub_thb['error'] ) ) {
					$updated_line .= "⚠️ ₽ → ฿: " . crm_merchant_tg_escape( (string) $rub_thb['error'] ) . "\n";
				}

				$text  = "💹 <b>Курсы</b>\n\n";
				$text .= $table . "\n";
				$text .= "<i>Курс — за 1 единицу получаемой валюты.</i>\n\n";
				$text .= $updated_line;
				$text .= "⚖️ Ваша наценка: <b>" . crm_merchant_tg_escape( $markup_pct_label ) . "</b>";

				$pair_btns = [];
				if ( $show_rub_thb )  { $pair_btns[] = [ 'text' => '₽ → ฿', 'callback_data' => 'm:rates:rub-thb' ]; }
				if ( $show_usdt_thb ) { $pair_btns[] = [ 'text' => '₮ → ฿', 'callback_data' => 'm:rates:usdt-thb' ]; }
				if ( $show_rub_usdt ) { $pair_btns[] = [ 'text' => '₽ → ₮', 'callback_data' => 'm:rates:rub-usdt' ]; }

				$rates_kb_rows = [];
				if ( ! empty( $pair_btns ) ) {
					$rates_kb_rows[] = $pair_btns;
				}
				$rates_kb_rows[] = [
					[ 'text' => '🔄 Обновить', 'callback_data' => 'm:rates' ],
					[ 'text' => '↩ Меню',     'callback_data' => 'm:main' ],
				];

				return [
					'screen'   => 'rates',
					'text'     => $text,
					'keyboard' => [
						'inline_keyboard' => $rates_kb_rows,
					],
				];

			case 'rates_rub_thb':
				$rub_thb = $rates_snapshot['rub_thb'] ?? [];
				$rate    = ( $rub_thb['rate'] ?? null ) !== null ? (float) $rub_thb['rate'] : null;

				$lines   = [];
				$lines[] = crm_merchant_tg_pad( '📊 Курс',    12 ) . ( $rate !== null ? crm_merchant_tg_fmt_rate( $rate, 4 ) : '—' );
				$lines[] = crm_merchant_tg_pad( '⚖️ Наценка', 12 ) . $markup_pct_label;
				$breakdown = "<pre>" . crm_merchant_tg_escape( implode( "\n", $lines ) ) . "</pre>";

				$calc_lines = [];
				if ( $rate !== null && $rate > 0 ) {
					foreach ( [ 100, 1000, 10000 ] as $amount ) {
						$converted    = (float) $amount / $rate;
						$calc_lines[] = crm_merchant_tg_pad( crm_merchant_tg_fmt_money( (float) $amount, 0 ) . ' ₽', 11, 'right' )
							. '  ≈  '
							. crm_merchant_tg_pad( crm_merchant_tg_fmt_money( $converted, 2 ) . ' ฿', 12, 'right' );
					}
				} else {
					$calc_lines[] = 'нет данных';
				}
				$calc = "<pre>" . crm_merchant_tg_escape( implode( "\n", $calc_lines ) ) . "</pre>";

				$updated_line = '';
				if ( ! empty( $rub_thb['checked_at'] ) ) {
					$updated_line = ! empty( $rub_thb['unchanged'] )
						? "🕒 Проверен: <b>" . crm_merchant_tg_escape( (string) $rub_thb['checked_at'] ) . "</b> · курс без изменений\n"
						: "🕒 Обновлён: <b>" . crm_merchant_tg_escape( (string) $rub_thb['updated_at'] ) . "</b>\n";
				} elseif ( ! empty( $rub_thb['updated_at'] ) ) {
					$updated_line = "🕒 Обновлён: <b>" . crm_merchant_tg_escape( (string) $rub_thb['updated_at'] ) . "</b>\n";
				}
				if ( ! empty( $rub_thb['error'] ) ) {
					$updated_line .= "⚠️ Не удалось проверить свежий курс: " . crm_merchant_tg_escape( (string) $rub_thb['error'] ) . "\n";
				}

				$text  = "💹 <b>₽ → ฿</b>\n\n";
				$text .= $breakdown . "\n";
				$text .= "<i>Курс — стоимость 1 ฿ в ₽. Источник: backoffice → Курсы → «Наш Sberbank».</i>\n\n";
				$text .= $updated_line;
				$text .= "🧮 Расчёт по корп. курсу\n";
				$text .= $calc . "\n";
				$text .= "<i>Итоговый курс с учётом наценки рассчитывается при выпуске счёта.</i>";

				return [
					'screen'   => 'rates_rub_thb',
					'text'     => $text,
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '🧾 Выпустить счёт', 'callback_data' => 'm:invoice:rub-thb' ],
							],
							[
								[ 'text' => '🔄 Обновить', 'callback_data' => 'm:rates:rub-thb' ],
								[ 'text' => '↩ К курсам', 'callback_data' => 'm:rates' ],
							],
						],
					],
				];

			case 'rates_usdt_thb':
				if ( empty( $rates_snapshot['usdt_thb']['has_pair'] ) ) {
					return crm_merchant_tg_screen_payload( 'rates', $merchant );
				}
				$usdt_thb = $rates_snapshot['usdt_thb'] ?? [];
				$rate_ut  = ( $usdt_thb['rate'] ?? null ) !== null ? (float) $usdt_thb['rate'] : null;

				$ut_lines   = [];
				$ut_lines[] = crm_merchant_tg_pad( '📊 Курс',    12 ) . ( $rate_ut !== null ? crm_merchant_tg_fmt_rate( $rate_ut, 4 ) : '—' );
				$ut_lines[] = crm_merchant_tg_pad( '⚖️ Наценка', 12 ) . $markup_pct_label;
				$ut_breakdown = "<pre>" . crm_merchant_tg_escape( implode( "\n", $ut_lines ) ) . "</pre>";

				$ut_calc_lines = [];
				if ( $rate_ut !== null && $rate_ut > 0 ) {
					foreach ( [ 100, 500, 1000 ] as $amount ) {
						$converted       = (float) $amount * $rate_ut;
						$ut_calc_lines[] = crm_merchant_tg_pad( crm_merchant_tg_fmt_money( (float) $amount, 0 ) . ' ₮', 10, 'right' )
							. '  ≈  '
							. crm_merchant_tg_pad( crm_merchant_tg_fmt_money( $converted, 2 ) . ' ฿', 12, 'right' );
					}
				} else {
					$ut_calc_lines[] = 'нет данных';
				}
				$ut_calc = "<pre>" . crm_merchant_tg_escape( implode( "\n", $ut_calc_lines ) ) . "</pre>";

				$ut_updated = ! empty( $usdt_thb['updated_at'] )
					? "🕒 Обновлён: <b>" . crm_merchant_tg_escape( (string) $usdt_thb['updated_at'] ) . "</b>\n"
					: '';

				$usdt_thb_src_label = isset( $rates_snapshot['usdt_thb']['market_source'] )
					? crm_merchant_tg_escape( $rates_snapshot['usdt_thb']['market_source'] ) . ' · mid'
					: 'bitkub · mid';

				$text  = "💹 <b>₮ → ฿</b>\n\n";
				$text .= $ut_breakdown . "\n";
				$text .= "<i>Курс — стоимость 1 ₮ в ฿. Источник: " . $usdt_thb_src_label . ".</i>\n\n";
				$text .= $ut_updated;
				$text .= "🧮 Расчёт по корп. курсу\n";
				$text .= $ut_calc . "\n";
				$text .= "<i>Итоговый курс с учётом наценки рассчитывается при выпуске счёта.</i>";

				return [
					'screen'   => 'rates_usdt_thb',
					'text'     => $text,
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '🧾 Выпустить счёт', 'callback_data' => 'm:invoice:usdt-thb' ],
							],
							[
								[ 'text' => '🔄 Обновить', 'callback_data' => 'm:rates:usdt-thb' ],
								[ 'text' => '↩ К курсам', 'callback_data' => 'm:rates' ],
							],
						],
					],
				];

			case 'rates_rub_usdt':
				if ( empty( $rates_snapshot['rub_usdt']['has_pair'] ) ) {
					return crm_merchant_tg_screen_payload( 'rates', $merchant );
				}
				$ru_rate    = $rates_snapshot['rub_usdt']['rate']       ?? null;
				$ru_updated = $rates_snapshot['rub_usdt']['updated_at'] ?? null;
				$ru_error   = trim( (string) ( $rates_snapshot['rub_usdt']['error'] ?? '' ) );

				$text  = "💹 <b>₽ → ₮</b>\n\n";
				if ( $ru_rate !== null ) {
					$text .= '<b>Kanyon</b>: <b>' . crm_merchant_tg_fmt_rate( (float) $ru_rate, 2 ) . "</b>\n";
					$text .= "<i>Прямое направление: ₽ → ₮. Источник: Kanyon, последний check-order.</i>\n";
					if ( $ru_updated ) {
						$text .= 'Обновлено: ' . crm_merchant_tg_escape( $ru_updated ) . "\n";
					}
					$text .= "\n";

					$calc_lines = [];
					foreach ( [ 100, 500, 1000 ] as $rub_amount ) {
						$usdt_amount = $ru_rate > 0 ? round( $rub_amount / (float) $ru_rate, 2 ) : 0;
						$calc_lines[] = crm_merchant_tg_pad( $rub_amount . ' ₽', 10 ) . '→  ' . crm_merchant_tg_pad( $usdt_amount . ' ₮', 12, 'right' );
					}
					$text .= '<pre>' . crm_merchant_tg_escape( implode( "\n", $calc_lines ) ) . "</pre>\n";
					$text .= '<i>⚠️ Курс — последний снятый через Kanyon. «Обновить» — новый запрос (лимит: раз в 30 мин).</i>';
				} else {
					if ( $ru_error !== '' ) {
						$text .= "<i>Курс ещё не снят. Автоматический check-order не вернул значение:</i>\n";
						$text .= '<code>' . crm_merchant_tg_escape( $ru_error ) . "</code>\n";
					} else {
						$text .= "<i>Курс ещё не снят. Нажмите «Обновить» для первого запроса к Kanyon.</i>\n";
					}
					$text .= "\n<i>⚠️ Лимит: не чаще 1 раза в 30 минут.</i>";
				}

				return [
					'screen'   => 'rates_rub_usdt',
					'text'     => $text,
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '🔄 Обновить курс', 'callback_data' => 'm:rates:rub-usdt:check' ],
							],
							[
								[ 'text' => '↩ К курсам', 'callback_data' => 'm:rates' ],
							],
						],
					],
				];

			case 'rates_rub_usdt_check':
				if ( empty( $rates_snapshot['rub_usdt']['has_pair'] ) ) {
					return crm_merchant_tg_screen_payload( 'rates', $merchant );
				}
				rates_kanyon_fetch_and_record( $company_id_for_rates, 'telegram' );
				return crm_merchant_tg_screen_payload( 'rates_rub_usdt', $merchant );

			case 'balances':
				$balance_lines = [];
				$balance_lines[] = crm_merchant_tg_pad( '🎁 Бонусный',    14 ) . crm_merchant_format_amount( $balance['bonus_balance'] );
				$balance_lines[] = crm_merchant_tg_pad( '🤝 Реферальный', 14 ) . crm_merchant_format_amount( $balance['referral_balance'] );
				$balance_lines[] = crm_merchant_tg_pad( 'Σ  Итого',       14 ) . crm_merchant_format_amount( $balance['total_balance'] );
				$balance_block = "<pre>" . crm_merchant_tg_escape( implode( "\n", $balance_lines ) ) . "</pre>";

				return [
					'screen'   => 'balances',
					'text'     => "💼 <b>Балансы</b>\n\n" . $balance_block,
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩ Меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'invoice':
				return [
					'screen'   => 'invoice',
					'text'     => "🧾 <b>Выставить счёт</b>\n\nВыберите направление продажи — что клиент платит и что получает.",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '₽ → ฿', 'callback_data' => 'm:invoice:rub-thb' ],
								[ 'text' => '₮ → ฿', 'callback_data' => 'm:invoice:usdt-thb' ],
								[ 'text' => '₽ → ₮', 'callback_data' => 'm:invoice:rub-usdt' ],
							],
							[
								[ 'text' => '↩ Меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'invoice_rub_thb':
				return [
					'screen'   => 'invoice_rub_thb',
					'text'     => "🧾 <b>Счёт ₽ → ฿</b>\n\n<i>Клиент платит рублями, получает баты. Расчёт подключим следующим этапом.</i>",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩ К способам', 'callback_data' => 'm:invoice' ],
								[ 'text' => '↩ Меню',      'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'invoice_usdt_thb':
				return [
					'screen'   => 'invoice_usdt_thb',
					'text'     => "🧾 <b>Счёт ₮ → ฿</b>\n\n<i>Клиент платит USDT, получает баты. Расчёт подключим следующим этапом.</i>",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩ К способам', 'callback_data' => 'm:invoice' ],
								[ 'text' => '↩ Меню',      'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'invoice_rub_usdt':
				return [
					'screen'   => 'invoice_rub_usdt',
					'text'     => "🧾 <b>Счёт ₽ → ₮</b>\n\n<i>Клиент платит рублями, получает USDT. Расчёт подключим следующим этапом.</i>",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩ К способам', 'callback_data' => 'm:invoice' ],
								[ 'text' => '↩ Меню',      'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'orders':
				$orders_lines = [];
				$orders_lines[] = crm_merchant_tg_pad( '🟡 Активные',    14 ) . crm_merchant_tg_pad( (string) $order_counts['open'],      4, 'right' );
				$orders_lines[] = crm_merchant_tg_pad( '🟢 Оплаченные',  14 ) . crm_merchant_tg_pad( (string) $order_counts['paid'],      4, 'right' );
				$orders_lines[] = crm_merchant_tg_pad( '🔴 Отменённые',  14 ) . crm_merchant_tg_pad( (string) $order_counts['cancelled'], 4, 'right' );
				$orders_block = "<pre>" . crm_merchant_tg_escape( implode( "\n", $orders_lines ) ) . "</pre>";

				return [
					'screen'   => 'orders',
					'text'     => "📂 <b>Мои счета</b>\n\n" . $orders_block,
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '🟡 Активные',   'callback_data' => 'm:orders:open' ],
								[ 'text' => '🟢 Оплаченные', 'callback_data' => 'm:orders:paid' ],
								[ 'text' => '🔴 Отменённые', 'callback_data' => 'm:orders:cancelled' ],
							],
							[
								[ 'text' => '↩ Меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'orders_open':
			case 'orders_paid':
			case 'orders_cancelled':
				$labels = [
					'orders_open'      => '🟡 <b>Активные счета</b>',
					'orders_paid'      => '🟢 <b>Оплаченные счета</b>',
					'orders_cancelled' => '🔴 <b>Отменённые счета</b>',
				];
				return [
					'screen'   => $screen,
					'text'     => $labels[ $screen ] . "\n\n<i>Список подключим следующим этапом.</i>",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩ К счетам', 'callback_data' => 'm:orders' ],
								[ 'text' => '↩ Меню',    'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'profile':
				return [
					'screen'   => 'profile',
					'text'     => "👤 <b>Профиль</b>\n\n<b>{$display_name}</b>\nКомпания: <b>{$company_name}</b>\nОфис: <b>{$office_name}</b>\nChat ID: <code>" . crm_merchant_tg_escape( (string) $merchant->chat_id ) . "</code>\nUsername: <b>" . crm_merchant_tg_escape( ! empty( $merchant->telegram_username ) ? '@' . ltrim( (string) $merchant->telegram_username, '@' ) : '—' ) . "</b>\nСтатус: <b>" . crm_merchant_tg_escape( crm_merchant_statuses()[ (string) $merchant->status ] ?? (string) $merchant->status ) . "</b>\n⚖️ Наценка: <b>{$markup_label}</b> · <b>{$markup_value}</b>",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩ Меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'help':
				return [
					'screen'   => 'help',
					'text'     => "ℹ️ <b>Помощь</b>\n\nЧерез меню вы можете:\n💹 смотреть курсы\n💼 проверять балансы\n🧾 выставлять счета\n📂 отслеживать свои счета\n\nЕсли доступ не работает как ожидается — обратитесь к администратору компании.",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩ Меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'main':
			default:
				return [
					'screen'   => 'main',
					'text'     => "🌴 <b>Malibu Merchant</b>\n\nПривет, {$display_name}!\nВаш кабинет в <b>{$company_name}</b> готов к работе.\n\nНажимайте <b>/start</b> в любой момент, чтобы открыть меню заново.",
					'keyboard' => $main_keyboard,
				];
		}
	}
}

if ( ! function_exists( 'crm_merchant_tg_present_screen' ) ) {
	function crm_merchant_tg_present_screen( $telegram, object $merchant, array $ctx = [], string $screen = 'main', bool $force_new = false, bool $delete_previous = false ): bool {
		$company_id = (int) ( $merchant->company_id ?? 0 );
		$merchant_id = (int) ( $merchant->id ?? 0 );
		$chat_id = trim( (string) ( $ctx['chat_id'] ?? $merchant->chat_id ?? '' ) );
		if ( $company_id <= 0 || $merchant_id <= 0 || $chat_id === '' || ! $telegram ) {
			return false;
		}

		$payload = crm_merchant_tg_screen_payload( $screen, $merchant );
		$session = crm_merchant_tg_session_get( $company_id, $chat_id );
		$stored_message_id = ! empty( $session['last_menu_message_id'] ) ? (int) $session['last_menu_message_id'] : 0;
		$current_message_id = ! empty( $ctx['message_id'] ) ? (int) $ctx['message_id'] : 0;
		$target_message_id = $current_message_id > 0 ? $current_message_id : $stored_message_id;
		$response = [ 'ok' => false ];

		if ( $delete_previous && $stored_message_id > 0 ) {
			crm_merchant_tg_delete_message( $telegram, $chat_id, $stored_message_id );
			$target_message_id = 0;
		}

		if ( ! $force_new && $target_message_id > 0 ) {
			$response = crm_merchant_tg_edit_message( $telegram, $chat_id, $target_message_id, $payload['text'], $payload['keyboard'] );
			if ( crm_merchant_tg_is_not_modified_response( $response ) ) {
				$response['ok'] = true;
			}
		}

		if ( empty( $response['ok'] ) ) {
			$response = crm_merchant_tg_send_message( $telegram, $chat_id, $payload['text'], $payload['keyboard'] );
			$result_message_id = ! empty( $response['result']['message_id'] ) ? (int) $response['result']['message_id'] : 0;
			if ( $result_message_id > 0 ) {
				$target_message_id = $result_message_id;
			}
		}

		if ( ! empty( $response['ok'] ) && $target_message_id > 0 ) {
			crm_merchant_tg_session_upsert(
				$company_id,
				$merchant_id,
				$chat_id,
				[
					'last_menu_message_id' => $target_message_id,
					'last_menu_screen'     => (string) $payload['screen'],
					'active_pipeline_code' => null,
					'pipeline_state_json'  => null,
				]
			);
			return true;
		}

		return false;
	}
}

if ( ! function_exists( 'crm_merchant_tg_map_callback_to_screen' ) ) {
	function crm_merchant_tg_map_callback_to_screen( string $callback_data ): ?string {
		$map = [
			'menu_main'        => 'main',
			'orders_refresh_rate' => 'rates',
			'orders_new'       => 'invoice',
			'orders_open'      => 'orders_open',
			'orders_closed'    => 'orders_paid',
			'orders_canceled'  => 'orders_cancelled',
			'm:main'             => 'main',
			'm:rates'            => 'rates',
			'm:rates:rub-thb'    => 'rates_rub_thb',
			'm:rates:usdt-thb'   => 'rates_usdt_thb',
			'm:rates:rub-usdt'       => 'rates_rub_usdt',
			'm:rates:rub-usdt:check' => 'rates_rub_usdt_check',
			'm:balances'         => 'balances',
			'm:invoice'          => 'invoice',
			'm:invoice:rub-thb'  => 'invoice_rub_thb',
			'm:invoice:usdt-thb' => 'invoice_usdt_thb',
			'm:invoice:rub-usdt' => 'invoice_rub_usdt',
			'm:orders'         => 'orders',
			'm:orders:open'    => 'orders_open',
			'm:orders:paid'    => 'orders_paid',
			'm:orders:cancelled' => 'orders_cancelled',
			'm:profile'        => 'profile',
			'm:help'           => 'help',
		];

		return $map[ $callback_data ] ?? null;
	}
}

if ( ! function_exists( 'crm_merchant_tg_route_command' ) ) {
	function crm_merchant_tg_route_command( string $command, string $text, array $ctx, $telegram, array $data ): bool {
		if ( ! in_array( $command, [ '/start', '/menu', '/help' ], true ) ) {
			return false;
		}

		$company_id = crm_merchant_tg_company_id();
		$chat_id = trim( (string) ( $ctx['chat_id'] ?? '' ) );
		if ( $company_id <= 0 || $chat_id === '' ) {
			return false;
		}

		$access = crm_merchant_tg_access_context( $company_id, $chat_id );
		if ( empty( $access['allowed'] ) || empty( $access['merchant'] ) ) {
			if ( function_exists( 'bot_send_message' ) ) {
				bot_send_message( $telegram, $chat_id, (string) $access['message'] );
			}
			return true;
		}

		$screen = $command === '/help' ? 'help' : 'main';
		crm_merchant_tg_present_screen( $telegram, $access['merchant'], $ctx, $screen, true, true );
		return true;
	}
}

if ( ! function_exists( 'crm_merchant_tg_route_callback' ) ) {
	function crm_merchant_tg_route_callback( string $callback_data, array $ctx, $telegram, array $data ): bool {
		$screen = crm_merchant_tg_map_callback_to_screen( $callback_data );
		if ( $screen === null ) {
			return false;
		}

		$company_id = crm_merchant_tg_company_id();
		$chat_id = trim( (string) ( $ctx['chat_id'] ?? '' ) );
		if ( $company_id <= 0 || $chat_id === '' ) {
			return false;
		}

		$access = crm_merchant_tg_access_context( $company_id, $chat_id );
		if ( empty( $access['allowed'] ) || empty( $access['merchant'] ) ) {
			if ( function_exists( 'tg_safe_answer_callback' ) ) {
				tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? null, 'Нет доступа' );
			}
			if ( function_exists( 'bot_send_message' ) ) {
				bot_send_message( $telegram, $chat_id, (string) $access['message'] );
			}
			return true;
		}

		crm_merchant_tg_present_screen( $telegram, $access['merchant'], $ctx, $screen, false, false );
		if ( function_exists( 'tg_safe_answer_callback' ) ) {
			tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? null, 'Готово' );
		}
		return true;
	}
}

if ( ! function_exists( 'crm_merchant_tg_route_message' ) ) {
	function crm_merchant_tg_route_message( string $text, array $ctx, $telegram, array $data ): bool {
		$text = trim( $text );
		if ( $text === '' ) {
			return false;
		}

		$company_id = crm_merchant_tg_company_id();
		$chat_id = trim( (string) ( $ctx['chat_id'] ?? '' ) );
		if ( $company_id <= 0 || $chat_id === '' ) {
			return false;
		}

		$access = crm_merchant_tg_access_context( $company_id, $chat_id );
		if ( empty( $access['allowed'] ) || empty( $access['merchant'] ) ) {
			if ( function_exists( 'bot_send_message' ) ) {
				bot_send_message( $telegram, $chat_id, (string) $access['message'] );
			}
			return true;
		}

		return crm_merchant_tg_present_screen( $telegram, $access['merchant'], $ctx, 'main', true, true );
	}
}

if ( ! function_exists( 'crm_merchant_tg_notify_activation' ) ) {
	function crm_merchant_tg_notify_activation( object $merchant ): bool {
		$company_id = (int) ( $merchant->company_id ?? 0 );
		$chat_id    = trim( (string) ( $merchant->chat_id ?? '' ) );
		if ( $company_id <= 0 || $chat_id === '' ) {
			return false;
		}

		$telegram_settings = function_exists( 'crm_telegram_collect_settings' ) ? crm_telegram_collect_settings( $company_id ) : [];
		$bot_token = trim( (string) ( $telegram_settings['bot_token'] ?? '' ) );
		if ( $bot_token === '' || ! crm_merchant_tg_require_telegram_class() ) {
			crm_log_entity(
				'merchant.telegram.activation_notify_failed',
				'users',
				'update',
				'Не удалось отправить activation-уведомление мерчанту: отсутствует Telegram runtime или token',
				'merchant',
				(int) $merchant->id,
				[
					'org_id'  => $company_id,
					'context' => [
						'chat_id' => $chat_id,
					],
				]
			);
			return false;
		}

		$telegram = new Telegram( $bot_token );
		crm_merchant_tg_send_message(
			$telegram,
			$chat_id,
			"✅ <b>Профиль мерчанта активирован</b>\n\nТеперь вам доступно рабочее меню бота.\nНажимайте <b>/start</b> в любой момент, чтобы открыть его заново."
		);

		$sent = crm_merchant_tg_present_screen(
			$telegram,
			$merchant,
			[
				'chat_id' => $chat_id,
			],
			'main',
			true,
			true
		);

		crm_log_entity(
			$sent ? 'merchant.telegram.activation_notified' : 'merchant.telegram.activation_notify_failed',
			'users',
			'update',
			$sent ? 'Мерчанту отправлено activation-уведомление и bot-меню' : 'Не удалось отправить merchant bot-меню после активации',
			'merchant',
			(int) $merchant->id,
			[
				'org_id'  => $company_id,
				'context' => [
					'chat_id' => $chat_id,
				],
			]
		);

		return $sent;
	}
}
