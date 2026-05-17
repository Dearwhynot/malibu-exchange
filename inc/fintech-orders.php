<?php
/**
 * Malibu Exchange — Fintech Orders Business Logic
 *
 * Что делает этот файл:
 * - crm_fintech_create_order()     — вызывает gateway, сохраняет ордер в БД, генерирует QR
 * - crm_fintech_save_order()       — сохраняет результат create_invoice() в crm_fintech_payment_orders
 * - crm_fintech_qr_url()           — генерирует PNG QR-кода и возвращает URL
 * - crm_fintech_process_callback() — подписчик хука fintech_payment_callback_received:
 *                                    обновляет статус ордера + history + callbacks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Status mapping ───────────────────────────────────────────────────────────

/**
 * Маппинг provider-статуса в наш внутренний status_code.
 * Kanyon: CREATED, PROCESSING, QRCDATA_CREATED, QRCDATA_IN_PROGRESS, IPS_ACCEPTED, DECLINED, EXPIRED, CANCELLED
 * Doverka: нормализованы в callback-handler → IPS_ACCEPTED, DECLINED, EXPIRED
 */
function _crm_fintech_map_status( string $provider_status ): string {
	static $map = [
		'CREATED'             => 'created',
		'QRCDATA_CREATED'     => 'created',
		'PROCESSING'          => 'pending',
		'IN_PROCESS'          => 'pending',
		'PAYOUT_IN_PROGRESS'  => 'pending',
		'QRCDATA_IN_PROGRESS' => 'pending',
		'CHARGE_IN_PROGRESS'  => 'pending',
		'AUTHORIZATION'       => 'pending',
		'PRE_AUTHORIZED_3DS'  => 'pending',
		'IPS_ACCEPTED'        => 'paid',
		'PAID'                => 'paid',
		'CHARGED'             => 'paid',
		'DECLINED'            => 'declined',
		'CHARGE_DECLINED'     => 'declined',
		'EXPIRED'             => 'expired',
		'CANCELLED'           => 'cancelled',
		'CANCELED'            => 'cancelled',
	];

	$upper = strtoupper( trim( $provider_status ) );

	return $map[ $upper ] ?? 'pending';
}

// ─── QR helper ────────────────────────────────────────────────────────────────

/**
 * Генерирует QR-PNG из payload и возвращает публичный URL.
 * Возвращает null, если библиотека phpqrcode недоступна.
 */
function crm_fintech_qr_url( string $payload, string $qrc_id, string $order_id ): ?string {
	if ( ! class_exists( 'QRcode' ) ) {
		$lib = get_template_directory() . '/vendorsphp/QR/phpqrcode/qrlib.php';
		if ( is_file( $lib ) ) {
			require_once $lib;
		}
	}

	if ( ! class_exists( 'QRcode' ) ) {
		error_log( '[FINTECH] QR generation skipped: phpqrcode library not available' );
		return null;
	}

	$base_dir = get_template_directory()     . '/uploadbotfiles/qrcodes/';
	$base_url = get_template_directory_uri() . '/uploadbotfiles/qrcodes/';

	if ( ! is_dir( $base_dir ) ) {
		@mkdir( $base_dir, 0775, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	$safe_qrc   = preg_replace( '/[^A-Za-z0-9_\-]/', '', $qrc_id );
	$safe_order = preg_replace( '/[^A-Za-z0-9_\-]/', '', $order_id );
	$file_name  = 'qr_' . $safe_qrc . '_' . $safe_order . '.png';
	$abs_path   = $base_dir . $file_name;

	if ( ! file_exists( $abs_path ) ) {
		QRcode::png( $payload, $abs_path );
	}

	if ( ! file_exists( $abs_path ) ) {
		error_log( '[FINTECH] QR PNG generation failed ' . wp_json_encode( [
			'order_id' => $order_id,
			'qrc_id'   => $qrc_id,
			'base_dir' => $base_dir,
		], JSON_UNESCAPED_UNICODE ) );
	}

	return file_exists( $abs_path ) ? ( $base_url . $file_name ) : null;
}

// ─── Save order to DB ─────────────────────────────────────────────────────────

/**
 * Вставляет запись в crm_fintech_payment_orders из результата create_invoice().
 * Возвращает ID новой строки или null при ошибке.
 */
function crm_fintech_save_order(
	array $invoice,
	int $company_id = 0,
	string $source_channel = 'web',
	?int $created_by_user_id = null,
	array $overrides = []
): ?int {
	global $wpdb;

	if ( empty( $invoice['success'] ) ) {
		return null;
	}

	if ( $company_id < 0 ) {
		return null;
	}

	$provider_status = (string) ( $invoice['providerStatus'] ?? '' );
	$status_code     = _crm_fintech_map_status( $provider_status !== '' ? $provider_status : 'CREATED' );
	$amount_usdt     = isset( $invoice['amountUsdt'] ) ? (float) $invoice['amountUsdt'] : 0.0;
	$payment_rub     = isset( $invoice['paymentAmountRub'] ) ? round( $invoice['paymentAmountRub'] / 100, 2 ) : null;
	$now             = current_time( 'mysql' );

	$row = [
		'company_id'                    => $company_id,
		'provider_code'                 => (string) ( $invoice['provider']            ?? '' ),
		'source_channel'                => $source_channel,
		'merchant_order_id'             => (string) ( $invoice['merchantOrderId']     ?? '' ),
		'provider_order_id'             => (string) ( $invoice['orderId']             ?? '' ),
		'provider_external_order_id'    => isset( $invoice['externalOrderId'] ) ? (string) $invoice['externalOrderId'] : null,
		'status_code'                   => $status_code,
		'provider_status_code'          => $provider_status !== '' ? $provider_status : null,
		'amount_asset_code'             => 'USDT',
		'amount_asset_value'            => $amount_usdt,
		'payment_currency_code'         => 'RUB',
		'payment_amount_value'          => $payment_rub,
		'payment_link'                  => isset( $invoice['payload'] )     ? (string) $invoice['payload']     : null,
		'qrc_id'                        => isset( $invoice['qrcId'] )       ? (string) $invoice['qrcId']       : null,
		'provider_public_link'          => isset( $invoice['publicLink'] )  ? (string) $invoice['publicLink']  : null,
		'provider_requires_verification'=> ! empty( $invoice['requiresVerification'] ) ? 1 : 0,
		'created_by_user_id'            => $created_by_user_id,
		'create_response_payload_json'  => wp_json_encode( $invoice['raw'] ?? [], JSON_UNESCAPED_UNICODE ),
		'created_at'                    => $now,
		'updated_at'                    => $now,
	];

	if ( ! empty( $overrides ) ) {
		$row = array_merge( $row, $overrides );
	}

	$wpdb->insert( 'crm_fintech_payment_orders', $row );

	$order_id = (int) $wpdb->insert_id;
	if ( $order_id <= 0 ) {
		return null;
	}

	// Начальная запись истории статусов
	$wpdb->insert(
		'crm_fintech_payment_order_status_history',
		[
			'payment_order_id'    => $order_id,
			'status_code'         => $status_code,
			'provider_status_code'=> $provider_status !== '' ? $provider_status : null,
			'source_code'         => 'create',
			'message'             => 'Ордер создан',
			'created_by_user_id'  => $created_by_user_id,
			'created_at'          => $now,
		]
	);

	return $order_id;
}

// ─── Shared create-order helpers ─────────────────────────────────────────────

/**
 * Общая подготовка перед созданием company-order.
 *
 * Возвращает:
 *   success (bool), error (?string), active_provider (?string)
 */
function _crm_fintech_prepare_create_order(
	int $company_id,
	string $source_channel,
	array $log_context = []
): array {
	if ( $company_id < 0 ) {
		return [
			'success'         => false,
			'active_provider' => null,
			'error'           => 'Операция требует валидную компанию пользователя.',
		];
	}

	$active_provider = crm_fintech_normalize_provider_code(
		(string) crm_get_setting( 'fintech_active_provider', $company_id, '' )
	);

	if ( $active_provider !== '' && ! crm_fintech_is_provider_allowed( $company_id, $active_provider ) ) {
		$error_message = sprintf(
			'Создание ордеров через %s отключено для этой компании. Обратитесь к root-администратору.',
			crm_fintech_provider_label( $active_provider )
		);

		crm_log( 'payment.order.provider_disabled', [
			'category'    => 'payments',
			'level'       => 'warning',
			'action'      => 'create',
			'message'     => $error_message,
			'target_type' => 'payment_order',
			'is_success'  => false,
			'context'     => array_merge(
				[
					'company_id'     => $company_id,
					'provider'       => $active_provider,
					'source_channel' => $source_channel,
				],
				$log_context
			),
		] );

		return [
			'success'         => false,
			'active_provider' => $active_provider,
			'error'           => $error_message,
		];
	}

	// Проверяем наличие настроек СТРОГО для этой компании.
	// Запрещено использовать настройки другой компании — это финансовая операция.
	if ( ! crm_fintech_is_configured( $company_id ) ) {
		$config_status  = crm_fintech_get_configuration_status( $company_id );
		$missing_labels = array_map(
			static fn( $item ) => (string) ( $item['label'] ?? '' ),
			$config_status['missing_fields'] ?? []
		);
		$missing_labels = array_values( array_filter( $missing_labels ) );

		crm_log( 'payment.order.create_failed', [
			'category'   => 'payments',
			'level'      => 'error',
			'action'     => 'create',
			'message'    => sprintf( 'Попытка создать ордер: платёжный шлюз не настроен для компании %d', $company_id ),
			'is_success' => false,
			'context'    => array_merge(
				[ 'company_id' => $company_id, 'source_channel' => $source_channel ],
				$log_context
			),
		] );

		return [
			'success'         => false,
			'active_provider' => $active_provider,
			'error'           => sprintf(
				'Платёжный шлюз не настроен для вашей компании (ID: %d).%s',
				$company_id,
				! empty( $missing_labels ) ? ' Не хватает: ' . implode( ', ', $missing_labels ) . '.' : ' Обратитесь к администратору системы.'
			),
		];
	}

	return [
		'success'         => true,
		'active_provider' => $active_provider,
		'error'           => null,
	];
}

/**
 * Общая финализация созданного provider-order: БД, QR, единый ответ и аудит.
 */
function _crm_fintech_finalize_created_order(
	array $invoice,
	int $company_id,
	string $source_channel,
	?int $created_by_user_id,
	array $success_context = []
): array {
	$order_db_id = crm_fintech_save_order( $invoice, $company_id, $source_channel, $created_by_user_id );

	$payload = (string) ( $invoice['payload'] ?? '' );
	$qrc_id  = (string) ( $invoice['qrcId']   ?? '' );
	$qr_url  = null;

	if ( $payload !== '' && $qrc_id !== '' ) {
		$order_id_str = (string) ( $invoice['merchantOrderId'] ?? ( (string) ( $order_db_id ?? '0' ) ) );
		$qr_url       = crm_fintech_qr_url( $payload, $qrc_id, $order_id_str );
	}

	crm_log( 'payment.order.created', [
		'category'    => 'payments',
		'level'       => 'info',
		'action'      => 'create',
		'message'     => 'Создан платёжный ордер',
		'target_type' => 'payment_order',
		'target_id'   => $order_db_id,
		'is_success'  => true,
		'context'     => array_merge(
			[
				'provider'          => $invoice['provider'] ?? null,
				'merchant_order_id' => $invoice['merchantOrderId'] ?? null,
				'source_channel'    => $source_channel,
				'company_id'        => $company_id,
			],
			$success_context
		),
	] );

	return [
		'success'              => true,
		'order_db_id'          => $order_db_id,
		'merchant_order_id'    => (string) ( $invoice['merchantOrderId']  ?? '' ),
		'provider_order_id'    => (string) ( $invoice['orderId']           ?? '' ),
		'amount_usdt'          => isset( $invoice['amountUsdt'] ) ? (float) $invoice['amountUsdt'] : null,
		'payment_link'         => $payload,
		'qrc_id'               => $qrc_id,
		'payload'              => $payload,
		'qr_url'               => $qr_url,
		'provider'             => (string) ( $invoice['provider']          ?? '' ),
		'payload_mode'         => (string) ( $invoice['payloadMode']       ?? '' ),
		'payment_amount_rub'   => isset( $invoice['paymentAmountRub'] ) ? round( $invoice['paymentAmountRub'] / 100, 2 ) : null,
		'payment_currency_code'=> isset( $invoice['paymentCurrencyCode'] ) ? (string) $invoice['paymentCurrencyCode'] : null,
		'warning'              => isset( $invoice['warning'] ) ? (string) $invoice['warning'] : null,
		'error'                => null,
	];
}

/**
 * Определяет input contour для company create-order form.
 * `rub` включается только для Kanyon-компаний с `fintech_pay2day_order_currency = RUB`.
 */
function crm_fintech_company_create_order_input_mode( int $company_id ): string {
	if ( $company_id <= 0 ) {
		return 'usdt';
	}

	$provider_code  = crm_fintech_normalize_provider_code(
		(string) crm_get_setting( 'fintech_active_provider', $company_id, '' )
	);
	$order_currency = crm_fintech_normalize_kanyon_order_currency(
		(string) crm_get_setting( 'fintech_pay2day_order_currency', $company_id, '' )
	);

	if ( $provider_code === 'kanyon' && $order_currency === 'RUB' ) {
		return 'rub';
	}

	return 'usdt';
}

// ─── Main orchestration ───────────────────────────────────────────────────────

/**
 * Создаёт платёжный ордер: вызывает gateway → сохраняет в БД → генерирует QR.
 *
 * Возвращает массив:
 *   success (bool), order_db_id (?int), merchant_order_id, provider_order_id,
 *   payment_link, qrc_id, payload, qr_url, provider, payment_amount_rub, warning (?string), error (?string)
 */
function crm_fintech_create_order(
	float $amount_usdt,
	int $company_id = 0,
	string $source_channel = 'web',
	?int $created_by_user_id = null,
	string $description = ''
): array {
	$preflight = _crm_fintech_prepare_create_order(
		$company_id,
		$source_channel,
		[ 'amount_usdt' => $amount_usdt ]
	);

	if ( empty( $preflight['success'] ) ) {
		return [
			'success'     => false,
			'order_db_id' => null,
			'error'       => $preflight['error'] ?? 'Ошибка создания ордера.',
		];
	}

	Fintech_Payment_Gateway::set_company_id( $company_id );
	$invoice = Fintech_Payment_Gateway::create_invoice( $amount_usdt, null, $description );

	if ( empty( $invoice['success'] ) ) {
		crm_log( 'payment.order.create_failed', [
			'category'    => 'payments',
			'level'       => 'error',
			'action'      => 'create',
			'message'     => 'Ошибка создания ордера: ' . ( $invoice['error'] ?? 'unknown' ),
			'target_type' => 'payment_order',
			'is_success'  => false,
			'context'     => [
				'amount_usdt'    => $amount_usdt,
				'provider'       => $invoice['provider'] ?? null,
				'source_channel' => $source_channel,
				'error'          => $invoice['error'] ?? null,
			],
		] );

		return [
			'success'     => false,
			'order_db_id' => null,
			'error'       => $invoice['error'] ?? 'Gateway error',
		];
	}

	return _crm_fintech_finalize_created_order(
		$invoice,
		$company_id,
		$source_channel,
		$created_by_user_id,
		[ 'amount_usdt' => $amount_usdt ]
	);
}

/**
 * Создаёт company-order из суммы оплаты в RUB для нового Kanyon paymentAmount contour.
 *
 * Возвращает тот же ответ, что и crm_fintech_create_order(), но входная сумма трактуется
 * как сумма платежа клиента в payment currency.
 */
function crm_fintech_create_order_by_payment_amount(
	float $payment_amount,
	string $payment_currency_code,
	int $company_id = 0,
	string $source_channel = 'web',
	?int $created_by_user_id = null,
	string $description = ''
): array {
	$payment_currency_code = strtoupper( trim( $payment_currency_code ) );

	$preflight = _crm_fintech_prepare_create_order(
		$company_id,
		$source_channel,
		[
			'payment_amount'        => $payment_amount,
			'payment_currency_code' => $payment_currency_code,
		]
	);

	if ( empty( $preflight['success'] ) ) {
		return [
			'success'     => false,
			'order_db_id' => null,
			'error'       => $preflight['error'] ?? 'Ошибка создания ордера.',
		];
	}

	Fintech_Payment_Gateway::set_company_id( $company_id );
	$invoice = Fintech_Payment_Gateway::create_invoice_by_payment_amount(
		$payment_amount,
		$payment_currency_code,
		null,
		$description
	);

	if ( empty( $invoice['success'] ) ) {
		crm_log( 'payment.order.create_failed', [
			'category'    => 'payments',
			'level'       => 'error',
			'action'      => 'create',
			'message'     => 'Ошибка создания paymentAmount-ордера: ' . ( $invoice['error'] ?? 'unknown' ),
			'target_type' => 'payment_order',
			'is_success'  => false,
			'context'     => [
				'payment_amount'        => $payment_amount,
				'payment_currency_code' => $payment_currency_code,
				'provider'              => $invoice['provider'] ?? null,
				'source_channel'        => $source_channel,
				'error'                 => $invoice['error'] ?? null,
			],
		] );

		return [
			'success'     => false,
			'order_db_id' => null,
			'error'       => $invoice['error'] ?? 'Gateway error',
		];
	}

	return _crm_fintech_finalize_created_order(
		$invoice,
		$company_id,
		$source_channel,
		$created_by_user_id,
		[
			'payment_amount'        => $payment_amount,
			'payment_currency_code' => $payment_currency_code,
			'amount_usdt'           => isset( $invoice['amountUsdt'] ) ? (float) $invoice['amountUsdt'] : null,
		]
	);
}

// ─── Poll order status ────────────────────────────────────────────────────────

/**
 * Запрашивает у провайдера текущий статус ордера, обновляет БД если изменился.
 *
 * @param object $order  Строка из crm_fintech_payment_orders (полный SELECT *)
 * @return array { changed: bool, old_status: string, new_status: string, provider_status: string, error: ?string }
 */
function crm_fintech_poll_order_status( object $order ): array {
	global $wpdb;

	$order_db_id       = (int) $order->id;
	$provider_order_id = (string) ( $order->provider_order_id ?? '' );
	$old_status        = (string) ( $order->status_code ?? '' );
	$now               = current_time( 'mysql' );

	if ( $provider_order_id === '' ) {
		return [ 'changed' => false, 'old_status' => $old_status, 'new_status' => $old_status, 'provider_status' => '', 'error' => 'No provider_order_id' ];
	}

	$company_id = (int) ( $order->company_id ?? 0 );
	if ( $company_id < 0 ) {
		return [
			'changed'         => false,
			'old_status'      => $old_status,
			'new_status'      => $old_status,
			'provider_status' => '',
			'error'           => 'Order has invalid company scope',
		];
	}

	Fintech_Payment_Gateway::set_company_id( $company_id );
	$status_result = Fintech_Payment_Gateway::get_order_status_for_provider( (string) ( $order->provider_code ?? '' ), $provider_order_id );

	if ( empty( $status_result['success'] ) ) {
		$wpdb->update( 'crm_fintech_payment_orders', [ 'last_checked_at' => $now ], [ 'id' => $order_db_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		crm_log( 'payment.order.poll_failed', [
			'category'    => 'payments',
			'level'       => 'warning',
			'action'      => 'poll',
			'message'     => 'Ошибка при проверке статуса: ' . ( $status_result['error'] ?? 'unknown' ),
			'target_type' => 'payment_order',
			'target_id'   => $order_db_id,
			'is_success'  => false,
			'context'     => [ 'provider_order_id' => $provider_order_id, 'error' => $status_result['error'] ?? null ],
		] );

		return [ 'changed' => false, 'old_status' => $old_status, 'new_status' => $old_status, 'provider_status' => '', 'error' => $status_result['error'] ?? 'Status check failed' ];
	}

	$provider_status = (string) ( $status_result['providerStatus'] ?? '' );
	$new_status      = _crm_fintech_map_status( $provider_status );

	$update = [ 'last_checked_at' => $now, 'updated_at' => $now ];

	if (
		(
			( empty( $order->payment_link ) || empty( $order->qrc_id ) )
			|| ( isset( $order->amount_asset_value ) && (float) $order->amount_asset_value <= 0 )
		)
		&& ! empty( $order->provider_code )
		&& $provider_order_id !== ''
	) {
		$order_qr_result = Fintech_Payment_Gateway::get_order_qr_data_for_provider( (string) ( $order->provider_code ?? '' ), $provider_order_id );
		if ( ! empty( $order_qr_result['success'] ) ) {
			if ( ! empty( $order_qr_result['payload'] ) ) {
				$update['payment_link'] = (string) $order_qr_result['payload'];
			}
			if ( ! empty( $order_qr_result['qrcId'] ) ) {
				$update['qrc_id'] = (string) $order_qr_result['qrcId'];
			}
			if ( ! empty( $order_qr_result['externalOrderId'] ) ) {
				$update['provider_external_order_id'] = (string) $order_qr_result['externalOrderId'];
			}
		}

		$order_data_result = Fintech_Payment_Gateway::get_order_data_for_provider( (string) ( $order->provider_code ?? '' ), $provider_order_id );
		if ( ! empty( $order_data_result['success'] ) ) {
			if (
				isset( $order_data_result['order']['orderAmount'] )
				&& $order_data_result['order']['orderAmount'] !== null
				&& (int) $order_data_result['order']['orderAmount'] > 0
			) {
				$update['amount_asset_value'] = round( ( (int) $order_data_result['order']['orderAmount'] ) / 100, 8 );
			}
			if ( ! empty( $order_data_result['payload'] ) ) {
				$update['payment_link'] = (string) $order_data_result['payload'];
			}
			if ( ! empty( $order_data_result['qrcId'] ) ) {
				$update['qrc_id'] = (string) $order_data_result['qrcId'];
			}
			if ( ! empty( $order_data_result['externalOrderId'] ) ) {
				$update['provider_external_order_id'] = (string) $order_data_result['externalOrderId'];
			}
			if ( isset( $order_data_result['paymentAmountRub'] ) && $order_data_result['paymentAmountRub'] !== null ) {
				$update['payment_amount_value'] = round( ( (int) $order_data_result['paymentAmountRub'] ) / 100, 2 );
			}
			if ( empty( $update['provider_status_code'] ) && ! empty( $order_data_result['providerStatus'] ) ) {
				$update['provider_status_code'] = (string) $order_data_result['providerStatus'];
			}
			if ( ! empty( $order_data_result['raw'] ) ) {
				$update['last_provider_payload_json'] = wp_json_encode( $order_data_result['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
		}
	}

	if ( $new_status !== $old_status ) {
		$update['status_code']          = $new_status;
		$update['provider_status_code'] = $provider_status !== '' ? $provider_status : null;

		if ( $new_status === 'paid'      && empty( $order->paid_at ) )       { $update['paid_at']      = $now; }
		if ( $new_status === 'declined'  && empty( $order->declined_at ) )   { $update['declined_at']  = $now; }
		if ( $new_status === 'cancelled' && empty( $order->cancelled_at ) )  { $update['cancelled_at'] = $now; }
		if ( $new_status === 'expired'   && empty( $order->expired_at ) )    { $update['expired_at']   = $now; }
	}

	$wpdb->update( 'crm_fintech_payment_orders', $update, [ 'id' => $order_db_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( $new_status !== $old_status ) {
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'crm_fintech_payment_order_status_history',
			[
				'payment_order_id'     => $order_db_id,
				'status_code'          => $new_status,
				'provider_status_code' => $provider_status !== '' ? $provider_status : null,
				'source_code'          => 'poll',
				'message'              => "Статус обновлён при проверке: {$old_status} → {$new_status}",
				'created_at'           => $now,
			]
		);

		crm_log( 'payment.order.status_polled', [
			'category'    => 'payments',
			'level'       => 'info',
			'action'      => 'poll',
			'message'     => "Статус ордера обновлён: {$old_status} → {$new_status}",
			'target_type' => 'payment_order',
			'target_id'   => $order_db_id,
			'is_success'  => true,
			'context'     => [
				'merchant_order_id' => $order->merchant_order_id,
				'old_status'        => $old_status,
				'new_status'        => $new_status,
				'provider_status'   => $provider_status,
			],
		] );
	}

	if ( $new_status === 'paid' ) {
		crm_merchant_create_paid_order_accrual( $order_db_id, 'poll' );
	}

	return [
		'changed'         => $new_status !== $old_status,
		'old_status'      => $old_status,
		'new_status'      => $new_status,
		'provider_status' => $provider_status,
		'error'           => null,
	];
}

// ─── Callback hook subscriber ─────────────────────────────────────────────────

add_action( 'fintech_payment_callback_received', 'crm_fintech_process_callback', 10, 4 );

function crm_fintech_process_callback( array $event, array $payload, array $headers, ?int $callback_id ): void {
	global $wpdb;

	$merchant_order_id = (string) ( $event['merchantOrderId'] ?? '' );
	$provider_order_id = (string) ( $event['orderId']         ?? '' );
	$provider_status   = (string) ( $event['status']          ?? '' );

	if ( $merchant_order_id === '' && $provider_order_id === '' ) {
		crm_log( 'payment.callback.no_order_id', [
			'category'    => 'callbacks',
			'level'       => 'warning',
			'action'      => 'callback',
			'message'     => 'Callback без merchant_order_id и orderId — пропускаем',
			'is_success'  => false,
			'context'     => [ 'provider' => $event['provider'] ?? null ],
		] );

		if ( $callback_id ) {
			$wpdb->update(
				'crm_fintech_payment_callbacks',
				[ 'processing_status' => 'skipped', 'processed_at' => current_time( 'mysql' ) ],
				[ 'id' => $callback_id ]
			);
		}

		return;
	}

	// Ищем ордер по merchant_order_id, затем по provider_order_id
	$order = null;

	if ( $merchant_order_id !== '' ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$order = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, status_code, first_callback_at, paid_at, declined_at, cancelled_at, expired_at FROM crm_fintech_payment_orders WHERE merchant_order_id = %s LIMIT 1',
			$merchant_order_id
		) );
	}

	if ( ! $order && $provider_order_id !== '' ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$order = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, status_code, first_callback_at, paid_at, declined_at, cancelled_at, expired_at FROM crm_fintech_payment_orders WHERE provider_order_id = %s LIMIT 1',
			$provider_order_id
		) );
	}

	if ( ! $order ) {
		crm_log( 'payment.callback.order_not_found', [
			'category'    => 'callbacks',
			'level'       => 'warning',
			'action'      => 'callback',
			'message'     => 'Callback: ордер не найден в БД',
			'is_success'  => false,
			'context'     => [
				'merchant_order_id' => $merchant_order_id,
				'provider_order_id' => $provider_order_id,
				'provider'          => $event['provider'] ?? null,
			],
		] );

		if ( $callback_id ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'crm_fintech_payment_callbacks',
				[ 'processing_status' => 'order_not_found', 'processed_at' => current_time( 'mysql' ) ],
				[ 'id' => $callback_id ]
			);
		}

		return;
	}

	$order_db_id = (int) $order->id;
	$new_status  = _crm_fintech_map_status( $provider_status );
	$old_status  = (string) $order->status_code;
	$now         = current_time( 'mysql' );

	// Дубликат в терминальном статусе — просто отмечаем как duplicate
	$terminal = [ 'paid', 'declined', 'cancelled', 'expired' ];
	if ( in_array( $old_status, $terminal, true ) && $old_status === $new_status ) {
		if ( $new_status === 'paid' ) {
			crm_merchant_create_paid_order_accrual( $order_db_id, 'callback_duplicate' );
		}

		if ( $callback_id ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'crm_fintech_payment_callbacks',
				[
					'payment_order_id'  => $order_db_id,
					'processing_status' => 'duplicate',
					'processed_at'      => $now,
				],
				[ 'id' => $callback_id ]
			);
		}

		return;
	}

	// Строим UPDATE-массив для ордера
	$update = [
		'status_code'          => $new_status,
		'provider_status_code' => $provider_status !== '' ? $provider_status : null,
		'last_callback_at'     => $now,
		'updated_at'           => $now,
	];

	if ( empty( $order->first_callback_at ) ) {
		$update['first_callback_at'] = $now;
	}

	if ( $new_status === 'paid'      && empty( $order->paid_at ) )       { $update['paid_at']      = $now; }
	if ( $new_status === 'declined'  && empty( $order->declined_at ) )   { $update['declined_at']  = $now; }
	if ( $new_status === 'cancelled' && empty( $order->cancelled_at ) )  { $update['cancelled_at'] = $now; }
	if ( $new_status === 'expired'   && empty( $order->expired_at ) )    { $update['expired_at']   = $now; }

	// Сумма оплаты из callback, если есть
	if ( isset( $event['paymentAmount'] ) && $event['paymentAmount'] !== null ) {
		$update['payment_amount_value'] = round( $event['paymentAmount'] / 100, 2 );
	}
	if ( isset( $event['orderAmount'] ) && $event['orderAmount'] !== null && (int) $event['orderAmount'] > 0 ) {
		$update['amount_asset_value'] = round( ( (int) $event['orderAmount'] ) / 100, 8 );
	}
	if ( ! empty( $event['qrcId'] ) ) {
		$update['qrc_id'] = (string) $event['qrcId'];
	}
	if ( ! empty( $event['payload'] ) ) {
		$update['payment_link'] = (string) $event['payload'];
	}
	if ( ! empty( $event['externalOrderId'] ) ) {
		$update['provider_external_order_id'] = (string) $event['externalOrderId'];
	}
	if ( isset( $event['raw'] ) && is_array( $event['raw'] ) ) {
		$update['last_provider_payload_json'] = wp_json_encode( $event['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	$wpdb->update( 'crm_fintech_payment_orders', $update, [ 'id' => $order_db_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// История статусов
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		'crm_fintech_payment_order_status_history',
		[
			'payment_order_id'    => $order_db_id,
			'status_code'         => $new_status,
			'provider_status_code'=> $provider_status !== '' ? $provider_status : null,
			'source_code'         => 'callback',
			'message'             => 'Статус обновлён через callback от провайдера',
			'raw_payload_json'    => wp_json_encode( $event, JSON_UNESCAPED_UNICODE ),
			'created_at'          => $now,
		]
	);

	// Помечаем callback-запись как обработанную
	if ( $callback_id ) {
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'crm_fintech_payment_callbacks',
			[
				'payment_order_id'  => $order_db_id,
				'processing_status' => 'processed',
				'processed_at'      => $now,
			],
			[ 'id' => $callback_id ]
		);
	}

	crm_log( 'payment.callback.processed', [
		'category'    => 'payments',
		'level'       => 'info',
		'action'      => 'callback',
		'message'     => "Статус ордера обновлён: {$old_status} → {$new_status}",
		'target_type' => 'payment_order',
		'target_id'   => $order_db_id,
		'is_success'  => true,
		'context'     => [
			'merchant_order_id' => $merchant_order_id,
			'old_status'        => $old_status,
			'new_status'        => $new_status,
			'provider_status'   => $provider_status,
			'provider'          => $event['provider'] ?? null,
		],
	] );

	if ( $new_status === 'paid' ) {
		crm_merchant_create_paid_order_accrual( $order_db_id, 'callback' );
	}
}
