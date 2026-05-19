<?php
/**
 * Malibu Exchange — Telegram mini app helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'crm_tg_miniapp_supported_contours' ) ) {
	function crm_tg_miniapp_supported_contours(): array {
		return [ 'merchant', 'operator' ];
	}
}

if ( ! function_exists( 'crm_tg_miniapp_normalize_contour' ) ) {
	function crm_tg_miniapp_normalize_contour( string $contour ): string {
		$contour = sanitize_key( $contour );

		return in_array( $contour, crm_tg_miniapp_supported_contours(), true ) ? $contour : '';
	}
}

if ( ! function_exists( 'crm_tg_miniapp_url_ttl' ) ) {
	function crm_tg_miniapp_url_ttl(): int {
		return 12 * HOUR_IN_SECONDS;
	}
}

if ( ! function_exists( 'crm_tg_miniapp_signature_secret' ) ) {
	function crm_tg_miniapp_signature_secret(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|crm_tg_miniapp_v1' );
	}
}

if ( ! function_exists( 'crm_tg_miniapp_base_args' ) ) {
	function crm_tg_miniapp_base_args( array $payload ): array {
		$contour = crm_tg_miniapp_normalize_contour( (string) ( $payload['contour'] ?? '' ) );
		$args = [
			'miniapp'          => '1',
			'contour'          => $contour,
			'company_id'       => max( 0, (int) ( $payload['company_id'] ?? 0 ) ),
			'chat_id'          => crm_telegram_sanitize_chat_id_value( (string) ( $payload['chat_id'] ?? '' ) ),
			'merchant_id'      => max( 0, (int) ( $payload['merchant_id'] ?? 0 ) ),
			'operator_user_id' => max( 0, (int) ( $payload['operator_user_id'] ?? 0 ) ),
			'exp'              => max( time() + 60, (int) ( $payload['exp'] ?? ( time() + crm_tg_miniapp_url_ttl() ) ) ),
		];

		if ( $args['merchant_id'] <= 0 ) {
			unset( $args['merchant_id'] );
		}

		if ( $args['operator_user_id'] <= 0 ) {
			unset( $args['operator_user_id'] );
		}

		return $args;
	}
}

if ( ! function_exists( 'crm_tg_miniapp_signature' ) ) {
	function crm_tg_miniapp_signature( array $args ): string {
		$allowed = crm_tg_miniapp_base_args( $args );
		unset( $allowed['miniapp'] );
		ksort( $allowed );

		$pairs = [];
		foreach ( $allowed as $key => $value ) {
			$pairs[] = $key . '=' . (string) $value;
		}

		return hash_hmac( 'sha256', implode( '&', $pairs ), crm_tg_miniapp_signature_secret() );
	}
}

if ( ! function_exists( 'crm_tg_miniapp_build_url' ) ) {
	function crm_tg_miniapp_build_url( array $payload, array $extra_args = [] ): string {
		$base_args         = crm_tg_miniapp_base_args( $payload );
		$base_args['sig']  = crm_tg_miniapp_signature( $base_args );
		$query_args        = array_merge( $base_args, $extra_args );

		foreach ( $query_args as $key => $value ) {
			if ( $value === null || $value === '' ) {
				unset( $query_args[ $key ] );
			}
		}

		return add_query_arg( $query_args, home_url( '/merchant-menu/' ) );
	}
}

if ( ! function_exists( 'crm_tg_miniapp_query_url' ) ) {
	function crm_tg_miniapp_query_url( array $validated_access, array $overrides = [], array $remove = [] ): string {
		$query_args = is_array( $validated_access['query_args'] ?? null ) ? $validated_access['query_args'] : [];
		foreach ( $overrides as $key => $value ) {
			$query_args[ $key ] = $value;
		}
		foreach ( $remove as $key ) {
			unset( $query_args[ $key ] );
		}

		foreach ( $query_args as $key => $value ) {
			if ( $value === null || $value === '' ) {
				unset( $query_args[ $key ] );
			}
		}

		return add_query_arg( $query_args, home_url( '/merchant-menu/' ) );
	}
}

if ( ! function_exists( 'crm_operator_tg_find_active_account_by_chat_id' ) ) {
	function crm_operator_tg_find_active_account_by_chat_id( int $company_id, string $chat_id ): ?array {
		global $wpdb;

		$chat_id = crm_telegram_sanitize_chat_id_value( $chat_id );
		if ( $company_id <= 0 || $chat_id === '' ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.*, u.user_login, u.display_name
				 FROM crm_user_telegram_accounts a
				 JOIN {$wpdb->users} u ON u.ID = a.user_id
				 WHERE a.company_id = %d
				   AND a.chat_id = %s
				   AND a.status = 'active'
				 LIMIT 1",
				$company_id,
				$chat_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$user_id = (int) ( $row['user_id'] ?? 0 );
		if ( $user_id <= 0 || crm_is_root( $user_id ) ) {
			return null;
		}

		return $row;
	}
}

if ( ! function_exists( 'crm_tg_miniapp_validate_request_access' ) ) {
	function crm_tg_miniapp_validate_request_access( array $source ): array {
		$contour          = crm_tg_miniapp_normalize_contour( (string) ( $source['contour'] ?? '' ) );
		$company_id       = max( 0, (int) ( $source['company_id'] ?? 0 ) );
		$chat_id          = crm_telegram_sanitize_chat_id_value( (string) ( $source['chat_id'] ?? '' ) );
		$merchant_id      = max( 0, (int) ( $source['merchant_id'] ?? 0 ) );
		$operator_user_id = max( 0, (int) ( $source['operator_user_id'] ?? 0 ) );
		$exp              = (int) ( $source['exp'] ?? 0 );
		$sig              = strtolower( trim( (string) ( $source['sig'] ?? '' ) ) );

		$result = [
			'ok'               => false,
			'error'            => 'Mini app ссылка недействительна.',
			'contour'          => $contour,
			'company_id'       => $company_id,
			'chat_id'          => $chat_id,
			'merchant_id'      => $merchant_id,
			'operator_user_id' => $operator_user_id,
			'merchant'         => null,
			'operator_account' => null,
			'query_args'       => [],
		];

		if ( $contour === '' || $company_id <= 0 || $chat_id === '' || $exp <= 0 || $sig === '' ) {
			return $result;
		}

		$base_args = crm_tg_miniapp_base_args(
			[
				'contour'          => $contour,
				'company_id'       => $company_id,
				'chat_id'          => $chat_id,
				'merchant_id'      => $merchant_id,
				'operator_user_id' => $operator_user_id,
				'exp'              => $exp,
			]
		);
		$expected_sig = crm_tg_miniapp_signature( $base_args );

		if ( ! hash_equals( $expected_sig, $sig ) ) {
			return $result;
		}

		if ( $exp < time() ) {
			$result['error'] = 'Ссылка mini app истекла. Вернитесь в Telegram и откройте экран заново.';
			return $result;
		}

		$result['query_args'] = array_merge( $base_args, [ 'sig' => $sig ] );

		if ( $contour === 'merchant' ) {
			$access = function_exists( 'crm_merchant_tg_access_context' )
				? crm_merchant_tg_access_context( $company_id, $chat_id )
				: [
					'allowed' => false,
					'message' => 'Merchant access helper is unavailable.',
					'merchant'=> null,
				];

			if ( empty( $access['allowed'] ) || empty( $access['merchant'] ) ) {
				$result['error'] = (string) ( $access['message'] ?? 'Доступ мерчанта недоступен.' );
				return $result;
			}

			$merchant = $access['merchant'];
			if ( $merchant_id > 0 && (int) ( $merchant->id ?? 0 ) !== $merchant_id ) {
				$result['error'] = 'Mini app access scope mismatch for merchant.';
				return $result;
			}

			$result['merchant']    = $merchant;
			$result['merchant_id'] = (int) ( $merchant->id ?? 0 );
			$result['ok']          = true;
			$result['error']       = '';
			return $result;
		}

		$account = crm_operator_tg_find_active_account_by_chat_id( $company_id, $chat_id );
		if ( ! is_array( $account ) ) {
			$result['error'] = 'Доступ оператора недоступен. Откройте mini app заново из Telegram.';
			return $result;
		}

		if ( $operator_user_id > 0 && (int) ( $account['user_id'] ?? 0 ) !== $operator_user_id ) {
			$result['error'] = 'Mini app access scope mismatch for operator.';
			return $result;
		}

		$result['operator_account'] = $account;
		$result['operator_user_id'] = (int) ( $account['user_id'] ?? 0 );
		$result['ok']               = true;
		$result['error']            = '';
		return $result;
	}
}

if ( ! function_exists( 'crm_tg_miniapp_url_for_merchant' ) ) {
	function crm_tg_miniapp_url_for_merchant( object $merchant, ?string $chat_id = null, array $extra_args = [] ): string {
		$company_id = (int) ( $merchant->company_id ?? 0 );
		$merchant_id = (int) ( $merchant->id ?? 0 );
		$chat_id = crm_telegram_sanitize_chat_id_value( $chat_id !== null ? $chat_id : (string) ( $merchant->chat_id ?? '' ) );

		if ( $company_id <= 0 || $merchant_id <= 0 || $chat_id === '' ) {
			return '';
		}

		$mode_summary = function_exists( 'crm_merchant_api_get_company_mode_summary' )
			? crm_merchant_api_get_company_mode_summary( $company_id, $merchant )
			: [];
		$provider_mode = (string) ( $mode_summary['provider_mode'] ?? '' );
		$requested_currency = strtoupper( trim( (string) ( $mode_summary['requested_amount_currency'] ?? '' ) ) );

		if ( $provider_mode !== 'paymentAmount' || $requested_currency !== 'RUB' ) {
			return '';
		}

		return crm_tg_miniapp_build_url(
			[
				'contour'     => 'merchant',
				'company_id'  => $company_id,
				'chat_id'     => $chat_id,
				'merchant_id' => $merchant_id,
			],
			$extra_args
		);
	}
}

if ( ! function_exists( 'crm_tg_miniapp_url_for_operator_chat' ) ) {
	function crm_tg_miniapp_url_for_operator_chat( int $company_id, string $chat_id, array $extra_args = [] ): string {
		$chat_id = crm_telegram_sanitize_chat_id_value( $chat_id );
		$account = crm_operator_tg_find_active_account_by_chat_id( $company_id, $chat_id );

		if ( ! is_array( $account ) ) {
			return '';
		}

		return crm_tg_miniapp_build_url(
			[
				'contour'          => 'operator',
				'company_id'       => $company_id,
				'chat_id'          => $chat_id,
				'operator_user_id' => (int) ( $account['user_id'] ?? 0 ),
			],
			$extra_args
		);
	}
}

if ( ! function_exists( 'crm_tg_miniapp_require_telegram_class' ) ) {
	function crm_tg_miniapp_require_telegram_class(): bool {
		if ( class_exists( 'Telegram' ) ) {
			return true;
		}

		if ( function_exists( 'crm_merchant_tg_require_telegram_class' ) && crm_merchant_tg_require_telegram_class() ) {
			return true;
		}

		$path = get_template_directory() . '/callbacks/telegram/Telegram.php';
		if ( is_file( $path ) ) {
			require_once $path;
		}

		return class_exists( 'Telegram' );
	}
}

if ( ! function_exists( 'crm_tg_miniapp_make_telegram_client' ) ) {
	function crm_tg_miniapp_make_telegram_client( int $company_id, string $contour ) {
		$contour = crm_tg_miniapp_normalize_contour( $contour );
		if ( $company_id <= 0 || $contour === '' || ! crm_tg_miniapp_require_telegram_class() ) {
			return null;
		}

		$settings = function_exists( 'crm_telegram_collect_settings' )
			? crm_telegram_collect_settings( $company_id, $contour )
			: [];
		$bot_token = trim( (string) ( $settings['bot_token'] ?? '' ) );

		if ( $bot_token === '' ) {
			return null;
		}

		return new Telegram( $bot_token );
	}
}

if ( ! function_exists( 'crm_tg_miniapp_send_message' ) ) {
	function crm_tg_miniapp_send_message( $telegram, string $chat_id, string $text, ?array $keyboard = null ): array {
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
			$payload['reply_markup'] = wp_json_encode( $keyboard );
		}

		return (array) $telegram->sendMessage( $payload );
	}
}

if ( ! function_exists( 'crm_tg_miniapp_send_photo' ) ) {
	function crm_tg_miniapp_send_photo( $telegram, string $chat_id, string $photo_url, string $caption = '', ?array $keyboard = null ): array {
		if ( ! $telegram || $chat_id === '' || $photo_url === '' ) {
			return [ 'ok' => false ];
		}

		$payload = [
			'chat_id'    => $chat_id,
			'photo'      => $photo_url,
			'parse_mode' => 'HTML',
		];

		if ( $caption !== '' ) {
			$payload['caption'] = $caption;
		}

		if ( $keyboard !== null ) {
			$payload['reply_markup'] = wp_json_encode( $keyboard );
		}

		$response = (array) $telegram->sendPhoto( $payload );
		if ( ! empty( $response['ok'] ) ) {
			return $response;
		}

		return crm_tg_miniapp_send_message( $telegram, $chat_id, $caption, $keyboard );
	}
}

if ( ! function_exists( 'crm_tg_miniapp_attach_order_telegram_meta' ) ) {
	function crm_tg_miniapp_attach_order_telegram_meta( int $order_db_id, int $company_id, string $chat_id, string $bot_context, string $source_channel, array $extra_meta = [] ): void {
		global $wpdb;

		$chat_id = crm_telegram_sanitize_chat_id_value( $chat_id );
		$bot_context = crm_tg_miniapp_normalize_contour( $bot_context );
		$source_channel = sanitize_key( $source_channel );

		if ( $order_db_id <= 0 || $company_id <= 0 || $chat_id === '' || $bot_context === '' || $source_channel === '' ) {
			return;
		}

		$raw_meta = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_json FROM crm_fintech_payment_orders WHERE id = %d AND company_id = %d LIMIT 1',
				$order_db_id,
				$company_id
			)
		);

		$meta = [];
		if ( is_string( $raw_meta ) && trim( $raw_meta ) !== '' && trim( $raw_meta ) !== 'null' ) {
			$decoded = json_decode( $raw_meta, true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}

		$meta['tg_chat_id']        = $chat_id;
		$meta['tg_bot_context']    = $bot_context;
		$meta['tg_company_id']     = $company_id;
		$meta['tg_source_channel'] = $source_channel;
		foreach ( $extra_meta as $key => $value ) {
			if ( ! is_string( $key ) || $key === '' ) {
				continue;
			}
			$meta[ $key ] = $value;
		}

		$wpdb->update(
			'crm_fintech_payment_orders',
			[
				'meta_json' => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			],
			[
				'id'         => $order_db_id,
				'company_id' => $company_id,
			],
			[ '%s' ],
			[ '%d', '%d' ]
		);
	}
}

if ( ! function_exists( 'crm_tg_miniapp_operator_message_keyboard' ) ) {
	function crm_tg_miniapp_operator_message_keyboard( int $order_db_id ): array {
		$rows = [];

		if ( $order_db_id > 0 ) {
			$rows[] = [
				[ 'text' => '✅ Проверить оплату', 'callback_data' => 'kanyon_paid:' . $order_db_id ],
			];
		}

		$rows[] = [
			[ 'text' => '🆕 Новый ордер', 'callback_data' => 'orders_new' ],
			[ 'text' => '↩️ Меню',        'callback_data' => 'menu_main' ],
		];

		return [
			'inline_keyboard' => $rows,
		];
	}
}

if ( ! function_exists( 'crm_tg_miniapp_send_operator_order_message' ) ) {
	function crm_tg_miniapp_send_operator_order_message( array $access, array $result ): bool {
		$company_id = (int) ( $access['company_id'] ?? 0 );
		$chat_id    = crm_telegram_sanitize_chat_id_value( (string) ( $access['chat_id'] ?? '' ) );
		$telegram   = crm_tg_miniapp_make_telegram_client( $company_id, 'operator' );

		if ( $company_id <= 0 || $chat_id === '' || ! $telegram ) {
			return false;
		}

		$order_db_id = (int) ( $result['order_db_id'] ?? 0 );
		if ( $order_db_id > 0 ) {
			crm_tg_miniapp_attach_order_telegram_meta(
				$order_db_id,
				$company_id,
				$chat_id,
				'operator',
				'telegram_operator',
				[
					'payment_purpose' => (string) ( $result['payment_purpose'] ?? '' ),
				]
			);
		}

		$keyboard = crm_tg_miniapp_operator_message_keyboard( $order_db_id );
		$result['payment_purpose'] = (string) ( $result['payment_purpose'] ?? '' );

		if ( ! empty( $result['qr_url'] ) ) {
			$response = crm_tg_miniapp_send_photo( $telegram, $chat_id, (string) $result['qr_url'], _tg_orders_success_message( $result ), $keyboard );
			return ! empty( $response['ok'] );
		}

		$response = crm_tg_miniapp_send_message( $telegram, $chat_id, _tg_orders_success_message( $result ), $keyboard );

		return ! empty( $response['ok'] );
	}
}

if ( ! function_exists( 'crm_tg_miniapp_send_merchant_invoice_message' ) ) {
	function crm_tg_miniapp_send_merchant_invoice_message( array $access, array $result ): bool {
		$company_id = (int) ( $access['company_id'] ?? 0 );
		$chat_id    = crm_telegram_sanitize_chat_id_value( (string) ( $access['chat_id'] ?? '' ) );
		$merchant   = $access['merchant'] ?? null;
		$telegram   = crm_tg_miniapp_make_telegram_client( $company_id, 'merchant' );

		if ( $company_id <= 0 || $chat_id === '' || ! $telegram || ! is_object( $merchant ) ) {
			return false;
		}

		$order_db_id = (int) ( $result['order_db_id'] ?? 0 );
		$keyboard    = crm_merchant_tg_rub_invoice_success_keyboard( $order_db_id );
		$caption     = crm_merchant_tg_rub_invoice_success_text( $result );
		$qr_url      = trim( (string) ( $result['qr_url'] ?? '' ) );
		$response    = [ 'ok' => false ];
		$message_type = 'text';

		if ( $qr_url !== '' ) {
			$response    = crm_merchant_tg_send_photo( $telegram, $chat_id, $qr_url, $caption, $keyboard );
			$message_type = 'photo';
		}

		if ( empty( $response['ok'] ) ) {
			$response    = crm_merchant_tg_send_message( $telegram, $chat_id, $caption, $keyboard );
			$message_type = 'text';
		}

		if ( ! empty( $response['ok'] ) && $order_db_id > 0 ) {
			crm_merchant_tg_store_invoice_message_context(
				$order_db_id,
				$company_id,
				(int) ( $merchant->id ?? 0 ),
				$chat_id,
				$message_type,
				$response
			);
		}

		return ! empty( $response['ok'] );
	}
}

if ( ! function_exists( 'crm_tg_miniapp_operator_preview_context' ) ) {
	function crm_tg_miniapp_operator_preview_context( int $company_id, float $requested_rub = 0.0 ): array {
		$requested_rub = max( 0, round( $requested_rub, 2 ) );
		$context = [
			'success'            => false,
			'error'              => 'Не удалось получить текущий курс ЭП.',
			'current_rate'       => null,
			'checked_at'         => '',
			'payment_amount_rub' => $requested_rub,
			'payable_usdt'       => 0.0,
		];

		if ( $company_id <= 0 || ! function_exists( 'rates_kanyon_get_last' ) ) {
			return $context;
		}

		$last = rates_kanyon_get_last( $company_id );
		if ( ! $last && function_exists( 'rates_kanyon_fetch_and_record' ) ) {
			$fetched = rates_kanyon_fetch_and_record( $company_id, 'telegram_operator' );
			if ( ! empty( $fetched['ok'] ) ) {
				$last = rates_kanyon_get_last( $company_id );
			} elseif ( ! empty( $fetched['error'] ) ) {
				$context['error'] = (string) $fetched['error'];
			}
		}

		if ( ! is_array( $last ) || empty( $last['kanyon_rate'] ) || (float) $last['kanyon_rate'] <= 0 ) {
			return $context;
		}

		$current_rate = round( (float) $last['kanyon_rate'], 4 );
		$payable_usdt = $current_rate > 0 ? round( $requested_rub / $current_rate, 4 ) : 0.0;

		return [
			'success'            => true,
			'error'              => '',
			'current_rate'       => $current_rate,
			'checked_at'         => ! empty( $last['created_at'] ) ? mysql2date( 'd.m.Y H:i', (string) $last['created_at'] ) : current_time( 'd.m.Y H:i' ),
			'payment_amount_rub' => $requested_rub,
			'payable_usdt'       => $payable_usdt,
		];
	}
}

if ( ! function_exists( 'crm_tg_miniapp_load_order_for_access' ) ) {
	function crm_tg_miniapp_load_order_for_access( array $access, int $order_id ) {
		global $wpdb;

		$order_id = (int) $order_id;
		if ( $order_id <= 0 || empty( $access['ok'] ) ) {
			return null;
		}

		$company_id = (int) ( $access['company_id'] ?? 0 );
		$chat_id    = crm_telegram_sanitize_chat_id_value( (string) ( $access['chat_id'] ?? '' ) );
		$contour    = crm_tg_miniapp_normalize_contour( (string) ( $access['contour'] ?? '' ) );

		if ( $company_id <= 0 || $chat_id === '' || $contour === '' ) {
			return null;
		}

		if ( $contour === 'merchant' ) {
			$merchant_id = (int) ( $access['merchant_id'] ?? 0 );
			if ( $merchant_id <= 0 ) {
				return null;
			}

			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT *
					 FROM crm_fintech_payment_orders
					 WHERE id = %d
					   AND company_id = %d
					   AND merchant_id = %d
					   AND created_for_type = 'merchant'
					 LIMIT 1",
					$order_id,
					$company_id,
					$merchant_id
				)
			);
		}

		$order = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				 FROM crm_fintech_payment_orders
				 WHERE id = %d
				   AND company_id = %d
				   AND created_for_type = 'company'
				 LIMIT 1",
				$order_id,
				$company_id
			)
		);

		if ( ! $order ) {
			return null;
		}

		$meta = [];
		if ( ! empty( $order->meta_json ) ) {
			$decoded = json_decode( (string) $order->meta_json, true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}

		if ( (string) ( $meta['tg_chat_id'] ?? '' ) !== $chat_id ) {
			return null;
		}

		$meta_context = sanitize_key( (string) ( $meta['tg_bot_context'] ?? '' ) );
		$source_channel = sanitize_key( (string) ( $meta['tg_source_channel'] ?? '' ) );
		if ( $meta_context !== 'operator' && $source_channel !== 'telegram_operator' ) {
			return null;
		}

		return $order;
	}
}
