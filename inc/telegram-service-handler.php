<?php
/**
 * Malibu Exchange — Service Telegram bot handler
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'crm_service_tg_is_service_context' ) ) {
	function crm_service_tg_is_service_context(): bool {
		return function_exists( 'crm_telegram_get_callback_bot_context' )
			&& crm_telegram_get_callback_bot_context() === 'service';
	}
}

if ( ! function_exists( 'crm_service_tg_scope_failure_message' ) ) {
	function crm_service_tg_scope_failure_message(): string {
		return 'Сервисный бот настроен некорректно: отсутствует или недействителен company context. Обратитесь к администратору.';
	}
}

if ( ! function_exists( 'crm_service_tg_get_chat_binding' ) ) {
	function crm_service_tg_get_chat_binding( int $company_id, string $chat_id ): ?object {
		if ( $company_id <= 0 || $chat_id === '' ) {
			return null;
		}

		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.*,
				        a.id AS access_id,
				        a.status AS access_status,
				        a.granted_at AS access_granted_at,
				        a.last_seen_at AS access_last_seen_at,
				        a.last_invite_id AS access_last_invite_id,
				        a.revoked_at AS access_revoked_at
				 FROM crm_user_telegram_accounts p
				 LEFT JOIN crm_service_telegram_access a
				   ON a.company_id = p.company_id
				  AND a.user_id = p.user_id
				 WHERE p.company_id = %d
				   AND p.chat_id = %s
				   AND p.status = 'active'
				 LIMIT 1",
				$company_id,
				$chat_id
			)
		) ?: null;
	}
}

if ( ! function_exists( 'crm_service_tg_get_chat_access' ) ) {
	function crm_service_tg_get_chat_access( int $company_id, string $chat_id ): ?object {
		$binding = crm_service_tg_get_chat_binding( $company_id, $chat_id );
		if ( ! $binding ) {
			return null;
		}

		$user_id = (int) $binding->user_id;
		$check   = crm_service_telegram_validate_target_user( $user_id, $company_id );
		if ( empty( $check['ok'] ) ) {
			return null;
		}

		return $binding;
	}
}

if ( ! function_exists( 'crm_service_tg_has_active_chat_access' ) ) {
	function crm_service_tg_has_active_chat_access( int $company_id, string $chat_id ): bool {
		$binding = crm_service_tg_get_chat_access( $company_id, $chat_id );

		return $binding && (string) ( $binding->access_status ?? '' ) === 'active';
	}
}

if ( ! function_exists( 'crm_service_tg_touch_last_seen' ) ) {
	function crm_service_tg_touch_last_seen( int $company_id, int $user_id ): void {
		if ( $company_id <= 0 || $user_id <= 0 || crm_is_root( $user_id ) ) {
			return;
		}

		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->update(
			'crm_user_telegram_accounts',
			[ 'last_seen_at' => $now ],
			[
				'company_id' => $company_id,
				'user_id'    => $user_id,
			],
			[ '%s' ],
			[ '%d', '%d' ]
		);

		$wpdb->update(
			'crm_service_telegram_access',
			[ 'last_seen_at' => $now ],
			[
				'company_id' => $company_id,
				'user_id'    => $user_id,
			],
			[ '%s' ],
			[ '%d', '%d' ]
		);
	}
}

if ( ! function_exists( 'crm_service_tg_log_security' ) ) {
	function crm_service_tg_log_security( string $event_code, string $message, int $company_id = 0, array $context = [] ): void {
		crm_log_security(
			$event_code,
			'telegram_callback',
			$message,
			[
				'org_id'  => $company_id,
				'context' => $context,
				'source'  => 'webhook',
			]
		);
	}
}

if ( ! function_exists( 'crm_service_tg_send_scope_failure' ) ) {
	function crm_service_tg_send_scope_failure( $telegram, array $ctx ): void {
		$chat_id  = crm_telegram_sanitize_chat_id_value( $ctx['chat_id'] ?? '' );
		$actor_id = crm_telegram_sanitize_chat_id_value( $ctx['actor_id'] ?? '' );
		$target   = $chat_id !== '' ? $chat_id : $actor_id;

		if ( $target !== '' ) {
			bot_send_message( $telegram, $target, crm_service_tg_scope_failure_message() );
		}
	}
}

if ( ! function_exists( 'crm_service_tg_should_bypass_acl' ) ) {
	function crm_service_tg_should_bypass_acl( array $data, array $ctx = [] ): bool {
		if ( ! crm_service_tg_is_service_context() ) {
			return false;
		}

		$company_id = crm_telegram_get_callback_company_id();
		if ( $company_id <= 0 ) {
			return true;
		}

		$text = '';
		if ( isset( $data['message']['text'] ) ) {
			$text = (string) $data['message']['text'];
		}
		if ( strpos( trim( $text ), '/start' ) === 0 ) {
			return true;
		}

		$chat_id = isset( $ctx['chat_id'] ) ? crm_telegram_sanitize_chat_id_value( $ctx['chat_id'] ) : '';
		if ( $chat_id === '' && function_exists( 'tg_extract_ids' ) ) {
			$ids     = tg_extract_ids( $data );
			$chat_id = isset( $ids[0] ) ? crm_telegram_sanitize_chat_id_value( $ids[0] ) : '';
		}

		if ( $chat_id !== '' && crm_service_tg_has_active_chat_access( $company_id, $chat_id ) ) {
			return true;
		}

		if ( $chat_id !== '' ) {
			crm_service_tg_log_security(
				'service.telegram.access_denied',
				'Service Telegram callback denied: active access not found.',
				$company_id,
				[
					'chat_id'   => $chat_id,
					'actor_id'  => isset( $ctx['actor_id'] ) ? crm_telegram_sanitize_chat_id_value( $ctx['actor_id'] ) : '',
					'text'      => mb_substr( $text, 0, 200 ),
					'bot_context' => 'service',
				]
			);
		}

		return false;
	}
}

if ( ! function_exists( 'tg_project_handle_update' ) ) {
	function tg_project_handle_update( array $ctx, $telegram, array $data ): bool {
		if ( ! crm_service_tg_is_service_context() ) {
			return false;
		}

		$company_id = crm_telegram_get_callback_company_id();
		if ( $company_id > 0 ) {
			return false;
		}

		crm_service_tg_log_security(
			'service.telegram.invalid_company_scope',
			'Service Telegram callback blocked: missing or invalid company context.',
			0,
			[
				'chat_id'      => crm_telegram_sanitize_chat_id_value( $ctx['chat_id'] ?? '' ),
				'actor_id'     => crm_telegram_sanitize_chat_id_value( $ctx['actor_id'] ?? '' ),
				'callback_data'=> (string) ( $ctx['callback_data'] ?? '' ),
				'update_type'  => (string) ( $ctx['update_type'] ?? '' ),
			]
		);

		crm_service_tg_send_scope_failure( $telegram, $ctx );

		return true;
	}
}

if ( ! function_exists( 'crm_service_tg_menu_sections' ) ) {
	function crm_service_tg_menu_sections( int $user_id ): array {
		$sections = [];

		if ( crm_user_has_permission( $user_id, 'service.telegram.merchant_payouts' ) ) {
			$sections[] = [
				'code'  => 'merchant_payouts',
				'label' => 'Merchant payouts',
			];
		}
		if ( crm_user_has_permission( $user_id, 'service.telegram.acquirer_payouts' ) ) {
			$sections[] = [
				'code'  => 'acquirer_payouts',
				'label' => 'Acquirer payouts',
			];
		}
		if ( crm_user_has_permission( $user_id, 'service.telegram.orders' ) ) {
			$sections[] = [
				'code'  => 'orders',
				'label' => 'Orders',
			];
		}
		if ( crm_user_has_permission( $user_id, 'service.telegram.rates' ) ) {
			$sections[] = [
				'code'  => 'rates',
				'label' => 'Rates',
			];
		}

		return $sections;
	}
}

if ( ! function_exists( 'crm_service_tg_send_main_menu' ) ) {
	function crm_service_tg_send_main_menu( $telegram, string $chat_id, int $company_id, int $user_id ): void {
		$sections = crm_service_tg_menu_sections( $user_id );
		$lines    = [
			'✅ <b>Service bot активирован</b>',
			'Компания ID: <code>' . (int) $company_id . '</code>',
		];

		if ( empty( $sections ) ) {
			$lines[] = '';
			$lines[] = 'CRM-доступ выдан, но рабочие разделы для этого пользователя пока не разрешены.';
		} else {
			$lines[] = '';
			$lines[] = 'Доступные разделы:';
			foreach ( $sections as $section ) {
				$lines[] = '• ' . $section['label'];
			}
		}

		$keyboard_rows = [];
		foreach ( $sections as $section ) {
			$keyboard_rows[] = [
				[
					'text'          => $section['label'],
					'callback_data' => 'service_menu:' . $section['code'],
				],
			];
		}
		$keyboard_rows[] = [
			[
				'text'          => 'Help',
				'callback_data' => 'service_menu:help',
			],
		];

		bot_send_message(
			$telegram,
			$chat_id,
			implode( "\n", $lines ),
			[
				'inline_keyboard' => $keyboard_rows,
			]
		);
	}
}

if ( ! function_exists( 'crm_service_tg_order_status_label' ) ) {
	function crm_service_tg_order_status_label( string $status_code ): string {
		$map = [
			'created'   => 'Создан',
			'pending'   => 'Ожидает оплаты',
			'paid'      => 'Оплачен',
			'declined'  => 'Отклонён',
			'cancelled' => 'Отменён',
			'expired'   => 'Истёк',
			'error'     => 'Ошибка',
		];

		return $map[ $status_code ] ?? $status_code;
	}
}

if ( ! function_exists( 'crm_service_tg_send_orders_menu' ) ) {
	function crm_service_tg_send_orders_menu( $telegram, string $chat_id, int $company_id ): void {
		$text = implode(
			"\n",
			[
				'📦 <b>Orders</b>',
				'Компания ID: <code>' . $company_id . '</code>',
				'',
				'Здесь доступна ручная проверка всех открытых платёжных ордеров внутри текущей компании.',
				'Проверка идёт напрямую через провайдера и не затрагивает другие компании.',
			]
		);

		tg_send_message_chunks(
			$telegram,
			$chat_id,
			$text,
			'HTML',
			[
				'inline_keyboard' => [
					[
						[
							'text'          => '🔄 Проверить открытые платежи',
							'callback_data' => 'service_orders:check_open',
						],
					],
					[
						[
							'text'          => '↩️ Главное меню',
							'callback_data' => 'service_menu:main',
						],
					],
				],
			]
		);
	}
}

if ( ! function_exists( 'crm_service_tg_send_merchant_payouts_stub' ) ) {
	function crm_service_tg_send_merchant_payouts_stub( $telegram, string $chat_id, int $company_id ): void {
		$text = implode(
			"\n",
			[
				'🚨 <b>Merchant payouts</b>',
				'Компания ID: <code>' . $company_id . '</code>',
				'',
				'Контур выплат мерчантам в service bot ещё подключается поэтапно.',
				'',
				'Но важное ограничение уже фиксируем сейчас:',
				'если у мерчанта <b>0 USDT к выплате</b>, создать выплату нельзя.',
				'',
				'Сначала на основном payable balance мерчанта должны появиться начисления.',
				'В web-контуре это уже подсвечивается явным красным предупреждением.',
			]
		);

		tg_send_message_chunks(
			$telegram,
			$chat_id,
			$text,
			'HTML',
			[
				'inline_keyboard' => [
					[
						[
							'text'          => '↩️ Главное меню',
							'callback_data' => 'service_menu:main',
						],
					],
				],
			]
		);
	}
}

if ( ! function_exists( 'crm_service_tg_check_open_payment_orders' ) ) {
	function crm_service_tg_check_open_payment_orders( int $company_id, int $limit = 100 ): array {
		global $wpdb;

		$result = [
			'company_id' => $company_id,
			'limit'      => $limit,
			'found'      => 0,
			'checked'    => 0,
			'changed'    => 0,
			'paid'       => 0,
			'unchanged'  => 0,
			'errors'     => 0,
			'merchant_receipts_updated' => 0,
			'merchant_receipts_replaced' => 0,
			'merchant_notifications'    => 0,
			'merchant_action_errors'    => 0,
			'truncated'  => false,
			'changes'    => [],
			'error_rows' => [],
		];

		if ( $company_id <= 0 ) {
			return $result;
		}

		$limit = max( 1, min( 200, $limit ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT *
				 FROM crm_fintech_payment_orders
				 WHERE company_id = %d
				   AND status_code IN ('created', 'pending')
				   AND (source_channel IS NULL OR source_channel <> 'rate_check')
				 ORDER BY id ASC
				 LIMIT %d",
				$company_id,
				$limit + 1
			)
		) ?: [];

		$result['truncated'] = count( $rows ) > $limit;
		if ( $result['truncated'] ) {
			$rows = array_slice( $rows, 0, $limit );
		}

		$result['found'] = count( $rows );

		foreach ( $rows as $order ) {
			$poll = crm_fintech_poll_order_status( $order, 'service_bot' );

			$result['checked']++;

			if ( ! empty( $poll['error'] ) ) {
				$result['errors']++;
				$result['error_rows'][] = [
					'id'                => (int) ( $order->id ?? 0 ),
					'merchant_order_id' => (string) ( $order->merchant_order_id ?? '' ),
					'error'             => (string) $poll['error'],
				];
				continue;
			}

			if ( ! empty( $poll['changed'] ) ) {
				$result['changed']++;
				$merchant_receipt_updated = false;
				$merchant_receipt_replaced = false;
				$merchant_notified       = false;
				$merchant_errors         = [];
				if ( (string) ( $poll['new_status'] ?? '' ) === 'paid' ) {
					$result['paid']++;
				}

				$status_actions = is_array( $poll['status_actions'] ?? null ) ? $poll['status_actions'] : [];
				$merchant_tg    = is_array( $status_actions['merchant_telegram'] ?? null ) ? $status_actions['merchant_telegram'] : [];

				$merchant_receipt_updated  = ! empty( $merchant_tg['receipt_updated'] );
				$merchant_receipt_replaced = ! empty( $merchant_tg['receipt_replaced'] );
				$merchant_notified         = ! empty( $merchant_tg['notification_sent'] );
				$merchant_errors           = isset( $merchant_tg['errors'] ) && is_array( $merchant_tg['errors'] ) ? array_values( array_filter( $merchant_tg['errors'] ) ) : [];

				if ( $merchant_receipt_updated ) {
					$result['merchant_receipts_updated']++;
				}
				if ( $merchant_receipt_replaced ) {
					$result['merchant_receipts_replaced']++;
				}
				if ( $merchant_notified ) {
					$result['merchant_notifications']++;
				}
				if ( ! empty( $merchant_errors ) ) {
					$result['merchant_action_errors'] += count( $merchant_errors );
				}

				$result['changes'][] = [
					'id'                => (int) ( $order->id ?? 0 ),
					'merchant_order_id' => (string) ( $order->merchant_order_id ?? '' ),
					'old_status'        => (string) ( $poll['old_status'] ?? '' ),
					'new_status'        => (string) ( $poll['new_status'] ?? '' ),
					'merchant_receipt_updated' => $merchant_receipt_updated,
					'merchant_receipt_replaced' => $merchant_receipt_replaced,
					'merchant_notified'       => $merchant_notified,
					'merchant_errors'         => $merchant_errors,
				];
				continue;
			}

			$result['unchanged']++;
		}

		return $result;
	}
}

if ( ! function_exists( 'crm_service_tg_check_open_payment_orders_message' ) ) {
	function crm_service_tg_check_open_payment_orders_message( array $summary ): string {
		$lines = [
			'🔄 <b>Проверка открытых платежей завершена</b>',
			'Компания ID: <code>' . (int) ( $summary['company_id'] ?? 0 ) . '</code>',
			'',
			'Найдено открытых ордеров: <b>' . (int) ( $summary['found'] ?? 0 ) . '</b>',
		];

		if ( ! empty( $summary['truncated'] ) ) {
			$lines[] = 'Проверено только первые <b>' . (int) ( $summary['limit'] ?? 0 ) . '</b> ордеров за один запуск.';
		}

		$lines[] = 'Проверено: <b>' . (int) ( $summary['checked'] ?? 0 ) . '</b>';
		$lines[] = 'Изменили статус: <b>' . (int) ( $summary['changed'] ?? 0 ) . '</b>';
		$lines[] = 'Оплачено: <b>' . (int) ( $summary['paid'] ?? 0 ) . '</b>';
		$lines[] = 'Без изменений: <b>' . (int) ( $summary['unchanged'] ?? 0 ) . '</b>';
		$lines[] = 'Ошибок: <b>' . (int) ( $summary['errors'] ?? 0 ) . '</b>';
		$lines[] = 'Обновлено merchant-сообщений: <b>' . (int) ( $summary['merchant_receipts_updated'] ?? 0 ) . '</b>';
		$lines[] = 'Заменено merchant-сообщений: <b>' . (int) ( $summary['merchant_receipts_replaced'] ?? 0 ) . '</b>';
		$lines[] = 'Отправлено merchant-уведомлений: <b>' . (int) ( $summary['merchant_notifications'] ?? 0 ) . '</b>';
		$lines[] = 'Ошибок синхронизации merchant bot: <b>' . (int) ( $summary['merchant_action_errors'] ?? 0 ) . '</b>';

		$changes = isset( $summary['changes'] ) && is_array( $summary['changes'] ) ? $summary['changes'] : [];
		if ( ! empty( $changes ) ) {
			$lines[] = '';
			$lines[] = '<b>Изменения:</b>';

			foreach ( array_slice( $changes, 0, 10 ) as $change ) {
				$merchant_bits = [];
				if ( ! empty( $change['merchant_receipt_updated'] ) ) {
					$merchant_bits[] = 'merchant-сообщение обновлено';
				}
				if ( ! empty( $change['merchant_receipt_replaced'] ) ) {
					$merchant_bits[] = 'merchant-сообщение заменено';
				}
				if ( ! empty( $change['merchant_notified'] ) ) {
					$merchant_bits[] = 'merchant-уведомление отправлено';
				}
				if ( ! empty( $change['merchant_errors'] ) ) {
					$merchant_bits[] = 'ошибок синхронизации: ' . count( (array) $change['merchant_errors'] );
				}

				$line = '• #'
					. (int) ( $change['id'] ?? 0 )
					. ' <code>'
					. htmlspecialchars( (string) ( $change['merchant_order_id'] ?? '' ), ENT_QUOTES )
					. '</code>: '
					. crm_service_tg_order_status_label( (string) ( $change['old_status'] ?? '' ) )
					. ' → '
					. crm_service_tg_order_status_label( (string) ( $change['new_status'] ?? '' ) );

				if ( ! empty( $merchant_bits ) ) {
					$line .= ' [' . implode( ', ', $merchant_bits ) . ']';
				}

				$lines[] = $line;
			}

			if ( count( $changes ) > 10 ) {
				$lines[] = '… и ещё ' . ( count( $changes ) - 10 ) . ' изменений.';
			}
		}

		$error_rows = isset( $summary['error_rows'] ) && is_array( $summary['error_rows'] ) ? $summary['error_rows'] : [];
		if ( ! empty( $error_rows ) ) {
			$lines[] = '';
			$lines[] = '<b>Ошибки проверки:</b>';

			foreach ( array_slice( $error_rows, 0, 5 ) as $error_row ) {
				$lines[] = '• #'
					. (int) ( $error_row['id'] ?? 0 )
					. ' <code>'
					. htmlspecialchars( (string) ( $error_row['merchant_order_id'] ?? '' ), ENT_QUOTES )
					. '</code>: '
					. htmlspecialchars( (string) ( $error_row['error'] ?? '' ), ENT_QUOTES );
			}

			if ( count( $error_rows ) > 5 ) {
				$lines[] = '… и ещё ' . ( count( $error_rows ) - 5 ) . ' ошибок.';
			}
		}

		return implode( "\n", $lines );
	}
}

if ( ! function_exists( 'crm_service_tg_handle_start_command' ) ) {
	function crm_service_tg_handle_start_command( string $text, array $ctx, $telegram, array $data ): bool {
		if ( ! crm_service_tg_is_service_context() ) {
			return false;
		}

		$company_id = crm_telegram_get_callback_company_id();
		if ( $company_id <= 0 ) {
			crm_service_tg_send_scope_failure( $telegram, $ctx );
			return true;
		}

		$payload = function_exists( 'crm_telegram_extract_start_payload_from_text' )
			? crm_telegram_extract_start_payload_from_text( $text )
			: '';

		$chat_id   = crm_telegram_sanitize_chat_id_value( $ctx['chat_id'] ?? '' );
		$actor_id  = crm_telegram_sanitize_chat_id_value( $ctx['actor_id'] ?? '' );
		$target_id = $chat_id !== '' ? $chat_id : $actor_id;

		if ( $payload !== '' && strpos( $payload, 'svc_' ) !== 0 ) {
			if ( $target_id !== '' ) {
				bot_send_message( $telegram, $target_id, 'Некорректный service invite. Попросите администратора компании выдать новую ссылку.' );
			}
			crm_service_tg_log_security(
				'service.telegram.invalid_start_payload',
				'Service Telegram start payload rejected.',
				$company_id,
				[
					'payload'  => $payload,
					'chat_id'  => $chat_id,
					'actor_id' => $actor_id,
				]
			);
			return true;
		}

		if ( $payload === '' ) {
			if ( $chat_id === '' ) {
				if ( $target_id !== '' ) {
					bot_send_message( $telegram, $target_id, 'Не удалось определить chat_id. Попробуйте снова.' );
				}
				return true;
			}

			$binding = crm_service_tg_get_chat_binding( $company_id, $chat_id );
			if ( ! $binding ) {
				bot_send_message( $telegram, $target_id, 'Для доступа к сервисному боту нужен invite из CRM.' );
				crm_service_tg_log_security(
					'service.telegram.start_without_invite',
					'Service Telegram start rejected: no binding found for chat.',
					$company_id,
					[
						'chat_id'  => $chat_id,
						'actor_id' => $actor_id,
					]
				);
				return true;
			}

			$check = crm_service_telegram_validate_target_user( (int) $binding->user_id, $company_id );
			if ( empty( $check['ok'] ) ) {
				bot_send_message( $telegram, $target_id, (string) $check['message'] );
				crm_service_tg_log_security(
					'service.telegram.start_user_invalid',
					'Service Telegram start rejected: user failed validation.',
					$company_id,
					[
						'user_id'  => (int) $binding->user_id,
						'chat_id'  => $chat_id,
						'actor_id' => $actor_id,
						'reason'   => (string) $check['message'],
					]
				);
				return true;
			}

			$access_status = (string) ( $binding->access_status ?? '' );
			if ( $access_status !== 'active' ) {
				$message = 'Доступ к сервисному боту не активен. Попросите администратора компании выдать новый invite.';
				if ( $access_status === 'revoked' ) {
					$message = 'Service access был отозван. Попросите администратора компании выдать новый invite.';
				} elseif ( $access_status === 'blocked' ) {
					$message = 'Service access заблокирован. Обратитесь к администратору компании.';
				}
				bot_send_message( $telegram, $target_id, $message );
				crm_service_tg_log_security(
					'service.telegram.start_access_denied',
					'Service Telegram start rejected: access is not active.',
					$company_id,
					[
						'user_id'       => (int) $binding->user_id,
						'chat_id'       => $chat_id,
						'actor_id'      => $actor_id,
						'access_status' => $access_status,
					]
				);
				return true;
			}

			crm_service_tg_touch_last_seen( $company_id, (int) $binding->user_id );
			crm_service_tg_send_main_menu( $telegram, $target_id, $company_id, (int) $binding->user_id );
			return true;
		}

		crm_service_telegram_expire_invites( $company_id );
		$invite = crm_service_telegram_find_invite_by_start_payload( $company_id, $payload );

		if ( ! $invite ) {
			if ( $target_id !== '' ) {
				bot_send_message( $telegram, $target_id, 'Service invite не найден. Попросите администратора компании выдать новую ссылку.' );
			}
			crm_service_tg_log_security(
				'service.telegram.invite_invalid',
				'Service Telegram invite not found.',
				$company_id,
				[
					'payload'  => $payload,
					'chat_id'  => $chat_id,
					'actor_id' => $actor_id,
				]
			);
			return true;
		}

		$status = (string) $invite->status;
		if ( $status !== 'new' ) {
			$message = 'Этот service invite уже недействителен.';
			if ( $status === 'expired' ) {
				$message = 'Срок действия service invite истёк. Попросите администратора компании выдать новую ссылку.';
			} elseif ( $status === 'revoked' ) {
				$message = 'Этот service invite был отозван администратором компании.';
			} elseif ( $status === 'used' ) {
				$message = 'Этот service invite уже использован.';
			}
			if ( $target_id !== '' ) {
				bot_send_message( $telegram, $target_id, $message );
			}
			crm_service_tg_log_security(
				'service.telegram.invite_rejected',
				'Service Telegram invite rejected by status.',
				$company_id,
				[
					'invite_id' => (int) $invite->id,
					'status'    => $status,
					'chat_id'   => $chat_id,
					'actor_id'  => $actor_id,
				]
			);
			return true;
		}

		if ( $chat_id === '' ) {
			if ( $target_id !== '' ) {
				bot_send_message( $telegram, $target_id, 'Не удалось определить Telegram chat_id. Попробуйте снова.' );
			}
			return true;
		}

		$user_id = (int) $invite->user_id;
		$check   = crm_service_telegram_validate_target_user( $user_id, $company_id );
		if ( empty( $check['ok'] ) ) {
			if ( $target_id !== '' ) {
				bot_send_message( $telegram, $target_id, (string) $check['message'] );
			}
			crm_service_tg_log_security(
				'service.telegram.invite_user_invalid',
				'Service Telegram invite rejected: target user failed validation.',
				$company_id,
				[
					'invite_id' => (int) $invite->id,
					'user_id'   => $user_id,
					'chat_id'   => $chat_id,
					'actor_id'  => $actor_id,
					'reason'    => (string) $check['message'],
				]
			);
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
			if ( $target_id !== '' ) {
				bot_send_message( $telegram, $target_id, 'Этот Telegram уже привязан к другому CRM-пользователю компании.' );
			}
			crm_service_tg_log_security(
				'service.telegram.chat_conflict',
				'Service Telegram invite rejected: chat already belongs to another company user.',
				$company_id,
				[
					'invite_id'      => (int) $invite->id,
					'user_id'        => $user_id,
					'chat_id'        => $chat_id,
					'conflict_user_id' => $chat_owner_id,
				]
			);
			return true;
		}

		$existing_profile = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				 FROM crm_user_telegram_accounts
				 WHERE company_id = %d
				   AND user_id = %d
				 LIMIT 1",
				$company_id,
				$user_id
			)
		);

		if (
			$existing_profile
			&& (string) ( $existing_profile->status ?? '' ) === 'active'
			&& (
				(
					(string) ( $existing_profile->chat_id ?? '' ) !== ''
					&& (string) $existing_profile->chat_id !== $chat_id
				)
				|| (
					$actor_id !== ''
					&& (string) ( $existing_profile->telegram_user_id ?? '' ) !== ''
					&& (string) $existing_profile->telegram_user_id !== $actor_id
				)
			)
		) {
			if ( $target_id !== '' ) {
				bot_send_message(
					$telegram,
					$target_id,
					'Этот CRM-пользователь уже привязан к другому Telegram-аккаунту. Service invite отклонён, чтобы не перезаписать существующую привязку.'
				);
			}
			crm_service_tg_log_security(
				'service.telegram.rebind_blocked',
				'Service Telegram invite rejected: existing Telegram profile belongs to another chat or actor.',
				$company_id,
				[
					'invite_id'             => (int) $invite->id,
					'user_id'               => $user_id,
					'chat_id'               => $chat_id,
					'actor_id'              => $actor_id,
					'existing_chat_id'      => (string) ( $existing_profile->chat_id ?? '' ),
					'existing_actor_id'     => (string) ( $existing_profile->telegram_user_id ?? '' ),
					'existing_profile_id'   => (int) ( $existing_profile->id ?? 0 ),
				]
			);
			return true;
		}

		$now          = current_time( 'mysql', true );
		$profile_json = wp_json_encode(
			[
				'chat_id'       => $chat_id,
				'actor_id'      => $actor_id,
				'first_name'    => (string) ( $ctx['first_name'] ?? '' ),
				'last_name'     => (string) ( $ctx['last_name'] ?? '' ),
				'username'      => (string) ( $ctx['username'] ?? '' ),
				'language_code' => (string) ( $ctx['language_code'] ?? '' ),
				'payload'       => $payload,
				'bot_context'   => 'service',
			],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$existing_profile_id = $existing_profile ? (int) $existing_profile->id : 0;

		$profile_row = [
			'company_id'             => $company_id,
			'user_id'                => $user_id,
			'chat_id'                => $chat_id,
			'telegram_user_id'       => $actor_id !== '' ? $actor_id : $chat_id,
			'telegram_username'      => ! empty( $ctx['username'] ) ? ltrim( (string) $ctx['username'], '@' ) : null,
			'telegram_first_name'    => ! empty( $ctx['first_name'] ) ? (string) $ctx['first_name'] : null,
			'telegram_last_name'     => ! empty( $ctx['last_name'] ) ? (string) $ctx['last_name'] : null,
			'telegram_language_code' => ! empty( $ctx['language_code'] ) ? (string) $ctx['language_code'] : null,
			'status'                 => 'active',
			'linked_at'              => $now,
			'last_seen_at'           => $now,
			'profile_json'           => false !== $profile_json ? $profile_json : null,
		];

		if ( $existing_profile_id > 0 ) {
			$wpdb->update(
				'crm_user_telegram_accounts',
				$profile_row,
				[ 'id' => $existing_profile_id ],
				[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
		} else {
			$profile_row['created_at'] = $now;
			$wpdb->insert(
				'crm_user_telegram_accounts',
				$profile_row,
				[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
		}

		$existing_access_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM crm_service_telegram_access WHERE company_id = %d AND user_id = %d LIMIT 1',
				$company_id,
				$user_id
			)
		);

		$access_row = [
			'company_id'          => $company_id,
			'user_id'             => $user_id,
			'status'              => 'active',
			'granted_by_user_id'  => ! empty( $invite->created_by_user_id ) ? (int) $invite->created_by_user_id : null,
			'granted_at'          => $now,
			'last_invite_id'      => (int) $invite->id,
			'last_seen_at'        => $now,
			'revoked_at'          => null,
			'revoked_by_user_id'  => null,
			'revoke_reason'       => null,
		];

		if ( $existing_access_id > 0 ) {
			$wpdb->update(
				'crm_service_telegram_access',
				$access_row,
				[ 'id' => $existing_access_id ],
				[ '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s' ],
				[ '%d' ]
			);
		} else {
			$access_row['created_at'] = $now;
			$wpdb->insert(
				'crm_service_telegram_access',
				$access_row,
				[ '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' ]
			);
			$existing_access_id = (int) $wpdb->insert_id;
		}

		$wpdb->update(
			'crm_service_telegram_invites',
			[
				'chat_id'         => $chat_id,
				'status'          => 'used',
				'used_at'         => $now,
				'used_by_chat_id' => $chat_id,
			],
			[ 'id' => (int) $invite->id ],
			[ '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE crm_service_telegram_invites
				 SET status = 'revoked'
				 WHERE company_id = %d
				   AND user_id = %d
				   AND status = 'new'
				   AND id <> %d",
				$company_id,
				$user_id,
				(int) $invite->id
			)
		);

		crm_log_entity(
			'service.telegram.linked',
			'users',
			'update',
			'Service Telegram привязан к CRM-пользователю',
			'service_telegram_access',
			$existing_access_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'invite_id'         => (int) $invite->id,
					'user_id'           => $user_id,
					'chat_id'           => $chat_id,
					'telegram_username' => ! empty( $ctx['username'] ) ? (string) $ctx['username'] : null,
				],
			]
		);

		crm_log_entity(
			'service.telegram.access_granted',
			'users',
			'update',
			'Активирован доступ CRM-пользователя к service bot',
			'service_telegram_access',
			$existing_access_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'invite_id' => (int) $invite->id,
					'user_id'   => $user_id,
					'chat_id'   => $chat_id,
				],
			]
		);

		$display_name = trim( (string) ( $invite->display_name ?: $invite->user_login ) );
		if ( $target_id !== '' ) {
			bot_send_message(
				$telegram,
				$target_id,
				"✅ <b>Service bot привязан</b>\n\nАккаунт: " . esc_html( $display_name ) . "\nДоступ активирован."
			);
		}

		crm_service_tg_send_main_menu( $telegram, $target_id, $company_id, $user_id );

		return true;
	}
}

if ( ! function_exists( 'crm_service_tg_route_command' ) ) {
	function crm_service_tg_route_command( string $command, string $text, array $ctx, $telegram, array $data ): bool {
		if ( ! crm_service_tg_is_service_context() ) {
			return false;
		}

		if ( $command === '/start' ) {
			return crm_service_tg_handle_start_command( $text, $ctx, $telegram, $data );
		}

		$company_id = crm_telegram_get_callback_company_id();
		if ( $company_id <= 0 ) {
			crm_service_tg_send_scope_failure( $telegram, $ctx );
			return true;
		}

		$chat_id = crm_telegram_sanitize_chat_id_value( $ctx['chat_id'] ?? '' );
		if ( $chat_id === '' ) {
			return true;
		}

		$binding = crm_service_tg_get_chat_access( $company_id, $chat_id );
		if ( ! $binding || (string) ( $binding->access_status ?? '' ) !== 'active' ) {
			bot_send_message( $telegram, $chat_id, '⛔ У вас нет active service access. Попросите администратора компании выдать invite.' );
			return true;
		}

		$user_id = (int) $binding->user_id;
		crm_service_tg_touch_last_seen( $company_id, $user_id );

		if ( in_array( $command, [ '/menu', '/help' ], true ) ) {
			crm_service_tg_send_main_menu( $telegram, $chat_id, $company_id, $user_id );
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

		bot_send_message( $telegram, $chat_id, 'Команда пока не подключена в service contour. Используйте /menu.' );

		return true;
	}
}

if ( ! function_exists( 'crm_service_tg_route_callback' ) ) {
	function crm_service_tg_route_callback( string $callback_data, array $ctx, $telegram, array $data ): bool {
		$is_service_menu   = strpos( $callback_data, 'service_menu:' ) === 0;
		$is_service_orders = strpos( $callback_data, 'service_orders:' ) === 0;

		if ( ! crm_service_tg_is_service_context() || ( ! $is_service_menu && ! $is_service_orders ) ) {
			return false;
		}

		$company_id = crm_telegram_get_callback_company_id();
		$chat_id    = crm_telegram_sanitize_chat_id_value( $ctx['chat_id'] ?? '' );
		if ( $company_id <= 0 || $chat_id === '' ) {
			tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? '', 'No scope', true );
			crm_service_tg_send_scope_failure( $telegram, $ctx );
			return true;
		}

		$binding = crm_service_tg_get_chat_access( $company_id, $chat_id );
		if ( ! $binding || (string) ( $binding->access_status ?? '' ) !== 'active' ) {
			tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? '', 'Нет доступа', true );
			bot_send_message( $telegram, $chat_id, '⛔ Service access недоступен. Запросите новый invite в CRM.' );
			return true;
		}

		$user_id = (int) $binding->user_id;
		crm_service_tg_touch_last_seen( $company_id, $user_id );

		if ( $callback_data === 'service_menu:main' ) {
			tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? '', 'Menu' );
			crm_service_tg_send_main_menu( $telegram, $chat_id, $company_id, $user_id );
			return true;
		}

		if ( $callback_data === 'service_menu:orders' ) {
			if ( ! crm_user_has_permission( $user_id, 'service.telegram.orders' ) ) {
				tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? '', 'Нет прав', true );
				bot_send_message( $telegram, $chat_id, '⛔ Для этого раздела у пользователя нет CRM-permission.' );
				return true;
			}

			tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? '', 'Orders' );
			crm_service_tg_send_orders_menu( $telegram, $chat_id, $company_id );
			return true;
		}

		if ( $callback_data === 'service_menu:merchant_payouts' ) {
			if ( ! crm_user_has_permission( $user_id, 'service.telegram.merchant_payouts' ) ) {
				tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? '', 'Нет прав', true );
				bot_send_message( $telegram, $chat_id, '⛔ Для этого раздела у пользователя нет CRM-permission.' );
				return true;
			}

			tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? '', 'Merchant payouts' );
			crm_service_tg_send_merchant_payouts_stub( $telegram, $chat_id, $company_id );
			return true;
		}

		if ( strpos( $callback_data, 'service_orders:' ) === 0 ) {
			if ( ! crm_user_has_permission( $user_id, 'service.telegram.orders' ) ) {
				tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? '', 'Нет прав', true );
				bot_send_message( $telegram, $chat_id, '⛔ Для этого раздела у пользователя нет CRM-permission.' );
				return true;
			}

			$action = substr( $callback_data, strlen( 'service_orders:' ) );

			if ( $action === 'check_open' ) {
				tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? '', 'Проверяю...' );

				$limit = 100;

				crm_log( 'service.telegram.orders_check_started', [
					'category'   => 'payments',
					'level'      => 'info',
					'action'     => 'telegram_service_check_open',
					'message'    => 'Service bot: запущена ручная проверка открытых платежей компании',
					'org_id'     => $company_id,
					'is_success' => true,
					'context'    => [
						'user_id' => $user_id,
						'chat_id' => $chat_id,
						'limit'   => $limit,
					],
				] );

				$summary = crm_service_tg_check_open_payment_orders( $company_id, $limit );

				crm_log( 'service.telegram.orders_check_finished', [
					'category'   => 'payments',
					'level'      => 'info',
					'action'     => 'telegram_service_check_open',
					'message'    => 'Service bot: завершена ручная проверка открытых платежей компании',
					'org_id'     => $company_id,
					'is_success' => empty( $summary['errors'] ),
					'context'    => [
						'user_id'   => $user_id,
						'chat_id'   => $chat_id,
						'found'     => (int) ( $summary['found'] ?? 0 ),
						'checked'   => (int) ( $summary['checked'] ?? 0 ),
						'changed'   => (int) ( $summary['changed'] ?? 0 ),
						'paid'      => (int) ( $summary['paid'] ?? 0 ),
						'unchanged' => (int) ( $summary['unchanged'] ?? 0 ),
						'errors'    => (int) ( $summary['errors'] ?? 0 ),
						'truncated' => ! empty( $summary['truncated'] ),
					],
				] );

				tg_send_message_chunks(
					$telegram,
					$chat_id,
					crm_service_tg_check_open_payment_orders_message( $summary ),
					'HTML',
					[
						'inline_keyboard' => [
							[
								[
									'text'          => '🔄 Проверить ещё раз',
									'callback_data' => 'service_orders:check_open',
								],
							],
							[
								[
									'text'          => '↩️ Orders',
									'callback_data' => 'service_menu:orders',
								],
								[
									'text'          => '🏠 Главное меню',
									'callback_data' => 'service_menu:main',
								],
							],
						],
					]
				);

				return true;
			}

			tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? '', 'Unknown action', true );
			return true;
		}

		$action = substr( $callback_data, strlen( 'service_menu:' ) );
		if ( $action === 'help' ) {
			tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? '', 'Help' );
			bot_send_message( $telegram, $chat_id, 'Service bot foundation подключён. Рабочие разделы будут запускаться поэтапно через общий backend contour.' );
			return true;
		}

		$section_permissions = [
			'merchant_payouts' => 'service.telegram.merchant_payouts',
			'acquirer_payouts' => 'service.telegram.acquirer_payouts',
			'orders'           => 'service.telegram.orders',
			'rates'            => 'service.telegram.rates',
		];

		if ( ! isset( $section_permissions[ $action ] ) ) {
			tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? '', 'Unknown action', true );
			return true;
		}

		if ( ! crm_user_has_permission( $user_id, $section_permissions[ $action ] ) ) {
			tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? '', 'Нет прав', true );
			bot_send_message( $telegram, $chat_id, '⛔ Для этого раздела у пользователя нет CRM-permission.' );
			return true;
		}

		tg_safe_answer_callback( $telegram, $ctx['callback_query_id'] ?? '', 'Скоро' );
		bot_send_message( $telegram, $chat_id, 'Раздел <b>' . esc_html( $action ) . '</b> будет подключён в следующем этапе. ACL уже активен.' );

		return true;
	}
}
