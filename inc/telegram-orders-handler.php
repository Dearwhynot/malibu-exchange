<?php
/**
 * Malibu Exchange — Telegram Orders Handler
 *
 * Реализует project-хуки для telegram-callback-universal.php:
 *   tg_project_handle_orders_action() — кнопки меню: orders_new, orders_open, etc.
 *   tg_project_handle_message()       — приём суммы при создании ордера
 *   kanyon_handle_paid()              — кнопка «Оплачено» под чеком
 *   kanyon_handle_cancel()            — кнопка «Отмена» под чеком
 *
 * Состояния хранятся в WP-транзиентах: tg_order_state_{chat_id}
 * Структура: [ 'step' => 'waiting_amount', 'amount_mode' => 'usdt|rub', 'payment_purpose' => '...' ]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── State helpers ─────────────────────────────────────────────────────────────

function _tg_orders_set_state( string $chat_id, array $state ): void {
	set_transient( 'tg_order_state_' . $chat_id, $state, 10 * MINUTE_IN_SECONDS );
}

function _tg_orders_get_state( string $chat_id ): ?array {
	$v = get_transient( 'tg_order_state_' . $chat_id );
	return is_array( $v ) ? $v : null;
}

function _tg_orders_clear_state( string $chat_id ): void {
	delete_transient( 'tg_order_state_' . $chat_id );
}

function _tg_orders_runtime_context(): array {
	$company_id = function_exists( 'crm_telegram_get_callback_company_id' )
		? (int) crm_telegram_get_callback_company_id()
		: 0;

	$bot_context = function_exists( 'crm_telegram_get_callback_bot_context' )
		? (string) crm_telegram_get_callback_bot_context()
		: 'merchant';

	$source_map = [
		'merchant' => 'telegram_merchant',
		'operator' => 'telegram_operator',
		'service'  => 'telegram_service',
	];

	return [
		'company_id'     => $company_id,
		'bot_context'    => $bot_context,
		'source_channel' => $source_map[ $bot_context ] ?? 'telegram',
	];
}

function _tg_orders_company_input_mode( int $company_id ): string {
	if ( $company_id <= 0 || ! function_exists( 'crm_fintech_company_create_order_input_mode' ) ) {
		return 'usdt';
	}

	$mode = (string) crm_fintech_company_create_order_input_mode( $company_id );

	return $mode === 'rub' ? 'rub' : 'usdt';
}

function _tg_orders_default_payment_purpose( int $company_id ): string {
	if ( $company_id <= 0 || ! function_exists( 'crm_fintech_get_pay2day_default_payment_purpose' ) ) {
		return '';
	}

	return crm_fintech_get_pay2day_default_payment_purpose( $company_id );
}

function _tg_orders_waiting_amount_prompt( array $state ): string {
	$amount_mode      = (string) ( $state['amount_mode'] ?? 'usdt' );
	$payment_purpose  = function_exists( 'crm_fintech_normalize_payment_purpose' )
		? crm_fintech_normalize_payment_purpose( (string) ( $state['payment_purpose'] ?? '' ) )
		: sanitize_text_field( (string) ( $state['payment_purpose'] ?? '' ) );
	$is_rub_mode      = $amount_mode === 'rub';
	$amount_currency  = $is_rub_mode ? 'RUB' : 'USDT';
	$amount_example   = $is_rub_mode ? '30000' : '150.50';
	$calculation_hint = $is_rub_mode
		? "Сумма к оплате вводится в RUB.\nИтоговая сумма в USDT будет рассчитана провайдером."
		: "Сумма ордера вводится в USDT.\nКонвертация в RUB производится провайдером.";

	$text  = "💰 <b>Новый ордер</b>\n\n";
	$text .= $calculation_hint . "\n\n";
	$text .= 'Введите сумму в ' . $amount_currency . ":\n";
	$text .= '<i>Например: ' . $amount_example . '</i>';

	if ( $payment_purpose !== '' ) {
		$text .= "\n\n📝 <b>Назначение платежа</b>\n";
		$text .= '<code>' . htmlspecialchars( $payment_purpose, ENT_QUOTES ) . '</code>';
	}

	return $text;
}

// ─── QR photo sending ─────────────────────────────────────────────────────────

/**
 * Sends a photo to Telegram by URL using the Telegram class.
 * Falls back to sendMessage with the URL as text if photo fails.
 *
 * @return array{ok:bool,response:array,message_type:string}
 */
function _tg_orders_send_photo( $telegram, string $chat_id, string $photo_url, string $caption = '', ?array $keyboard = null ): array {
	$result = [
		'ok'           => false,
		'response'     => [],
		'message_type' => 'text',
	];

	if ( function_exists( 'crm_merchant_tg_send_photo' ) ) {
		$response = crm_merchant_tg_send_photo( $telegram, $chat_id, $photo_url, $caption, $keyboard );
		if ( ! empty( $response['ok'] ) ) {
			$result['ok']           = true;
			$result['response']     = $response;
			$result['message_type'] = 'photo';
			return $result;
		}
	} else {
		$params = [
			'chat_id'    => $chat_id,
			'photo'      => $photo_url,
			'parse_mode' => 'HTML',
		];
		if ( $caption !== '' ) {
			$params['caption'] = $caption;
		}
		if ( $keyboard !== null ) {
			$params['reply_markup'] = wp_json_encode( $keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$response = (array) $telegram->sendPhoto( $params );
		if ( ! empty( $response['ok'] ) ) {
			$result['ok']           = true;
			$result['response']     = $response;
			$result['message_type'] = 'photo';
			return $result;
		}
	}

	$fallback_text = $caption . "\n\n<a href=\"" . esc_url( $photo_url ) . "\">QR-код</a>";
	if ( function_exists( 'crm_merchant_tg_send_message' ) ) {
		$fallback = crm_merchant_tg_send_message( $telegram, $chat_id, $fallback_text, $keyboard );
		$result['ok']       = ! empty( $fallback['ok'] );
		$result['response'] = $fallback;
		return $result;
	}

	if ( function_exists( 'bot_send_message' ) ) {
		bot_send_message( $telegram, $chat_id, $fallback_text, $keyboard );
	}

	return $result;
}

// ─── Order summary message (receipt) ─────────────────────────────────────────

/**
 * Форматирует сумму в RUB в русской локали: «8 238,00 ₽».
 */
function _tg_orders_format_rub( float $amount ): string {
	return number_format( $amount, 2, ',', "\xc2\xa0" ) . "\xc2\xa0₽";
}

/**
 * Текст чека — caption к QR-фото или обычное сообщение.
 */
function _tg_orders_success_message( array $result ): string {
	$amount_usdt      = isset( $result['amount_usdt'] ) ? (float) $result['amount_usdt'] : 0.0;
	$payment_rub      = isset( $result['payment_amount_rub'] ) ? (float) $result['payment_amount_rub'] : 0.0;
	$order_db_id      = isset( $result['order_db_id'] ) ? (int) $result['order_db_id'] : 0;
	$receipt_id       = $order_db_id > 0 ? '#' . $order_db_id : (string) ( $result['merchant_order_id'] ?? '—' );
	$rate_value       = ( $amount_usdt > 0 && $payment_rub > 0 ) ? round( $payment_rub / $amount_usdt, 4 ) : 0.0;
	$payment_purpose = trim( (string) ( $result['payment_purpose'] ?? '' ) );
	$invoice_mode    = sanitize_key( (string) ( $result['operator_invoice_mode'] ?? '' ) );
	$warning         = trim( (string) ( $result['warning'] ?? '' ) );

	$text = crm_tg_receipt_block(
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
				'value' => $amount_usdt > 0 ? crm_tg_receipt_format_amount( $amount_usdt, 'USDT', 2, true ) : '—',
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

	if ( ! empty( $result['payment_link'] ) ) {
		$text .= "\n\nPayment link:\n<code>" . htmlspecialchars( (string) $result['payment_link'], ENT_QUOTES ) . '</code>';
	}

	if ( $payment_purpose !== '' ) {
		$text .= "\n\nPayment purpose:\n<code>" . htmlspecialchars( $payment_purpose, ENT_QUOTES ) . '</code>';
	}

	if ( $invoice_mode !== '' && function_exists( 'crm_fintech_create_order_mode_label' ) ) {
		$text .= "\n\nContour:\n<code>" . htmlspecialchars( crm_fintech_create_order_mode_label( $invoice_mode ), ENT_QUOTES ) . '</code>';
	}

	if ( ! empty( $result['target_amount_thb'] ) ) {
		$thb_rate = isset( $result['thb_rate'] ) ? (float) $result['thb_rate'] : 0.0;
		$text    .= "\n\nTHB context:\n<code>"
			. htmlspecialchars( crm_tg_receipt_format_amount( (float) $result['target_amount_thb'], 'THB', 2, false ), ENT_QUOTES )
			. '</code>';
		if ( $thb_rate > 0 ) {
			$text .= ' @ <code>' . htmlspecialchars( crm_tg_receipt_format_number( $thb_rate, 4, false ), ENT_QUOTES ) . ' RUB/THB</code>';
		}
	}

	if ( $warning !== '' ) {
		$text .= "\n\n⚠️ " . htmlspecialchars( $warning, ENT_QUOTES );
	}

	return $text;
}

// ─── Order list helpers ───────────────────────────────────────────────────────

function _tg_orders_list_message( array $statuses, string $title ): string {
	global $wpdb;

	$runtime          = _tg_orders_runtime_context();
	$company_id       = (int) ( $runtime['company_id'] ?? 0 );
	$bot_context      = (string) ( $runtime['bot_context'] ?? 'merchant' );
	$created_for_type = $bot_context === 'merchant' ? 'merchant' : 'company';

	if ( $company_id <= 0 ) {
		return $title . "\n\nКонтур бота не привязан к компании.";
	}

	$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
	$params       = array_merge( [ $company_id, $created_for_type ], $statuses );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, merchant_order_id, amount_asset_value, amount_asset_code, payment_amount_value, payment_currency_code, status_code, provider_code, created_at
			 FROM crm_fintech_payment_orders
			 WHERE company_id = %d
			   AND created_for_type = %s
			   AND status_code IN ($placeholders)
			 ORDER BY id DESC LIMIT 15",
			$params
		)
	);
	// phpcs:enable

	if ( empty( $rows ) ) {
		return $title . "\n\nНичего не найдено.";
	}

	$e   = "\n";
	$txt = $title . $e . $e;

	foreach ( $rows as $row ) {
		$rub    = $row->payment_amount_value
			? _tg_orders_format_rub( (float) $row->payment_amount_value )
			: '—';
		$date   = $row->created_at ? substr( $row->created_at, 0, 10 ) : '—';
		$status = strtoupper( $row->status_code );

		$txt .= "#{$row->id} · {$date}" . $e;
		$txt .= '<code>' . htmlspecialchars( $row->merchant_order_id, ENT_QUOTES ) . '</code>' . $e;
		$txt .= $rub . ' · [' . $status . ']' . $e;
		$txt .= $e;
	}

	return trim( $txt );
}

// ─── Status check helper ──────────────────────────────────────────────────────

/**
 * Загружает ордер из БД по DB ID, проверяет статус у провайдера, отправляет ответ в чат.
 * Используется в kanyon_handle_paid и kanyon_handle_cancel.
 *
 * @param string $db_id     Числовой DB ID ордера (из callback_data)
 * @param string $chat_id   Telegram chat_id
 * @param object $telegram  Telegram instance
 * @param string $intent    'paid' | 'cancel'
 * @param array  $ctx       Telegram callback context
 */
function _tg_orders_check_status( string $db_id, string $chat_id, $telegram, string $intent, array $ctx = [] ): void {
	global $wpdb;

	$runtime          = _tg_orders_runtime_context();
	$company_id       = (int) ( $runtime['company_id'] ?? 0 );
	$bot_context      = (string) ( $runtime['bot_context'] ?? 'merchant' );
	$chat_id          = crm_telegram_sanitize_chat_id_value( $chat_id );
	$created_for_type = $bot_context === 'merchant' ? 'merchant' : 'company';
	$poll_source      = 'telegram_manual';
	if ( $bot_context === 'merchant' ) {
		$poll_source = 'telegram_merchant_manual';
	} elseif ( $bot_context === 'operator' ) {
		$poll_source = 'telegram_operator_manual';
	} elseif ( $bot_context === 'service' ) {
		$poll_source = 'telegram_service_manual';
	}
	$order_db_id = (int) $db_id;
	if ( $order_db_id <= 0 ) {
		bot_send_message( $telegram, $chat_id, '⚠️ Неверный ID ордера.' );
		return;
	}

	if ( $company_id <= 0 ) {
		bot_send_message( $telegram, $chat_id, '⚠️ Бот не привязан к компании. Проверьте webhook компании.' );
		return;
	}

	$query  = 'SELECT * FROM `crm_fintech_payment_orders` WHERE `id` = %d AND `company_id` = %d AND `created_for_type` = %s';
	$params = [ $order_db_id, $company_id, $created_for_type ];

	if ( $bot_context === 'operator' ) {
		$query   .= ' AND `source_channel` = %s';
		$params[] = 'telegram_operator';

		if ( $chat_id !== '' && function_exists( 'crm_operator_tg_chat_order_like' ) ) {
			$query   .= ' AND `meta_json` LIKE %s';
			$params[] = crm_operator_tg_chat_order_like( $chat_id );
		}
	}

	$order = $wpdb->get_row( $wpdb->prepare( $query, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( ! $order ) {
		bot_send_message( $telegram, $chat_id, '⚠️ Ордер #' . $order_db_id . ' не найден.' );
		return;
	}

	$new_order_callback = $bot_context === 'operator' ? 'o:invoice' : 'orders_new';
	$menu_callback      = $bot_context === 'operator' ? 'o:main' : 'menu_main';
	$list_callback      = $bot_context === 'operator' ? 'o:orders' : $new_order_callback;
	$list_label         = $bot_context === 'operator' ? '📂 Мои ордера' : '🆕 Новый ордер';

	$current_status = (string) $order->status_code;
	$terminal       = [ 'paid', 'declined', 'cancelled', 'expired', 'error' ];

	// Для отмены открытого ордера — нет API, сообщаем об этом
	if ( $intent === 'cancel' && ! in_array( $current_status, $terminal, true ) ) {
		bot_send_message( $telegram, $chat_id,
			'ℹ️ Ручная отмена через API недоступна.' . "\n"
			. 'Ордер истечёт автоматически по таймауту провайдера.' . "\n\n"
			. '📋 Заказ: <code>' . htmlspecialchars( $order->merchant_order_id, ENT_QUOTES ) . '</code>' . "\n"
			. '🔄 Текущий статус: ' . strtoupper( $current_status )
		);
		return;
	}

	// Запрашиваем актуальный статус у провайдера
	$poll = crm_fintech_poll_order_status( $order, $poll_source );

	$new_status = $poll['new_status'];
	$e          = "\n";

	if ( $poll['error'] ) {
		bot_send_message( $telegram, $chat_id,
			'⚠️ Не удалось получить статус от провайдера.' . $e . '<code>' . htmlspecialchars( $poll['error'], ENT_QUOTES ) . '</code>'
		);
		return;
	}

	$status_actions = is_array( $poll['status_actions'] ?? null ) ? $poll['status_actions'] : [];
	$merchant_tg    = is_array( $status_actions['merchant_telegram'] ?? null ) ? $status_actions['merchant_telegram'] : [];
	$operator_tg    = is_array( $status_actions['operator_telegram'] ?? null ) ? $status_actions['operator_telegram'] : [];
	$merchant_sync_visible = $bot_context === 'merchant'
		&& (
			! empty( $merchant_tg['receipt_updated'] )
			|| ! empty( $merchant_tg['receipt_replaced'] )
			|| ! empty( $merchant_tg['notification_sent'] )
		);
	$operator_sync_visible = $bot_context === 'operator'
		&& (
			! empty( $operator_tg['receipt_updated'] )
			|| ! empty( $operator_tg['receipt_replaced'] )
		);

	if ( $merchant_sync_visible && in_array( $new_status, [ 'paid', 'declined', 'cancelled', 'expired', 'error' ], true ) ) {
		return;
	}
	if ( $operator_sync_visible && in_array( $new_status, [ 'paid', 'declined', 'cancelled', 'expired', 'error' ], true ) ) {
		if ( $bot_context === 'operator' && function_exists( 'tg_safe_answer_callback' ) ) {
			$labels = [
				'paid'      => 'Оплата подтверждена',
				'declined'  => 'Ордер отклонён',
				'cancelled' => 'Ордер отменён',
				'expired'   => 'Ордер истёк',
				'error'     => 'Ордер завершён с ошибкой',
			];
			tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? null, $labels[ $new_status ] ?? 'Статус обновлён' );
		}
		return;
	}

	if ( $new_status === 'paid' ) {
		$rub = $order->payment_amount_value !== null
			? _tg_orders_format_rub( (float) $order->payment_amount_value )
			: '—';
		$txt  = '✅ <b>Платёж подтверждён!</b>' . $e . $e;
		$txt .= '<b>' . $rub . '</b>' . $e . $e;
		$txt .= '📋 Заказ: <code>' . htmlspecialchars( $order->merchant_order_id, ENT_QUOTES ) . '</code>';
		$keyboard = [
			'inline_keyboard' => [
				[
					[ 'text' => $list_label, 'callback_data' => $list_callback ],
					[ 'text' => '↩️ Меню', 'callback_data' => $menu_callback ],
				],
			],
		];
		bot_send_message( $telegram, $chat_id, $txt, $keyboard );
		return;
	}

	if ( in_array( $new_status, [ 'declined', 'cancelled', 'expired', 'error' ], true ) ) {
		$label_map = [
			'declined'  => '❌ Ордер отклонён',
			'cancelled' => '🚫 Ордер отменён',
			'expired'   => '⏰ Ордер истёк',
			'error'     => '⛔ Ошибка ордера',
		];
		$label = $label_map[ $new_status ] ?? '⚠️ Ордер завершён';
		$txt   = $label . $e . '📋 Заказ: <code>' . htmlspecialchars( $order->merchant_order_id, ENT_QUOTES ) . '</code>';
		$keyboard = [
			'inline_keyboard' => [
				[
					[ 'text' => $list_label, 'callback_data' => $list_callback ],
					[ 'text' => '↩️ Меню', 'callback_data' => $menu_callback ],
				],
			],
		];
		bot_send_message( $telegram, $chat_id, $txt, $keyboard );
		return;
	}

	// Ещё открыт
	if ( $bot_context === 'operator' && function_exists( 'tg_safe_answer_callback' ) ) {
		tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? null, 'Оплата ещё не поступила' );
		return;
	}

	$keyboard = [
		'inline_keyboard' => [
			[
				[ 'text' => '🔄 Проверить ещё раз', 'callback_data' => 'kanyon_paid:' . $order_db_id ],
				[ 'text' => '↩️ Меню', 'callback_data' => $menu_callback ],
			],
		],
	];
	bot_send_message( $telegram, $chat_id,
		'⏳ Оплата ещё не поступила.' . $e
		. '📋 Заказ: <code>' . htmlspecialchars( $order->merchant_order_id, ENT_QUOTES ) . '</code>',
		$keyboard
	);
}

// ─── kanyon adapter hooks (роутятся universal-handler'ом) ─────────────────────

if ( ! function_exists( 'kanyon_handle_paid' ) ) {
function kanyon_handle_paid( string $db_id, array $ctx, $telegram ): bool {
	$chat_id = $ctx['chat_id'] ?? '';
	if ( ! $chat_id ) {
		return false;
	}
	_tg_orders_check_status( $db_id, $chat_id, $telegram, 'paid', $ctx );
	return true;
}
}

if ( ! function_exists( 'kanyon_handle_cancel' ) ) {
function kanyon_handle_cancel( string $db_id, array $ctx, $telegram ): bool {
	$chat_id = $ctx['chat_id'] ?? '';
	if ( ! $chat_id ) {
		return false;
	}
	_tg_orders_check_status( $db_id, $chat_id, $telegram, 'cancel', $ctx );
	return true;
}
}

// ─── Project hook: orders actions ─────────────────────────────────────────────

if ( ! function_exists( 'tg_project_handle_orders_action' ) ) {
	function tg_project_handle_orders_action( string $callback_data, array $ctx, $telegram, array $data ): bool {
		$chat_id  = $ctx['chat_id']  ?? '';
		$actor_id = $ctx['actor_id'] ?? null;
		$runtime  = _tg_orders_runtime_context();
		$company_id = (int) ( $runtime['company_id'] ?? 0 );
		$bot_context = (string) ( $runtime['bot_context'] ?? 'merchant' );

		if ( ! $chat_id ) {
			return false;
		}

		if ( $callback_data === 'orders_new' ) {
			if ( $company_id <= 0 ) {
				bot_send_message( $telegram, $chat_id, '⚠️ Бот не привязан к компании. Проверьте webhook компании.' );
				return true;
			}

			if ( $bot_context !== 'operator' ) {
				bot_send_message( $telegram, $chat_id, '⚠️ Этот Telegram-контур не использует legacy-создание ордеров.' );
				return true;
			}

			$state = [
				'step'            => 'waiting_amount',
				'amount_mode'     => _tg_orders_company_input_mode( $company_id ),
				'payment_purpose' => _tg_orders_default_payment_purpose( $company_id ),
			];

			_tg_orders_set_state( $chat_id, $state );

			$keyboard = [
				'inline_keyboard' => $bot_context === 'operator'
					? [
						[
							[ 'text' => '↩️ Меню', 'callback_data' => 'o:main' ],
						],
					]
					: [
						[
							[ 'text' => '❌ Отмена', 'callback_data' => 'orders_cancel_new' ],
						],
					],
			];

			bot_send_message( $telegram, $chat_id, _tg_orders_waiting_amount_prompt( $state ), $keyboard );

			return true;
		}

		if ( $callback_data === 'orders_cancel_new' ) {
			_tg_orders_clear_state( $chat_id );
			bot_send_message( $telegram, $chat_id, '❌ Создание ордера отменено.' );
			if ( $bot_context === 'operator' && function_exists( 'crm_operator_tg_access_context' ) && function_exists( 'crm_operator_tg_present_screen' ) ) {
				$access = crm_operator_tg_access_context( $company_id, $chat_id );
				if ( ! empty( $access['allowed'] ) && ! empty( $access['account'] ) ) {
					crm_operator_tg_present_screen( $telegram, $company_id, $chat_id, $access['account'], $ctx, 'main', true );
					return true;
				}
			}
			if ( function_exists( 'fifo_bot_menu' ) ) {
				fifo_bot_menu( $telegram, $chat_id, $actor_id );
			}
			return true;
		}

		if ( $callback_data === 'orders_refresh_rate' ) {
			bot_send_message( $telegram, $chat_id,
				'ℹ️ Курс RUB/USDT определяется провайдером в момент создания ордера.'
			);
			return true;
		}

		if ( $callback_data === 'orders_open' ) {
			$msg = _tg_orders_list_message( [ 'created', 'pending' ], '📂 <b>Открытые ордера (последние 15)</b>' );
			tg_send_message_chunks( $telegram, $chat_id, $msg );
			return true;
		}

		if ( $callback_data === 'orders_closed' ) {
			$msg = _tg_orders_list_message( [ 'paid' ], '✅ <b>Закрытые ордера (последние 15)</b>' );
			tg_send_message_chunks( $telegram, $chat_id, $msg );
			return true;
		}

		if ( $callback_data === 'orders_canceled' ) {
			$msg = _tg_orders_list_message( [ 'declined', 'cancelled', 'expired', 'error' ], '❌ <b>Отменённые/отклонённые ордера (последние 15)</b>' );
			tg_send_message_chunks( $telegram, $chat_id, $msg );
			return true;
		}

		return false;
	}
}

// ─── Project hook: text messages ──────────────────────────────────────────────

if ( ! function_exists( 'tg_project_handle_message' ) ) {
	function tg_project_handle_message( string $text, array $ctx, $telegram, array $data ): bool {
		$chat_id  = $ctx['chat_id']  ?? '';
		$actor_id = $ctx['actor_id'] ?? null;
		$runtime  = _tg_orders_runtime_context();
		$company_id = (int) ( $runtime['company_id'] ?? 0 );
		$bot_context = (string) ( $runtime['bot_context'] ?? 'merchant' );
		$source_channel = (string) ( $runtime['source_channel'] ?? 'telegram' );

		if ( ! $chat_id ) {
			return false;
		}

		$state = _tg_orders_get_state( $chat_id );

		if ( ! $state || ( $state['step'] ?? '' ) !== 'waiting_amount' ) {
			return false; // no state — let universal handle it
		}

		$amount_mode     = (string) ( $state['amount_mode'] ?? 'usdt' );
		$payment_purpose = function_exists( 'crm_fintech_normalize_payment_purpose' )
			? crm_fintech_normalize_payment_purpose( (string) ( $state['payment_purpose'] ?? '' ) )
			: sanitize_text_field( (string) ( $state['payment_purpose'] ?? '' ) );

		// Parse amount
		$cleaned = trim( str_replace( ',', '.', $text ) );
		$amount  = filter_var( $cleaned, FILTER_VALIDATE_FLOAT );

		if ( $amount === false || $amount <= 0 ) {
			bot_send_message( $telegram, $chat_id,
				$amount_mode === 'rub'
					? '⚠️ Некорректная сумма. Введите положительное число в RUB, например <code>30000</code>.'
					: '⚠️ Некорректная сумма. Введите положительное число в USDT, например <code>150.50</code>.'
			);
			return true; // handled (bad input)
		}

		if ( $company_id <= 0 ) {
			_tg_orders_clear_state( $chat_id );
			bot_send_message( $telegram, $chat_id, '❌ Бот не привязан к компании. Проверьте webhook компании.' );
			return true;
		}

		if ( $bot_context !== 'operator' ) {
			_tg_orders_clear_state( $chat_id );
			bot_send_message( $telegram, $chat_id, '❌ Этот Telegram-контур пока не поддерживает legacy-создание ордеров.' );
			return true;
		}

		_tg_orders_clear_state( $chat_id );

		bot_send_message( $telegram, $chat_id, '⏳ Создаём ордер…' );

		$description = $payment_purpose !== '' ? $payment_purpose : 'Telegram bot order';

		if ( $amount_mode === 'rub' ) {
			$result = crm_fintech_create_order_by_payment_amount(
				$amount,
				'RUB',
				$company_id,
				$source_channel,
				null,
				$description
			);
		} else {
			$result = crm_fintech_create_order(
				$amount,
				$company_id,
				$source_channel,
				null,        // no WP user in bot context
				$description
			);
		}

		if ( empty( $result['success'] ) ) {
			$new_order_callback = $bot_context === 'operator' ? 'o:invoice' : 'orders_new';
			$menu_callback      = $bot_context === 'operator' ? 'o:main' : 'menu_main';
			$keyboard = [
				'inline_keyboard' => [
					[
						[ 'text' => '🔁 Попробовать снова', 'callback_data' => $new_order_callback ],
						[ 'text' => '↩️ Меню', 'callback_data' => $menu_callback ],
					],
				],
			];
			bot_send_message( $telegram, $chat_id,
				'❌ Ошибка создания ордера: ' . htmlspecialchars( $result['error'] ?? 'unknown', ENT_QUOTES ),
				$keyboard
			);
			return true;
		}

		$result['payment_purpose'] = $payment_purpose;

		// Сохраняем tg_chat_id в meta_json ордера — нужно для cron-уведомлений
		$order_db_id = (int) ( $result['order_db_id'] ?? 0 );
		if ( $order_db_id > 0 ) {
			global $wpdb;
			$raw_meta = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'SELECT meta_json FROM `crm_fintech_payment_orders` WHERE id = %d',
				$order_db_id
			) );
			$meta                    = ( $raw_meta && $raw_meta !== 'null' ) ? (array) json_decode( $raw_meta, true ) : [];
			$meta['tg_chat_id']      = $chat_id;
			$meta['tg_bot_context']  = $bot_context;
			$meta['tg_company_id']   = $company_id;
			$meta['tg_source_channel'] = $source_channel;
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'crm_fintech_payment_orders',
				[ 'meta_json' => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) ],
				[ 'id' => $order_db_id ]
			);
		}

		// Кнопки: Оплачено / Отмена + навигация
		$keyboard_rows = [
			[
				[ 'text' => '✅ Проверить оплату', 'callback_data' => 'kanyon_paid:' . $order_db_id ],
			],
			[
				[ 'text' => '🆕 Новый ордер', 'callback_data' => $bot_context === 'operator' ? 'o:invoice' : 'orders_new' ],
				[ 'text' => '↩️ Меню',        'callback_data' => $bot_context === 'operator' ? 'o:main' : 'menu_main'  ],
			],
		];

		if ( $bot_context !== 'operator' ) {
			array_unshift(
				$keyboard_rows,
				[
					[ 'text' => '✅ Проверить оплату', 'callback_data' => 'kanyon_paid:' . $order_db_id ],
					[ 'text' => '❌ Отмена', 'callback_data' => 'kanyon_cancel:' . $order_db_id ],
				]
			);
			unset( $keyboard_rows[1] );
		}

		$keyboard = [
			'inline_keyboard' => array_values( $keyboard_rows ),
		];

		// Отправляем чек (QR + caption или текст)
		if ( ! empty( $result['qr_url'] ) ) {
			_tg_orders_send_photo( $telegram, $chat_id, $result['qr_url'], _tg_orders_success_message( $result ), $keyboard );
		} else {
			bot_send_message( $telegram, $chat_id, _tg_orders_success_message( $result ), $keyboard );
		}

		return true;
	}
}
