<?php
/**
 * Malibu Exchange — Fintech Order Polling Cron
 *
 * Каждые 5 минут проверяет статус открытых ордеров у провайдера.
 * Если ордер оплачен и создан через Telegram — отправляет уведомление в чат.
 *
 * Зависимости (загружаются раньше через functions.php):
 *   crm_fintech_poll_order_status()  — inc/fintech-orders.php
 *   _tg_orders_format_rub()          — inc/telegram-orders-handler.php
 *   crm_get_setting()                — inc/settings.php
 *   crm_log()                        — inc/audit-log.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Кастомный интервал ───────────────────────────────────────────────────────

add_filter( 'cron_schedules', 'crm_fintech_add_cron_schedules' );
function crm_fintech_add_cron_schedules( array $schedules ): array {
	if ( ! isset( $schedules['every_five_minutes'] ) ) {
		$schedules['every_five_minutes'] = [
			'interval' => 300,
			'display'  => 'Every 5 Minutes',
		];
	}
	return $schedules;
}

// ─── Регистрация события ──────────────────────────────────────────────────────

add_action( 'init', 'crm_fintech_schedule_cron' );
function crm_fintech_schedule_cron(): void {
	if ( ! wp_next_scheduled( 'malibu_fintech_poll_orders' ) ) {
		wp_schedule_event( time(), 'every_five_minutes', 'malibu_fintech_poll_orders' );
	}
}

// ─── Cron-колбэк ─────────────────────────────────────────────────────────────

add_action( 'malibu_fintech_poll_orders', 'crm_fintech_cron_poll_orders' );
function crm_fintech_cron_poll_orders(): void {
	global $wpdb;

	// Только открытые ордера, созданные за последние 48 часов
	$orders = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT * FROM `crm_fintech_payment_orders`
		 WHERE `status_code` IN ('created', 'pending')
		   AND (`source_channel` IS NULL OR `source_channel` <> 'rate_check')
		   AND `created_at` >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
		 ORDER BY `id` ASC
		 LIMIT 50"
	);

	if ( empty( $orders ) ) {
		return;
	}

	crm_log( 'payment.cron.start', [
		'category'   => 'payments',
		'level'      => 'info',
		'action'     => 'cron',
		'message'    => 'Cron: проверяем ' . count( $orders ) . ' открытых ордеров',
		'is_success' => true,
		'context'    => [ 'count' => count( $orders ) ],
	] );

	$notified = 0;
	$updated  = 0;

	foreach ( $orders as $order ) {
		$poll = crm_fintech_poll_order_status( $order );

		if ( $poll['changed'] ) {
			$updated++;

			if ( $poll['new_status'] === 'paid' ) {
				// Уведомляем в Telegram, если ордер создан через бот
				if ( crm_fintech_cron_notify_telegram( $order ) ) {
					$notified++;
				}
			}
		}

		// Небольшая пауза между запросами к провайдеру
		usleep( 250000 ); // 250ms
	}

	if ( $updated > 0 || $notified > 0 ) {
		crm_log( 'payment.cron.done', [
			'category'   => 'payments',
			'level'      => 'info',
			'action'     => 'cron',
			'message'    => "Cron завершён: обновлено {$updated}, уведомлений {$notified}",
			'is_success' => true,
			'context'    => [ 'updated' => $updated, 'notified' => $notified ],
		] );
	}
}

// ─── Telegram-уведомление об оплате ──────────────────────────────────────────

/**
 * Отправляет уведомление в Telegram-чат при подтверждении оплаты.
 * Возвращает true если уведомление успешно отправлено.
 */
function crm_fintech_cron_notify_telegram( object $order ): bool {
	if ( ( $order->source_channel ?? '' ) !== 'telegram' ) {
		return false;
	}

	// chat_id сохранён в meta_json при создании ордера через бот
	$chat_id = '';
	if ( ! empty( $order->meta_json ) && $order->meta_json !== 'null' ) {
		$meta    = json_decode( $order->meta_json, true );
		$chat_id = is_array( $meta ) ? (string) ( $meta['tg_chat_id'] ?? '' ) : '';
	}

	if ( $chat_id === '' ) {
		crm_log( 'payment.cron.notify_no_chat', [
			'category'    => 'payments',
			'level'       => 'warning',
			'action'      => 'cron_notify',
			'message'     => 'Нет tg_chat_id — уведомление не отправлено',
			'target_type' => 'payment_order',
			'target_id'   => (int) $order->id,
			'is_success'  => false,
			'context'     => [ 'merchant_order_id' => $order->merchant_order_id ],
		] );
		return false;
	}

	// Загружаем Telegram class если не загружен
	if ( ! class_exists( 'Telegram' ) ) {
		$tg_php = get_template_directory() . '/callbacks/telegram/Telegram.php';
		if ( is_file( $tg_php ) ) {
			require_once $tg_php;
		}
	}

	if ( ! class_exists( 'Telegram' ) ) {
		return false;
	}

	$company_id = (int) ( $order->company_id ?? 0 );
	if ( $company_id < 0 ) {
		return false;
	}

	$token = crm_get_setting( 'telegram_bot_token', $company_id, '' );
	if ( $token === '' ) {
		return false;
	}

	$telegram = new Telegram( $token );

	$rub = ( $order->payment_amount_value !== null && function_exists( '_tg_orders_format_rub' ) )
		? _tg_orders_format_rub( (float) $order->payment_amount_value )
		: '—';

	$e   = "\n";
	$txt = '✅ <b>Платёж получен!</b>' . $e . $e;
	$txt .= '<b>' . $rub . '</b>' . $e . $e;
	$txt .= '📋 Заказ: <code>' . htmlspecialchars( (string) $order->merchant_order_id, ENT_QUOTES ) . '</code>' . $e;
	$txt .= '📅 ' . date_i18n( 'd.m.Y H:i' );

	$keyboard = json_encode( [
		'inline_keyboard' => [
			[
				[ 'text' => '🆕 Новый ордер', 'callback_data' => 'orders_new' ],
				[ 'text' => '↩️ Меню',        'callback_data' => 'menu_main'  ],
			],
		],
	] );

	$result = $telegram->sendMessage( [
		'chat_id'      => $chat_id,
		'text'         => $txt,
		'parse_mode'   => 'HTML',
		'reply_markup' => $keyboard,
	] );

	$ok = is_array( $result ) && ! empty( $result['ok'] );

	crm_log( 'payment.cron.notified', [
		'category'    => 'payments',
		'level'       => $ok ? 'info' : 'warning',
		'action'      => 'cron_notify',
		'message'     => $ok ? 'Telegram-уведомление отправлено' : 'Не удалось отправить Telegram-уведомление',
		'target_type' => 'payment_order',
		'target_id'   => (int) $order->id,
		'is_success'  => $ok,
		'context'     => [
			'chat_id'           => $chat_id,
			'merchant_order_id' => $order->merchant_order_id,
		],
	] );

	return $ok;
}
