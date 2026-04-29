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
			'rates_thb',
			'rates_rub',
			'rates_usdt',
			'balances',
			'invoice',
			'invoice_thb',
			'invoice_rub',
			'invoice_usdt',
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

		switch ( $screen ) {
			case 'rates':
				return [
					'screen'   => 'rates',
					'text'     => "💱 <b>Курсы</b>\n\nЗдесь будет быстрый расчёт по вашим рабочим сценариям.\n\n• 🇹🇭 THB → ₽\n• 🇷🇺 RUB → счёт в рублях\n• ₮ USDT → ₽\n\nВ расчёте автоматически будет учитываться ваша наценка.",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '🇹🇭 THB → ₽', 'callback_data' => 'm:rates:thb' ],
								[ 'text' => '🇷🇺 RUB', 'callback_data' => 'm:rates:rub' ],
							],
							[
								[ 'text' => '₮ USDT → ₽', 'callback_data' => 'm:rates:usdt' ],
								[ 'text' => '🔄 Обновить', 'callback_data' => 'm:rates' ],
							],
							[
								[ 'text' => '↩️ Главное меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'rates_thb':
				return [
					'screen'   => 'rates_thb',
					'text'     => "🇹🇭 <b>THB → ₽</b>\n\nСледующим этапом здесь откроется pipeline расчёта курса из суммы в батах.\n\nМеню уже готово, теперь осталось подключить сам расчёт и выпуск счёта.",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩️ К курсам', 'callback_data' => 'm:rates' ],
								[ 'text' => '🏝️ Главное меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'rates_rub':
				return [
					'screen'   => 'rates_rub',
					'text'     => "🇷🇺 <b>RUB</b>\n\nЭтот экран подготовлен под сценарий, где вы начинаете расчёт от суммы в рублях.\n\nСледующим этапом подключим формулу и быстрый выпуск счёта.",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩️ К курсам', 'callback_data' => 'm:rates' ],
								[ 'text' => '🏝️ Главное меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'rates_usdt':
				return [
					'screen'   => 'rates_usdt',
					'text'     => "₮ <b>USDT → ₽</b>\n\nЭтот экран подготовлен под сценарий расчёта от суммы в USDT.\n\nСледующим этапом сюда добавим итоговый курс и выпуск счёта.",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩️ К курсам', 'callback_data' => 'm:rates' ],
								[ 'text' => '🏝️ Главное меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'balances':
				return [
					'screen'   => 'balances',
					'text'     => "💼 <b>Балансы</b>\n\n🎁 Бонусный счёт: <b>" . crm_merchant_tg_escape( crm_merchant_format_amount( $balance['bonus_balance'] ) ) . "</b>\n🤝 Реферальный счёт: <b>" . crm_merchant_tg_escape( crm_merchant_format_amount( $balance['referral_balance'] ) ) . "</b>\nΣ Всего: <b>" . crm_merchant_tg_escape( crm_merchant_format_amount( $balance['total_balance'] ) ) . "</b>\n\nЕсли начислений ещё не было, значения будут равны нулю.",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩️ Главное меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'invoice':
				return [
					'screen'   => 'invoice',
					'text'     => "🧾 <b>Выставить счёт</b>\n\nВыберите, от какой суммы начать расчёт счёта.\n\nМы уже подготовили три рабочих сценария:\n• 🇹🇭 из суммы в THB\n• 🇷🇺 из суммы в RUB\n• ₮ из суммы в USDT",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '🇹🇭 Ввести THB', 'callback_data' => 'm:invoice:thb' ],
								[ 'text' => '🇷🇺 Ввести RUB', 'callback_data' => 'm:invoice:rub' ],
							],
							[
								[ 'text' => '₮ Ввести USDT', 'callback_data' => 'm:invoice:usdt' ],
							],
							[
								[ 'text' => '↩️ Главное меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'invoice_thb':
				return [
					'screen'   => 'invoice_thb',
					'text'     => "🇹🇭 <b>Счёт из THB</b>\n\nСледующим этапом здесь откроется pipeline, где вы вводите сумму в батах, а система считает рублёвый счёт по курсу компании и вашей наценке.",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩️ К способам', 'callback_data' => 'm:invoice' ],
								[ 'text' => '🏝️ Главное меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'invoice_rub':
				return [
					'screen'   => 'invoice_rub',
					'text'     => "🇷🇺 <b>Счёт из RUB</b>\n\nСледующим этапом здесь откроется pipeline, где вы сразу задаёте сумму в рублях и выпускаете счёт без лишних шагов.",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩️ К способам', 'callback_data' => 'm:invoice' ],
								[ 'text' => '🏝️ Главное меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'invoice_usdt':
				return [
					'screen'   => 'invoice_usdt',
					'text'     => "₮ <b>Счёт из USDT</b>\n\nСледующим этапом здесь откроется pipeline, где вы задаёте сумму в USDT, а система рассчитывает рублёвый счёт автоматически.",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩️ К способам', 'callback_data' => 'm:invoice' ],
								[ 'text' => '🏝️ Главное меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'orders':
				return [
					'screen'   => 'orders',
					'text'     => "📂 <b>Мои счета</b>\n\n🟡 Активные: <b>{$order_counts['open']}</b>\n🟢 Оплаченные: <b>{$order_counts['paid']}</b>\n🔴 Отменённые: <b>{$order_counts['cancelled']}</b>\n\nДетальные списки подключим следующим этапом.",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '🟡 Активные', 'callback_data' => 'm:orders:open' ],
								[ 'text' => '🟢 Оплаченные', 'callback_data' => 'm:orders:paid' ],
							],
							[
								[ 'text' => '🔴 Отменённые', 'callback_data' => 'm:orders:cancelled' ],
							],
							[
								[ 'text' => '↩️ Главное меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'orders_open':
			case 'orders_paid':
			case 'orders_cancelled':
				$labels = [
					'orders_open'      => '🟡 Активные счета',
					'orders_paid'      => '🟢 Оплаченные счета',
					'orders_cancelled' => '🔴 Отменённые счета',
				];
				return [
					'screen'   => $screen,
					'text'     => $labels[ $screen ] . "\n\nЭтот список подключим следующим этапом. Экран уже зарезервирован под ваш отдельный pipeline.",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩️ К счетам', 'callback_data' => 'm:orders' ],
								[ 'text' => '🏝️ Главное меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'profile':
				return [
					'screen'   => 'profile',
					'text'     => "👤 <b>Профиль мерчанта</b>\n\n<b>{$display_name}</b>\nКомпания: <b>{$company_name}</b>\nОфис: <b>{$office_name}</b>\nChat ID: <code>" . crm_merchant_tg_escape( (string) $merchant->chat_id ) . "</code>\nUsername: <b>" . crm_merchant_tg_escape( ! empty( $merchant->telegram_username ) ? '@' . ltrim( (string) $merchant->telegram_username, '@' ) : '—' ) . "</b>\nСтатус: <b>" . crm_merchant_tg_escape( crm_merchant_statuses()[ (string) $merchant->status ] ?? (string) $merchant->status ) . "</b>\nНаценка: <b>{$markup_label}</b> · <b>{$markup_value}</b>",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩️ Главное меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'help':
				return [
					'screen'   => 'help',
					'text'     => "ℹ️ <b>Помощь</b>\n\nЧерез это меню вы сможете:\n• смотреть курсы\n• проверять бонусный и реферальный баланс\n• выставлять счета\n• отслеживать свои счета\n\nЕсли доступ не работает как ожидается, обратитесь к администратору вашей компании.",
					'keyboard' => [
						'inline_keyboard' => [
							[
								[ 'text' => '↩️ Главное меню', 'callback_data' => 'm:main' ],
							],
						],
					],
				];

			case 'main':
			default:
				return [
					'screen'   => 'main',
					'text'     => "🌴 <b>Malibu Merchant</b>\n\nПривет, {$display_name}!\nВаш кабинет мерчанта <b>{$company_name}</b> готов к работе.\n\nЗдесь можно быстро посмотреть курс, проверить балансы и перейти к созданию счёта.\n\nНажимайте <b>/start</b> в любой момент, чтобы открыть меню заново.",
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
			'm:main'           => 'main',
			'm:rates'          => 'rates',
			'm:rates:thb'      => 'rates_thb',
			'm:rates:rub'      => 'rates_rub',
			'm:rates:usdt'     => 'rates_usdt',
			'm:balances'       => 'balances',
			'm:invoice'        => 'invoice',
			'm:invoice:thb'    => 'invoice_thb',
			'm:invoice:rub'    => 'invoice_rub',
			'm:invoice:usdt'   => 'invoice_usdt',
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

		return crm_merchant_tg_present_screen( $telegram, $access['merchant'], $ctx, 'main', false, false );
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
