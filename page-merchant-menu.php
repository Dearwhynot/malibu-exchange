<?php
/*
Template Name: Merchant Menu Page
Slug: merchant-menu
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'crm_merchant_menu_preview_allowed_screens' ) ) {
	function crm_merchant_menu_preview_allowed_screens(): array {
		return [ 'input', 'invoice', 'success' ];
	}
}

if ( ! function_exists( 'crm_merchant_menu_preview_screen' ) ) {
	function crm_merchant_menu_preview_screen(): string {
		$screen = sanitize_key( (string) wp_unslash( $_GET['screen'] ?? 'input' ) );

		return in_array( $screen, crm_merchant_menu_preview_allowed_screens(), true ) ? $screen : 'input';
	}
}

if ( ! function_exists( 'crm_merchant_menu_preview_float' ) ) {
	function crm_merchant_menu_preview_float( string $key, float $default, float $min = 0.0 ): float {
		$raw = trim( (string) wp_unslash( $_GET[ $key ] ?? '' ) );
		if ( $raw === '' || ! is_numeric( $raw ) ) {
			return $default;
		}

		return max( $min, (float) $raw );
	}
}

if ( ! function_exists( 'crm_merchant_menu_preview_text' ) ) {
	function crm_merchant_menu_preview_text( string $key, string $default ): string {
		$value = sanitize_text_field( (string) wp_unslash( $_GET[ $key ] ?? '' ) );

		return $value !== '' ? $value : $default;
	}
}

if ( ! function_exists( 'crm_merchant_menu_preview_url' ) ) {
	function crm_merchant_menu_preview_url( string $key, string $default ): string {
		$value = esc_url_raw( (string) wp_unslash( $_GET[ $key ] ?? '' ) );

		return $value !== '' ? $value : $default;
	}
}

if ( ! function_exists( 'crm_merchant_menu_preview_currency' ) ) {
	function crm_merchant_menu_preview_currency( float $value, int $decimals = 2 ): string {
		return number_format( $value, $decimals, '.', ' ' );
	}
}

if ( ! function_exists( 'crm_merchant_menu_preview_amount' ) ) {
	function crm_merchant_menu_preview_amount( float $value, string $currency_code, int $decimals = 2 ): string {
		return crm_merchant_menu_preview_currency( $value, $decimals ) . ' ' . $currency_code;
	}
}

if ( ! function_exists( 'crm_merchant_menu_preview_query_url' ) ) {
	function crm_merchant_menu_preview_query_url( array $overrides = [], array $remove = [] ): string {
		$params = [
			'screen'         => crm_merchant_menu_preview_screen(),
			'amount'         => crm_merchant_menu_preview_float( 'amount', 0, 0 ),
			'markup_percent' => crm_merchant_menu_preview_float( 'markup_percent', 1.5, 0 ),
			'base_rate'      => crm_merchant_menu_preview_float( 'base_rate', 96.45, 0.0001 ),
			'purpose'        => crm_merchant_menu_preview_text( 'purpose', 'Оплата по договору' ),
			'invoice_id'     => crm_merchant_menu_preview_text( 'invoice_id', 'MX-1048' ),
			'payment_link'   => crm_merchant_menu_preview_url( 'payment_link', 'https://qr.nspk.ru/preview-sbp-link' ),
		];

		foreach ( $overrides as $key => $value ) {
			$params[ $key ] = $value;
		}

		foreach ( $remove as $key ) {
			unset( $params[ $key ] );
		}

		return add_query_arg( $params, home_url( '/merchant-menu/' ) );
	}
}

if ( ! function_exists( 'crm_merchant_menu_hidden_inputs' ) ) {
	function crm_merchant_menu_hidden_inputs( array $args, array $remove = [] ): void {
		foreach ( $remove as $key ) {
			unset( $args[ $key ] );
		}

		foreach ( $args as $key => $value ) {
			if ( $value === null || $value === '' ) {
				continue;
			}
			echo '<input type="hidden" name="' . esc_attr( (string) $key ) . '" value="' . esc_attr( (string) $value ) . '">' . "\n";
		}
	}
}

$theme_uri    = get_template_directory_uri();
$theme_dir    = get_template_directory();
$css_rel      = '/assets/css/merchant-menu-public.css';
$css_file     = $theme_dir . $css_rel;
$sbp_logo_rel = '/assets/img/sbp-logo.svg';
$sbp_logo_file = $theme_dir . $sbp_logo_rel;
$sbp_logo_url  = file_exists( $sbp_logo_file ) ? $theme_uri . $sbp_logo_rel : '';
$css_version  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : null;

wp_enqueue_style( 'merchant-menu-public', $theme_uri . $css_rel, [], $css_version );

$request_source  = wp_unslash( $_REQUEST );
$miniapp_access  = function_exists( 'crm_tg_miniapp_validate_request_access' ) ? crm_tg_miniapp_validate_request_access( $request_source ) : [ 'ok' => false ];
$is_live_mode    = ! empty( $miniapp_access['ok'] );
$screen          = 'input';
$live_error      = '';
$live_notice     = '';
$live_order      = null;
$live_order_id   = max( 0, (int) ( $request_source['order_id'] ?? 0 ) );
$live_contour    = $is_live_mode ? (string) ( $miniapp_access['contour'] ?? '' ) : '';
$markup_percent  = 0.0;
$rub_invoice_markup_mode = 'none';
$input_amount    = 0.0;
$purpose         = 'Оплата по договору';
$payment_link    = '';
$qr_url          = '';
$invoice_id      = '';
$merchant_rate   = 0.0;
$payment_amount_rub = 0.0;
$merchant_payable_usdt = 0.0;
$merchant_markup_added_rub = 0.0;
$issued_label    = wp_date( 'd M Y, H:i' );
$paid_label      = wp_date( 'd M Y, H:i' );
$expires_label   = '';
$expires_iso     = '';
$screen_error_text = '';
$new_invoice_url = '';
$back_url        = '';
$success_url     = '';
$share_url       = '';
$page_title      = 'Новый счёт';
$screen_note     = '';
$input_hint      = 'Клиент оплачивает счёт по СБП в RUB. Если для мерчанта включена наценка, она автоматически добавится к сумме клиента.';
$input_stats     = [];
$invoice_details = [];
$success_details = [];
$merchant        = $miniapp_access['merchant'] ?? null;
$operator_account = $miniapp_access['operator_account'] ?? null;
$live_query_args = is_array( $miniapp_access['query_args'] ?? null ) ? $miniapp_access['query_args'] : [];

if ( $is_live_mode ) {
	$base_live_url = crm_tg_miniapp_query_url( $miniapp_access, [], [ 'order_id', 'screen', 'miniapp_action' ] );
	$new_invoice_url = $base_live_url;
	$back_url        = $base_live_url;

	$default_purpose = '';
	if ( $live_contour === 'merchant' && is_object( $merchant ) ) {
		$default_purpose = function_exists( 'crm_merchant_tg_rub_invoice_default_payment_purpose' )
			? crm_merchant_tg_rub_invoice_default_payment_purpose( $merchant )
			: '';
		$input_hint = 'Клиент оплачивает счёт по СБП в RUB. Если для мерчанта включена наценка, она автоматически добавится к сумме клиента.';
	} elseif ( $live_contour === 'operator' ) {
		$default_purpose = function_exists( 'crm_fintech_get_pay2day_default_payment_purpose' )
			? crm_fintech_get_pay2day_default_payment_purpose( (int) ( $miniapp_access['company_id'] ?? 0 ) )
			: '';
		$input_hint = 'Клиент оплачивает счёт по СБП в RUB. Сумма к оплате рассчитывается без merchant-наценки.';
	}

	$purpose = trim( (string) ( $request_source['purpose'] ?? $default_purpose ) );
	$purpose = function_exists( 'crm_fintech_normalize_payment_purpose' )
		? crm_fintech_normalize_payment_purpose( $purpose )
		: sanitize_text_field( $purpose );
	if ( $purpose === '' ) {
		$purpose = $default_purpose;
	}

	if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
		$miniapp_action = sanitize_key( (string) ( $request_source['miniapp_action'] ?? '' ) );

		if ( $miniapp_action === 'reset' ) {
			wp_safe_redirect( $base_live_url );
			exit;
		}

		if ( $miniapp_action === 'create' ) {
			$input_amount = function_exists( 'crm_merchant_normalize_rub_amount' )
				? crm_merchant_normalize_rub_amount( (string) ( $request_source['amount'] ?? '' ) )
				: max( 0, (float) str_replace( ',', '.', (string) ( $request_source['amount'] ?? '' ) ) );

			if ( $input_amount <= 0 ) {
				$live_error = 'Введите сумму счёта в RUB.';
			} elseif ( $live_contour === 'merchant' && is_object( $merchant ) ) {
				$result = crm_merchant_create_rub_invoice(
					(int) ( $merchant->id ?? 0 ),
					$input_amount,
					[
						'source_channel'  => 'telegram_merchant',
						'payment_purpose' => $purpose,
					]
				);

				if ( empty( $result['success'] ) ) {
					$live_error = (string) ( $result['error'] ?? 'Не удалось выпустить счёт.' );
				} else {
					$sent = crm_tg_miniapp_send_merchant_invoice_message( $miniapp_access, $result );
					crm_log( 'telegram.miniapp.merchant_invoice_created', [
						'category'    => 'payments',
						'level'       => 'info',
						'action'      => 'create',
						'message'     => 'Merchant invoice created from Telegram mini app.',
						'target_type' => 'payment_order',
						'target_id'   => (int) ( $result['order_db_id'] ?? 0 ),
						'org_id'      => (int) ( $miniapp_access['company_id'] ?? 0 ),
						'is_success'  => true,
						'context'     => [
							'merchant_id'      => (int) ( $merchant->id ?? 0 ),
							'chat_id'          => (string) ( $miniapp_access['chat_id'] ?? '' ),
							'fallback_sent'    => $sent,
							'payment_purpose'  => $purpose,
							'requested_rub'    => $input_amount,
						],
					] );

					wp_safe_redirect(
						crm_tg_miniapp_query_url(
							$miniapp_access,
							[ 'order_id' => (int) ( $result['order_db_id'] ?? 0 ) ],
							[ 'miniapp_action' ]
						)
					);
					exit;
				}
			} elseif ( $live_contour === 'operator' ) {
				$company_id = (int) ( $miniapp_access['company_id'] ?? 0 );
				$description = $purpose !== '' ? $purpose : 'Telegram operator order';
				$result = crm_fintech_create_order_by_payment_amount(
					$input_amount,
					'RUB',
					$company_id,
					'telegram_operator',
					null,
					$description
				);
				$result['payment_purpose'] = $purpose;

				if ( empty( $result['success'] ) ) {
					$live_error = (string) ( $result['error'] ?? 'Не удалось создать ордер.' );
				} else {
					$sent = crm_tg_miniapp_send_operator_order_message( $miniapp_access, $result );
					crm_log( 'telegram.miniapp.operator_order_created', [
						'category'    => 'payments',
						'level'       => 'info',
						'action'      => 'create',
						'message'     => 'Operator order created from Telegram mini app.',
						'target_type' => 'payment_order',
						'target_id'   => (int) ( $result['order_db_id'] ?? 0 ),
						'org_id'      => $company_id,
						'is_success'  => true,
						'context'     => [
							'user_id'         => (int) ( $miniapp_access['operator_user_id'] ?? 0 ),
							'chat_id'         => (string) ( $miniapp_access['chat_id'] ?? '' ),
							'fallback_sent'   => $sent,
							'payment_purpose' => $purpose,
							'payment_rub'     => $input_amount,
						],
					] );

					wp_safe_redirect(
						crm_tg_miniapp_query_url(
							$miniapp_access,
							[ 'order_id' => (int) ( $result['order_db_id'] ?? 0 ) ],
							[ 'miniapp_action' ]
						)
					);
					exit;
				}
			}
		}

		if ( $miniapp_action === 'check' ) {
			$live_order = crm_tg_miniapp_load_order_for_access( $miniapp_access, $live_order_id );
			if ( ! $live_order ) {
				$live_error = 'Счёт не найден или доступ к нему истёк.';
			} else {
				$poll_source = $live_contour === 'merchant' ? 'telegram_merchant_manual' : 'telegram_operator_manual';
				$poll = crm_fintech_poll_order_status( $live_order, $poll_source );

				if ( ! empty( $poll['error'] ) ) {
					$live_error = 'Не удалось проверить статус: ' . (string) $poll['error'];
				} else {
					$live_notice = (string) ( $poll['new_status'] ?? '' ) === 'paid'
						? 'Оплата подтверждена.'
						: 'Платёж ещё не поступил. Можно проверить ещё раз позже.';
					crm_log( 'telegram.miniapp.order_status_checked', [
						'category'    => 'payments',
						'level'       => 'info',
						'action'      => 'status_check',
						'message'     => 'Telegram mini app manual status check executed.',
						'target_type' => 'payment_order',
						'target_id'   => (int) ( $live_order->id ?? 0 ),
						'org_id'      => (int) ( $miniapp_access['company_id'] ?? 0 ),
						'is_success'  => empty( $poll['error'] ),
						'context'     => [
							'contour'    => $live_contour,
							'chat_id'    => (string) ( $miniapp_access['chat_id'] ?? '' ),
							'old_status' => (string) ( $poll['old_status'] ?? '' ),
							'new_status' => (string) ( $poll['new_status'] ?? '' ),
						],
					] );
				}

				$live_order = crm_tg_miniapp_load_order_for_access( $miniapp_access, $live_order_id );
			}
		}
	}

	if ( ! $live_order && $live_order_id > 0 ) {
		$live_order = crm_tg_miniapp_load_order_for_access( $miniapp_access, $live_order_id );
		if ( ! $live_order ) {
			$screen_error_text = 'Счёт не найден или ссылка больше недействительна.';
		}
	}

	if ( $live_order ) {
		$screen = in_array( (string) ( $live_order->status_code ?? '' ), [ 'paid' ], true ) ? 'success' : 'invoice';
		$page_title = $screen === 'success' ? 'Оплата' : 'Счёт';
		$invoice_id = trim( (string) ( $live_order->merchant_order_id ?? '' ) );
		$payment_link = trim( (string) ( $live_order->payment_link ?? '' ) );
		$share_url = $payment_link;
		$input_amount = $live_contour === 'merchant'
			? (float) ( $live_order->merchant_requested_rub_value ?? $live_order->payment_amount_value ?? 0 )
			: (float) ( $live_order->payment_amount_value ?? 0 );
		$payment_amount_rub = round( (float) ( $live_order->payment_amount_value ?? 0 ), 2 );
		$created_mysql = (string) ( $live_order->created_at ?? '' );
		$paid_mysql    = (string) ( $live_order->paid_at ?? $live_order->updated_at ?? '' );
		$issued_label  = $created_mysql !== '' ? mysql2date( 'd M Y, H:i', $created_mysql ) : wp_date( 'd M Y, H:i' );
		$paid_label    = $paid_mysql !== '' ? mysql2date( 'd M Y, H:i', $paid_mysql ) : wp_date( 'd M Y, H:i' );
		$created_dt = null;
		if ( $created_mysql !== '' ) {
			$created_dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $created_mysql, wp_timezone() ) ?: null;
		}
		$created_ts    = $created_dt ? $created_dt->getTimestamp() : time();
		$expires_dt    = $created_dt ? $created_dt->modify( '+15 minutes' ) : ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+15 minutes' );
		$expires_ts    = $expires_dt->getTimestamp();
		$expires_label = $expires_dt->format( 'H:i' );
		$expires_iso   = $expires_dt->format( DATE_ATOM );

		$order_meta = [];
		if ( ! empty( $live_order->meta_json ) ) {
			$decoded_meta = json_decode( (string) $live_order->meta_json, true );
			if ( is_array( $decoded_meta ) ) {
				$order_meta = $decoded_meta;
			}
		}

		$merchant_meta = [];
		if ( ! empty( $live_order->merchant_meta_json ) ) {
			$decoded_merchant_meta = json_decode( (string) $live_order->merchant_meta_json, true );
			if ( is_array( $decoded_merchant_meta ) ) {
				$merchant_meta = $decoded_merchant_meta;
			}
		}

		$purpose = function_exists( 'crm_fintech_normalize_payment_purpose' )
			? crm_fintech_normalize_payment_purpose(
				(string) ( $merchant_meta['payment_purpose'] ?? $order_meta['payment_purpose'] ?? $purpose )
			)
			: sanitize_text_field( (string) ( $merchant_meta['payment_purpose'] ?? $order_meta['payment_purpose'] ?? $purpose ) );

		$qrc_id = trim( (string) ( $live_order->qrc_id ?? '' ) );
		if ( $payment_link !== '' && $qrc_id !== '' && function_exists( 'crm_fintech_qr_url' ) ) {
			$qr_url = (string) crm_fintech_qr_url(
				$payment_link,
				$qrc_id,
				$invoice_id !== '' ? $invoice_id : (string) ( $live_order->id ?? 0 )
			);
		}

		if ( $live_contour === 'merchant' ) {
			$merchant_rate = round( (float) ( $merchant_meta['merchant_rate'] ?? 0 ), 4 );
			$merchant_markup_added_rub = round( (float) ( $merchant_meta['merchant_markup_added_rub'] ?? max( 0, $payment_amount_rub - $input_amount ) ), 2 );
			$merchant_payable_usdt = function_exists( 'crm_merchant_order_payable_amount' )
				? crm_merchant_order_payable_amount( $live_order )
				: round( (float) ( $live_order->merchant_payable_value ?? $live_order->amount_asset_value ?? 0 ), 4 );
			$screen_note = 'QR-код и кнопка Shared ведут на одну и ту же ссылку оплаты СБП. Кнопка Проверить уже продублирована в Telegram-чате на случай закрытия mini app.';
		} else {
			$merchant_rate = $merchant_payable_usdt = 0.0;
			$merchant_payable_usdt = round( (float) ( $live_order->amount_asset_value ?? 0 ), 4 );
			$merchant_rate = $merchant_payable_usdt > 0 ? round( $payment_amount_rub / $merchant_payable_usdt, 4 ) : 0.0;
			$screen_note = 'Операторский контур показывает чистый курс ЭП без merchant-наценок. Кнопка Проверить также остаётся в Telegram-чате как fallback.';
		}

		if ( $screen === 'success' ) {
			$screen_note = 'Платёж подтверждён. Если mini app был закрыт, тот же счёт всё равно можно было проверить через fallback-кнопку в Telegram.';
		}

		$invoice_details = [
			[
				'label' => 'Назначение',
				'value' => $purpose !== '' ? $purpose : '—',
			],
			[
				'label' => 'Счёт №',
				'value' => $invoice_id !== '' ? $invoice_id : '#' . (int) ( $live_order->id ?? 0 ),
			],
			[
				'label' => 'Введено',
				'value' => crm_merchant_menu_preview_amount( $input_amount, 'RUB', 2 ),
			],
			[
				'label' => 'Клиент оплатит',
				'value' => crm_merchant_menu_preview_amount( $payment_amount_rub, 'RUB', 2 ),
			],
		];

		if ( $merchant_markup_added_rub > 0.000001 ) {
			$invoice_details[] = [
				'label' => 'Наценка',
				'value' => '+' . crm_merchant_menu_preview_amount( $merchant_markup_added_rub, 'RUB', 2 ),
			];
		}

		$invoice_details[] = [
			'label' => 'Истекает',
			'value' => in_array( (string) ( $live_order->status_code ?? '' ), [ 'created', 'pending' ], true ) ? $expires_label : strtoupper( (string) ( $live_order->status_code ?? '—' ) ),
		];

		$success_details = [
			[
				'label' => 'Счёт №',
				'value' => $invoice_id !== '' ? $invoice_id : '#' . (int) ( $live_order->id ?? 0 ),
			],
			[
				'label' => 'Статус',
				'value' => 'Оплачен',
			],
			[
				'label' => 'Оплатил клиент',
				'value' => crm_merchant_menu_preview_amount( $payment_amount_rub, 'RUB', 2 ),
			],
			[
				'label' => 'Введено',
				'value' => crm_merchant_menu_preview_amount( $input_amount, 'RUB', 2 ),
			],
			[
				'label' => 'Оплачен',
				'value' => $paid_label,
			],
		];

		if ( $merchant_markup_added_rub > 0.000001 ) {
			$success_details[] = [
				'label' => 'Наценка',
				'value' => '+' . crm_merchant_menu_preview_amount( $merchant_markup_added_rub, 'RUB', 2 ),
			];
		}

		$success_url = crm_tg_miniapp_query_url( $miniapp_access, [ 'order_id' => (int) ( $live_order->id ?? 0 ) ], [ 'miniapp_action' ] );
		$back_url = $base_live_url;
	} else {
		$screen = 'input';
		$page_title = $live_contour === 'operator' ? 'Новый ордер' : 'Новый счёт';
		$input_amount = isset( $input_amount ) && $input_amount > 0 ? $input_amount : 0.0;

		if ( $live_contour === 'merchant' && is_object( $merchant ) ) {
			$preview = crm_merchant_tg_rub_invoice_preview_context( $merchant );
			$merchant_rate = round( (float) ( $preview['current_rate'] ?? 0 ), 4 );
			$markup_percent = round( (float) ( $preview['merchant_markup_percent'] ?? 0 ), 2 );
			$rub_invoice_markup_mode = (string) ( $preview['rub_invoice_markup_mode'] ?? 'none' );
			$payment_amount_rub = $rub_invoice_markup_mode === 'add_on_top' && $markup_percent > 0
				? round( $input_amount * ( 1 + ( $markup_percent / 100 ) ), 2 )
				: round( $input_amount, 2 );
			$merchant_markup_added_rub = round( max( 0, $payment_amount_rub - $input_amount ), 2 );
			$merchant_payable_usdt = $merchant_rate > 0 ? round( $payment_amount_rub / $merchant_rate, 4 ) : 0.0;
			$issued_label = (string) ( $preview['checked_at'] ?? wp_date( 'd M Y, H:i' ) );

			if ( empty( $preview['success'] ) ) {
				$screen_error_text = (string) ( $preview['error'] ?? 'Не удалось получить live-rate.' );
			}
		} else {
			$preview = crm_tg_miniapp_operator_preview_context( (int) ( $miniapp_access['company_id'] ?? 0 ), $input_amount );
			$merchant_rate = round( (float) ( $preview['current_rate'] ?? 0 ), 4 );
			$payment_amount_rub = round( (float) ( $preview['payment_amount_rub'] ?? $input_amount ), 2 );
			$merchant_payable_usdt = round( (float) ( $preview['payable_usdt'] ?? 0 ), 4 );
			$merchant_markup_added_rub = 0.0;
			$rapira_ask = round( (float) ( $preview['rapira_ask'] ?? 0 ), 4 );
			$company_markup_percent = round( (float) ( $preview['company_markup_percent'] ?? 0 ), 2 );
			$issued_label = (string) ( $preview['checked_at'] ?? wp_date( 'd M Y, H:i' ) );

			if ( empty( $preview['success'] ) ) {
				$screen_error_text = (string) ( $preview['error'] ?? 'Не удалось получить live-rate.' );
			}
		}

		if ( $live_contour === 'operator' ) {
			$input_stats = [
				[
					'label'    => 'Rapira ask',
					'number'   => crm_merchant_menu_preview_currency( $rapira_ask, 4 ),
					'currency' => 'RUB',
				],
				[
					'label'    => 'Markup',
					'number'   => crm_merchant_menu_preview_currency( $company_markup_percent, 2 ),
					'currency' => '%',
				],
				[
					'label'    => 'Итоговый курс',
					'number'   => crm_merchant_menu_preview_currency( $merchant_rate, 4 ),
					'currency' => 'RUB',
				],
				[
					'label'    => 'Получите',
					'number'   => crm_merchant_menu_preview_currency( $merchant_payable_usdt, 4 ),
					'currency' => 'USDT',
					'role'     => 'usdt',
				],
			];
		} else {
			$input_stats = [
				[
					'label'    => 'Наценка',
					'number'   => crm_merchant_menu_preview_currency( $merchant_markup_added_rub, 2 ),
					'currency' => 'RUB',
					'role'     => 'markup',
				],
				[
					'label'    => 'Оплатит клиент',
					'number'   => crm_merchant_menu_preview_currency( $payment_amount_rub, 2 ),
					'currency' => 'RUB',
					'role'     => 'payment',
				],
			];
		}
	}
} else {
	$screen         = crm_merchant_menu_preview_screen();
	$requested_rub  = round( crm_merchant_menu_preview_float( 'amount', 0, 0 ), 2 );
	$markup_percent = round( crm_merchant_menu_preview_float( 'markup_percent', 1.5, 0 ), 2 );
	$rub_invoice_markup_mode = $markup_percent > 0 ? 'add_on_top' : 'none';
	$base_rate      = round( crm_merchant_menu_preview_float( 'base_rate', 96.45, 0.0001 ), 4 );
	$invoice_id     = crm_merchant_menu_preview_text( 'invoice_id', 'MX-1048' );
	$purpose        = crm_merchant_menu_preview_text( 'purpose', 'Оплата по договору' );
	$payment_link   = crm_merchant_menu_preview_url( 'payment_link', 'https://qr.nspk.ru/preview-sbp-link' );
	$issued_dt      = new DateTimeImmutable( 'now', wp_timezone() );
	$expires_dt     = $issued_dt->modify( '+15 minutes' );
	$paid_dt        = $issued_dt->modify( '+2 minutes' );
	$issued_label   = wp_date( 'd M Y, H:i', $issued_dt->getTimestamp(), wp_timezone() );
	$paid_label     = wp_date( 'd M Y, H:i', $paid_dt->getTimestamp(), wp_timezone() );
	$expires_iso    = $expires_dt->format( DATE_ATOM );

	$economics = function_exists( 'crm_merchant_calculate_rub_invoice_economics' )
		? crm_merchant_calculate_rub_invoice_economics(
			$base_rate,
			0.0,
			$markup_percent,
			'acquirer_cost',
			$requested_rub,
			$markup_percent > 0 ? 'add_on_top' : 'none'
		)
		: [
			'requested_rub_input'       => $requested_rub,
			'payment_amount_rub'        => round( $requested_rub * ( 1 + ( $markup_percent / 100 ) ), 2 ),
			'merchant_markup_added_rub' => round( $requested_rub * ( $markup_percent / 100 ), 2 ),
			'merchant_rate_commercial'  => $base_rate,
			'merchant_payable_usdt'     => round( $requested_rub / max( $base_rate, 0.0001 ), 4 ),
		];

	$input_amount               = $requested_rub;
	$payment_amount_rub         = round( (float) ( $economics['payment_amount_rub'] ?? $requested_rub ), 2 );
	$merchant_markup_added_rub  = round( (float) ( $economics['merchant_markup_added_rub'] ?? 0 ), 2 );
	$merchant_rate              = round( (float) ( $economics['merchant_rate_commercial'] ?? $base_rate ), 4 );
	$merchant_payable_usdt      = round( (float) ( $economics['merchant_payable_usdt'] ?? 0 ), 4 );

	$qr_url = '';
	if ( $payment_link !== '' && function_exists( 'crm_fintech_qr_url' ) ) {
		$qr_url = (string) crm_fintech_qr_url(
			$payment_link,
			'merchantmenupreview',
			preg_replace( '/[^A-Za-z0-9_-]/', '', $invoice_id ) ?: 'preview'
		);
	}

	$new_invoice_url = crm_merchant_menu_preview_query_url( [
		'screen' => 'input',
		'amount' => 0,
		'markup_percent' => $markup_percent,
		'base_rate' => $base_rate,
		'purpose' => $purpose,
		'invoice_id' => 'MX-1048',
	] );

	$success_url = crm_merchant_menu_preview_query_url( [
		'screen' => 'success',
		'amount' => $requested_rub,
		'markup_percent' => $markup_percent,
		'base_rate' => $base_rate,
		'purpose' => $purpose,
		'invoice_id' => $invoice_id,
		'payment_link' => $payment_link,
	] );

	$back_url = $screen === 'input'
		? $new_invoice_url
		: crm_merchant_menu_preview_query_url( [
			'screen' => 'input',
			'amount' => $requested_rub,
			'markup_percent' => $markup_percent,
			'base_rate' => $base_rate,
			'purpose' => $purpose,
			'invoice_id' => $invoice_id,
			'payment_link' => $payment_link,
		] );

	$input_stats = [
		[
			'label'    => 'Наценка',
			'number'   => crm_merchant_menu_preview_currency( $merchant_markup_added_rub, 2 ),
			'currency' => 'RUB',
			'role'     => 'markup',
		],
		[
			'label'    => 'Оплатит клиент',
			'number'   => crm_merchant_menu_preview_currency( $payment_amount_rub, 2 ),
			'currency' => 'RUB',
			'role'     => 'payment',
		],
	];

	$invoice_details = [
		[ 'label' => 'Назначение', 'value' => $purpose ],
		[ 'label' => 'Счёт №', 'value' => $invoice_id ],
		[ 'label' => 'Введено', 'value' => crm_merchant_menu_preview_amount( $requested_rub, 'RUB', 2 ) ],
		[ 'label' => 'Клиент оплатит', 'value' => crm_merchant_menu_preview_amount( $payment_amount_rub, 'RUB', 2 ) ],
	];

	if ( $merchant_markup_added_rub > 0 ) {
		$invoice_details[] = [
			'label' => 'Наценка',
			'value' => '+' . crm_merchant_menu_preview_amount( $merchant_markup_added_rub, 'RUB', 2 ),
		];
	}

	$invoice_details[] = [
		'label' => 'Истекает',
		'value' => $expires_dt->format( 'H:i' ),
	];

	$success_details = [
		[ 'label' => 'Счёт №', 'value' => $invoice_id ],
		[ 'label' => 'Статус', 'value' => 'Оплачен' ],
		[ 'label' => 'Оплатил клиент', 'value' => crm_merchant_menu_preview_amount( $payment_amount_rub, 'RUB', 2 ) ],
		[ 'label' => 'Введено', 'value' => crm_merchant_menu_preview_amount( $requested_rub, 'RUB', 2 ) ],
		[ 'label' => 'Оплачен', 'value' => $paid_dt->format( 'H:i' ) ],
	];

	if ( $merchant_markup_added_rub > 0 ) {
		$success_details[] = [
			'label' => 'Наценка',
			'value' => '+' . crm_merchant_menu_preview_amount( $merchant_markup_added_rub, 'RUB', 2 ),
		];
	}

	$page_title = $screen === 'success' ? 'Оплата' : ( $screen === 'invoice' ? 'Счёт' : 'Новый счёт' );
	$screen_note = $screen === 'success'
		? 'Поступление подтверждено. Детали платежа можно дублировать в чат и позже выводить в разделе «Мои счета».'
		: 'QR-код и кнопка shared ведут на одну и ту же ссылку оплаты СБП. Покажите QR клиенту или откройте ссылку напрямую.';
}

if ( ! $input_stats ) {
	$input_stats = [
		[
			'label'    => 'Наценка',
			'number'   => crm_merchant_menu_preview_currency( $merchant_markup_added_rub, 2 ),
			'currency' => 'RUB',
			'role'     => 'markup',
		],
		[
			'label'    => 'Оплатит клиент',
			'number'   => crm_merchant_menu_preview_currency( $payment_amount_rub, 2 ),
			'currency' => 'RUB',
			'role'     => 'payment',
		],
	];
}

$input_calc_mode = $is_live_mode && $live_contour === 'operator' ? 'operator' : 'merchant';

$countdown_active = $expires_iso !== ''
	&& (
		! $is_live_mode
		|| (
			is_object( $live_order )
			&& in_array( (string) ( $live_order->status_code ?? '' ), [ 'created', 'pending' ], true )
		)
	);

$fallback_qr_markup = <<<'SVG'
<svg viewBox="0 0 210 210" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
	<rect width="210" height="210" fill="#ffffff"/>
	<rect x="10" y="10" width="54" height="54" rx="2" fill="#000000"/>
	<rect x="18" y="18" width="38" height="38" fill="#ffffff"/>
	<rect x="26" y="26" width="22" height="22" fill="#000000"/>
	<rect x="146" y="10" width="54" height="54" rx="2" fill="#000000"/>
	<rect x="154" y="18" width="38" height="38" fill="#ffffff"/>
	<rect x="162" y="26" width="22" height="22" fill="#000000"/>
	<rect x="10" y="146" width="54" height="54" rx="2" fill="#000000"/>
	<rect x="18" y="154" width="38" height="38" fill="#ffffff"/>
	<rect x="26" y="162" width="22" height="22" fill="#000000"/>
	<rect x="80" y="14" width="8" height="24" fill="#000000"/>
	<rect x="94" y="14" width="8" height="12" fill="#000000"/>
	<rect x="108" y="14" width="8" height="24" fill="#000000"/>
	<rect x="122" y="14" width="8" height="12" fill="#000000"/>
	<rect x="80" y="46" width="8" height="18" fill="#000000"/>
	<rect x="94" y="34" width="22" height="8" fill="#000000"/>
	<rect x="122" y="42" width="8" height="22" fill="#000000"/>
	<rect x="74" y="74" width="12" height="12" fill="#000000"/>
	<rect x="92" y="74" width="12" height="12" fill="#000000"/>
	<rect x="110" y="74" width="12" height="12" fill="#000000"/>
	<rect x="128" y="74" width="12" height="12" fill="#000000"/>
	<rect x="146" y="74" width="12" height="12" fill="#000000"/>
	<rect x="164" y="74" width="12" height="12" fill="#000000"/>
	<rect x="14" y="80" width="12" height="12" fill="#000000"/>
	<rect x="32" y="80" width="12" height="12" fill="#000000"/>
	<rect x="50" y="80" width="12" height="12" fill="#000000"/>
	<rect x="14" y="98" width="12" height="12" fill="#000000"/>
	<rect x="50" y="98" width="12" height="12" fill="#000000"/>
	<rect x="74" y="94" width="12" height="12" fill="#000000"/>
	<rect x="92" y="94" width="30" height="12" fill="#000000"/>
	<rect x="128" y="94" width="12" height="12" fill="#000000"/>
	<rect x="146" y="94" width="12" height="12" fill="#000000"/>
	<rect x="164" y="94" width="30" height="12" fill="#000000"/>
	<rect x="14" y="116" width="12" height="12" fill="#000000"/>
	<rect x="32" y="116" width="12" height="12" fill="#000000"/>
	<rect x="50" y="116" width="12" height="12" fill="#000000"/>
	<rect x="74" y="114" width="12" height="12" fill="#000000"/>
	<rect x="98" y="114" width="12" height="30" fill="#000000"/>
	<rect x="116" y="114" width="12" height="12" fill="#000000"/>
	<rect x="134" y="114" width="12" height="12" fill="#000000"/>
	<rect x="152" y="114" width="12" height="12" fill="#000000"/>
	<rect x="176" y="114" width="12" height="12" fill="#000000"/>
	<rect x="74" y="132" width="12" height="12" fill="#000000"/>
	<rect x="116" y="132" width="12" height="12" fill="#000000"/>
	<rect x="152" y="132" width="12" height="12" fill="#000000"/>
	<rect x="170" y="132" width="12" height="12" fill="#000000"/>
	<rect x="74" y="150" width="12" height="12" fill="#000000"/>
	<rect x="92" y="150" width="12" height="12" fill="#000000"/>
	<rect x="110" y="150" width="30" height="12" fill="#000000"/>
	<rect x="146" y="150" width="12" height="12" fill="#000000"/>
	<rect x="164" y="150" width="12" height="12" fill="#000000"/>
	<rect x="74" y="168" width="12" height="12" fill="#000000"/>
	<rect x="110" y="168" width="12" height="12" fill="#000000"/>
	<rect x="128" y="168" width="12" height="12" fill="#000000"/>
	<rect x="146" y="168" width="12" height="12" fill="#000000"/>
	<rect x="182" y="168" width="12" height="12" fill="#000000"/>
	<rect x="74" y="186" width="12" height="12" fill="#000000"/>
	<rect x="92" y="186" width="12" height="12" fill="#000000"/>
	<rect x="128" y="186" width="12" height="12" fill="#000000"/>
	<rect x="146" y="186" width="12" height="12" fill="#000000"/>
	<rect x="164" y="186" width="12" height="12" fill="#000000"/>
</svg>
SVG;

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
	<script src="https://telegram.org/js/telegram-web-app.js?62"></script>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'merchant-menu-public-body' ); ?>>
<?php wp_body_open(); ?>
<main class="merchant-menu-public-page">
	<div class="merchant-menu-public-device">
		<div class="merchant-menu-public-screen merchant-menu-public-screen--<?php echo esc_attr( $screen ); ?>">
			<header class="merchant-menu-public-header">
				<span class="merchant-menu-public-header__placeholder" aria-hidden="true"></span>
				<h1><?php echo esc_html( $page_title ); ?></h1>
				<span class="merchant-menu-public-header__placeholder" aria-hidden="true"></span>
			</header>

			<div class="merchant-menu-public-content">
				<?php if ( $screen === 'input' ) : ?>
					<section class="merchant-menu-public-card merchant-menu-public-card--hero merchant-menu-public-card--hero-input">
						<div class="merchant-menu-public-hero-copy">
							<h2>Система Быстрых Платежей</h2>
							<p><?php echo esc_html( $issued_label ); ?></p>
						</div>
						<span class="merchant-menu-public-hero-badge merchant-menu-public-hero-badge--logo" aria-hidden="true">
							<?php if ( $sbp_logo_url !== '' ) : ?>
								<img src="<?php echo esc_url( $sbp_logo_url ); ?>" alt="СБП">
							<?php else : ?>
								SBP
							<?php endif; ?>
						</span>
					</section>

						<form
							method="<?php echo esc_attr( $is_live_mode ? 'post' : 'get' ); ?>"
							action="<?php echo esc_url( $is_live_mode ? crm_tg_miniapp_query_url( $miniapp_access, [], [ 'order_id', 'screen', 'miniapp_action' ] ) : home_url( '/merchant-menu/' ) ); ?>"
							class="merchant-menu-public-card merchant-menu-public-card--input"
							data-live-calc="1"
							data-calc-mode="<?php echo esc_attr( $input_calc_mode ); ?>"
							data-markup-percent="<?php echo esc_attr( number_format( $markup_percent, 4, '.', '' ) ); ?>"
							data-markup-mode="<?php echo esc_attr( $rub_invoice_markup_mode ); ?>"
							data-effective-rate="<?php echo esc_attr( number_format( $merchant_rate, 4, '.', '' ) ); ?>"
						>
						<?php if ( $is_live_mode ) : ?>
							<?php crm_merchant_menu_hidden_inputs( $live_query_args ); ?>
							<input type="hidden" name="miniapp_action" value="create">
						<?php else : ?>
							<input type="hidden" name="screen" value="invoice">
							<input type="hidden" name="invoice_id" value="<?php echo esc_attr( $invoice_id ); ?>">
							<input type="hidden" name="payment_link" value="<?php echo esc_attr( $payment_link ); ?>">
							<input type="hidden" name="markup_percent" value="<?php echo esc_attr( (string) $markup_percent ); ?>">
							<input type="hidden" name="base_rate" value="<?php echo esc_attr( (string) $base_rate ); ?>">
						<?php endif; ?>

						<?php if ( $screen_error_text !== '' || $live_error !== '' ) : ?>
							<div class="merchant-menu-public-inline-note merchant-menu-public-inline-note--danger">
								<span>Ошибка:</span>
								<?php echo esc_html( $live_error !== '' ? $live_error : $screen_error_text ); ?>
							</div>
						<?php elseif ( $live_notice !== '' ) : ?>
							<div class="merchant-menu-public-inline-note merchant-menu-public-inline-note--pending">
								<span>Ожидание:</span>
								<?php echo esc_html( $live_notice ); ?>
							</div>
						<?php endif; ?>

						<label class="merchant-menu-public-field">
							<span class="merchant-menu-public-field__label">Сумма счёта</span>
							<span class="merchant-menu-public-field__shell">
								<span class="merchant-menu-public-field__prefix">RUB</span>
								<input
									class="merchant-menu-public-field__input"
									type="number"
									name="amount"
									min="1"
									step="0.01"
									value="<?php echo esc_attr( $input_amount > 0 ? number_format( $input_amount, 2, '.', '' ) : '' ); ?>"
									inputmode="decimal"
									autofocus
									required
								>
							</span>
						</label>

						<label class="merchant-menu-public-purpose-field">
							<span class="merchant-menu-public-purpose-field__label">Назначение платежа</span>
							<span class="merchant-menu-public-purpose-field__shell">
								<input
									class="merchant-menu-public-purpose-field__input"
									type="text"
									name="purpose"
									value="<?php echo esc_attr( $purpose ); ?>"
									maxlength="160"
									placeholder="Укажите назначение платежа"
									autocomplete="off"
									required
								>
							</span>
						</label>

						<div class="merchant-menu-public-input-hint"><?php echo esc_html( $input_hint ); ?></div>

						<div class="merchant-menu-public-kpi-grid">
							<?php foreach ( $input_stats as $item ) : ?>
								<div
									class="merchant-menu-public-kpi-item<?php echo ! empty( $item['wide'] ) ? ' merchant-menu-public-kpi-item--wide' : ''; ?>"
									<?php echo ! empty( $item['role'] ) ? 'data-calc-role="' . esc_attr( (string) $item['role'] ) . '"' : ''; ?>
								>
									<div class="merchant-menu-public-kpi-item__label"><?php echo esc_html( $item['label'] ); ?></div>
									<div class="merchant-menu-public-kpi-item__number"><?php echo esc_html( $item['number'] ); ?></div>
									<div class="merchant-menu-public-kpi-item__currency"><?php echo esc_html( $item['currency'] ); ?></div>
								</div>
							<?php endforeach; ?>
						</div>

						<div class="merchant-menu-public-actions merchant-menu-public-actions--form">
							<a class="merchant-menu-public-action merchant-menu-public-action--ghost" href="<?php echo esc_url( $new_invoice_url !== '' ? $new_invoice_url : home_url( '/merchant-menu/' ) ); ?>">Отмена</a>
							<button class="merchant-menu-public-action merchant-menu-public-action--primary" type="submit" data-show-loader="1">Продолжить</button>
						</div>
					</form>
				<?php elseif ( $screen === 'invoice' ) : ?>
					<section class="merchant-menu-public-card merchant-menu-public-card--hero">
						<div class="merchant-menu-public-hero-copy">
							<h2>Ссылка на оплату СБП</h2>
							<p><?php echo esc_html( $issued_label ); ?></p>
						</div>
						<a class="merchant-menu-public-icon-button merchant-menu-public-icon-button--share" href="<?php echo esc_url( $share_url !== '' ? $share_url : $payment_link ); ?>" target="_blank" rel="noopener" aria-label="Open payment link">
							<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
								<circle cx="18" cy="5" r="2.25" fill="currentColor"/>
								<circle cx="7" cy="12" r="2.25" fill="currentColor"/>
								<circle cx="18" cy="19" r="2.25" fill="currentColor"/>
								<path d="m8.9 11 6.1-4.2M8.9 13l6.1 4.2" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="2"/>
							</svg>
						</a>
					</section>

					<?php if ( $live_error !== '' || $live_notice !== '' ) : ?>
						<div class="merchant-menu-public-inline-note <?php echo esc_attr( $live_error !== '' ? 'merchant-menu-public-inline-note--danger' : 'merchant-menu-public-inline-note--pending' ); ?>">
							<span><?php echo esc_html( $live_error !== '' ? 'Ошибка:' : 'Ожидание:' ); ?></span>
							<?php echo esc_html( $live_error !== '' ? $live_error : $live_notice ); ?>
						</div>
					<?php endif; ?>

					<section class="merchant-menu-public-card merchant-menu-public-card--details">
						<div class="merchant-menu-public-detail-grid">
							<?php foreach ( $invoice_details as $detail ) : ?>
								<div class="merchant-menu-public-detail-item">
									<div class="merchant-menu-public-detail-item__label"><?php echo esc_html( $detail['label'] ); ?></div>
									<div class="merchant-menu-public-detail-item__value">
										<?php if ( $detail['label'] === 'Истекает' && $countdown_active ) : ?>
											<span
												class="merchant-menu-public-countdown"
												data-expires-at="<?php echo esc_attr( $expires_iso ); ?>"
												data-fallback="<?php echo esc_attr( $detail['value'] ); ?>"
											><?php echo esc_html( $detail['value'] ); ?></span>
										<?php else : ?>
											<?php echo esc_html( $detail['value'] ); ?>
										<?php endif; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
						<div class="merchant-menu-public-total-row">
							<span>К оплате:</span>
							<strong><?php echo esc_html( crm_merchant_menu_preview_amount( $payment_amount_rub, 'RUB', 2 ) ); ?></strong>
						</div>
					</section>

					<section class="merchant-menu-public-card merchant-menu-public-card--qr">
						<div class="merchant-menu-public-qr-frame">
							<?php if ( $qr_url !== '' ) : ?>
								<img src="<?php echo esc_url( $qr_url ); ?>" alt="SBP QR">
							<?php else : ?>
								<?php echo $fallback_qr_markup; ?>
							<?php endif; ?>
						</div>
					</section>

					<p class="merchant-menu-public-note">
						<span>Note:</span>
						<?php echo esc_html( $screen_note ); ?>
					</p>

					<div class="merchant-menu-public-actions">
						<a class="merchant-menu-public-action merchant-menu-public-action--ghost" href="<?php echo esc_url( $new_invoice_url ); ?>">Новый</a>
						<?php if ( $is_live_mode ) : ?>
							<form method="post" action="<?php echo esc_url( crm_tg_miniapp_query_url( $miniapp_access, [ 'order_id' => $live_order_id ], [ 'miniapp_action' ] ) ); ?>">
								<?php crm_merchant_menu_hidden_inputs( $live_query_args ); ?>
								<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $live_order_id ); ?>">
								<input type="hidden" name="miniapp_action" value="check">
								<button class="merchant-menu-public-action merchant-menu-public-action--primary" type="submit" data-show-loader="1">Проверить</button>
							</form>
						<?php else : ?>
							<a class="merchant-menu-public-action merchant-menu-public-action--primary" href="<?php echo esc_url( $success_url ); ?>" data-show-loader="1">Проверить</a>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<section class="merchant-menu-public-card merchant-menu-public-card--hero merchant-menu-public-card--hero-success">
						<div class="merchant-menu-public-hero-copy">
							<h2>Оплата прошла успешно</h2>
							<p><?php echo esc_html( $paid_label ); ?></p>
						</div>
						<div class="merchant-menu-public-success-mark" aria-hidden="true">
							<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
								<path d="m5 12 4.2 4.2L19 6.5" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.4"/>
							</svg>
						</div>
					</section>

					<section class="merchant-menu-public-card merchant-menu-public-card--details merchant-menu-public-card--details-success">
						<div class="merchant-menu-public-detail-grid">
							<?php foreach ( $success_details as $detail ) : ?>
								<div class="merchant-menu-public-detail-item">
									<div class="merchant-menu-public-detail-item__label"><?php echo esc_html( $detail['label'] ); ?></div>
									<div class="merchant-menu-public-detail-item__value"><?php echo esc_html( $detail['value'] ); ?></div>
								</div>
							<?php endforeach; ?>
						</div>
						<div class="merchant-menu-public-total-row merchant-menu-public-total-row--success">
							<span>Оплачено:</span>
							<strong><?php echo esc_html( crm_merchant_menu_preview_amount( $payment_amount_rub, 'RUB', 2 ) ); ?></strong>
						</div>
					</section>

					<p class="merchant-menu-public-note merchant-menu-public-note--success">
						<span>Успех:</span>
						<?php echo esc_html( $screen_note ); ?>
					</p>

					<div class="merchant-menu-public-actions">
						<a class="merchant-menu-public-action merchant-menu-public-action--ghost" href="<?php echo esc_url( $new_invoice_url ); ?>">Новый</a>
						<button
							class="merchant-menu-public-action merchant-menu-public-action--primary"
							type="button"
							data-miniapp-close="1"
							data-close-fallback="<?php echo esc_url( $new_invoice_url !== '' ? $new_invoice_url : home_url( '/merchant-menu/' ) ); ?>"
						>Закрыть</button>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
</main>

<script>
(function () {
	var webApp = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;

	function applyTelegramSafeArea() {
		if (!webApp) {
			return;
		}

		var safeArea = webApp.contentSafeAreaInset || webApp.safeAreaInset || {};
		document.documentElement.style.setProperty('--merchant-menu-safe-top', (safeArea.top || 0) + 'px');
		document.documentElement.style.setProperty('--merchant-menu-safe-right', (safeArea.right || 0) + 'px');
		document.documentElement.style.setProperty('--merchant-menu-safe-bottom', (safeArea.bottom || 0) + 'px');
		document.documentElement.style.setProperty('--merchant-menu-safe-left', (safeArea.left || 0) + 'px');
	}

	if (webApp) {
		document.documentElement.classList.add('merchant-menu-public-telegram');

		try {
			webApp.ready();
		} catch (error) {}

		try {
			webApp.expand();
		} catch (error) {}

		try {
			if (typeof webApp.setHeaderColor === 'function') {
				webApp.setHeaderColor('#f8e4df');
			}
		} catch (error) {}

		try {
			if (typeof webApp.setBackgroundColor === 'function') {
				webApp.setBackgroundColor('#f6ddd8');
			}
		} catch (error) {}

		try {
			if (typeof webApp.setBottomBarColor === 'function') {
				webApp.setBottomBarColor('#f6ddd8');
			}
		} catch (error) {}

		applyTelegramSafeArea();

		if (typeof webApp.onEvent === 'function') {
			webApp.onEvent('safeAreaChanged', applyTelegramSafeArea);
			webApp.onEvent('contentSafeAreaChanged', applyTelegramSafeArea);
			webApp.onEvent('fullscreenFailed', function () {
				try {
					webApp.expand();
				} catch (error) {}
			});
		}

		try {
			if (typeof webApp.requestFullscreen === 'function' && (!webApp.isVersionAtLeast || webApp.isVersionAtLeast('8.0'))) {
				webApp.requestFullscreen();
			}
		} catch (error) {
			try {
				webApp.expand();
			} catch (expandError) {}
		}
	}

	var calcForms = document.querySelectorAll('.merchant-menu-public-card--input[data-live-calc="1"]');

	function parseAmount(rawValue) {
		var normalized = String(rawValue || '').replace(',', '.').replace(/[^0-9.\-]/g, '');
		var parsed = parseFloat(normalized);
		return Number.isFinite(parsed) ? parsed : 0;
	}

	function formatAmount(value, decimals) {
		var safe = Number.isFinite(value) ? value : 0;
		var fixed = safe.toFixed(decimals);
		var parts = fixed.split('.');
		var integer = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
		return decimals > 0 ? integer + '.' + parts[1] : integer;
	}

	calcForms.forEach(function (form) {
		var amountInput = form.querySelector('.merchant-menu-public-field__input');
		if (!amountInput) {
			return;
		}

		var calcMode = form.getAttribute('data-calc-mode') || 'merchant';
		var markupPercent = parseAmount(form.getAttribute('data-markup-percent'));
		var markupMode = form.getAttribute('data-markup-mode') || 'none';
		var effectiveRate = parseAmount(form.getAttribute('data-effective-rate'));
		var markupNode = form.querySelector('[data-calc-role="markup"] .merchant-menu-public-kpi-item__number');
		var paymentNode = form.querySelector('[data-calc-role="payment"] .merchant-menu-public-kpi-item__number');
		var usdtNode = form.querySelector('[data-calc-role="usdt"] .merchant-menu-public-kpi-item__number');

		function renderCalculator() {
			var inputAmount = Math.max(0, parseAmount(amountInput.value));
			var paymentAmount = inputAmount;
			var markupAdded = 0;
			var payableUsdt = 0;

			if (calcMode === 'merchant' && markupMode === 'add_on_top' && markupPercent > 0) {
				paymentAmount = Math.round((inputAmount * (1 + (markupPercent / 100))) * 100) / 100;
				markupAdded = Math.max(0, paymentAmount - inputAmount);
			}

			if (calcMode === 'operator' && effectiveRate > 0) {
				payableUsdt = inputAmount > 0 ? (inputAmount / effectiveRate) : 0;
			}

			if (markupNode) {
				markupNode.textContent = formatAmount(markupAdded, 2);
			}

			if (paymentNode) {
				paymentNode.textContent = formatAmount(paymentAmount, 2);
			}

			if (usdtNode) {
				usdtNode.textContent = formatAmount(payableUsdt, 4);
			}
		}

		amountInput.addEventListener('input', renderCalculator);
		amountInput.addEventListener('change', renderCalculator);
		renderCalculator();
	});

	function disableInteractiveButton(node) {
		if (!node) {
			return;
		}

		node.classList.add('is-disabled');
		node.setAttribute('aria-disabled', 'true');

		if ('disabled' in node) {
			node.disabled = true;
		}
	}

	function shouldShowLoader(node) {
		return !!(node && node.getAttribute('data-show-loader') === '1');
	}

	function lockInteractiveState(trigger) {
		if (document.documentElement.classList.contains('merchant-menu-public-is-busy')) {
			return false;
		}

		document.documentElement.classList.add('merchant-menu-public-is-busy');

		document.querySelectorAll('.merchant-menu-public-action, .merchant-menu-public-icon-button--back').forEach(function (node) {
			disableInteractiveButton(node);
		});

		if (shouldShowLoader(trigger)) {
			trigger.classList.add('is-loading');
		}

		return true;
	}

	document.querySelectorAll('form').forEach(function (form) {
		form.addEventListener('submit', function (event) {
			if (form.dataset.submitting === '1' || document.documentElement.classList.contains('merchant-menu-public-is-busy')) {
				event.preventDefault();
				return;
			}

			form.dataset.submitting = '1';
			event.preventDefault();

			var submitter = event.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
			if (!lockInteractiveState(submitter)) {
				return;
			}

			window.setTimeout(function () {
				form.submit();
			}, 80);
		});
	});

	document.querySelectorAll('[data-miniapp-close="1"]').forEach(function (button) {
		button.addEventListener('click', function (event) {
			event.preventDefault();

			if (!lockInteractiveState(button)) {
				return;
			}

			var closeFallback = button.getAttribute('data-close-fallback') || '';

			if (webApp && typeof webApp.close === 'function') {
				window.setTimeout(function () {
					webApp.close();
				}, 80);
				return;
			}

			window.setTimeout(function () {
				if (window.history.length > 1) {
					window.history.back();
					return;
				}

				if (closeFallback) {
					window.location.href = closeFallback;
					return;
				}

				window.close();
			}, 80);
		});
	});

	document.querySelectorAll('.merchant-menu-public-action[href], .merchant-menu-public-icon-button--back[href]').forEach(function (link) {
		link.addEventListener('click', function (event) {
			if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
				return;
			}

			if (document.documentElement.classList.contains('merchant-menu-public-is-busy')) {
				event.preventDefault();
				return;
			}

			var href = link.getAttribute('href');
			if (!href || href.charAt(0) === '#') {
				return;
			}

			event.preventDefault();

			if (!lockInteractiveState(link)) {
				return;
			}

			window.setTimeout(function () {
				window.location.href = href;
			}, 80);
		});
	});

	var countdowns = document.querySelectorAll('.merchant-menu-public-countdown[data-expires-at]');
	function pad(value) {
		return String(value).padStart(2, '0');
	}

	function render(node) {
		var expiresAt = node.getAttribute('data-expires-at');
		if (!expiresAt) {
			return;
		}

		var expires = new Date(expiresAt).getTime();
		if (!expires) {
			return;
		}

		var diff = expires - Date.now();
		if (diff <= 0) {
			node.textContent = 'Истёк';
			node.classList.add('is-expired');
			return;
		}

		var totalSeconds = Math.floor(diff / 1000);
		var minutes = Math.floor(totalSeconds / 60);
		var seconds = totalSeconds % 60;
		node.textContent = pad(minutes) + ':' + pad(seconds);
	}

	if (countdowns.length) {
		countdowns.forEach(function (node) {
			render(node);
		});
		window.setInterval(function () {
			countdowns.forEach(function (node) {
				render(node);
			});
		}, 1000);
	}
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
