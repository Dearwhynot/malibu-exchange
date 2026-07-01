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
 * Friendly Pay: created, initialized, success, failed, expired
 */
function _crm_fintech_map_status( string $provider_status ): string {
	static $map = [
		'CREATED'             => 'created',
		'QRCDATA_CREATED'     => 'created',
		'INITIALIZED'         => 'pending',
		'PROCESSING'          => 'pending',
		'IN_PROCESS'          => 'pending',
		'PAYOUT_IN_PROGRESS'  => 'pending',
		'QRCDATA_IN_PROGRESS' => 'pending',
		'CHARGE_IN_PROGRESS'  => 'pending',
		'AUTHORIZATION'       => 'pending',
		'PRE_AUTHORIZED_3DS'  => 'pending',
		'IPS_ACCEPTED'        => 'paid',
		'SUCCESS'             => 'paid',
		'PAID'                => 'paid',
		'CHARGED'             => 'paid',
		'FAILED'              => 'declined',
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
	$amount_asset_code = strtoupper( trim( (string) ( $invoice['amountAssetCode'] ?? 'USDT' ) ) );
	$amount_asset_value = isset( $invoice['amountAssetValue'] ) ? (float) $invoice['amountAssetValue'] : $amount_usdt;
	$payment_currency_code = strtoupper( trim( (string) ( $invoice['paymentCurrencyCode'] ?? 'RUB' ) ) );
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
		'amount_asset_code'             => $amount_asset_code !== '' ? $amount_asset_code : 'USDT',
		'amount_asset_value'            => $amount_asset_value,
		'payment_currency_code'         => $payment_currency_code !== '' ? $payment_currency_code : 'RUB',
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
	array $success_context = [],
	array $save_overrides = []
): array {
	$order_db_id = crm_fintech_save_order( $invoice, $company_id, $source_channel, $created_by_user_id, $save_overrides );

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
		'amount_asset_code'    => isset( $invoice['amountAssetCode'] ) ? (string) $invoice['amountAssetCode'] : null,
		'amount_asset_value'   => isset( $invoice['amountAssetValue'] ) ? (float) $invoice['amountAssetValue'] : null,
		'payment_amount_rub'   => isset( $invoice['paymentAmountRub'] ) ? round( $invoice['paymentAmountRub'] / 100, 2 ) : null,
		'payment_amount_value' => isset( $invoice['paymentAmountValue'] ) ? (float) $invoice['paymentAmountValue'] : null,
		'payment_currency_code'=> isset( $invoice['paymentCurrencyCode'] ) ? (string) $invoice['paymentCurrencyCode'] : null,
		'warning'              => isset( $invoice['warning'] ) ? (string) $invoice['warning'] : null,
		'error'                => null,
	];
}

/**
 * Определяет input contour для company create-order form.
 * `rub` включается только для Kanyon-компаний с `fintech_pay2day_order_currency = RUB`.
 * `friendly_pay_rub` включается для Friendly Pay SBP.
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
	if ( $provider_code === 'friendly_pay' ) {
		return 'friendly_pay_rub';
	}

	return 'usdt';
}

/**
 * Отдельный web-only markup для RUB input поверх legacy Kanyon USDT contour.
 * Нужен только для операторской веб-формы Create Order и не должен молча менять Telegram flow.
 */
function crm_fintech_order_amount_rub_input_markup_percent(): float {
	return 4.0;
}

/**
 * Дефолтный режим ввода для веб-формы create-order.
 * Возвращает legacy/основной mode, не подмешивая дополнительные экспериментальные формы.
 */
function crm_fintech_company_web_create_order_input_mode( int $company_id ): string {
	if ( $company_id <= 0 ) {
		return 'usdt';
	}

	return crm_fintech_company_create_order_input_mode( $company_id );
}

/**
 * Все доступные режимы create-order именно для веба.
 *
 * @return string[]
 */
function crm_fintech_company_web_create_order_supported_modes( int $company_id ): array {
	$default_mode = crm_fintech_company_web_create_order_input_mode( $company_id );
	$modes        = [ $default_mode ];

	if ( $company_id <= 0 ) {
		return $modes;
	}

	$provider_code  = crm_fintech_normalize_provider_code(
		(string) crm_get_setting( 'fintech_active_provider', $company_id, '' )
	);
	$order_currency = crm_fintech_normalize_kanyon_order_currency(
		(string) crm_get_setting( 'fintech_pay2day_order_currency', $company_id, '' )
	);

	if ( $provider_code === 'kanyon' && $order_currency === 'USDT' ) {
		$modes[] = 'rub_usdt';
		$modes[] = 'rub_usdt_live';

		if ( function_exists( 'crm_company_contour_is_enabled' ) && crm_company_contour_is_enabled( $company_id, 'RUB_THB' ) ) {
			$modes[] = 'rub_thb_rub_rapira';
			$modes[] = 'rub_thb_rub_live';
			$modes[] = 'rub_thb_thb_rapira';
			$modes[] = 'rub_thb_thb_live';
		}
	}

	$modes = array_values( array_unique( array_filter( array_map( 'sanitize_key', $modes ) ) ) );

	return ! empty( $modes ) ? $modes : [ 'usdt' ];
}

/**
 * Человекочитаемая подпись режима create-order.
 */
function crm_fintech_create_order_mode_label( string $mode ): string {
	$mode = sanitize_key( $mode );

	switch ( $mode ) {
		case 'friendly_pay_rub':
			return 'Friendly Pay SBP · RUB';
		case 'rub':
			return 'RUB paymentAmount';
		case 'rub_usdt':
			return 'RUB -> USDT · Rapira + 4%';
		case 'rub_usdt_live':
			return 'RUB -> USDT · Live Kanyon';
		case 'rub_thb_rub_rapira':
			return 'THB contour · RUB input · Rapira';
		case 'rub_thb_rub_live':
			return 'THB contour · RUB input · Live Kanyon';
		case 'rub_thb_thb_rapira':
			return 'THB contour · THB input · Rapira';
		case 'rub_thb_thb_live':
			return 'THB contour · THB input · Live Kanyon';
		case 'usdt':
		default:
			return 'Legacy USDT / orderAmount';
	}
}

/**
 * Валюта ввода для режима create-order.
 */
function crm_fintech_create_order_mode_input_currency( string $mode ): string {
	$mode = sanitize_key( $mode );

	if ( in_array( $mode, [ 'friendly_pay_rub', 'rub', 'rub_usdt', 'rub_usdt_live', 'rub_thb_rub_rapira', 'rub_thb_rub_live' ], true ) ) {
		return 'RUB';
	}

	if ( in_array( $mode, [ 'rub_thb_thb_rapira', 'rub_thb_thb_live' ], true ) ) {
		return 'THB';
	}

	return 'USDT';
}

/**
 * Пример значения для поля ввода в зависимости от режима create-order.
 */
function crm_fintech_create_order_mode_input_example( string $mode ): string {
	switch ( crm_fintech_create_order_mode_input_currency( $mode ) ) {
		case 'RUB':
			return '30000';
		case 'THB':
			return '10000';
		case 'USDT':
		default:
			return '150.50';
	}
}

/**
 * Единая точка входа для company-order create по mode-коду.
 *
 * @return array<string,mixed>
 */
function crm_fintech_create_company_order_from_mode(
	string $mode,
	float $amount_value,
	int $company_id,
	string $source_channel = 'web',
	?int $created_by_user_id = null,
	string $description = ''
): array {
	$mode         = sanitize_key( $mode );
	$amount_value = (float) $amount_value;

	switch ( $mode ) {
		case 'friendly_pay_rub':
		case 'rub':
			return crm_fintech_create_order_by_payment_amount(
				$amount_value,
				'RUB',
				$company_id,
				$source_channel,
				$created_by_user_id,
				$description
			);
		case 'rub_usdt':
			return crm_fintech_create_usdt_order_from_rub_amount(
				$amount_value,
				$company_id,
				$source_channel,
				$created_by_user_id,
				$description
			);
		case 'rub_usdt_live':
			return crm_fintech_create_usdt_order_from_rub_amount_via_live_kanyon(
				$amount_value,
				$company_id,
				$source_channel,
				$created_by_user_id,
				$description
			);
		case 'rub_thb_rub_rapira':
			return crm_fintech_create_usdt_order_for_rub_thb_direction(
				$amount_value,
				'RUB',
				'rapira',
				$company_id,
				$source_channel,
				$created_by_user_id,
				$description
			);
		case 'rub_thb_rub_live':
			return crm_fintech_create_usdt_order_for_rub_thb_direction(
				$amount_value,
				'RUB',
				'live_kanyon',
				$company_id,
				$source_channel,
				$created_by_user_id,
				$description
			);
		case 'rub_thb_thb_rapira':
			return crm_fintech_create_usdt_order_for_rub_thb_direction(
				$amount_value,
				'THB',
				'rapira',
				$company_id,
				$source_channel,
				$created_by_user_id,
				$description
			);
		case 'rub_thb_thb_live':
			return crm_fintech_create_usdt_order_for_rub_thb_direction(
				$amount_value,
				'THB',
				'live_kanyon',
				$company_id,
				$source_channel,
				$created_by_user_id,
				$description
			);
		case 'usdt':
		default:
			return crm_fintech_create_order(
				$amount_value,
				$company_id,
				$source_channel,
				$created_by_user_id,
				$description
			);
	}
}

/**
 * Контекст сохранённого corporate RUB/THB курса для web THB-flows.
 * Курс = стоимость 1 THB в RUB (колонка "Наш Sberbank").
 *
 * @return array<string,mixed>
 */
function crm_fintech_company_web_rub_thb_context( int $company_id ): array {
	$context = [
		'success'             => false,
		'error'               => '',
		'company_id'          => $company_id,
		'rub_per_thb_rate'    => null,
		'rate_updated_at_utc' => null,
		'rate_source'         => 'our_sberbank_rate',
		'provider'            => RATES_PROVIDER_EX24,
		'source_param'        => RATES_PROVIDER_SOURCE,
		'pair_id'             => 0,
	];

	if ( $company_id <= 0 ) {
		$context['error'] = 'Отсутствует валидная компания.';
		return $context;
	}

	if ( function_exists( 'crm_company_contour_is_enabled' ) && ! crm_company_contour_is_enabled( $company_id, 'RUB_THB' ) ) {
		$context['error'] = 'Направление RUB -> THB не включено для этой компании.';
		return $context;
	}

	$pair = function_exists( 'rates_get_pair' ) ? rates_get_pair( 'RUB_THB', $company_id ) : null;
	if ( ! $pair || empty( $pair->id ) ) {
		$context['error'] = 'Для компании не настроена активная пара RUB/THB.';
		return $context;
	}

	$context['pair_id'] = (int) $pair->id;
	$last = function_exists( 'rates_get_last_snapshot' )
		? rates_get_last_snapshot( (int) $pair->id, $company_id, RATES_PROVIDER_EX24, RATES_PROVIDER_SOURCE )
		: null;

	if ( ! is_array( $last ) || ! isset( $last['our_sberbank_rate'] ) || (float) $last['our_sberbank_rate'] <= 0 ) {
		$context['error'] = 'Для компании ещё нет сохранённого курса "Наш Sberbank" по направлению RUB/THB.';
		return $context;
	}

	$context['success']             = true;
	$context['rub_per_thb_rate']    = round( (float) $last['our_sberbank_rate'], 4 );
	$context['rate_updated_at_utc'] = (string) ( $last['created_at'] ?? '' );
	$context['error']               = '';

	return $context;
}

/**
 * Нормализует THB-aware ввод для create-order:
 * - если оператор вводит RUB, считаем эквивалент THB по сохранённому Sber курсу;
 * - если оператор вводит THB, считаем целевой RUB invoice по тому же курсу.
 *
 * @return array<string,mixed>
 */
function crm_fintech_company_web_rub_thb_target_context(
	int $company_id,
	string $input_currency,
	float $input_amount
): array {
	$input_currency = strtoupper( trim( $input_currency ) );
	$input_amount   = round( max( 0, $input_amount ), 2 );
	$rate_context   = crm_fintech_company_web_rub_thb_context( $company_id );

	$result = [
		'success'             => false,
		'error'               => (string) ( $rate_context['error'] ?? '' ),
		'company_id'          => $company_id,
		'input_currency'      => $input_currency,
		'input_amount'        => $input_amount,
		'target_invoice_rub'  => 0.0,
		'target_amount_thb'   => 0.0,
		'rub_per_thb_rate'    => isset( $rate_context['rub_per_thb_rate'] ) ? (float) $rate_context['rub_per_thb_rate'] : 0.0,
		'rate_updated_at_utc' => (string) ( $rate_context['rate_updated_at_utc'] ?? '' ),
		'rate_source'         => (string) ( $rate_context['rate_source'] ?? 'our_sberbank_rate' ),
	];

	if ( empty( $rate_context['success'] ) ) {
		return $result;
	}

	$rate = (float) $rate_context['rub_per_thb_rate'];
	if ( $rate <= 0 ) {
		$result['error'] = 'Некорректный corporate курс RUB/THB.';
		return $result;
	}

	if ( ! in_array( $input_currency, [ 'RUB', 'THB' ], true ) ) {
		$result['error'] = 'Поддерживаются только RUB и THB inputs.';
		return $result;
	}

	if ( $input_amount <= 0 ) {
		$result['error'] = $input_currency === 'THB'
			? 'Укажите корректную сумму THB.'
			: 'Укажите корректную сумму RUB.';
		return $result;
	}

	if ( $input_currency === 'THB' ) {
		$result['target_amount_thb']  = $input_amount;
		$result['target_invoice_rub'] = round( $input_amount * $rate, 2 );
	} else {
		$result['target_invoice_rub'] = $input_amount;
		$result['target_amount_thb']  = round( $input_amount / $rate, 2 );
	}

	$result['success'] = $result['target_invoice_rub'] > 0 && $result['target_amount_thb'] > 0;
	if ( ! $result['success'] ) {
		$result['error'] = 'Не удалось рассчитать RUB/THB контекст для ордера.';
	}

	return $result;
}

/**
 * Дописывает к уже созданному company-order THB-метаданные.
 */
function crm_fintech_company_order_append_meta( int $order_db_id, int $company_id, array $meta_append, string $notes_suffix = '' ): void {
	global $wpdb;

	if ( $order_db_id <= 0 || $company_id <= 0 || empty( $meta_append ) ) {
		return;
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT meta_json, notes
			 FROM crm_fintech_payment_orders
			 WHERE id = %d
			   AND company_id = %d
			 LIMIT 1',
			$order_db_id,
			$company_id
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return;
	}

	$current_meta = [];
	if ( isset( $row['meta_json'] ) && is_string( $row['meta_json'] ) && trim( $row['meta_json'] ) !== '' ) {
		$decoded = json_decode( $row['meta_json'], true );
		if ( is_array( $decoded ) ) {
			$current_meta = $decoded;
		}
	}

	$updated_meta = array_merge( $current_meta, $meta_append );
	$update       = [
		'meta_json'   => wp_json_encode( $updated_meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		'updated_at'  => current_time( 'mysql' ),
	];
	$formats      = [ '%s', '%s' ];

	if ( $notes_suffix !== '' ) {
		$existing_notes  = isset( $row['notes'] ) ? trim( (string) $row['notes'] ) : '';
		$update['notes'] = trim( $existing_notes !== '' ? ( $existing_notes . ' ' . $notes_suffix ) : $notes_suffix );
		$formats[]       = '%s';
	}

	$wpdb->update(
		'crm_fintech_payment_orders',
		$update,
		[
			'id'         => $order_db_id,
			'company_id' => $company_id,
		],
		$formats,
		[ '%d', '%d' ]
	);
}

/**
 * THB-aware web create-order flow поверх legacy Kanyon USDT contour.
 * Сначала приводит ввод RUB/THB к целевому RUB invoice, потом использует
 * один из уже рабочих USDT quote paths: Rapira + 4% или live Kanyon.
 *
 * @return array<string,mixed>
 */
function crm_fintech_create_usdt_order_for_rub_thb_direction(
	float $input_amount,
	string $input_currency,
	string $quote_strategy,
	int $company_id = 0,
	string $source_channel = 'web',
	?int $created_by_user_id = null,
	string $description = ''
): array {
	$input_currency = strtoupper( trim( $input_currency ) );
	$quote_strategy = sanitize_key( $quote_strategy );
	$input_amount   = round( max( 0, $input_amount ), 2 );

	$target = crm_fintech_company_web_rub_thb_target_context( $company_id, $input_currency, $input_amount );
	if ( empty( $target['success'] ) ) {
		return [
			'success'     => false,
			'order_db_id' => null,
			'error'       => $target['error'] ?? 'Не удалось подготовить RUB/THB ордер.',
		];
	}

	$target_rub = round( (float) $target['target_invoice_rub'], 2 );
	if ( $target_rub <= 0 ) {
		return [
			'success'     => false,
			'order_db_id' => null,
			'error'       => 'После расчёта RUB/THB получилась некорректная сумма RUB.',
		];
	}

	$create = $quote_strategy === 'live_kanyon'
		? crm_fintech_create_usdt_order_from_rub_amount_via_live_kanyon(
			$target_rub,
			$company_id,
			$source_channel,
			$created_by_user_id,
			$description
		)
		: crm_fintech_create_usdt_order_from_rub_amount(
			$target_rub,
			$company_id,
			$source_channel,
			$created_by_user_id,
			$description
		);

	if ( empty( $create['success'] ) ) {
		return $create;
	}

	$order_db_id = isset( $create['order_db_id'] ) ? (int) $create['order_db_id'] : 0;
	$meta_append = [
		'company_direction_flow' => 'web_rub_thb',
		'input_currency'         => $input_currency,
		'input_amount'           => $input_amount,
		'target_invoice_rub'     => $target_rub,
		'target_amount_thb'      => round( (float) $target['target_amount_thb'], 2 ),
		'rub_per_thb_rate'       => round( (float) $target['rub_per_thb_rate'], 4 ),
		'rate_updated_at_utc'    => (string) ( $target['rate_updated_at_utc'] ?? '' ),
		'rate_source'            => (string) ( $target['rate_source'] ?? 'our_sberbank_rate' ),
		'usdt_quote_strategy'    => $quote_strategy === 'live_kanyon'
			? 'kanyon_test_order_100_usdt'
			: 'rapira_ask_plus_4_percent',
	];

	if ( $order_db_id > 0 ) {
		$notes_suffix = sprintf(
			'THB contour context: %s %.2f -> target %.2f RUB @ %.4f RUB/THB.',
			$input_currency,
			$input_amount,
			$target_rub,
			(float) $target['rub_per_thb_rate']
		);

		crm_fintech_company_order_append_meta( $order_db_id, $company_id, $meta_append, $notes_suffix );
	}

	$create['thb_input_currency'] = $input_currency;
	$create['thb_input_amount']   = $input_amount;
	$create['target_amount_thb']  = round( (float) $target['target_amount_thb'], 2 );
	$create['target_invoice_rub'] = $target_rub;
	$create['thb_rate']           = round( (float) $target['rub_per_thb_rate'], 4 );

	crm_log( 'payment.order.thb_context_applied', [
		'category'    => 'payments',
		'level'       => 'info',
		'action'      => 'create',
		'message'     => 'К company-order применён RUB/THB контекст.',
		'target_type' => 'payment_order',
		'target_id'   => $order_db_id > 0 ? $order_db_id : null,
		'is_success'  => true,
		'context'     => [
			'company_id'         => $company_id,
			'order_db_id'        => $order_db_id,
			'source_channel'     => $source_channel,
			'input_currency'     => $input_currency,
			'input_amount'       => $input_amount,
			'target_invoice_rub' => $target_rub,
			'target_amount_thb'  => $create['target_amount_thb'],
			'thb_rate'           => $create['thb_rate'],
			'quote_strategy'     => $quote_strategy,
		],
	] );

	return $create;
}

/**
 * Снимает live quote RUB/USDT через тестовый ордер Kanyon на 100 USDT.
 * Используется именно в create-order flow, без cooldown страницы курсов.
 *
 * @return array<string,mixed>
 */
function crm_fintech_fetch_live_kanyon_rub_usdt_quote(
	int $company_id,
	string $source_channel = 'web',
	?int $created_by_user_id = null
): array {
	if ( $company_id <= 0 ) {
		return [
			'ok'    => false,
			'error' => 'Отсутствует валидная компания для live Kanyon quote.',
		];
	}

	if ( ! function_exists( 'rates_kanyon_is_enabled_for_company' ) || ! rates_kanyon_is_enabled_for_company( $company_id ) ) {
		return [
			'ok'    => false,
			'error' => 'Kanyon не активен или не настроен для этой компании.',
		];
	}

	Fintech_Payment_Gateway::set_company_id( $company_id );
	$result = Fintech_Payment_Gateway::create_invoice( RATES_KANYON_TEST_AMOUNT_USDT, null, 'kanyon_live_quote' );

	if ( empty( $result['success'] ) ) {
		crm_log( 'payment.order.kanyon_live_quote_failed', [
			'category'    => 'payments',
			'level'       => 'error',
			'action'      => 'quote',
			'message'     => 'Не удалось получить live Kanyon quote через тестовый ордер.',
			'target_type' => 'payment_order',
			'is_success'  => false,
			'context'     => [
				'company_id'      => $company_id,
				'source_channel'  => $source_channel,
				'test_amount_usdt'=> RATES_KANYON_TEST_AMOUNT_USDT,
				'provider'        => $result['provider'] ?? Fintech_Payment_Gateway::PROVIDER_KANYON,
				'error'           => $result['error'] ?? null,
			],
		] );

		return [
			'ok'    => false,
			'error' => $result['error'] ?? 'Ошибка Kanyon API при live quote.',
		];
	}

	$order_db_id = crm_fintech_save_order(
		$result,
		$company_id,
		'rate_check',
		$created_by_user_id
	);

	if ( ! $order_db_id ) {
		crm_log( 'payment.order.kanyon_live_quote_local_save_failed', [
			'category'    => 'payments',
			'level'       => 'error',
			'action'      => 'quote',
			'message'     => 'Kanyon live quote создан у провайдера, но не сохранился локально.',
			'target_type' => 'payment_order',
			'is_success'  => false,
			'context'     => [
				'company_id'        => $company_id,
				'source_channel'    => $source_channel,
				'provider_order_id' => $result['orderId'] ?? null,
				'merchant_order_id' => $result['merchantOrderId'] ?? null,
			],
		] );

		return [
			'ok'    => false,
			'error' => 'Kanyon создал live quote, но он не сохранился локально.',
		];
	}

	$payment_kopecks = isset( $result['paymentAmountRub'] ) ? (int) $result['paymentAmountRub'] : null;
	$order_cents     = isset( $result['orderAmountCents'] ) ? (int) $result['orderAmountCents'] : null;

	if ( ! $payment_kopecks || ! $order_cents ) {
		if ( function_exists( 'rates_kanyon_mark_order_untracked' ) ) {
			rates_kanyon_mark_order_untracked(
				(int) $order_db_id,
				0.0,
				null,
				'web',
				[
					'purpose'        => 'kanyon_live_quote',
					'local_order_ref'=> 'kanyon_live_quote',
					'notes'          => 'kanyon_live_quote',
					'status_reason'  => 'Kanyon live quote order. Not tracked by payment polling.',
					'history_message'=> 'Тестовый ордер Kanyon для live quote помечен как неотслеживаемый',
				]
			);
		}

		crm_log( 'payment.order.kanyon_live_quote_invalid_response', [
			'category'    => 'payments',
			'level'       => 'error',
			'action'      => 'quote',
			'message'     => 'Kanyon live quote не вернул payment amount.',
			'target_type' => 'payment_order',
			'target_id'   => (int) $order_db_id,
			'is_success'  => false,
			'context'     => [
				'company_id'        => $company_id,
				'source_channel'    => $source_channel,
				'provider_order_id' => $result['orderId'] ?? null,
				'merchant_order_id' => $result['merchantOrderId'] ?? null,
			],
		] );

		return [
			'ok'    => false,
			'error' => 'Kanyon не вернул RUB-сумму для live quote.',
		];
	}

	$kanyon_rate         = round( $payment_kopecks / $order_cents, 4 );
	$quote_payment_rub   = round( $payment_kopecks / 100, 2 );
	$quote_amount_usdt   = round( $order_cents / 100, 2 );
	$rapira              = function_exists( 'rates_get_rapira' ) ? rates_get_rapira() : [ 'ok' => false ];
	$rapira_rate         = ( ! empty( $rapira['ok'] ) && isset( $rapira['bid'], $rapira['ask'] ) )
		? round( ( (float) $rapira['bid'] + (float) $rapira['ask'] ) / 2, 4 )
		: null;

	if ( function_exists( 'rates_kanyon_mark_order_untracked' ) ) {
		rates_kanyon_mark_order_untracked(
			(int) $order_db_id,
			$kanyon_rate,
			$rapira_rate,
			'web',
			[
				'purpose'        => 'kanyon_live_quote',
				'local_order_ref'=> 'kanyon_live_quote',
				'notes'          => 'kanyon_live_quote',
				'status_reason'  => 'Kanyon live quote order. Not tracked by payment polling.',
				'history_message'=> 'Тестовый ордер Kanyon для live quote помечен как неотслеживаемый',
			]
		);
	}

	crm_log( 'payment.order.kanyon_live_quote_received', [
		'category'    => 'payments',
		'level'       => 'info',
		'action'      => 'quote',
		'message'     => 'Получен live Kanyon quote через тестовый ордер 100 USDT.',
		'target_type' => 'payment_order',
		'target_id'   => (int) $order_db_id,
		'is_success'  => true,
		'context'     => [
			'company_id'          => $company_id,
			'source_channel'      => $source_channel,
			'provider_order_id'   => $result['orderId'] ?? null,
			'merchant_order_id'   => $result['merchantOrderId'] ?? null,
			'quote_amount_usdt'   => $quote_amount_usdt,
			'quote_payment_rub'   => $quote_payment_rub,
			'kanyon_rate'         => $kanyon_rate,
			'rapira_rate'         => $rapira_rate,
		],
	] );

	return [
		'ok'                 => true,
		'payment_order_id'   => (int) $order_db_id,
		'provider_order_id'  => (string) ( $result['orderId'] ?? '' ),
		'merchant_order_id'  => (string) ( $result['merchantOrderId'] ?? '' ),
		'quote_amount_usdt'  => $quote_amount_usdt,
		'quote_payment_rub'  => $quote_payment_rub,
		'kanyon_rate'        => $kanyon_rate,
		'rapira_rate'        => $rapira_rate,
	];
}

/**
 * Возвращает превью курса для web-only RUB -> USDT create-order form.
 *
 * @return array<string,mixed>
 */
function crm_fintech_company_web_rub_usdt_preview_context(
	int $company_id,
	float $requested_rub = 0.0,
	bool $refresh_market = false
): array {
	$requested_rub  = round( max( 0, $requested_rub ), 2 );
	$sample_rub     = $requested_rub > 0 ? $requested_rub : 30000.0;
	$markup_percent = round( max( 0, crm_fintech_order_amount_rub_input_markup_percent() ), 4 );
	$context        = [
		'success'                 => false,
		'error'                   => 'Не удалось получить курс Rapira для расчёта RUB -> USDT.',
		'warning'                 => '',
		'requested_rub'           => $requested_rub,
		'sample_requested_rub'    => $sample_rub,
		'rapira_ask'              => null,
		'markup_percent'          => $markup_percent,
		'effective_rate'          => null,
		'amount_usdt'             => 0.0,
		'sample_amount_usdt'      => 0.0,
		'estimated_payment_rub'   => 0.0,
		'checked_at'              => current_time( 'd.m.Y H:i' ),
	];

	if ( $company_id <= 0 ) {
		$context['error'] = 'Отсутствует валидный company context.';
		return $context;
	}

	if ( ! function_exists( 'crm_merchant_calculate_rub_invoice_economics' ) ) {
		$context['error'] = 'Модуль расчёта RUB -> USDT недоступен.';
		return $context;
	}

	$market = $refresh_market ? rates_get_rapira() : rates_get_rapira_cached();
	if ( $refresh_market && ! empty( $market['ok'] ) ) {
		set_transient( 'me_rapira_rates', $market, RATES_MARKET_CACHE_TTL );
	}

	$rapira_ask = ( ! empty( $market['ok'] ) && ! empty( $market['ask'] ) && (float) $market['ask'] > 0 )
		? round( (float) $market['ask'], 8 )
		: null;

	if ( $rapira_ask === null ) {
		$context['error'] = 'Не удалось получить живой курс Rapira Ask.';
		if ( ! empty( $market['error'] ) ) {
			$context['error'] .= ' ' . (string) $market['error'];
		}

		return $context;
	}

	$economics = crm_merchant_calculate_rub_invoice_economics(
		$rapira_ask,
		$markup_percent,
		0.0,
		'acquirer_cost',
		$sample_rub,
		'none'
	);
	$effective_rate = isset( $economics['merchant_rate_commercial'] )
		? round( max( 0, (float) $economics['merchant_rate_commercial'] ), 4 )
		: 0.0;

	if ( $effective_rate <= 0 ) {
		$context['error'] = 'Не удалось рассчитать итоговый курс RUB за 1 USDT.';
		return $context;
	}

	$sample_amount_usdt = round( $sample_rub / $effective_rate, 2 );
	$amount_usdt        = $requested_rub > 0 ? round( $requested_rub / $effective_rate, 2 ) : 0.0;
	$estimated_rub      = $amount_usdt > 0 ? round( $amount_usdt * $effective_rate, 2 ) : 0.0;

	$context['success']               = true;
	$context['error']                 = '';
	$context['rapira_ask']            = $rapira_ask;
	$context['effective_rate']        = $effective_rate;
	$context['sample_amount_usdt']    = $sample_amount_usdt;
	$context['amount_usdt']           = $amount_usdt;
	$context['estimated_payment_rub'] = $estimated_rub;

	return $context;
}

/**
 * Web-only flow: оператор вводит сумму в RUB, а под капотом создаётся legacy Kanyon USDT orderAmount.
 * Формула расчёта: Rapira Ask + 4%.
 */
function crm_fintech_create_usdt_order_from_rub_amount(
	float $requested_rub,
	int $company_id = 0,
	string $source_channel = 'web',
	?int $created_by_user_id = null,
	string $description = ''
): array {
	$requested_rub = round( max( 0, $requested_rub ), 2 );

	$preflight = _crm_fintech_prepare_create_order(
		$company_id,
		$source_channel,
		[
			'requested_rub' => $requested_rub,
			'input_mode'    => 'rub_usdt',
		]
	);

	if ( empty( $preflight['success'] ) ) {
		return [
			'success'     => false,
			'order_db_id' => null,
			'error'       => $preflight['error'] ?? 'Ошибка создания ордера.',
		];
	}

	$active_provider = (string) ( $preflight['active_provider'] ?? '' );
	$order_currency  = crm_fintech_normalize_kanyon_order_currency(
		(string) crm_get_setting( 'fintech_pay2day_order_currency', $company_id, '' )
	);

	if ( $active_provider !== Fintech_Payment_Gateway::PROVIDER_KANYON || $order_currency !== 'USDT' ) {
		$error_message = 'RUB -> USDT web-flow доступен только для Kanyon-компаний с валютой USDT.';

		crm_log( 'payment.order.rub_input_unavailable', [
			'category'    => 'payments',
			'level'       => 'warning',
			'action'      => 'create',
			'message'     => $error_message,
			'target_type' => 'payment_order',
			'is_success'  => false,
			'context'     => [
				'company_id'      => $company_id,
				'source_channel'  => $source_channel,
				'requested_rub'   => $requested_rub,
				'active_provider' => $active_provider,
				'order_currency'  => $order_currency,
			],
		] );

		return [
			'success'     => false,
			'order_db_id' => null,
			'error'       => $error_message,
		];
	}

	$quote = crm_fintech_company_web_rub_usdt_preview_context( $company_id, $requested_rub, true );
	if ( empty( $quote['success'] ) || empty( $quote['amount_usdt'] ) ) {
		$error_message = (string) ( $quote['error'] ?? 'Не удалось рассчитать USDT по курсу Rapira.' );

		crm_log( 'payment.order.rub_input_quote_failed', [
			'category'    => 'payments',
			'level'       => 'error',
			'action'      => 'create',
			'message'     => 'Не удалось рассчитать RUB -> USDT quote для web order.',
			'target_type' => 'payment_order',
			'is_success'  => false,
			'context'     => [
				'company_id'     => $company_id,
				'source_channel' => $source_channel,
				'requested_rub'  => $requested_rub,
				'quote_error'    => $error_message,
			],
		] );

		return [
			'success'     => false,
			'order_db_id' => null,
			'error'       => $error_message,
		];
	}

	$amount_usdt    = round( max( 0, (float) $quote['amount_usdt'] ), 2 );
	$rapira_ask     = isset( $quote['rapira_ask'] ) ? (float) $quote['rapira_ask'] : 0.0;
	$effective_rate = isset( $quote['effective_rate'] ) ? (float) $quote['effective_rate'] : 0.0;
	$markup_percent = isset( $quote['markup_percent'] ) ? (float) $quote['markup_percent'] : 0.0;

	if ( $amount_usdt <= 0 || $effective_rate <= 0 || $rapira_ask <= 0 ) {
		return [
			'success'     => false,
			'order_db_id' => null,
			'error'       => 'Не удалось получить валидный quote для создания ордера.',
		];
	}

	Fintech_Payment_Gateway::set_company_id( $company_id );
	$invoice = Fintech_Payment_Gateway::create_invoice( $amount_usdt, null, $description );

	if ( empty( $invoice['success'] ) ) {
		crm_log( 'payment.order.create_failed', [
			'category'    => 'payments',
			'level'       => 'error',
			'action'      => 'create',
			'message'     => 'Ошибка создания RUB -> USDT web-ордера: ' . ( $invoice['error'] ?? 'unknown' ),
			'target_type' => 'payment_order',
			'is_success'  => false,
			'context'     => [
				'company_id'      => $company_id,
				'source_channel'  => $source_channel,
				'requested_rub'   => $requested_rub,
				'amount_usdt'     => $amount_usdt,
				'rapira_ask'      => $rapira_ask,
				'effective_rate'  => $effective_rate,
				'markup_percent'  => $markup_percent,
				'provider'        => $invoice['provider'] ?? null,
				'error'           => $invoice['error'] ?? null,
			],
		] );

		return [
			'success'     => false,
			'order_db_id' => null,
			'error'       => $invoice['error'] ?? 'Gateway error',
		];
	}

	$provider_payment_rub = isset( $invoice['paymentAmountRub'] ) && $invoice['paymentAmountRub'] !== null
		? round( (float) $invoice['paymentAmountRub'] / 100, 2 )
		: null;
	$warning_parts        = [];

	if ( ! empty( $invoice['warning'] ) ) {
		$warning_parts[] = trim( (string) $invoice['warning'] );
	}

	if ( $provider_payment_rub !== null ) {
		$delta_rub = round( $provider_payment_rub - $requested_rub, 2 );
		if ( abs( $delta_rub ) >= 1 ) {
			$warning_parts[] = sprintf(
				'Ориентир по форме: %s RUB. Провайдер выставил: %s RUB.',
				number_format( $requested_rub, 2, '.', ' ' ),
				number_format( $provider_payment_rub, 2, '.', ' ' )
			);
		}
	}

	$invoice['warning'] = ! empty( $warning_parts ) ? implode( ' ', $warning_parts ) : null;

	$meta_json = wp_json_encode(
		[
			'company_flow'             => 'web_rub_input_kanyon_order_amount',
			'pricing_source'           => 'rapira_ask_plus_4_percent',
			'requested_rub_input'      => $requested_rub,
			'calculated_amount_usdt'   => $amount_usdt,
			'estimated_payment_rub'    => isset( $quote['estimated_payment_rub'] ) ? (float) $quote['estimated_payment_rub'] : null,
			'provider_payment_rub'     => $provider_payment_rub,
			'rapira_ask'               => $rapira_ask,
			'markup_percent'           => $markup_percent,
			'effective_rate'           => $effective_rate,
			'quote_checked_at_utc'     => current_time( 'mysql', true ),
		],
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);

	$result = _crm_fintech_finalize_created_order(
		$invoice,
		$company_id,
		$source_channel,
		$created_by_user_id,
		[
			'input_mode'      => 'rub_usdt',
			'requested_rub'   => $requested_rub,
			'amount_usdt'     => $amount_usdt,
			'rapira_ask'      => $rapira_ask,
			'effective_rate'  => $effective_rate,
			'markup_percent'  => $markup_percent,
			'pricing_source'  => 'rapira_ask_plus_4_percent',
		],
		[
			'meta_json' => $meta_json,
			'notes'     => 'Company web RUB -> USDT invoice created via Rapira ask + 4% and Kanyon orderAmount flow.',
		]
	);

	$result['requested_amount_rub'] = $requested_rub;
	$result['quote_rate']           = $effective_rate;
	$result['quote_rapira_ask']     = $rapira_ask;
	$result['quote_markup_percent'] = $markup_percent;

	return $result;
}

/**
 * Web-only flow: оператор вводит RUB, сервер сперва получает live Kanyon rate
 * через тестовый ордер 100 USDT, затем сразу создаёт финальный USDT orderAmount.
 */
function crm_fintech_create_usdt_order_from_rub_amount_via_live_kanyon(
	float $requested_rub,
	int $company_id = 0,
	string $source_channel = 'web',
	?int $created_by_user_id = null,
	string $description = ''
): array {
	$requested_rub = round( max( 0, $requested_rub ), 2 );

	$preflight = _crm_fintech_prepare_create_order(
		$company_id,
		$source_channel,
		[
			'requested_rub' => $requested_rub,
			'input_mode'    => 'rub_usdt_live',
		]
	);

	if ( empty( $preflight['success'] ) ) {
		return [
			'success'     => false,
			'order_db_id' => null,
			'error'       => $preflight['error'] ?? 'Ошибка создания ордера.',
		];
	}

	$active_provider = (string) ( $preflight['active_provider'] ?? '' );
	$order_currency  = crm_fintech_normalize_kanyon_order_currency(
		(string) crm_get_setting( 'fintech_pay2day_order_currency', $company_id, '' )
	);

	if ( $active_provider !== Fintech_Payment_Gateway::PROVIDER_KANYON || $order_currency !== 'USDT' ) {
		return [
			'success'     => false,
			'order_db_id' => null,
			'error'       => 'Live Kanyon quote доступен только для Kanyon-компаний с валютой USDT.',
		];
	}

	$quote = crm_fintech_fetch_live_kanyon_rub_usdt_quote(
		$company_id,
		$source_channel,
		$created_by_user_id
	);

	if ( empty( $quote['ok'] ) || empty( $quote['kanyon_rate'] ) ) {
		return [
			'success'     => false,
			'order_db_id' => null,
			'error'       => $quote['error'] ?? 'Не удалось получить live Kanyon quote.',
		];
	}

	$kanyon_rate       = round( max( 0, (float) $quote['kanyon_rate'] ), 4 );
	$quote_amount_usdt = isset( $quote['quote_amount_usdt'] ) ? (float) $quote['quote_amount_usdt'] : RATES_KANYON_TEST_AMOUNT_USDT;
	$quote_payment_rub = isset( $quote['quote_payment_rub'] ) ? (float) $quote['quote_payment_rub'] : 0.0;
	$rapira_rate       = isset( $quote['rapira_rate'] ) && $quote['rapira_rate'] !== null ? (float) $quote['rapira_rate'] : null;
	$quote_order_db_id = isset( $quote['payment_order_id'] ) ? (int) $quote['payment_order_id'] : 0;
	$amount_usdt       = $kanyon_rate > 0 ? round( $requested_rub / $kanyon_rate, 2 ) : 0.0;

	if ( $amount_usdt <= 0 ) {
		return [
			'success'     => false,
			'order_db_id' => null,
			'error'       => 'Live Kanyon quote вернул некорректный курс для расчёта USDT.',
		];
	}

	Fintech_Payment_Gateway::set_company_id( $company_id );
	$invoice = Fintech_Payment_Gateway::create_invoice( $amount_usdt, null, $description );

	if ( empty( $invoice['success'] ) ) {
		crm_log( 'payment.order.create_failed', [
			'category'    => 'payments',
			'level'       => 'error',
			'action'      => 'create',
			'message'     => 'Ошибка создания RUB -> USDT web-ордера через live Kanyon quote.',
			'target_type' => 'payment_order',
			'is_success'  => false,
			'context'     => [
				'company_id'         => $company_id,
				'source_channel'     => $source_channel,
				'requested_rub'      => $requested_rub,
				'amount_usdt'        => $amount_usdt,
				'quote_order_db_id'  => $quote_order_db_id,
				'quote_amount_usdt'  => $quote_amount_usdt,
				'quote_payment_rub'  => $quote_payment_rub,
				'kanyon_rate'        => $kanyon_rate,
				'provider'           => $invoice['provider'] ?? null,
				'error'              => $invoice['error'] ?? null,
			],
		] );

		return [
			'success'     => false,
			'order_db_id' => null,
			'error'       => $invoice['error'] ?? 'Gateway error',
		];
	}

	$provider_payment_rub = isset( $invoice['paymentAmountRub'] ) && $invoice['paymentAmountRub'] !== null
		? round( (float) $invoice['paymentAmountRub'] / 100, 2 )
		: null;
	$warning_parts        = [];

	if ( ! empty( $invoice['warning'] ) ) {
		$warning_parts[] = trim( (string) $invoice['warning'] );
	}

	if ( $provider_payment_rub !== null ) {
		$delta_rub = round( $provider_payment_rub - $requested_rub, 2 );
		if ( abs( $delta_rub ) >= 1 ) {
			$warning_parts[] = sprintf(
				'Ориентир по live Kanyon quote: %s RUB. Провайдер выставил: %s RUB.',
				number_format( $requested_rub, 2, '.', ' ' ),
				number_format( $provider_payment_rub, 2, '.', ' ' )
			);
		}
	}

	$invoice['warning'] = ! empty( $warning_parts ) ? implode( ' ', $warning_parts ) : null;

	$meta_json = wp_json_encode(
		[
			'company_flow'             => 'web_rub_input_kanyon_live_quote',
			'pricing_source'           => 'kanyon_test_order_100_usdt',
			'requested_rub_input'      => $requested_rub,
			'calculated_amount_usdt'   => $amount_usdt,
			'provider_payment_rub'     => $provider_payment_rub,
			'quote_order_db_id'        => $quote_order_db_id,
			'quote_provider_order_id'  => (string) ( $quote['provider_order_id'] ?? '' ),
			'quote_merchant_order_id'  => (string) ( $quote['merchant_order_id'] ?? '' ),
			'quote_amount_usdt'        => $quote_amount_usdt,
			'quote_payment_rub'        => $quote_payment_rub,
			'quote_kanyon_rate'        => $kanyon_rate,
			'quote_rapira_rate'        => $rapira_rate,
			'quote_checked_at_utc'     => current_time( 'mysql', true ),
		],
		JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);

	$result = _crm_fintech_finalize_created_order(
		$invoice,
		$company_id,
		$source_channel,
		$created_by_user_id,
		[
			'input_mode'         => 'rub_usdt_live',
			'requested_rub'      => $requested_rub,
			'amount_usdt'        => $amount_usdt,
			'quote_order_db_id'  => $quote_order_db_id,
			'quote_amount_usdt'  => $quote_amount_usdt,
			'quote_payment_rub'  => $quote_payment_rub,
			'kanyon_rate'        => $kanyon_rate,
			'pricing_source'     => 'kanyon_test_order_100_usdt',
		],
		[
			'meta_json' => $meta_json,
			'notes'     => 'Company web RUB -> USDT invoice created via live Kanyon quote (test order 100 USDT).',
		]
	);

	$result['requested_amount_rub'] = $requested_rub;
	$result['quote_rate']           = $kanyon_rate;
	$result['quote_test_amount_usdt'] = $quote_amount_usdt;
	$result['quote_payment_rub']    = $quote_payment_rub;
	$result['quote_order_db_id']    = $quote_order_db_id;

	return $result;
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
 * @param string $poll_source Источник проверки: poll|cron|service_bot|web_manual|telegram_merchant_manual|...
 * @return array { changed: bool, old_status: string, new_status: string, provider_status: string, error: ?string, status_actions: ?array, paid_actions: ?array }
 */
function crm_fintech_poll_order_status( object $order, string $poll_source = 'poll' ): array {
	global $wpdb;

	$order_db_id       = (int) $order->id;
	$provider_order_id = (string) ( $order->provider_order_id ?? '' );
	$old_status        = (string) ( $order->status_code ?? '' );
	$now               = current_time( 'mysql' );

	if ( $provider_order_id === '' ) {
		return [ 'changed' => false, 'old_status' => $old_status, 'new_status' => $old_status, 'provider_status' => '', 'error' => 'No provider_order_id', 'status_actions' => null, 'paid_actions' => null ];
	}

	$company_id = (int) ( $order->company_id ?? 0 );
	if ( $company_id < 0 ) {
		return [
			'changed'         => false,
			'old_status'      => $old_status,
			'new_status'      => $old_status,
			'provider_status' => '',
			'error'           => 'Order has invalid company scope',
			'status_actions'  => null,
			'paid_actions'    => null,
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

		return [ 'changed' => false, 'old_status' => $old_status, 'new_status' => $old_status, 'provider_status' => '', 'error' => $status_result['error'] ?? 'Status check failed', 'status_actions' => null, 'paid_actions' => null ];
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
			if ( isset( $order_data_result['amountAssetValue'] ) && is_numeric( $order_data_result['amountAssetValue'] ) && (float) $order_data_result['amountAssetValue'] > 0 ) {
				$update['amount_asset_value'] = round( (float) $order_data_result['amountAssetValue'], 8 );
			}
			if ( ! empty( $order_data_result['amountAssetCode'] ) ) {
				$update['amount_asset_code'] = strtoupper( trim( (string) $order_data_result['amountAssetCode'] ) );
			}
			if ( ! empty( $order_data_result['paymentCurrencyCode'] ) ) {
				$update['payment_currency_code'] = strtoupper( trim( (string) $order_data_result['paymentCurrencyCode'] ) );
			}
			if (
				isset( $order_data_result['order']['orderAmount'] )
				&& $order_data_result['order']['orderAmount'] !== null
				&& is_scalar( $order_data_result['order']['orderAmount'] )
				&& is_numeric( $order_data_result['order']['orderAmount'] )
				&& (int) $order_data_result['order']['orderAmount'] > 0
				&& empty( $update['amount_asset_value'] )
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
			} elseif ( isset( $order_data_result['paymentAmountValue'] ) && is_numeric( $order_data_result['paymentAmountValue'] ) ) {
				$update['payment_amount_value'] = round( (float) $order_data_result['paymentAmountValue'], 2 );
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
				'source_code'          => $poll_source !== '' ? $poll_source : 'poll',
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
				'poll_source'       => $poll_source,
			],
		] );
	}

	$status_actions = null;
	$terminal_statuses = [ 'paid', 'declined', 'cancelled', 'expired', 'error' ];
	if ( in_array( $new_status, $terminal_statuses, true ) ) {
		$status_actions = crm_fintech_run_terminal_order_side_effects( $order_db_id, $new_status, $poll_source );
	}

	return [
		'changed'         => $new_status !== $old_status,
		'old_status'      => $old_status,
		'new_status'      => $new_status,
		'provider_status' => $provider_status,
		'error'           => null,
		'status_actions'  => $status_actions,
		'paid_actions'    => $new_status === 'paid' ? $status_actions : null,
	];
}

if ( ! function_exists( 'crm_fintech_run_terminal_order_side_effects' ) ) {
	function crm_fintech_run_terminal_order_side_effects( int $order_id, string $status_code, string $source_code = 'system' ): array {
		$status_code = sanitize_key( $status_code );
		$source_code = sanitize_key( $source_code );

		$result = [
			'accrual'           => null,
			'merchant_telegram' => null,
			'operator_telegram' => null,
			'telegram_channels' => null,
		];

		if ( $order_id <= 0 ) {
			return $result;
		}

		if ( $status_code === 'paid' && function_exists( 'crm_merchant_create_paid_order_accrual' ) ) {
			$result['accrual'] = crm_merchant_create_paid_order_accrual( $order_id, $source_code !== '' ? $source_code : 'system' );
		}

		if ( in_array( $status_code, [ 'paid', 'declined', 'cancelled', 'expired', 'error' ], true ) ) {
			if ( function_exists( 'crm_telegram_channels_handle_terminal_order' ) ) {
				$result['telegram_channels'] = crm_telegram_channels_handle_terminal_order( $order_id, $status_code, $source_code !== '' ? $source_code : 'system' );
			} elseif ( $status_code === 'paid' && function_exists( 'crm_telegram_channels_handle_paid_order' ) ) {
				$result['telegram_channels'] = crm_telegram_channels_handle_paid_order( $order_id, $source_code !== '' ? $source_code : 'system' );
			}
		}

		if ( in_array( $status_code, [ 'paid', 'declined', 'cancelled', 'expired', 'error' ], true ) && function_exists( 'crm_merchant_tg_sync_order_status' ) ) {
			$result['merchant_telegram'] = crm_merchant_tg_sync_order_status(
				$order_id,
				[
					'source_code'       => $source_code !== '' ? $source_code : 'system',
					'send_notification' => $status_code === 'paid' && ! in_array( $source_code, [ 'telegram_merchant_manual' ], true ),
				]
			);
		}

		if ( in_array( $status_code, [ 'paid', 'declined', 'cancelled', 'expired', 'error' ], true ) && function_exists( 'crm_operator_tg_sync_order_status' ) ) {
			$result['operator_telegram'] = crm_operator_tg_sync_order_status(
				$order_id,
				[
					'source_code' => $source_code !== '' ? $source_code : 'system',
				]
			);
		}

		return $result;
	}
}

if ( ! function_exists( 'crm_fintech_run_paid_order_side_effects' ) ) {
	function crm_fintech_run_paid_order_side_effects( int $order_id, string $source_code = 'system' ): array {
		return crm_fintech_run_terminal_order_side_effects( $order_id, 'paid', $source_code );
	}
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
	$terminal = [ 'paid', 'declined', 'cancelled', 'expired', 'error' ];
	if ( in_array( $old_status, $terminal, true ) && $old_status === $new_status ) {
		crm_fintech_run_terminal_order_side_effects( $order_db_id, $new_status, 'callback_duplicate' );

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

	if ( in_array( $new_status, [ 'paid', 'declined', 'cancelled', 'expired', 'error' ], true ) ) {
		crm_fintech_run_terminal_order_side_effects( $order_db_id, $new_status, 'callback' );
	}
}
