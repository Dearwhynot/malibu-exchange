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

if ( ! function_exists( 'crm_merchant_tg_is_merchant_context' ) ) {
	function crm_merchant_tg_is_merchant_context(): bool {
		return function_exists( 'crm_telegram_get_callback_bot_context' )
			&& crm_telegram_get_callback_bot_context() === 'merchant';
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

if ( ! function_exists( 'crm_merchant_tg_safe_json_decode' ) ) {
	function crm_merchant_tg_safe_json_decode( $value ): array {
		if ( ! is_string( $value ) || trim( $value ) === '' || trim( $value ) === 'null' ) {
			return [];
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : [];
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

if ( ! function_exists( 'crm_merchant_tg_close_menu_anchor' ) ) {
	function crm_merchant_tg_close_menu_anchor( $telegram, int $company_id, int $merchant_id, string $chat_id, ?array $session = null ): void {
		$chat_id = trim( $chat_id );
		if ( $company_id <= 0 || $merchant_id <= 0 || $chat_id === '' ) {
			return;
		}

		$session = is_array( $session ) ? $session : crm_merchant_tg_session_get( $company_id, $chat_id );
		$stored_message_id = ! empty( $session['last_menu_message_id'] ) ? (int) $session['last_menu_message_id'] : 0;

		if ( $stored_message_id > 0 ) {
			crm_merchant_tg_delete_message( $telegram, $chat_id, $stored_message_id );
		}

		crm_merchant_tg_session_upsert(
			$company_id,
			$merchant_id,
			$chat_id,
			[
				'last_menu_message_id' => 0,
				'last_menu_screen'     => (string) ( $session['last_menu_screen'] ?? 'main' ),
			]
		);
	}
}

if ( ! function_exists( 'crm_merchant_tg_present_anchor_message' ) ) {
	function crm_merchant_tg_present_anchor_message( $telegram, object $merchant, array $ctx, string $text, ?array $keyboard = null, array $session_fields = [] ): bool {
		$company_id = (int) ( $merchant->company_id ?? 0 );
		$merchant_id = (int) ( $merchant->id ?? 0 );
		$chat_id = trim( (string) ( $ctx['chat_id'] ?? $merchant->chat_id ?? '' ) );
		if ( $company_id <= 0 || $merchant_id <= 0 || $chat_id === '' || ! $telegram ) {
			return false;
		}

		$session            = crm_merchant_tg_session_get( $company_id, $chat_id );
		$stored_message_id  = ! empty( $session['last_menu_message_id'] ) ? (int) $session['last_menu_message_id'] : 0;
		$current_message_id = ! empty( $ctx['message_id'] ) ? (int) $ctx['message_id'] : 0;
		$is_callback_context = ! empty( $ctx['callback_query_id'] );
		$target_message_id  = $is_callback_context && $current_message_id > 0 ? $current_message_id : $stored_message_id;
		$response           = [ 'ok' => false ];

		if ( $target_message_id > 0 ) {
			$response = crm_merchant_tg_edit_message( $telegram, $chat_id, $target_message_id, $text, $keyboard );
			if ( crm_merchant_tg_is_not_modified_response( $response ) ) {
				$response['ok'] = true;
			}
		}

		if ( empty( $response['ok'] ) ) {
			$response = crm_merchant_tg_send_message( $telegram, $chat_id, $text, $keyboard );
			$result_message_id = ! empty( $response['result']['message_id'] ) ? (int) $response['result']['message_id'] : 0;
			if ( $result_message_id > 0 ) {
				$target_message_id = $result_message_id;
			}
		}

		if ( empty( $response['ok'] ) || $target_message_id <= 0 ) {
			return false;
		}

		$stale_candidates = [ $stored_message_id ];
		if ( $is_callback_context && $current_message_id > 0 ) {
			$stale_candidates[] = $current_message_id;
		}

		foreach ( array_unique( array_filter( $stale_candidates ) ) as $stale_message_id ) {
			$stale_message_id = (int) $stale_message_id;
			if ( $stale_message_id > 0 && $stale_message_id !== $target_message_id ) {
				crm_merchant_tg_delete_message( $telegram, $chat_id, $stale_message_id );
			}
		}

		crm_merchant_tg_session_upsert(
			$company_id,
			$merchant_id,
			$chat_id,
			array_merge(
				[
					'last_menu_message_id' => $target_message_id,
					'last_menu_screen'     => (string) ( $session_fields['last_menu_screen'] ?? ( $session['last_menu_screen'] ?? 'main' ) ),
				],
				$session_fields
			)
		);

		return true;
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

if ( ! function_exists( 'crm_merchant_tg_send_photo' ) ) {
	function crm_merchant_tg_send_photo( $telegram, string $chat_id, string $photo_url, string $caption = '', ?array $keyboard = null ): array {
		$chat_id   = trim( $chat_id );
		$photo_url = trim( $photo_url );

		if ( ! $telegram || $chat_id === '' || $photo_url === '' ) {
			return [ 'ok' => false ];
		}

		$payload = [
			'chat_id' => $chat_id,
			'photo'   => $photo_url,
		];

		if ( $caption !== '' ) {
			$payload['caption']    = $caption;
			$payload['parse_mode'] = 'HTML';
		}
		if ( $keyboard !== null ) {
			$payload['reply_markup'] = wp_json_encode( $keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		return (array) $telegram->sendPhoto( $payload );
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

if ( ! function_exists( 'crm_merchant_tg_edit_message_caption' ) ) {
	function crm_merchant_tg_edit_message_caption( $telegram, string $chat_id, int $message_id, string $caption, ?array $keyboard = null ): array {
		if ( ! $telegram || $chat_id === '' || $message_id <= 0 ) {
			return [ 'ok' => false ];
		}

		$payload = [
			'chat_id'    => $chat_id,
			'message_id' => $message_id,
			'caption'    => $caption,
			'parse_mode' => 'HTML',
		];
		if ( $keyboard !== null ) {
			$payload['reply_markup'] = wp_json_encode( $keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		return (array) $telegram->editMessageCaption( $payload );
	}
}

if ( ! function_exists( 'crm_merchant_tg_telegram_response_ok' ) ) {
	function crm_merchant_tg_telegram_response_ok( array $response ): bool {
		if ( ! empty( $response['ok'] ) ) {
			return true;
		}

		$description = strtolower( trim( (string) ( $response['description'] ?? '' ) ) );

		return $description !== '' && strpos( $description, 'message is not modified' ) !== false;
	}
}

if ( ! function_exists( 'crm_merchant_tg_merge_order_meta' ) ) {
	function crm_merchant_tg_merge_order_meta( int $order_id, int $company_id, array $meta_patch ): array {
		global $wpdb;

		$result = [
			'ok'   => false,
			'meta' => [],
		];

		if ( $order_id <= 0 || $company_id <= 0 || empty( $meta_patch ) ) {
			return $result;
		}

		$raw_meta = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_json FROM crm_fintech_payment_orders WHERE id = %d AND company_id = %d LIMIT 1',
				$order_id,
				$company_id
			)
		);

		if ( null === $raw_meta ) {
			return $result;
		}

		$meta    = crm_merchant_tg_safe_json_decode( (string) $raw_meta );
		$updated = array_merge( $meta, $meta_patch );
		$json    = wp_json_encode( $updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $json ) || $json === '' ) {
			return $result;
		}

		$write = $wpdb->update(
			'crm_fintech_payment_orders',
			[ 'meta_json' => $json ],
			[
				'id'         => $order_id,
				'company_id' => $company_id,
			],
			[ '%s' ],
			[ '%d', '%d' ]
		);

		$result['ok']   = false !== $write;
		$result['meta'] = $updated;

		return $result;
	}
}

if ( ! function_exists( 'crm_merchant_tg_get_order_meta' ) ) {
	function crm_merchant_tg_get_order_meta( int $order_id, int $company_id ): array {
		global $wpdb;

		if ( $order_id <= 0 || $company_id <= 0 ) {
			return [];
		}

		$raw_meta = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_json FROM crm_fintech_payment_orders WHERE id = %d AND company_id = %d LIMIT 1',
				$order_id,
				$company_id
			)
		);

		if ( null === $raw_meta ) {
			return [];
		}

		return crm_merchant_tg_safe_json_decode( (string) $raw_meta );
	}
}

if ( ! function_exists( 'crm_merchant_tg_normalize_message_id_list' ) ) {
	function crm_merchant_tg_normalize_message_id_list( $raw_list ): array {
		$list = is_array( $raw_list ) ? $raw_list : [];
		$out  = [];

		foreach ( $list as $message_id ) {
			$message_id = (int) $message_id;
			if ( $message_id > 0 ) {
				$out[] = $message_id;
			}
		}

		return array_values( array_unique( $out ) );
	}
}

if ( ! function_exists( 'crm_merchant_tg_append_message_id_list' ) ) {
	function crm_merchant_tg_append_message_id_list( $raw_list, int $message_id ): array {
		$list = crm_merchant_tg_normalize_message_id_list( $raw_list );
		if ( $message_id > 0 ) {
			$list[] = $message_id;
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $list ) ) ) );
	}
}

if ( ! function_exists( 'crm_merchant_tg_store_invoice_message_context' ) ) {
	function crm_merchant_tg_store_invoice_message_context( int $order_id, int $company_id, int $merchant_id, string $chat_id, string $message_type, array $telegram_response ): array {
		$message_id = (int) ( $telegram_response['result']['message_id'] ?? 0 );
		$message_type = sanitize_key( $message_type );

		$result = [
			'ok'           => false,
			'message_id'   => $message_id,
			'message_type' => in_array( $message_type, [ 'photo', 'text' ], true ) ? $message_type : 'text',
		];

		if ( $order_id <= 0 || $company_id <= 0 || $merchant_id <= 0 || $chat_id === '' || $message_id <= 0 ) {
			return $result;
		}

		$current_meta = crm_merchant_tg_get_order_meta( $order_id, $company_id );

		$meta_write = crm_merchant_tg_merge_order_meta(
			$order_id,
			$company_id,
			[
				'merchant_tg_receipt_chat_id'      => $chat_id,
				'merchant_tg_receipt_message_id'   => $message_id,
				'merchant_tg_receipt_message_type' => $result['message_type'],
				'merchant_tg_receipt_message_ids'  => crm_merchant_tg_append_message_id_list( $current_meta['merchant_tg_receipt_message_ids'] ?? [], $message_id ),
				'merchant_tg_receipt_stored_at'    => current_time( 'mysql', true ),
			]
		);

		$result['ok'] = ! empty( $meta_write['ok'] );

		crm_log(
			$result['ok'] ? 'merchant.telegram.invoice_message_bound' : 'merchant.telegram.invoice_message_bind_failed',
			[
				'category'    => 'payments',
				'level'       => $result['ok'] ? 'info' : 'warning',
				'action'      => 'telegram_message_bind',
				'message'     => $result['ok']
					? 'Merchant invoice message linked to payment order.'
					: 'Failed to link merchant invoice message to payment order.',
				'target_type' => 'payment_order',
				'target_id'   => $order_id,
				'org_id'      => $company_id,
				'is_success'  => $result['ok'],
				'context'     => [
					'merchant_id'   => $merchant_id,
					'chat_id'       => $chat_id,
					'message_id'    => $message_id,
					'message_type'  => $result['message_type'],
				],
			]
		);

		return $result;
	}
}

if ( ! function_exists( 'crm_merchant_tg_payout_message_html' ) ) {
	function crm_merchant_tg_payout_message_html( object $merchant, array $payout, string $receipt_link_html = '' ): string {
		$company_id     = (int) ( $merchant->company_id ?? 0 );
		$payout_id      = (int) ( $payout['payout_id'] ?? 0 );
		$amount         = (float) ( $payout['amount'] ?? 0 );
		$network        = trim( (string) ( $payout['network'] ?? '' ) );
		$wallet_address = trim( (string) ( $payout['wallet_address'] ?? '' ) );
		$tx_hash        = trim( (string) ( $payout['tx_hash'] ?? '' ) );
		$notes          = trim( (string) ( $payout['notes'] ?? '' ) );
		$paid_at        = trim( (string) ( $payout['paid_at'] ?? '' ) );

		$message = crm_tg_receipt_block(
			[
				[
					'label' => 'AMOUNT:',
					'value' => $amount > 0 ? crm_tg_receipt_format_amount( $amount, 'USDT', 8, true ) : '—',
				],
				[
					'label' => 'NETWORK:',
					'value' => $network !== '' ? $network : '—',
				],
				[
					'label' => 'STATUS:',
					'value' => 'Completed',
				],
			],
			[
				[
					'label' => 'TIME:',
					'value' => $paid_at !== '' ? crm_format_dt( $paid_at, $company_id ) : '—',
				],
				[
					'label' => 'ID:',
					'value' => $payout_id > 0 ? '#' . $payout_id : '—',
				],
			],
			[
				'The payout has been recorded',
				'Please review the details below',
			],
			'PAYOUT RECEIPT'
		);

		$details = [];
		if ( $wallet_address !== '' ) {
			$details[] = '<b>WALLET</b>' . "\n" . '<code>' . crm_merchant_tg_escape( $wallet_address ) . '</code>';
		}
		if ( $tx_hash !== '' ) {
			$details[] = '<b>TX HASH</b>' . "\n" . '<code>' . crm_merchant_tg_escape( $tx_hash ) . '</code>';
		}
		if ( $notes !== '' ) {
			$details[] = '<b>COMMENT</b>' . "\n" . nl2br( crm_merchant_tg_escape( $notes ) );
		}
		if ( $receipt_link_html !== '' ) {
			$details[] = '<b>RECEIPT IMAGE</b>' . "\n" . $receipt_link_html;
		}

		if ( ! empty( $details ) ) {
			$message .= "\n\n" . implode( "\n\n", $details );
		}

		$message .= "\n\n<i>"
			. crm_merchant_tg_escape( 'Пожалуйста, как только средства будут зачислены на ваш кошелёк, подтвердите это кнопкой ниже. До подтверждения следующий платёж может не быть выполнен в автоматическом режиме.' )
			. '</i>';

		return $message;
	}
}

if ( ! function_exists( 'crm_merchant_tg_payout_confirmation_callback_data' ) ) {
	function crm_merchant_tg_payout_confirmation_callback_data( int $payout_id ): string {
		return 'm:payout:confirm:' . max( 0, $payout_id );
	}
}

if ( ! function_exists( 'crm_merchant_tg_payout_confirmation_keyboard' ) ) {
	function crm_merchant_tg_payout_confirmation_keyboard( int $payout_id ): ?array {
		if ( $payout_id <= 0 ) {
			return null;
		}

		return [
			'inline_keyboard' => [
				[
					[
						'text'          => '✅ Подтвердить платёж',
						'callback_data' => crm_merchant_tg_payout_confirmation_callback_data( $payout_id ),
					],
				],
			],
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_payout_belongs_to_merchant' ) ) {
	function crm_merchant_tg_payout_belongs_to_merchant( int $company_id, int $merchant_id, int $payout_id ): bool {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 || $payout_id <= 0 ) {
			return false;
		}

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				 FROM crm_merchant_payouts
				 WHERE id = %d
				   AND company_id = %d
				   AND merchant_id = %d
				 LIMIT 1",
				$payout_id,
				$company_id,
				$merchant_id
			)
		);

		return $exists > 0;
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

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_pipeline_code' ) ) {
	function crm_merchant_tg_rub_invoice_pipeline_code(): string {
		return 'merchant_invoice_rub_usdt_amount';
	}
}

if ( ! function_exists( 'crm_merchant_tg_invoice_direction_definitions' ) ) {
	function crm_merchant_tg_invoice_direction_definitions(): array {
		return [
			'RUB_THB'  => [
				'code'        => 'RUB_THB',
				'button_text' => '₽ → ฿',
				'screen'      => 'invoice_rub_thb',
			],
			'USDT_THB' => [
				'code'        => 'USDT_THB',
				'button_text' => '₮ → ฿',
				'screen'      => 'invoice_usdt_thb',
			],
			'RUB_USDT' => [
				'code'        => 'RUB_USDT',
				'button_text' => '₽ → ₮',
				'screen'      => 'invoice_rub_usdt',
				'entry_mode'  => 'rub_usdt_pipeline',
			],
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_normalize_invoice_direction_code' ) ) {
	function crm_merchant_tg_normalize_invoice_direction_code( string $code ): string {
		if ( function_exists( 'crm_merchant_normalize_invoice_direction_code' ) ) {
			return crm_merchant_normalize_invoice_direction_code( $code );
		}

		$code = trim( $code );

		return $code !== '' ? strtoupper( $code ) : '';
	}
}

if ( ! function_exists( 'crm_merchant_tg_available_invoice_directions' ) ) {
	function crm_merchant_tg_available_invoice_directions( object $merchant ): array {
		$company_id = (int) ( $merchant->company_id ?? 0 );
		if ( $company_id <= 0 ) {
			return [];
		}

		$definitions   = crm_merchant_tg_invoice_direction_definitions();
		$enabled_codes = function_exists( 'crm_merchant_resolve_invoice_directions_from_row' )
			? crm_merchant_resolve_invoice_directions_from_row( $merchant, true )
			: [];
		$available = [];

		foreach ( $enabled_codes as $raw_code ) {
			$code = crm_merchant_tg_normalize_invoice_direction_code( (string) $raw_code );
			if ( $code !== '' && isset( $definitions[ $code ] ) ) {
				$available[ $code ] = $definitions[ $code ];
			}
		}

		return array_values( $available );
	}
}

if ( ! function_exists( 'crm_merchant_tg_invoice_direction_is_available' ) ) {
	function crm_merchant_tg_invoice_direction_is_available( object $merchant, string $direction_code ): bool {
		$direction_code = crm_merchant_tg_normalize_invoice_direction_code( $direction_code );
		if ( $direction_code === '' ) {
			return false;
		}

		foreach ( crm_merchant_tg_available_invoice_directions( $merchant ) as $direction ) {
			if ( (string) ( $direction['code'] ?? '' ) === $direction_code ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'crm_merchant_tg_placeholder_text' ) ) {
	function crm_merchant_tg_placeholder_text( string $title_html, string $status_text, string $roadmap_text, string $note_text = '' ): string {
		$text  = $title_html . "\n\n";
		$text .= $status_text . "\n\n";
		$text .= "🗓️ " . $roadmap_text;

		if ( $note_text !== '' ) {
			$text .= "\n\n<i>" . $note_text . '</i>';
		}

		return $text;
	}
}

if ( ! function_exists( 'crm_merchant_tg_open_invoice_entrypoint' ) ) {
	function crm_merchant_tg_open_invoice_entrypoint( $telegram, object $merchant, array $ctx = [], bool $force_new = false, bool $delete_previous = false ): bool {
		$available_directions = crm_merchant_tg_available_invoice_directions( $merchant );

		if ( count( $available_directions ) === 1 ) {
			$single_direction = $available_directions[0];
			if ( (string) ( $single_direction['entry_mode'] ?? '' ) === 'rub_usdt_pipeline' ) {
				return crm_merchant_tg_begin_rub_invoice_pipeline( $telegram, $merchant, $ctx );
			}

			return crm_merchant_tg_present_screen(
				$telegram,
				$merchant,
				$ctx,
				(string) ( $single_direction['screen'] ?? 'invoice' ),
				$force_new,
				$delete_previous
			);
		}

		return crm_merchant_tg_present_screen( $telegram, $merchant, $ctx, 'invoice', $force_new, $delete_previous );
	}
}

if ( ! function_exists( 'crm_merchant_tg_single_rate_screen' ) ) {
	function crm_merchant_tg_single_rate_screen( object $merchant ): ?string {
		$available_directions = crm_merchant_tg_available_invoice_directions( $merchant );
		if ( count( $available_directions ) !== 1 ) {
			return null;
		}

		$direction_code = (string) ( $available_directions[0]['code'] ?? '' );
		$map = [
			'RUB_THB'  => 'rates_rub_thb',
			'USDT_THB' => 'rates_usdt_thb',
			'RUB_USDT' => 'rates_rub_usdt',
		];

		return $map[ $direction_code ] ?? null;
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_preview_context' ) ) {
	function crm_merchant_tg_rub_invoice_preview_context( object $merchant, bool $refresh_market = false ): array {
		$precheck = crm_merchant_validate_rub_invoice_prerequisites( $merchant );
		$context  = [
			'success'                   => ! empty( $precheck['success'] ),
			'error'                     => (string) ( $precheck['error'] ?? '' ),
			'current_rate'              => null,
			'rate_line'                 => '',
			'checked_at'                => '',
			'merchant_markup_percent'   => (float) ( $precheck['merchant_markup_percent'] ?? 0 ),
			'rub_invoice_markup_mode'   => (string) ( $precheck['rub_invoice_markup_mode'] ?? 'none' ),
			'sample_input_rub'          => 30000.0,
			'sample_payment_amount_rub' => 30000.0,
			'sample_markup_added_rub'   => 0.0,
		];

		if ( empty( $precheck['success'] ) ) {
			return $context;
		}

		$market = $refresh_market ? rates_get_rapira() : rates_get_rapira_cached();
		if ( $refresh_market && ! empty( $market['ok'] ) ) {
			set_transient( 'me_rapira_rates', $market, RATES_MARKET_CACHE_TTL );
		}
		$market_ask = ( ! empty( $market['ok'] ) && ! empty( $market['ask'] ) && (float) $market['ask'] > 0 )
			? round( (float) $market['ask'], 8 )
			: null;

		if ( $market_ask === null ) {
			return $context;
		}

		$economics = function_exists( 'crm_merchant_calculate_rub_invoice_economics' )
			? crm_merchant_calculate_rub_invoice_economics(
				$market_ask,
				(float) ( $precheck['acquirer_markup_percent'] ?? 0 ),
				(float) ( $precheck['merchant_markup_percent'] ?? 0 ),
				(string) ( $precheck['merchant_markup_basis'] ?? 'acquirer_cost' ),
				30000.0,
				(string) ( $precheck['rub_invoice_markup_mode'] ?? 'none' )
			)
			: [
				'merchant_rate_commercial' => round( $market_ask, 4 ),
			];
		$current_rate = isset( $economics['merchant_rate_commercial'] )
			? (float) $economics['merchant_rate_commercial']
			: null;

		if ( $current_rate === null || $current_rate <= 0 ) {
			return $context;
		}

		$context['current_rate'] = $current_rate;
		$context['checked_at']   = current_time( 'd.m.Y H:i' );
		$context['rate_line']    = "Текущий курс для расчёта:\n<b>"
			. crm_merchant_tg_fmt_rate( $current_rate, 4 )
			. "</b> RUB за 1 USDT";
		$context['rub_invoice_markup_mode'] = (string) ( $economics['rub_invoice_markup_mode'] ?? $context['rub_invoice_markup_mode'] );
		$context['sample_input_rub']        = (float) ( $economics['requested_rub_input'] ?? 30000.0 );
		$context['sample_payment_amount_rub'] = (float) ( $economics['payment_amount_rub'] ?? $context['sample_input_rub'] );
		$context['sample_markup_added_rub'] = (float) ( $economics['merchant_markup_added_rub'] ?? 0.0 );

		return $context;
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_usdt_rate_text' ) ) {
	function crm_merchant_tg_rub_usdt_rate_text( object $merchant, bool $refresh_market = false ): string {
		$preview = crm_merchant_tg_rub_invoice_preview_context( $merchant, $refresh_market );
		if ( empty( $preview['success'] ) ) {
			return "💱 <b>Rate</b>\n\n" . crm_merchant_tg_escape( (string) $preview['error'] );
		}

		$rate_value = $preview['current_rate'] !== null
			? crm_tg_receipt_format_number( (float) $preview['current_rate'], 4, false ) . ' RUB per 1 USDT'
			: 'unavailable';
		$checked_at = (string) ( $preview['checked_at'] ?? '' );

		return crm_tg_receipt_block(
			[
				[
					'label' => 'PAIR:',
					'value' => 'RUB -> USDT',
				],
				[
					'label' => 'RATE:',
					'value' => $rate_value,
				],
			],
			$checked_at !== ''
				? [
					[
						'label' => 'TIME:',
						'value' => $checked_at,
					],
				]
				: [],
			[],
			'EXCHANGE RATE'
		);
	}
}

if ( ! function_exists( 'crm_merchant_tg_inline_menu_keyboard' ) ) {
	function crm_merchant_tg_inline_menu_keyboard( string $label = '📋 Menu' ): array {
		return [
			'inline_keyboard' => [
				[
					[
						'text'          => $label,
						'callback_data' => 'm:main',
					],
				],
			],
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_invoice_retry_keyboard' ) ) {
	function crm_merchant_tg_invoice_retry_keyboard(): array {
		return [
			'inline_keyboard' => [
				[
					[ 'text' => '🧾 Выставить счёт', 'callback_data' => 'm:invoice' ],
				],
				[
					[ 'text' => '📋 Menu', 'callback_data' => 'm:main' ],
				],
			],
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_decode_pipeline_state' ) ) {
	function crm_merchant_tg_decode_pipeline_state( $raw_state ): array {
		if ( is_array( $raw_state ) ) {
			return $raw_state;
		}

		$decoded = json_decode( (string) $raw_state, true );

		return is_array( $decoded ) ? $decoded : [];
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_pipeline_state' ) ) {
	function crm_merchant_tg_rub_invoice_pipeline_state( $raw_state = [] ): array {
		$state = crm_merchant_tg_decode_pipeline_state( $raw_state );

		$awaiting = trim( (string) ( $state['awaiting'] ?? 'amount' ) );
		if ( ! in_array( $awaiting, [ 'amount', 'purpose' ], true ) ) {
			$awaiting = 'amount';
		}

		$payment_purpose = function_exists( 'crm_fintech_normalize_payment_purpose' )
			? crm_fintech_normalize_payment_purpose( $state['payment_purpose'] ?? '' )
			: sanitize_text_field( (string) ( $state['payment_purpose'] ?? '' ) );

		return [
			'started_at_gmt'   => trim( (string) ( $state['started_at_gmt'] ?? '' ) ) ?: gmdate( 'c' ),
			'screen'           => 'invoice_rub_usdt',
			'awaiting'         => $awaiting,
			'payment_purpose'  => $payment_purpose,
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_state_json' ) ) {
	function crm_merchant_tg_rub_invoice_state_json( array $state ): string {
		return (string) wp_json_encode(
			crm_merchant_tg_rub_invoice_pipeline_state( $state ),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_default_payment_purpose' ) ) {
	function crm_merchant_tg_rub_invoice_default_payment_purpose( object $merchant ): string {
		$company_id = (int) ( $merchant->company_id ?? 0 );

		if ( $company_id <= 0 || ! function_exists( 'crm_fintech_get_pay2day_default_payment_purpose' ) ) {
			return '';
		}

		return crm_fintech_get_pay2day_default_payment_purpose( $company_id );
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_effective_payment_purpose' ) ) {
	function crm_merchant_tg_rub_invoice_effective_payment_purpose( object $merchant, array $state = [] ): string {
		$state           = crm_merchant_tg_rub_invoice_pipeline_state( $state );
		$custom_purpose  = (string) ( $state['payment_purpose'] ?? '' );
		$default_purpose = crm_merchant_tg_rub_invoice_default_payment_purpose( $merchant );

		return $custom_purpose !== '' ? $custom_purpose : $default_purpose;
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_prompt_keyboard' ) ) {
	function crm_merchant_tg_rub_invoice_prompt_keyboard(): array {
		return [
			'inline_keyboard' => [
				[
					[ 'text' => '✏️ Заменить на своё', 'callback_data' => 'm:invoice:rub-usdt:purpose' ],
				],
				[
					[ 'text' => '📋 Menu', 'callback_data' => 'm:main' ],
				],
			],
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_purpose_keyboard' ) ) {
	function crm_merchant_tg_rub_invoice_purpose_keyboard(): array {
		return [
			'inline_keyboard' => [
				[
					[ 'text' => '↩ К сумме', 'callback_data' => 'm:invoice:rub-usdt:amount' ],
				],
				[
					[ 'text' => '📋 Menu', 'callback_data' => 'm:main' ],
				],
			],
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_prompt_text' ) ) {
	function crm_merchant_tg_rub_invoice_prompt_text( object $merchant, string $warning_text = '', array $state = [] ): string {
		$preview = crm_merchant_tg_rub_invoice_preview_context( $merchant );
		if ( empty( $preview['success'] ) ) {
			return "🧾 <b>Счёт ₽ → ₮</b>\n\n" . crm_merchant_tg_escape( (string) $preview['error'] );
		}

		$state = crm_merchant_tg_rub_invoice_pipeline_state( $state );
		$rate_block = $preview['current_rate'] !== null
			? '<b>' . crm_merchant_tg_fmt_rate( (float) $preview['current_rate'], 4 ) . "</b> RUB за 1 USDT\n"
			: "—\n";
		$amount_adjustment_block = '';
		if ( (string) ( $preview['rub_invoice_markup_mode'] ?? 'none' ) === 'add_on_top' && (float) ( $preview['merchant_markup_percent'] ?? 0 ) > 0 ) {
			$sample_input  = (float) ( $preview['sample_input_rub'] ?? 30000 );
			$sample_total  = (float) ( $preview['sample_payment_amount_rub'] ?? $sample_input );
			$amount_adjustment_block = "➕ <b>AMOUNT ADJUSTMENT:</b>\n\n"
				. "К введённой сумме будет добавлено <b>"
				. crm_merchant_tg_fmt_rate( (float) $preview['merchant_markup_percent'], 2 )
				. "%</b>.\n"
				. "Например, если вы отправите <code>"
				. crm_merchant_tg_escape( crm_merchant_tg_fmt_money( $sample_input, 2 ) )
				. "</code>, клиент оплатит <code>"
				. crm_merchant_tg_escape( crm_merchant_tg_fmt_money( $sample_total, 2 ) )
				. "</code> RUB.\n"
				. "━━━━━━━━━━━━━━━━━━━\n";
		}
		$payment_purpose = crm_merchant_tg_rub_invoice_effective_payment_purpose( $merchant, $state );
		$purpose_hint    = $payment_purpose !== ''
			? "📝 Назначение платежа:\n<code>" . crm_merchant_tg_escape( $payment_purpose ) . "</code>\n\n"
			. "Если хотите заменить назначение,\nнажмите кнопку ниже\n"
			: "📝 Назначение платежа можно задать отдельно.\n"
			. "Если хотите указать своё значение,\nнажмите кнопку ниже\n";

		$text = '';
		if ( $warning_text !== '' ) {
			$text .= "⚠️ " . crm_merchant_tg_escape( $warning_text ) . "\n\n";
		}

		$text .= "🧾 <b>PRELIMINARY CALCULATION</b>\n"
			. "━━━━━━━━━━━━━━━━━━━\n"
			. "💳 Клиент оплачивает сумму в RUB,\n"
			. "💵 выплата рассчитывается в USDT\n"
			. "━━━━━━━━━━━━━━━━━━━\n"
			. "📈 <b>CURRENT RATE:</b>\n\n"
			. $rate_block
			. "━━━━━━━━━━━━━━━━━━━\n"
			. $amount_adjustment_block
			. $purpose_hint
			. "━━━━━━━━━━━━━━━━━━━\n"
			. "✍️ Отправьте сумму счёта\n"
			. "одним сообщением\n\n"
			. "Например:\n"
			. "<code>30000</code>\n"
			. "━━━━━━━━━━━━━━━━━━━\n"
			. "⏱️ Итоговый курс фиксируется\n"
			. "в момент выпуска счёта\n"
			. "по текущему курсу биржи";

		return $text;
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_purpose_prompt_text' ) ) {
	function crm_merchant_tg_rub_invoice_purpose_prompt_text( object $merchant, array $state = [], string $warning_text = '' ): string {
		$state           = crm_merchant_tg_rub_invoice_pipeline_state( $state );
		$current_purpose = crm_merchant_tg_rub_invoice_effective_payment_purpose( $merchant, $state );

		$text = '';
		if ( $warning_text !== '' ) {
			$text .= "⚠️ " . crm_merchant_tg_escape( $warning_text ) . "\n\n";
		}

		$text .= "📝 <b>Назначение платежа</b>\n"
			. "━━━━━━━━━━━━━━━━━━━\n"
			. "Клиент увидит эту строку\n"
			. "как назначение платежа\n"
			. "при переходе к оплате\n"
			. "━━━━━━━━━━━━━━━━━━━\n";

		if ( $current_purpose !== '' ) {
			$text .= "Текущее значение:\n"
				. "<code>" . crm_merchant_tg_escape( $current_purpose ) . "</code>\n"
				. "━━━━━━━━━━━━━━━━━━━\n";
		}

		$text .= "✍️ Введите новое название\n"
			. "одним сообщением\n\n"
			. "Например:\n"
			. "<code>Одежда</code>";

		return $text;
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_success_keyboard' ) ) {
	function crm_merchant_tg_rub_invoice_success_keyboard( int $order_db_id ): array {
		$first_row = [];
		if ( $order_db_id > 0 ) {
			$first_row[] = [ 'text' => '✅ Проверить оплату', 'callback_data' => 'kanyon_paid:' . $order_db_id ];
		}

		$keyboard = [
			'inline_keyboard' => [],
		];

		if ( ! empty( $first_row ) ) {
			$keyboard['inline_keyboard'][] = $first_row;
		}

		$keyboard['inline_keyboard'][] = [
			[ 'text' => '📂 Мои счета', 'callback_data' => 'm:orders' ],
			[ 'text' => '↩ Меню',      'callback_data' => 'm:main' ],
		];

		return $keyboard;
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_success_text' ) ) {
	function crm_merchant_tg_rub_invoice_success_text( array $result ): string {
		$warning             = trim( (string) ( $result['warning'] ?? '' ) );
		$link                = trim( (string) ( $result['payment_link'] ?? '' ) );
		$payment_amount_rub  = (float) ( $result['payment_amount_rub'] ?? 0 );
		$requested_rub_input = (float) ( $result['requested_rub'] ?? 0 );
		$markup_added_rub    = (float) ( $result['merchant_markup_added_rub'] ?? 0 );
		$rub_invoice_markup_mode = (string) ( $result['rub_invoice_markup_mode'] ?? 'none' );
		$merchant_payable    = (float) ( $result['merchant_payable_usdt'] ?? 0 );
		$current_rate        = (float) ( $result['merchant_rate'] ?? 0 );
		$order_db_id         = (int) ( $result['order_db_id'] ?? 0 );
		$merchant_order_id   = trim( (string) ( $result['merchant_order_id'] ?? '' ) );
		$receipt_id          = $order_db_id > 0 ? '#' . $order_db_id : $merchant_order_id;

		$text = crm_tg_receipt_block(
			[
				[
					'label' => 'FROM:',
					'value' => crm_tg_receipt_format_amount( $payment_amount_rub, 'RUB', 2, true ),
				],
				[
					'label' => 'RATE:',
					'value' => crm_tg_receipt_format_number( $current_rate, 4, false ),
				],
				[
					'label' => 'TO:',
					'value' => crm_tg_receipt_format_amount( $merchant_payable, 'USDT', 4, true ),
				],
			],
			[
				[
					'label' => 'TIME:',
					'value' => current_time( 'd.m.Y H:i' ),
				],
				[
					'label' => 'ID:',
					'value' => $receipt_id !== '' ? $receipt_id : '—',
				],
				[
					'label' => 'STATUS:',
					'value' => 'Calculated',
				],
				[
					'label' => 'FEE:',
					'value' => 'included',
				],
			],
			[
				'Thank you for choosing us',
				'Always available for your operations',
			]
		);

		if ( $link !== '' ) {
			$text .= "\n\nPayment link:\n<code>" . crm_merchant_tg_escape( $link ) . '</code>';
		}

		$payment_purpose = trim( (string) ( $result['payment_purpose'] ?? '' ) );
		if ( $payment_purpose !== '' ) {
			$text .= "\n\nPayment purpose:\n<code>" . crm_merchant_tg_escape( $payment_purpose ) . '</code>';
		}

		if ( $rub_invoice_markup_mode === 'add_on_top' && $markup_added_rub > 0.000001 && $requested_rub_input > 0 ) {
			$text .= "\n\nEntered amount:\n<code>" . crm_merchant_tg_escape( crm_merchant_tg_fmt_money( $requested_rub_input, 2 ) ) . " RUB</code>";
			$text .= "\nMarkup on top:\n<code>+" . crm_merchant_tg_escape( crm_merchant_tg_fmt_money( $markup_added_rub, 2 ) ) . " RUB</code>";
		}

		if ( $warning !== '' ) {
			$text .= "\n\nNote:\n" . crm_merchant_tg_escape( $warning );
		}

		return $text;
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_paid_keyboard' ) ) {
	function crm_merchant_tg_rub_invoice_paid_keyboard(): array {
		return [
			'inline_keyboard' => [
				[
					[ 'text' => '🟢 Оплачен', 'callback_data' => 'm:orders:paid' ],
				],
				[
					[ 'text' => '📂 Мои счета', 'callback_data' => 'm:orders' ],
					[ 'text' => '↩ Меню',      'callback_data' => 'm:main' ],
				],
			],
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_terminal_keyboard' ) ) {
	function crm_merchant_tg_rub_invoice_terminal_keyboard(): array {
		return [
			'inline_keyboard' => [
				[
					[ 'text' => '📂 Мои счета', 'callback_data' => 'm:orders' ],
					[ 'text' => '↩ Меню',      'callback_data' => 'm:main' ],
				],
			],
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_terminal_descriptor' ) ) {
	function crm_merchant_tg_rub_invoice_terminal_descriptor( string $status_code ): array {
		$status_code = sanitize_key( $status_code );

		$map = [
			'declined'  => [
				'title'         => '❌ <b>Платёж отклонён</b>',
				'status_label'  => 'Declined',
				'info_text'     => 'Провайдер отклонил платёж. Ссылка на оплату больше недействительна.',
				'timestamp_key' => 'declined_at',
				'timestamp_label' => 'Отклонён',
			],
			'cancelled' => [
				'title'         => '🚫 <b>Счёт отменён</b>',
				'status_label'  => 'Cancelled',
				'info_text'     => 'Счёт отменён. Ссылка на оплату больше недействительна.',
				'timestamp_key' => 'cancelled_at',
				'timestamp_label' => 'Отменён',
			],
			'expired'   => [
				'title'         => '⏰ <b>Срок оплаты истёк</b>',
				'status_label'  => 'Expired',
				'info_text'     => 'Срок оплаты истёк. Счёт больше недоступен для оплаты.',
				'timestamp_key' => 'expired_at',
				'timestamp_label' => 'Истёк',
			],
			'error'     => [
				'title'         => '⚠️ <b>Счёт недоступен</b>',
				'status_label'  => 'Error',
				'info_text'     => 'Счёт закрыт из-за ошибки. Ссылка на оплату больше недоступна.',
				'timestamp_key' => 'updated_at',
				'timestamp_label' => 'Закрыт',
			],
		];

		return $map[ $status_code ] ?? [
			'title'           => '⚠️ <b>Счёт закрыт</b>',
			'status_label'    => strtoupper( $status_code !== '' ? $status_code : 'closed' ),
			'info_text'       => 'Счёт больше недоступен для оплаты.',
			'timestamp_key'   => 'updated_at',
			'timestamp_label' => 'Закрыт',
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_paid_text_from_order' ) ) {
	function crm_merchant_tg_rub_invoice_paid_text_from_order( object $order ): string {
		$order_meta        = crm_merchant_tg_safe_json_decode( (string) ( $order->meta_json ?? '' ) );
		$merchant_meta     = crm_merchant_tg_safe_json_decode( (string) ( $order->merchant_meta_json ?? '' ) );
		$payment_link      = trim( (string) ( $order->payment_link ?? '' ) );
		$payment_amount_rub = $order->payment_amount_value !== null
			? (float) $order->payment_amount_value
			: (float) ( $merchant_meta['payment_amount_rub'] ?? 0 );
		$requested_rub_input = $order->merchant_requested_rub_value !== null
			? (float) $order->merchant_requested_rub_value
			: (float) ( $merchant_meta['merchant_requested_rub_input'] ?? 0 );
		$markup_added_rub = (float) ( $merchant_meta['merchant_markup_added_rub'] ?? $order_meta['merchant_markup_added_rub'] ?? 0 );
		$rub_invoice_markup_mode = (string) ( $merchant_meta['rub_invoice_markup_mode'] ?? $order_meta['rub_invoice_markup_mode'] ?? 'none' );
		$merchant_payable = function_exists( 'crm_merchant_order_payable_amount' )
			? crm_merchant_order_payable_amount( $order )
			: ( isset( $order->merchant_payable_value ) ? (float) $order->merchant_payable_value : 0.0 );
		$current_rate      = (float) ( $merchant_meta['merchant_rate'] ?? 0 );
		$merchant_order_id = trim( (string) ( $order->merchant_order_id ?? '' ) );
		$receipt_id        = ! empty( $order->id ) ? '#' . (int) $order->id : $merchant_order_id;
		$payment_purpose   = trim( (string) ( $merchant_meta['payment_purpose'] ?? $order_meta['payment_purpose'] ?? '' ) );
		$paid_at_label     = ! empty( $order->paid_at )
			? mysql2date( 'd.m.Y H:i', (string) $order->paid_at )
			: current_time( 'd.m.Y H:i' );

		$text = crm_tg_receipt_block(
			[
				[
					'label' => 'FROM:',
					'value' => crm_tg_receipt_format_amount( $payment_amount_rub, 'RUB', 2, true ),
				],
				[
					'label' => 'RATE:',
					'value' => crm_tg_receipt_format_number( $current_rate, 4, false ),
				],
				[
					'label' => 'TO:',
					'value' => crm_tg_receipt_format_amount( $merchant_payable, 'USDT', 4, true ),
				],
			],
			[
				[
					'label' => 'TIME:',
					'value' => $paid_at_label,
				],
				[
					'label' => 'ID:',
					'value' => $receipt_id !== '' ? $receipt_id : '—',
				],
				[
					'label' => 'STATUS:',
					'value' => 'Paid',
				],
				[
					'label' => 'FEE:',
					'value' => 'included',
				],
			],
			[
				'Thank you for choosing us',
				'Always available for your operations',
			]
		);

		if ( $payment_link !== '' ) {
			$text .= "\n\nPayment link:\n<code>" . crm_merchant_tg_escape( $payment_link ) . '</code>';
		}

		if ( $payment_purpose !== '' ) {
			$text .= "\n\nPayment purpose:\n<code>" . crm_merchant_tg_escape( $payment_purpose ) . '</code>';
		}

		if ( $rub_invoice_markup_mode === 'add_on_top' && $markup_added_rub > 0.000001 && $requested_rub_input > 0 ) {
			$text .= "\n\nEntered amount:\n<code>" . crm_merchant_tg_escape( crm_merchant_tg_fmt_money( $requested_rub_input, 2 ) ) . " RUB</code>";
			$text .= "\nMarkup on top:\n<code>+" . crm_merchant_tg_escape( crm_merchant_tg_fmt_money( $markup_added_rub, 2 ) ) . " RUB</code>";
		}

		return $text;
	}
}

if ( ! function_exists( 'crm_merchant_tg_rub_invoice_terminal_text_from_order' ) ) {
	function crm_merchant_tg_rub_invoice_terminal_text_from_order( object $order, string $status_code ): string {
		$order_meta         = crm_merchant_tg_safe_json_decode( (string) ( $order->meta_json ?? '' ) );
		$merchant_meta      = crm_merchant_tg_safe_json_decode( (string) ( $order->merchant_meta_json ?? '' ) );
		$payment_amount_rub = $order->payment_amount_value !== null
			? (float) $order->payment_amount_value
			: (float) ( $merchant_meta['payment_amount_rub'] ?? 0 );
		$requested_rub_input = $order->merchant_requested_rub_value !== null
			? (float) $order->merchant_requested_rub_value
			: (float) ( $merchant_meta['merchant_requested_rub_input'] ?? 0 );
		$markup_added_rub = (float) ( $merchant_meta['merchant_markup_added_rub'] ?? $order_meta['merchant_markup_added_rub'] ?? 0 );
		$rub_invoice_markup_mode = (string) ( $merchant_meta['rub_invoice_markup_mode'] ?? $order_meta['rub_invoice_markup_mode'] ?? 'none' );
		$merchant_payable  = function_exists( 'crm_merchant_order_payable_amount' )
			? crm_merchant_order_payable_amount( $order )
			: ( isset( $order->merchant_payable_value ) ? (float) $order->merchant_payable_value : 0.0 );
		$current_rate      = (float) ( $merchant_meta['merchant_rate'] ?? 0 );
		$receipt_id        = ! empty( $order->id ) ? '#' . (int) $order->id : trim( (string) ( $order->merchant_order_id ?? '' ) );
		$payment_purpose   = trim( (string) ( $merchant_meta['payment_purpose'] ?? $order_meta['payment_purpose'] ?? '' ) );
		$descriptor        = crm_merchant_tg_rub_invoice_terminal_descriptor( $status_code );
		$timestamp_key     = (string) ( $descriptor['timestamp_key'] ?? 'updated_at' );
		$timestamp_raw     = trim( (string) ( $order->{$timestamp_key} ?? $order->updated_at ?? '' ) );
		$timestamp_label   = $timestamp_raw !== ''
			? mysql2date( 'd.m.Y H:i', $timestamp_raw )
			: current_time( 'd.m.Y H:i' );

		$text = $descriptor['title'] . "\n\n";
		$text .= crm_tg_receipt_block(
			[
				[
					'label' => 'FROM:',
					'value' => crm_tg_receipt_format_amount( $payment_amount_rub, 'RUB', 2, true ),
				],
				[
					'label' => 'RATE:',
					'value' => crm_tg_receipt_format_number( $current_rate, 4, false ),
				],
				[
					'label' => 'TO:',
					'value' => crm_tg_receipt_format_amount( $merchant_payable, 'USDT', 4, true ),
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
					'value' => (string) ( $descriptor['status_label'] ?? 'Closed' ),
				],
				[
					'label' => 'NOTE:',
					'value' => 'inactive',
				],
			],
			[
				(string) ( $descriptor['info_text'] ?? 'Счёт закрыт.' ),
			]
		);

		$text .= "\n\n" . crm_merchant_tg_escape( (string) ( $descriptor['info_text'] ?? 'Счёт закрыт.' ) );

		if ( $payment_purpose !== '' ) {
			$text .= "\n\nPayment purpose:\n<code>" . crm_merchant_tg_escape( $payment_purpose ) . '</code>';
		}

		if ( $rub_invoice_markup_mode === 'add_on_top' && $markup_added_rub > 0.000001 && $requested_rub_input > 0 ) {
			$text .= "\n\nEntered amount:\n<code>" . crm_merchant_tg_escape( crm_merchant_tg_fmt_money( $requested_rub_input, 2 ) ) . " RUB</code>";
			$text .= "\nMarkup on top:\n<code>+" . crm_merchant_tg_escape( crm_merchant_tg_fmt_money( $markup_added_rub, 2 ) ) . " RUB</code>";
		}

		return $text;
	}
}

if ( ! function_exists( 'crm_merchant_tg_paid_notification_text_from_order' ) ) {
	function crm_merchant_tg_paid_notification_text_from_order( object $order ): string {
		$merchant_payable = function_exists( 'crm_merchant_order_payable_amount' )
			? crm_merchant_order_payable_amount( $order )
			: ( isset( $order->merchant_payable_value ) ? (float) $order->merchant_payable_value : 0.0 );
		$paid_at_label = ! empty( $order->paid_at )
			? mysql2date( 'd.m.Y H:i', (string) $order->paid_at )
			: current_time( 'd.m.Y H:i' );

		$text  = "✅ <b>Платёж подтверждён</b>\n\n";
		$text .= 'Счёт: <code>#' . (int) ( $order->id ?? 0 ) . "</code>\n";
		$text .= 'Order ID: <code>' . crm_merchant_tg_escape( (string) ( $order->merchant_order_id ?? '' ) ) . "</code>\n";
		$text .= 'К выплате мерчанту: <b>' . crm_tg_receipt_format_amount( $merchant_payable, 'USDT', 4, true ) . "</b>\n";
		$text .= 'Оплачен: <b>' . crm_merchant_tg_escape( $paid_at_label ) . '</b>';

		return $text;
	}
}

if ( ! function_exists( 'crm_merchant_tg_sync_order_status' ) ) {
	function crm_merchant_tg_sync_order_status( int $order_id, array $args = [] ): array {
		global $wpdb;

		$source_code       = sanitize_key( (string) ( $args['source_code'] ?? 'system' ) );
		$send_notification = ! array_key_exists( 'send_notification', $args ) || (bool) $args['send_notification'];

		$result = [
			'ok'                   => false,
			'order_id'             => $order_id,
			'company_id'           => 0,
			'merchant_id'          => 0,
			'status_code'          => '',
			'skipped'              => false,
			'skip_reason'          => '',
			'receipt_updated'      => false,
			'receipt_deleted'      => false,
			'receipt_replaced'     => false,
			'replacement_message_id' => 0,
			'notification_sent'    => false,
			'notification_skipped' => false,
			'errors'               => [],
		];

		if ( $order_id <= 0 ) {
			$result['errors'][] = 'invalid_order_id';
			return $result;
		}

		$order = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT o.id, o.company_id, o.merchant_id, o.created_for_type, o.status_code, o.source_channel,
					        o.merchant_order_id, o.payment_link, o.payment_amount_value, o.amount_asset_value,
					        o.amount_asset_code, o.merchant_requested_rub_value, o.merchant_payable_value,
					        o.platform_fee_value, o.referral_reward_value, o.meta_json, o.merchant_meta_json,
					        o.created_at, o.updated_at, o.paid_at, o.declined_at, o.cancelled_at, o.expired_at,
					        m.chat_id AS merchant_chat_id, m.status AS merchant_status
					 FROM crm_fintech_payment_orders o
					 LEFT JOIN crm_merchants m
					   ON m.id = o.merchant_id
				  AND m.company_id = o.company_id
				 WHERE o.id = %d
				 LIMIT 1",
				$order_id
			)
		);

		if ( ! $order ) {
			$result['errors'][] = 'order_not_found';
			return $result;
		}

		$result['company_id']  = (int) ( $order->company_id ?? 0 );
		$result['merchant_id'] = (int) ( $order->merchant_id ?? 0 );
		$result['status_code'] = (string) ( $order->status_code ?? '' );

		if ( (int) $order->company_id <= 0 ) {
			$result['errors'][] = 'invalid_company';
			return $result;
		}
		if ( (string) $order->created_for_type !== 'merchant' ) {
			$result['skipped']     = true;
			$result['skip_reason'] = 'not_merchant_order';
			return $result;
		}

		$status_code = sanitize_key( (string) $order->status_code );
		$terminal_statuses = [ 'paid', 'declined', 'cancelled', 'expired', 'error' ];
		if ( ! in_array( $status_code, $terminal_statuses, true ) ) {
			$result['skipped']     = true;
			$result['skip_reason'] = 'order_not_terminal';
			return $result;
		}

		$meta                 = crm_merchant_tg_safe_json_decode( (string) ( $order->meta_json ?? '' ) );
		$chat_id              = trim( (string) ( $meta['merchant_tg_receipt_chat_id'] ?? $order->merchant_chat_id ?? '' ) );
		$receipt_message_id   = (int) ( $meta['merchant_tg_receipt_message_id'] ?? 0 );
		$receipt_message_type = sanitize_key( (string) ( $meta['merchant_tg_receipt_message_type'] ?? '' ) );
		$receipt_message_ids  = crm_merchant_tg_normalize_message_id_list( $meta['merchant_tg_receipt_message_ids'] ?? [] );
		$deleted_message_ids  = crm_merchant_tg_normalize_message_id_list( $meta['merchant_tg_receipt_deleted_message_ids'] ?? [] );

		if ( $chat_id === '' ) {
			$result['errors'][] = 'merchant_chat_missing';
			return $result;
		}

		$telegram_settings = function_exists( 'crm_telegram_collect_settings' )
			? crm_telegram_collect_settings( (int) $order->company_id, 'merchant' )
			: [];
		$bot_token = trim( (string) ( $telegram_settings['bot_token'] ?? '' ) );
		if ( $bot_token === '' || ! crm_merchant_tg_require_telegram_class() ) {
			$result['errors'][] = 'merchant_bot_not_ready';
			return $result;
		}

		$telegram   = new Telegram( $bot_token );
		$meta_patch = [];
		$meta_patch['merchant_tg_receipt_last_status_code']        = $status_code;
		$meta_patch['merchant_tg_receipt_last_status_sync_at']     = current_time( 'mysql', true );
		$meta_patch['merchant_tg_receipt_last_status_sync_source'] = $source_code !== '' ? $source_code : 'system';

		if ( $status_code === 'paid' ) {
			$keyboard     = crm_merchant_tg_rub_invoice_paid_keyboard();
			$receipt_text = crm_merchant_tg_rub_invoice_paid_text_from_order( $order );

			if ( $receipt_message_id > 0 ) {
				$receipt_response = [ 'ok' => false ];

				if ( $receipt_message_type === 'photo' ) {
					$receipt_response = crm_merchant_tg_edit_message_caption( $telegram, $chat_id, $receipt_message_id, $receipt_text, $keyboard );
				} elseif ( $receipt_message_type === 'text' ) {
					$receipt_response = crm_merchant_tg_edit_message( $telegram, $chat_id, $receipt_message_id, $receipt_text, $keyboard );
				} else {
					$receipt_response = crm_merchant_tg_edit_message_caption( $telegram, $chat_id, $receipt_message_id, $receipt_text, $keyboard );
					if ( ! crm_merchant_tg_telegram_response_ok( $receipt_response ) ) {
						$receipt_response = crm_merchant_tg_edit_message( $telegram, $chat_id, $receipt_message_id, $receipt_text, $keyboard );
					}
				}

				if ( crm_merchant_tg_telegram_response_ok( $receipt_response ) ) {
					$result['receipt_updated'] = true;
					$meta_patch['merchant_tg_receipt_message_ids'] = crm_merchant_tg_append_message_id_list( $receipt_message_ids, $receipt_message_id );
					$meta_patch['merchant_tg_receipt_paid_synced_at'] = current_time( 'mysql', true );
					$meta_patch['merchant_tg_receipt_paid_sync_source'] = $source_code !== '' ? $source_code : 'system';
				} else {
					$result['errors'][] = 'receipt_update_failed:' . trim( (string) ( $receipt_response['description'] ?? 'unknown' ) );
				}
			}

			$already_notified = ! empty( $meta['merchant_tg_paid_notify_sent_at'] );
			if ( $send_notification && ! $already_notified ) {
				$notify_response = crm_merchant_tg_send_message(
					$telegram,
					$chat_id,
					crm_merchant_tg_paid_notification_text_from_order( $order ),
					$keyboard
				);

				if ( crm_merchant_tg_telegram_response_ok( $notify_response ) ) {
					$result['notification_sent'] = true;
					$meta_patch['merchant_tg_paid_notify_sent_at'] = current_time( 'mysql', true );
					$meta_patch['merchant_tg_paid_notify_message_id'] = (int) ( $notify_response['result']['message_id'] ?? 0 );
					$meta_patch['merchant_tg_paid_notify_source'] = $source_code !== '' ? $source_code : 'system';
				} else {
					$result['errors'][] = 'notification_send_failed:' . trim( (string) ( $notify_response['description'] ?? 'unknown' ) );
				}
			} else {
				$result['notification_skipped'] = true;
			}
		} else {
			$keyboard     = crm_merchant_tg_rub_invoice_terminal_keyboard();
			$receipt_text = crm_merchant_tg_rub_invoice_terminal_text_from_order( $order, $status_code );

			if ( $receipt_message_id > 0 && $receipt_message_type === 'photo' ) {
				$deleted = crm_merchant_tg_delete_message( $telegram, $chat_id, $receipt_message_id );

				if ( $deleted ) {
					$result['receipt_deleted'] = true;
					$deleted_message_ids = crm_merchant_tg_append_message_id_list( $deleted_message_ids, $receipt_message_id );
					$meta_patch['merchant_tg_receipt_deleted_message_ids'] = $deleted_message_ids;
					$meta_patch['merchant_tg_receipt_message_id'] = 0;
					$meta_patch['merchant_tg_receipt_message_type'] = 'text';
				} else {
					$receipt_response = crm_merchant_tg_edit_message_caption( $telegram, $chat_id, $receipt_message_id, $receipt_text, $keyboard );
					if ( crm_merchant_tg_telegram_response_ok( $receipt_response ) ) {
						$result['receipt_updated'] = true;
						$meta_patch['merchant_tg_receipt_message_ids'] = crm_merchant_tg_append_message_id_list( $receipt_message_ids, $receipt_message_id );
					} else {
						$result['errors'][] = 'receipt_delete_failed:' . trim( (string) ( $receipt_response['description'] ?? 'unknown' ) );
					}
				}
			}

			if ( ! $result['receipt_updated'] && ! $result['receipt_deleted'] && $receipt_message_id > 0 && $receipt_message_type !== 'photo' ) {
				$receipt_response = [ 'ok' => false ];

				if ( $receipt_message_type === 'text' ) {
					$receipt_response = crm_merchant_tg_edit_message( $telegram, $chat_id, $receipt_message_id, $receipt_text, $keyboard );
				} else {
					$receipt_response = crm_merchant_tg_edit_message( $telegram, $chat_id, $receipt_message_id, $receipt_text, $keyboard );
					if ( ! crm_merchant_tg_telegram_response_ok( $receipt_response ) ) {
						$receipt_response = crm_merchant_tg_edit_message_caption( $telegram, $chat_id, $receipt_message_id, $receipt_text, $keyboard );
					}
				}

				if ( crm_merchant_tg_telegram_response_ok( $receipt_response ) ) {
					$result['receipt_updated'] = true;
					$meta_patch['merchant_tg_receipt_message_ids'] = crm_merchant_tg_append_message_id_list( $receipt_message_ids, $receipt_message_id );
				}
			}

			if ( ! $result['receipt_updated'] ) {
				if ( $receipt_message_id > 0 && ! $result['receipt_deleted'] ) {
					$deleted = crm_merchant_tg_delete_message( $telegram, $chat_id, $receipt_message_id );
					if ( $deleted ) {
						$result['receipt_deleted'] = true;
						$deleted_message_ids = crm_merchant_tg_append_message_id_list( $deleted_message_ids, $receipt_message_id );
						$meta_patch['merchant_tg_receipt_deleted_message_ids'] = $deleted_message_ids;
						$meta_patch['merchant_tg_receipt_message_id'] = 0;
						$meta_patch['merchant_tg_receipt_message_type'] = 'text';
					}
				}

				$replacement_response = crm_merchant_tg_send_message( $telegram, $chat_id, $receipt_text, $keyboard );

				if ( crm_merchant_tg_telegram_response_ok( $replacement_response ) ) {
					$new_message_id = (int) ( $replacement_response['result']['message_id'] ?? 0 );
					$result['receipt_replaced'] = true;
					$result['replacement_message_id'] = $new_message_id;
					$meta_patch['merchant_tg_receipt_chat_id'] = $chat_id;
					$meta_patch['merchant_tg_receipt_message_id'] = $new_message_id;
					$meta_patch['merchant_tg_receipt_message_type'] = 'text';
					$meta_patch['merchant_tg_receipt_message_ids'] = crm_merchant_tg_append_message_id_list( $receipt_message_ids, $new_message_id );
					if ( ! empty( $deleted_message_ids ) ) {
						$meta_patch['merchant_tg_receipt_deleted_message_ids'] = $deleted_message_ids;
					}
				} else {
					$result['errors'][] = 'replacement_send_failed:' . trim( (string) ( $replacement_response['description'] ?? 'unknown' ) );
				}
			}

			$result['notification_skipped'] = true;
		}

		if ( ! empty( $meta_patch ) ) {
			$meta_write = crm_merchant_tg_merge_order_meta( $order_id, (int) $order->company_id, $meta_patch );
			if ( empty( $meta_write['ok'] ) ) {
				$result['errors'][] = 'meta_update_failed';
			}
		}

		$result['ok'] = $result['receipt_updated']
			|| $result['receipt_replaced']
			|| $result['notification_sent']
			|| ( $status_code === 'paid' && $result['notification_skipped'] && empty( $result['errors'] ) );

		crm_log(
			$result['ok'] ? 'merchant.telegram.order_status_synced' : 'merchant.telegram.order_status_sync_failed',
			[
				'category'    => 'payments',
				'level'       => $result['ok'] ? 'info' : 'warning',
				'action'      => 'telegram_status_sync',
				'message'     => $result['ok']
					? 'Merchant order status synced to Telegram.'
					: 'Merchant order status Telegram sync finished with issues.',
				'target_type' => 'payment_order',
				'target_id'   => $order_id,
				'org_id'      => (int) $order->company_id,
				'is_success'  => $result['ok'],
				'context'     => [
					'status_code'          => $status_code,
					'merchant_id'          => (int) $order->merchant_id,
					'chat_id'              => $chat_id,
					'receipt_message_id'   => $receipt_message_id,
					'receipt_message_type' => $receipt_message_type,
					'receipt_updated'      => $result['receipt_updated'],
					'receipt_deleted'      => $result['receipt_deleted'],
					'receipt_replaced'     => $result['receipt_replaced'],
					'replacement_message_id' => $result['replacement_message_id'],
					'notification_sent'    => $result['notification_sent'],
					'notification_skipped' => $result['notification_skipped'],
					'source_code'          => $source_code,
					'errors'               => $result['errors'],
				],
			]
		);

		return $result;
	}
}

if ( ! function_exists( 'crm_merchant_tg_sync_paid_order' ) ) {
	function crm_merchant_tg_sync_paid_order( int $order_id, array $args = [] ): array {
		return crm_merchant_tg_sync_order_status( $order_id, $args );
	}
}

if ( ! function_exists( 'crm_merchant_tg_present_rub_invoice_amount_step' ) ) {
	function crm_merchant_tg_present_rub_invoice_amount_step( $telegram, object $merchant, array $ctx, array $state = [], string $warning_text = '' ): bool {
		$state             = crm_merchant_tg_rub_invoice_pipeline_state( $state );
		$state['awaiting'] = 'amount';

		return crm_merchant_tg_present_anchor_message(
			$telegram,
			$merchant,
			$ctx,
			crm_merchant_tg_rub_invoice_prompt_text( $merchant, $warning_text, $state ),
			crm_merchant_tg_rub_invoice_prompt_keyboard(),
			[
				'last_menu_screen'     => 'invoice_rub_usdt',
				'active_pipeline_code' => crm_merchant_tg_rub_invoice_pipeline_code(),
				'pipeline_state_json'  => crm_merchant_tg_rub_invoice_state_json( $state ),
			]
		);
	}
}

if ( ! function_exists( 'crm_merchant_tg_present_rub_invoice_purpose_step' ) ) {
	function crm_merchant_tg_present_rub_invoice_purpose_step( $telegram, object $merchant, array $ctx, array $state = [], string $warning_text = '' ): bool {
		$state             = crm_merchant_tg_rub_invoice_pipeline_state( $state );
		$state['awaiting'] = 'purpose';

		return crm_merchant_tg_present_anchor_message(
			$telegram,
			$merchant,
			$ctx,
			crm_merchant_tg_rub_invoice_purpose_prompt_text( $merchant, $state, $warning_text ),
			crm_merchant_tg_rub_invoice_purpose_keyboard(),
			[
				'last_menu_screen'     => 'invoice_rub_usdt',
				'active_pipeline_code' => crm_merchant_tg_rub_invoice_pipeline_code(),
				'pipeline_state_json'  => crm_merchant_tg_rub_invoice_state_json( $state ),
			]
		);
	}
}

if ( ! function_exists( 'crm_merchant_tg_begin_rub_invoice_pipeline' ) ) {
	function crm_merchant_tg_begin_rub_invoice_pipeline( $telegram, object $merchant, array $ctx ): bool {
		$company_id = (int) ( $merchant->company_id ?? 0 );
		$merchant_id = (int) ( $merchant->id ?? 0 );
		$chat_id = trim( (string) ( $ctx['chat_id'] ?? $merchant->chat_id ?? '' ) );

		if ( $company_id <= 0 || $merchant_id <= 0 || $chat_id === '' || ! $telegram ) {
			return false;
		}

		$precheck = crm_merchant_validate_rub_invoice_prerequisites( $merchant );
		if ( empty( $precheck['success'] ) ) {
			if ( function_exists( 'tg_safe_answer_callback' ) ) {
				tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? null, 'Недоступно', true );
			}
			return crm_merchant_tg_present_anchor_message(
				$telegram,
				$merchant,
				$ctx,
				"🧾 <b>Счёт ₽ → ₮</b>\n\n" . crm_merchant_tg_escape( (string) $precheck['error'] ),
				crm_merchant_tg_inline_menu_keyboard(),
				[
					'last_menu_screen'     => 'invoice_rub_usdt',
					'active_pipeline_code' => null,
					'pipeline_state_json'  => null,
				]
			);
		}

		if ( function_exists( 'tg_safe_answer_callback' ) ) {
			tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? null, 'Жду сумму' );
		}

		return crm_merchant_tg_present_rub_invoice_amount_step( $telegram, $merchant, $ctx );
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
	function crm_merchant_tg_orders_counts( int $company_id, int $merchant_id ): array {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 ) {
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
				 WHERE company_id = %d
				   AND merchant_id = %d
				   AND created_for_type = 'merchant'
				 GROUP BY status_code",
				$company_id,
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

if ( ! function_exists( 'crm_merchant_tg_orders_screen_meta' ) ) {
	function crm_merchant_tg_orders_screen_meta( string $screen ): ?array {
		$map = [
			'orders_open' => [
				'title'        => '🟡 <b>Активные счета</b>',
				'empty_text'   => 'Открытых счетов пока нет.',
				'status_codes' => [ 'created', 'pending' ],
				'checkable'    => true,
			],
			'orders_paid' => [
				'title'        => '🟢 <b>Оплаченные счета</b>',
				'empty_text'   => 'Оплаченных счетов пока нет.',
				'status_codes' => [ 'paid' ],
				'checkable'    => false,
			],
			'orders_cancelled' => [
				'title'        => '🔴 <b>Отменённые счета</b>',
				'empty_text'   => 'Отменённых счетов пока нет.',
				'status_codes' => [ 'declined', 'cancelled', 'expired', 'error' ],
				'checkable'    => false,
			],
		];

		return $map[ $screen ] ?? null;
	}
}

if ( ! function_exists( 'crm_merchant_tg_orders_page_size' ) ) {
	function crm_merchant_tg_orders_page_size(): int {
		return 5;
	}
}

if ( ! function_exists( 'crm_merchant_tg_orders_screen_token' ) ) {
	function crm_merchant_tg_orders_screen_token( string $screen, int $page = 1 ): string {
		$page = max( 1, (int) $page );

		if ( ! in_array( $screen, [ 'orders_open', 'orders_paid', 'orders_cancelled' ], true ) ) {
			return $screen;
		}

		return $page > 1 ? $screen . ':page:' . $page : $screen;
	}
}

if ( ! function_exists( 'crm_merchant_tg_parse_orders_screen_token' ) ) {
	function crm_merchant_tg_parse_orders_screen_token( string $screen ): array {
		$screen = trim( strtolower( $screen ) );

		if ( preg_match( '/^(orders_(open|paid|cancelled))(?::page:(\d+))?$/', $screen, $matches ) ) {
			return [
				'screen' => (string) $matches[1],
				'page'   => ! empty( $matches[3] ) ? max( 1, (int) $matches[3] ) : 1,
			];
		}

		return [
			'screen' => $screen,
			'page'   => 1,
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_orders_callback_data' ) ) {
	function crm_merchant_tg_orders_callback_data( string $screen, int $page = 1 ): string {
		$map = [
			'orders_open'      => 'm:orders:open',
			'orders_paid'      => 'm:orders:paid',
			'orders_cancelled' => 'm:orders:cancelled',
		];

		$prefix = $map[ $screen ] ?? 'm:orders';
		$page   = max( 1, (int) $page );

		return $page > 1 ? $prefix . ':page:' . $page : $prefix;
	}
}

if ( ! function_exists( 'crm_merchant_tg_orders_screen_from_callback' ) ) {
	function crm_merchant_tg_orders_screen_from_callback( string $callback_data ): ?string {
		if ( preg_match( '/^m:orders:(open|paid|cancelled)(?::page:(\d+))?$/', $callback_data, $matches ) ) {
			$screen_map = [
				'open'      => 'orders_open',
				'paid'      => 'orders_paid',
				'cancelled' => 'orders_cancelled',
			];
			$screen     = $screen_map[ (string) $matches[1] ] ?? '';
			$page       = ! empty( $matches[2] ) ? max( 1, (int) $matches[2] ) : 1;

			return $screen !== '' ? crm_merchant_tg_orders_screen_token( $screen, $page ) : null;
		}

		return null;
	}
}

if ( ! function_exists( 'crm_merchant_tg_recent_orders' ) ) {
	function crm_merchant_tg_recent_orders( int $company_id, int $merchant_id, array $status_codes, int $page = 1, int $limit = 6 ): array {
		global $wpdb;

		$company_id   = (int) $company_id;
		$merchant_id  = (int) $merchant_id;
		$status_codes = array_values( array_filter( array_map( 'sanitize_key', $status_codes ) ) );
		$page         = max( 1, (int) $page );
		$limit        = max( 1, min( 12, (int) $limit ) );

		if ( $company_id <= 0 || $merchant_id <= 0 || empty( $status_codes ) ) {
			return [
				'items'       => [],
				'page'        => 1,
				'total'       => 0,
				'total_pages' => 1,
				'has_prev'    => false,
				'has_next'    => false,
			];
		}

		$placeholders = implode( ', ', array_fill( 0, count( $status_codes ), '%s' ) );
		$count_sql    = "SELECT COUNT(*)
			FROM crm_fintech_payment_orders
			WHERE company_id = %d
			  AND merchant_id = %d
			  AND created_for_type = 'merchant'
			  AND status_code IN ($placeholders)";
		$count_params = array_merge( [ $company_id, $merchant_id ], $status_codes );
		$total        = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) );
		$total_pages  = max( 1, (int) ceil( $total / $limit ) );
		$page         = min( $page, $total_pages );
		$offset       = ( $page - 1 ) * $limit;
		$params       = array_merge( [ $company_id, $merchant_id ], $status_codes, [ $limit, $offset ] );

		$sql = "SELECT id, merchant_order_id, status_code, payment_currency_code, payment_amount_value,
		               amount_asset_code, amount_asset_value, merchant_payable_value, created_at, paid_at
		        FROM crm_fintech_payment_orders
		        WHERE company_id = %d
		          AND merchant_id = %d
		          AND created_for_type = 'merchant'
		          AND status_code IN ($placeholders)
		        ORDER BY id DESC
		        LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, $params ),
			ARRAY_A
		);

		return [
			'items'       => is_array( $rows ) ? $rows : [],
			'page'        => $page,
			'total'       => $total,
			'total_pages' => $total_pages,
			'has_prev'    => $page > 1,
			'has_next'    => $page < $total_pages,
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_order_compact_label' ) ) {
	function crm_merchant_tg_order_compact_label( array $row ): string {
		$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
		return $id > 0 ? '#' . $id : '—';
	}
}

if ( ! function_exists( 'crm_merchant_tg_orders_list_text' ) ) {
	function crm_merchant_tg_orders_list_text( string $title_html, array $orders, string $empty_text, array $page_state = [] ): string {
		$page        = max( 1, (int) ( $page_state['page'] ?? 1 ) );
		$total_pages = max( 1, (int) ( $page_state['total_pages'] ?? 1 ) );

		if ( empty( $orders ) ) {
			return $title_html . "\n\n<i>" . crm_merchant_tg_escape( $empty_text ) . '</i>';
		}

		$lines = [];
		foreach ( $orders as $row ) {
			$payment_currency = trim( (string) ( $row['payment_currency_code'] ?? 'RUB' ) );
			$asset_code       = trim( (string) ( $row['amount_asset_code'] ?? 'USDT' ) );
			$payment_amount   = isset( $row['payment_amount_value'] ) && $row['payment_amount_value'] !== null
				? (float) $row['payment_amount_value']
				: 0.0;
			$payable_amount   = function_exists( 'crm_merchant_order_payable_amount' )
				? crm_merchant_order_payable_amount( $row )
				: ( isset( $row['amount_asset_value'] ) ? (float) $row['amount_asset_value'] : 0.0 );
			$created_at       = trim( (string) ( $row['created_at'] ?? '' ) );
			$time_label       = $created_at !== '' ? mysql2date( 'd.m H:i', $created_at ) : '—';

			$lines[] = crm_merchant_tg_order_compact_label( $row );
			$lines[] = crm_tg_receipt_format_amount( $payment_amount, $payment_currency !== '' ? $payment_currency : 'RUB', 2, true )
				. ' -> '
				. crm_tg_receipt_format_amount( $payable_amount, $asset_code !== '' ? $asset_code : 'USDT', 4, true );
			$lines[] = $time_label;
			$lines[] = '';
		}

		$text = $title_html . "\n\n<pre>" . crm_merchant_tg_escape( rtrim( implode( "\n", $lines ) ) ) . '</pre>';
		if ( $total_pages > 1 ) {
			$text .= "\n\n<i>Страница " . $page . ' из ' . $total_pages . '</i>';
		}

		return $text;
	}
}

if ( ! function_exists( 'crm_merchant_tg_orders_screen_keyboard' ) ) {
	function crm_merchant_tg_orders_screen_keyboard( string $screen, array $orders, array $page_state = [], bool $allow_check = false ): array {
		$rows = [];
		$page = max( 1, (int) ( $page_state['page'] ?? 1 ) );

		if ( $allow_check && ! empty( $orders ) ) {
			$check_buttons = [];
			foreach ( array_slice( $orders, 0, 4 ) as $row ) {
				$order_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
				if ( $order_id <= 0 ) {
					continue;
				}

				$check_buttons[] = [
					'text'          => '✅ ' . crm_merchant_tg_order_compact_label( $row ),
					'callback_data' => 'kanyon_paid:' . $order_id,
				];
			}

			if ( ! empty( $check_buttons ) ) {
				foreach ( array_chunk( $check_buttons, 2 ) as $chunk ) {
					$rows[] = $chunk;
				}
			}
		}

		if ( ! empty( $page_state['has_prev'] ) || ! empty( $page_state['has_next'] ) ) {
			$pagination_row = [];
			if ( ! empty( $page_state['has_prev'] ) ) {
				$pagination_row[] = [
					'text'          => '← Назад',
					'callback_data' => crm_merchant_tg_orders_callback_data( $screen, $page - 1 ),
				];
			}

			$pagination_row[] = [
				'text'          => $page . '/' . max( 1, (int) ( $page_state['total_pages'] ?? 1 ) ),
				'callback_data' => crm_merchant_tg_orders_callback_data( $screen, $page ),
			];

			if ( ! empty( $page_state['has_next'] ) ) {
				$pagination_row[] = [
					'text'          => 'Вперёд →',
					'callback_data' => crm_merchant_tg_orders_callback_data( $screen, $page + 1 ),
				];
			}

			$rows[] = $pagination_row;
		}

		$rows[] = [
			[ 'text' => '🔄 Обновить', 'callback_data' => crm_merchant_tg_orders_callback_data( $screen, $page ) ],
		];
		$rows[] = [
			[ 'text' => '↩ К счетам', 'callback_data' => 'm:orders' ],
			[ 'text' => '↩ Меню',     'callback_data' => 'm:main' ],
		];

		return [
			'inline_keyboard' => $rows,
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_screen_payload' ) ) {
	function crm_merchant_tg_screen_payload( string $screen, object $merchant ): array {
		$screen_context = crm_merchant_tg_parse_orders_screen_token( $screen );
		$screen        = crm_merchant_tg_normalize_screen( (string) ( $screen_context['screen'] ?? $screen ) );
		$screen_page   = max( 1, (int) ( $screen_context['page'] ?? 1 ) );
		$display_name  = crm_merchant_tg_escape( crm_merchant_tg_display_name( $merchant ) );
		$company_name  = crm_merchant_tg_escape( (string) ( $merchant->company_name ?? '' ) );
		$office_name   = trim( (string) ( $merchant->office_name ?? '' ) );
		$office_name   = $office_name !== '' ? crm_merchant_tg_escape( $office_name ) : 'Без офиса';
		$markup_label  = crm_merchant_tg_escape( crm_merchant_markup_type_label( (string) ( $merchant->base_markup_type ?? 'percent' ) ) );
		$markup_value  = crm_merchant_tg_escape( crm_merchant_format_amount( (float) ( $merchant->base_markup_value ?? 0 ), '' ) );
		$balance_map   = crm_get_merchant_balance_summary_map( [ (int) $merchant->id ] );
		$balance       = $balance_map[ (int) $merchant->id ] ?? [ 'main_balance' => 0, 'bonus_balance' => 0, 'referral_balance' => 0, 'total_balance' => 0 ];
		$order_counts  = crm_merchant_tg_orders_counts( (int) ( $merchant->company_id ?? 0 ), (int) $merchant->id );

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
				$single_rate_screen = crm_merchant_tg_single_rate_screen( $merchant );
				if ( $single_rate_screen !== null ) {
					return crm_merchant_tg_screen_payload( $single_rate_screen, $merchant );
				}

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
				return [
					'screen'   => 'rates_rub_usdt',
					'text'     => crm_merchant_tg_rub_usdt_rate_text( $merchant ),
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '🔄 Refresh', 'callback_data' => 'm:rates:rub-usdt:check' ],
							],
							[
								[ 'text' => '↩ Menu', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'rates_rub_usdt_check':
				crm_merchant_tg_rub_invoice_preview_context( $merchant, true );
				return crm_merchant_tg_screen_payload( 'rates_rub_usdt', $merchant );

			case 'balances':
				$balance_lines = [];
				$balance_lines[] = crm_merchant_tg_pad( '💵 К выплате',    14 ) . crm_merchant_format_amount( $balance['main_balance'] );
				$balance_lines[] = crm_merchant_tg_pad( '🎁 Бонусный',    14 ) . crm_merchant_format_amount( $balance['bonus_balance'] );
				$balance_lines[] = crm_merchant_tg_pad( '🤝 Реферальный', 14 ) . crm_merchant_format_amount( $balance['referral_balance'] );
				$balance_lines[] = crm_merchant_tg_pad( '🧾 Итого',       14 ) . crm_merchant_format_amount( $balance['total_balance'] );
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
				$available_directions = crm_merchant_tg_available_invoice_directions( $merchant );
				$invoice_rows         = [];

				if ( ! empty( $available_directions ) ) {
					$invoice_rows[] = array_map(
						static function ( array $direction ): array {
							return [
								'text'          => (string) ( $direction['button_text'] ?? 'Направление' ),
								'callback_data' => 'm:invoice:' . strtolower( str_replace( '_', '-', (string) ( $direction['code'] ?? '' ) ) ),
							];
						},
						$available_directions
					);
				}

				$invoice_rows[] = [
					[ 'text' => '↩ Меню', 'callback_data' => 'm:main' ],
				];

				return [
					'screen'   => 'invoice',
					'text'     => empty( $available_directions )
						? "🧾 <b>Выставить счёт</b>\n\n<i>Для вашей компании пока не открыто ни одного направления.</i>"
						: "🧾 <b>Выставить счёт</b>\n\nВыберите направление продажи — что клиент платит и что получает.",
					'keyboard' => [
						'inline_keyboard' => $invoice_rows,
					],
				];

			case 'invoice_rub_thb':
				if ( ! crm_merchant_tg_invoice_direction_is_available( $merchant, 'RUB_THB' ) ) {
					return crm_merchant_tg_screen_payload( 'invoice', $merchant );
				}

				return [
					'screen'   => 'invoice_rub_thb',
					'text'     => crm_merchant_tg_placeholder_text(
						'🧾 <b>Счёт ₽ → ฿</b>',
						'Контур уже выделен в меню, но выпуск счёта в этом направлении ещё не открыт.',
						'В ближайшее время здесь появится расчёт по корпоративному курсу, фиксация суммы к выдаче и стандартная карточка оплаты.',
						'Пока в этом кабинете доступен рабочий сценарий ₽ → ₮.'
					),
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
				if ( ! crm_merchant_tg_invoice_direction_is_available( $merchant, 'USDT_THB' ) ) {
					return crm_merchant_tg_screen_payload( 'invoice', $merchant );
				}

				return [
					'screen'   => 'invoice_usdt_thb',
					'text'     => crm_merchant_tg_placeholder_text(
						'🧾 <b>Счёт ₮ → ฿</b>',
						'Контур уже подготовлен на уровне интерфейса, но боевой выпуск счёта пока не включён.',
						'В ближайшее время здесь появится полноценный сценарий с расчётом суммы к выдаче, карточкой оплаты и дальнейшей проверкой статуса.',
						'До открытия этого направления используйте уже доступные рабочие разделы кабинета.'
					),
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
				if ( ! crm_merchant_tg_invoice_direction_is_available( $merchant, 'RUB_USDT' ) ) {
					return crm_merchant_tg_screen_payload( 'invoice', $merchant );
				}

				$invoice_preview = crm_merchant_tg_rub_invoice_preview_context( $merchant );
				$rate_block      = $invoice_preview['rate_line'] !== ''
					? $invoice_preview['rate_line'] . "\n\n"
					: '';
				$amount_adjustment_note = '';
				if ( (string) ( $invoice_preview['rub_invoice_markup_mode'] ?? 'none' ) === 'add_on_top' && (float) ( $invoice_preview['merchant_markup_percent'] ?? 0 ) > 0 ) {
					$amount_adjustment_note = "➕ К введённой сумме будет добавлено <b>"
						. crm_merchant_tg_fmt_rate( (float) $invoice_preview['merchant_markup_percent'], 2 )
						. "%</b>.\n\n";
				}

				if ( empty( $invoice_preview['success'] ) ) {
					return [
						'screen'   => 'invoice_rub_usdt',
						'text'     => "🧾 <b>Счёт ₽ → ₮</b>\n\n" . crm_merchant_tg_escape( (string) $invoice_preview['error'] ),
						'keyboard' => [
							'inline_keyboard' => [
								[
									[ 'text' => '↩ К способам', 'callback_data' => 'm:invoice' ],
									[ 'text' => '↩ Меню',      'callback_data' => 'm:main' ],
								],
							],
						],
					];
				}

				return [
					'screen'   => 'invoice_rub_usdt',
					'text'     => "🧾 <b>Счёт ₽ → ₮</b>\n\n"
						. "<b>Предварительный расчёт</b>\n"
						. "Клиент оплачивает счёт в RUB, выплата рассчитывается в USDT.\n\n"
						. $rate_block
						. $amount_adjustment_note
						. "<i>Итоговый курс фиксируется в момент выпуска счёта по текущему курсу биржи.</i>\n\n"
						. "Нажмите кнопку ниже, чтобы перейти к вводу суммы.",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '✍️ Ввести сумму', 'callback_data' => 'm:invoice:rub-usdt:start' ],
							],
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
								[ 'text' => '🟡 Активные', 'callback_data' => 'm:orders:open' ],
							],
							[
								[ 'text' => '🟢 Оплаченные', 'callback_data' => 'm:orders:paid' ],
							],
							[
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
				$meta = crm_merchant_tg_orders_screen_meta( $screen );
				if ( ! is_array( $meta ) ) {
					return crm_merchant_tg_screen_payload( 'orders', $merchant );
				}

				$orders_page = crm_merchant_tg_recent_orders(
					(int) ( $merchant->company_id ?? 0 ),
					(int) $merchant->id,
					(array) ( $meta['status_codes'] ?? [] ),
					$screen_page,
					crm_merchant_tg_orders_page_size()
				);
				$orders      = (array) ( $orders_page['items'] ?? [] );

				return [
					'screen'   => crm_merchant_tg_orders_screen_token(
						$screen,
						(int) ( $orders_page['page'] ?? $screen_page )
					),
					'text'     => crm_merchant_tg_orders_list_text(
						(string) ( $meta['title'] ?? '📂 <b>Счета</b>' ),
						$orders,
						(string) ( $meta['empty_text'] ?? 'Список пока пуст.' ),
						$orders_page
					),
					'keyboard' => crm_merchant_tg_orders_screen_keyboard(
						$screen,
						$orders,
						$orders_page,
						! empty( $meta['checkable'] )
					),
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
				$command_lines = [
					'🏢 <code>/start</code> — открыть кабинет',
					'📋 <code>/menu</code> — главное меню',
					'🧾 <code>/invoice</code> — выставить счёт',
					'📂 <code>/orders</code> — мои счета',
					'💼 <code>/balance</code> — балансы',
					'💹 <code>/rates</code> — курсы',
					'👤 <code>/profile</code> — профиль',
					'ℹ️ <code>/help</code> — помощь',
				];

				return [
					'screen'   => 'help',
					'text'     => "ℹ️ <b>Помощь</b>\n\n"
						. "Командное меню merchant-бота уже подключено.\n\n"
						. implode( "\n", $command_lines )
						. "\n\n🗓️ В ближайшее время бот получит детальные списки счетов, расширенные карточки направлений и дополнительные сервисные действия."
						. "\n\n<i>Если доступ или данные работают не так, как ожидается, обратитесь к администратору компании.</i>",
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
		$is_callback_context = ! empty( $ctx['callback_query_id'] );
		$target_message_id = $is_callback_context && $current_message_id > 0 ? $current_message_id : $stored_message_id;
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
			$stale_candidates = [ $stored_message_id ];
			if ( $is_callback_context && $current_message_id > 0 ) {
				$stale_candidates[] = $current_message_id;
			}

			foreach ( array_unique( array_filter( $stale_candidates ) ) as $stale_message_id ) {
				$stale_message_id = (int) $stale_message_id;
				if ( $stale_message_id > 0 && $stale_message_id !== $target_message_id ) {
					crm_merchant_tg_delete_message( $telegram, $chat_id, $stale_message_id );
				}
			}

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
		$orders_screen = crm_merchant_tg_orders_screen_from_callback( $callback_data );
		if ( $orders_screen !== null ) {
			return $orders_screen;
		}

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
		if ( ! crm_merchant_tg_is_merchant_context() ) {
			return false;
		}

		$command_definitions = crm_merchant_tg_command_definitions();
		$command_meta        = $command_definitions[ $command ] ?? null;
		if ( ! is_array( $command_meta ) ) {
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

		if ( (string) ( $command_meta['entrypoint'] ?? '' ) === 'invoice' ) {
			return crm_merchant_tg_open_invoice_entrypoint( $telegram, $access['merchant'], $ctx, true, true );
		}

		$screen = (string) ( $command_meta['screen'] ?? 'main' );
		return crm_merchant_tg_present_screen( $telegram, $access['merchant'], $ctx, $screen, true, true );
	}
}

if ( ! function_exists( 'crm_merchant_tg_route_callback' ) ) {
	function crm_merchant_tg_route_callback( string $callback_data, array $ctx, $telegram, array $data ): bool {
		if ( ! crm_merchant_tg_is_merchant_context() ) {
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

		$session = crm_merchant_tg_session_get( $company_id, $chat_id );

		if ( preg_match( '/^m:payout:confirm:(\d+)$/', $callback_data, $matches ) ) {
			$payout_id = (int) $matches[1];

			if ( ! crm_merchant_tg_payout_belongs_to_merchant( $company_id, (int) $access['merchant']->id, $payout_id ) ) {
				if ( function_exists( 'tg_safe_answer_callback' ) ) {
					tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? null, 'Выплата не найдена.', true );
				}
				return true;
			}

			$confirm_result = function_exists( 'crm_merchant_confirm_payout' )
				? crm_merchant_confirm_payout( $company_id, (int) $access['merchant']->id, $payout_id )
				: [
					'success' => false,
					'code'    => 'helper_missing',
					'error'   => 'Подтверждение сейчас недоступно.',
				];

			$log_success = ! empty( $confirm_result['success'] );
			$log_message = $log_success
				? 'Мерчант подтвердил получение выплаты'
				: 'Не удалось зафиксировать подтверждение выплаты от мерчанта';

			crm_log_entity(
				$log_success ? 'merchant.telegram.payout_confirmed' : 'merchant.telegram.payout_confirmation_failed',
				'payouts',
				'update',
				$log_message,
				'merchant_payout',
				$payout_id,
				[
					'org_id'     => $company_id,
					'is_success' => $log_success,
					'level'      => $log_success ? 'info' : 'warning',
					'context'    => [
						'merchant_id' => (int) $access['merchant']->id,
						'chat_id'     => $chat_id,
						'code'        => (string) ( $confirm_result['code'] ?? '' ),
					],
				]
			);

			if ( function_exists( 'tg_safe_answer_callback' ) ) {
				tg_safe_answer_callback(
					$telegram,
					$ctx['callback_query_id'] ?? null,
					(string) ( $confirm_result['success'] ? ( $confirm_result['message'] ?? 'Спасибо, подтверждение получено.' ) : ( $confirm_result['error'] ?? 'Подтверждение сейчас недоступно.' ) ),
					! $confirm_result['success']
				);
			}
			return true;
		}

		if ( $callback_data === 'm:invoice:rub-usdt:purpose' ) {
			if ( function_exists( 'tg_safe_answer_callback' ) ) {
				tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? null, 'Жду назначение' );
			}

			return crm_merchant_tg_present_rub_invoice_purpose_step(
				$telegram,
				$access['merchant'],
				$ctx,
				crm_merchant_tg_rub_invoice_pipeline_state( (string) ( $session['pipeline_state_json'] ?? '' ) )
			);
		}

		if ( $callback_data === 'm:invoice:rub-usdt:amount' ) {
			if ( function_exists( 'tg_safe_answer_callback' ) ) {
				tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? null, 'Жду сумму' );
			}

			return crm_merchant_tg_present_rub_invoice_amount_step(
				$telegram,
				$access['merchant'],
				$ctx,
				crm_merchant_tg_rub_invoice_pipeline_state( (string) ( $session['pipeline_state_json'] ?? '' ) )
			);
		}

		if ( $callback_data === 'm:invoice:rub-usdt:start' ) {
			return crm_merchant_tg_begin_rub_invoice_pipeline( $telegram, $access['merchant'], $ctx );
		}

		if ( in_array( $callback_data, [ 'm:invoice', 'orders_new' ], true ) ) {
			$available_directions = crm_merchant_tg_available_invoice_directions( $access['merchant'] );
			if ( count( $available_directions ) === 1 && (string) ( $available_directions[0]['entry_mode'] ?? '' ) === 'rub_usdt_pipeline' ) {
				return crm_merchant_tg_begin_rub_invoice_pipeline( $telegram, $access['merchant'], $ctx );
			}

			$shown = crm_merchant_tg_open_invoice_entrypoint( $telegram, $access['merchant'], $ctx, false, false );
			if ( $shown && function_exists( 'tg_safe_answer_callback' ) ) {
				tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? null, 'Готово' );
			}
			return $shown;
		}

		$screen = crm_merchant_tg_map_callback_to_screen( $callback_data );
		if ( $screen === null ) {
			return false;
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
		if ( ! crm_merchant_tg_is_merchant_context() ) {
			return false;
		}

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

		$session = crm_merchant_tg_session_get( $company_id, $chat_id );
		$active_pipeline_code = (string) ( $session['active_pipeline_code'] ?? '' );

		if ( $active_pipeline_code === crm_merchant_tg_rub_invoice_pipeline_code() ) {
			$pipeline_state = crm_merchant_tg_rub_invoice_pipeline_state( (string) ( $session['pipeline_state_json'] ?? '' ) );

			if ( (string) ( $pipeline_state['awaiting'] ?? 'amount' ) === 'purpose' ) {
				$payment_purpose = function_exists( 'crm_fintech_normalize_payment_purpose' )
					? crm_fintech_normalize_payment_purpose( $text )
					: sanitize_text_field( $text );

				if ( $payment_purpose === '' ) {
					return crm_merchant_tg_present_rub_invoice_purpose_step(
						$telegram,
						$access['merchant'],
						$ctx,
						$pipeline_state,
						'Введите непустое назначение платежа одним сообщением.'
					);
				}

				$pipeline_state['payment_purpose'] = $payment_purpose;

				return crm_merchant_tg_present_rub_invoice_amount_step(
					$telegram,
					$access['merchant'],
					$ctx,
					$pipeline_state,
					'Назначение платежа обновлено. Теперь отправьте сумму счёта.'
				);
			}

			$requested_rub = crm_merchant_normalize_rub_amount( $text );

			if ( $requested_rub <= 0 ) {
				return crm_merchant_tg_present_rub_invoice_amount_step(
					$telegram,
					$access['merchant'],
					$ctx,
					$pipeline_state,
					'Отправьте только сумму счёта в RUB одним сообщением.'
				);
			}

			$payment_purpose = crm_merchant_tg_rub_invoice_effective_payment_purpose( $access['merchant'], $pipeline_state );

			crm_merchant_tg_session_upsert(
				$company_id,
				(int) $access['merchant']->id,
				$chat_id,
				[
					'active_pipeline_code' => null,
					'pipeline_state_json'  => null,
				]
			);

			$result = crm_merchant_create_rub_invoice(
				(int) $access['merchant']->id,
				$requested_rub,
				[
					'source_channel'  => 'telegram_merchant',
					'payment_purpose' => $payment_purpose,
				]
			);

			if ( empty( $result['success'] ) ) {
				return crm_merchant_tg_present_anchor_message(
					$telegram,
					$access['merchant'],
					$ctx,
					"Не удалось выпустить счёт.\n\n" . crm_merchant_tg_escape( (string) ( $result['error'] ?? 'Ошибка gateway.' ) ),
					crm_merchant_tg_invoice_retry_keyboard(),
					[
						'last_menu_screen'     => 'invoice_rub_usdt',
						'active_pipeline_code' => null,
						'pipeline_state_json'  => null,
					]
				);
			}

			$keyboard = crm_merchant_tg_rub_invoice_success_keyboard( (int) ( $result['order_db_id'] ?? 0 ) );
			$caption  = crm_merchant_tg_rub_invoice_success_text( $result );
			$qr_url   = trim( (string) ( $result['qr_url'] ?? '' ) );
			$send_result = [ 'ok' => false ];
			$message_type = 'text';

			if ( $qr_url !== '' ) {
				$send_result  = crm_merchant_tg_send_photo( $telegram, $chat_id, $qr_url, $caption, $keyboard );
				$message_type = 'photo';
			}

			if ( empty( $send_result['ok'] ) ) {
				$send_result  = crm_merchant_tg_send_message( $telegram, $chat_id, $caption, $keyboard );
				$message_type = 'text';
			}

			if ( ! empty( $result['order_db_id'] ) ) {
				crm_merchant_tg_store_invoice_message_context(
					(int) $result['order_db_id'],
					$company_id,
					(int) $access['merchant']->id,
					$chat_id,
					$message_type,
					$send_result
				);
			}

			crm_merchant_tg_close_menu_anchor(
				$telegram,
				$company_id,
				(int) $access['merchant']->id,
				$chat_id,
				is_array( $session ) ? $session : null
			);
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

if ( ! function_exists( 'crm_merchant_tg_notify_activation_request_cancelled' ) ) {
	function crm_merchant_tg_notify_activation_request_cancelled( object $merchant ): bool {
		$company_id = (int) ( $merchant->company_id ?? 0 );
		$merchant_id = (int) ( $merchant->id ?? 0 );
		$chat_id    = trim( (string) ( $merchant->chat_id ?? '' ) );
		if ( $company_id <= 0 || $merchant_id <= 0 || $chat_id === '' ) {
			return false;
		}

		$telegram_settings = function_exists( 'crm_telegram_collect_settings' ) ? crm_telegram_collect_settings( $company_id ) : [];
		$bot_token = trim( (string) ( $telegram_settings['bot_token'] ?? '' ) );
		if ( $bot_token === '' || ! crm_merchant_tg_require_telegram_class() ) {
			crm_log_entity(
				'merchant.telegram.request_cancel_notify_failed',
				'users',
				'update',
				'Не удалось отправить уведомление об отмене запроса мерчанту: отсутствует Telegram runtime или token',
				'merchant',
				$merchant_id,
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
		$session  = crm_merchant_tg_session_get( $company_id, $chat_id );

		crm_merchant_tg_close_menu_anchor( $telegram, $company_id, $merchant_id, $chat_id, $session );
		crm_merchant_tg_session_upsert(
			$company_id,
			$merchant_id,
			$chat_id,
			[
				'last_menu_screen'     => 'main',
				'active_pipeline_code' => null,
				'pipeline_state_json'  => null,
			]
		);

		$sent = ! empty( crm_merchant_tg_send_message(
			$telegram,
			$chat_id,
			"⚠️ <b>Запрос на подключение не был подтверждён</b>\n\n"
			. "К сожалению, по текущему приглашению доступ к кабинету мерчанта сейчас не открыт.\n\n"
			. "Если подключение по-прежнему актуально, пожалуйста, свяжитесь с представителем компании, который направил вам invite-ссылку, или запросите новое приглашение.\n\n"
			. "Благодарим за понимание."
		)['ok'] );

		crm_log_entity(
			$sent ? 'merchant.telegram.request_cancel_notified' : 'merchant.telegram.request_cancel_notify_failed',
			'users',
			'update',
			$sent ? 'Мерчанту отправлено уведомление об отмене запроса на активацию' : 'Не удалось отправить уведомление об отмене запроса на активацию',
			'merchant',
			$merchant_id,
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

if ( ! function_exists( 'crm_merchant_tg_notify_payout' ) ) {
	function crm_merchant_tg_notify_payout( object $merchant, array $payout ): array {
		$company_id  = (int) ( $merchant->company_id ?? 0 );
		$merchant_id = (int) ( $merchant->id ?? 0 );
		$chat_id     = trim( (string) ( $merchant->chat_id ?? '' ) );
		$payout_id   = (int) ( $payout['payout_id'] ?? 0 );
		$receipt_url = trim( (string) ( $payout['receipt_url'] ?? '' ) );
		$has_receipt = $receipt_url !== '';

		$result = [
			'ok'             => false,
			'message_sent'   => false,
			'photo_sent'     => ! $has_receipt,
			'receipt_shared' => ! $has_receipt,
			'reason'         => '',
		];

		if ( $company_id <= 0 || $merchant_id <= 0 || $chat_id === '' || $payout_id <= 0 ) {
			$result['reason'] = 'missing_context';
			return $result;
		}

		$telegram_settings = function_exists( 'crm_telegram_collect_settings' ) ? crm_telegram_collect_settings( $company_id ) : [];
		$bot_token         = trim( (string) ( $telegram_settings['bot_token'] ?? '' ) );

		if ( $bot_token === '' || ! crm_merchant_tg_require_telegram_class() ) {
			$result['reason'] = 'missing_runtime';
			crm_log_entity(
				'merchant.telegram.payout_notify_failed',
				'payouts',
				'notify',
				'Не удалось отправить payout-уведомление мерчанту: отсутствует Telegram runtime или token',
				'merchant_payout',
				$payout_id,
				[
					'org_id'  => $company_id,
					'context' => [
						'merchant_id'     => $merchant_id,
						'chat_id'         => $chat_id,
						'receipt_attached'=> $has_receipt,
					],
				]
			);
			return $result;
		}

		$telegram          = new Telegram( $bot_token );
		$receipt_link_html = '';

		if ( $has_receipt ) {
			$photo_result = crm_merchant_tg_send_photo( $telegram, $chat_id, $receipt_url, 'Payout screenshot' );
			$result['photo_sent'] = ! empty( $photo_result['ok'] );
			if ( ! $result['photo_sent'] ) {
				$receipt_link_html = '<a href="' . esc_url( $receipt_url ) . '">Open screenshot</a>';
				$result['receipt_shared'] = true;
			} else {
				$result['receipt_shared'] = true;
			}
		}

		$message_html      = crm_merchant_tg_payout_message_html( $merchant, $payout, $receipt_link_html );
		$message_keyboard  = crm_merchant_tg_payout_confirmation_keyboard( $payout_id );
		$message_response  = crm_merchant_tg_send_message( $telegram, $chat_id, $message_html, $message_keyboard );
		$result['message_sent'] = ! empty( $message_response['ok'] );

		if ( ! $has_receipt ) {
			$result['receipt_shared'] = true;
		}

		$result['ok'] = $result['message_sent'] && $result['receipt_shared'];
		$result['reason'] = $result['ok']
			? ( $has_receipt && ! $result['photo_sent'] ? 'receipt_link_fallback' : 'sent' )
			: ( $result['message_sent'] ? 'message_only' : 'send_failed' );

		$log_message = 'Не удалось отправить уведомление о выплате мерчанту.';
		if ( $result['ok'] ) {
			$log_message = $has_receipt && ! $result['photo_sent']
				? 'Мерчанту отправлено уведомление о выплате; скриншот передан ссылкой.'
				: 'Мерчанту отправлено уведомление о выплате.';
		}

		crm_log_entity(
			$result['ok'] ? 'merchant.telegram.payout_notified' : 'merchant.telegram.payout_notify_failed',
			'payouts',
			'notify',
			$log_message,
			'merchant_payout',
			$payout_id,
			[
				'org_id'     => $company_id,
				'is_success' => $result['ok'],
				'level'      => $result['ok'] ? 'info' : 'warning',
				'context'    => [
					'merchant_id'      => $merchant_id,
					'chat_id'          => $chat_id,
					'receipt_attached' => $has_receipt,
					'message_sent'     => $result['message_sent'],
					'photo_sent'       => $result['photo_sent'],
					'receipt_shared'   => $result['receipt_shared'],
					'reason'           => $result['reason'],
				],
			]
		);

		return $result;
	}
}
