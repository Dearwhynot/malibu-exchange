<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0060_seed_company_2_demo_data',
	'title'    => 'Seed demo orders, merchants and payouts for company 2',
	'callback' => function () {
		global $wpdb;

		$company_id = 2;
		$seed_key   = 'company_2_demo_seed_0060';
		$messages   = [];

		$company = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, name, status FROM crm_companies WHERE id = %d LIMIT 1',
				$company_id
			)
		);

		if ( ! $company ) {
			return [
				'summary'  => 'Company 2 demo seed skipped.',
				'messages' => [ 'crm_companies.id=2 was not found; no demo data inserted.' ],
			];
		}

		if ( function_exists( 'crm_merchants_seed_company_settings' ) ) {
			crm_merchants_seed_company_settings( $company_id );
			$messages[] = 'Merchant settings ensured for company 2.';
		}

		$now_ts = (int) current_time( 'timestamp' );
		$dt     = static function ( int $days_ago = 0, int $offset_seconds = 0 ) use ( $now_ts ): string {
			return date( 'Y-m-d H:i:s', $now_ts - ( $days_ago * 86400 ) + $offset_seconds );
		};
		$date   = static function ( int $days_ago = 0 ) use ( $now_ts ): string {
			return date( 'Y-m-d', $now_ts - ( $days_ago * 86400 ) );
		};

		$company_user_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id
				 FROM crm_user_companies
				 WHERE company_id = %d
				   AND status = 'active'
				 ORDER BY is_company_admin DESC, is_primary DESC, id ASC
				 LIMIT 1",
				$company_id
			)
		);

		$ensure_merchant = static function ( array $data ) use ( $wpdb, $company_id, $company_user_id, &$messages ): int {
			$chat_id = (int) ( $data['chat_id'] ?? 0 );
			if ( $chat_id <= 0 ) {
				return 0;
			}

			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM crm_merchants WHERE company_id = %d AND chat_id = %d LIMIT 1',
					$company_id,
					$chat_id
				)
			);

			$row = [
				'company_id'          => $company_id,
				'chat_id'             => $chat_id,
				'telegram_username'   => sanitize_text_field( (string) ( $data['telegram_username'] ?? '' ) ),
				'name'                => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
				'status'              => CRM_MERCHANT_STATUS_ACTIVE,
				'base_markup_type'    => (string) ( $data['base_markup_type'] ?? 'percent' ),
				'base_markup_value'   => number_format( (float) ( $data['base_markup_value'] ?? 0 ), 8, '.', '' ),
				'ref_code'            => sanitize_key( (string) ( $data['ref_code'] ?? '' ) ),
				'note'                => 'Demo merchant for company 2 settlement tests.',
				'updated_by_user_id'  => $company_user_id > 0 ? $company_user_id : null,
				'updated_at'          => current_time( 'mysql' ),
			];

			if ( $existing_id > 0 ) {
				$wpdb->update(
					'crm_merchants',
					$row,
					[
						'id'         => $existing_id,
						'company_id' => $company_id,
					]
				);
				$messages[] = sprintf( 'Merchant #%d refreshed: %s.', $existing_id, $row['name'] );
				return $existing_id;
			}

			$row['created_by_user_id'] = $company_user_id > 0 ? $company_user_id : null;
			$row['created_at']         = current_time( 'mysql' );

			$inserted = $wpdb->insert( 'crm_merchants', $row );
			if ( $inserted === false ) {
				$messages[] = sprintf( 'Merchant insert failed for chat_id %d: %s.', $chat_id, (string) $wpdb->last_error );
				return 0;
			}

			$merchant_id = (int) $wpdb->insert_id;
			$messages[]  = sprintf( 'Merchant #%d created: %s.', $merchant_id, $row['name'] );
			return $merchant_id;
		};

		$merchant_alpha = $ensure_merchant( [
			'chat_id'           => 9200002001,
			'telegram_username' => 'demo_alpha_c2',
			'name'              => 'DEMO Merchant Alpha',
			'base_markup_type'  => 'percent',
			'base_markup_value' => 2.5,
			'ref_code'          => 'demo-alpha-c2',
		] );
		$merchant_beta = $ensure_merchant( [
			'chat_id'           => 9200002002,
			'telegram_username' => 'demo_beta_c2',
			'name'              => 'DEMO Merchant Beta',
			'base_markup_type'  => 'percent',
			'base_markup_value' => 2.0,
			'ref_code'          => 'demo-beta-c2',
		] );
		$merchant_gamma = $ensure_merchant( [
			'chat_id'           => 9200002003,
			'telegram_username' => 'demo_gamma_c2',
			'name'              => 'DEMO Merchant Gamma',
			'base_markup_type'  => 'fixed',
			'base_markup_value' => 1.5,
			'ref_code'          => 'demo-gamma-c2',
		] );

		if ( $merchant_alpha > 0 && $merchant_beta > 0 ) {
			$wpdb->update(
				'crm_merchants',
				[ 'referred_by_merchant_id' => $merchant_alpha ],
				[
					'id'         => $merchant_beta,
					'company_id' => $company_id,
				],
				[ '%d' ],
				[ '%d', '%d' ]
			);
			if ( function_exists( 'crm_sync_merchant_referral_link' ) ) {
				crm_sync_merchant_referral_link( $company_id, $merchant_beta, $merchant_alpha );
			}
			$messages[] = 'Referral link ensured: DEMO Merchant Alpha -> DEMO Merchant Beta.';
		}

		$create_mock_order = static function ( int $merchant_id, string $label, array $args ) use ( $wpdb, $company_id, $seed_key, &$messages ): void {
			if ( $merchant_id <= 0 ) {
				$messages[] = sprintf( 'Skipped merchant mock order "%s": merchant is missing.', $label );
				return;
			}

			$notes = $seed_key . ': ' . $label;
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM crm_fintech_payment_orders WHERE company_id = %d AND merchant_id = %d AND notes = %s',
					$company_id,
					$merchant_id,
					$notes
				)
			);
			if ( $exists > 0 ) {
				$messages[] = sprintf( 'Merchant mock order skipped, already exists: %s.', $label );
				return;
			}

			if ( ! function_exists( 'crm_mock_create_paid_merchant_order' ) ) {
				$messages[] = sprintf( 'Merchant mock helper missing, skipped: %s.', $label );
				return;
			}

			$args['notes']          = $notes;
			$args['description']    = 'Company 2 demo seed: ' . $label;
			$args['source_channel'] = 'merchant_mock';

			$result = crm_mock_create_paid_merchant_order( $merchant_id, $args );
			if ( empty( $result['success'] ) ) {
				$messages[] = sprintf( 'Merchant mock order failed "%s": %s.', $label, (string) ( $result['message'] ?? 'unknown error' ) );
				return;
			}

			$data = $result['data'] ?? [];
			$messages[] = sprintf(
				'Merchant mock order created "%s": order #%d, payable %s.',
				$label,
				(int) ( $data['order_id'] ?? 0 ),
				(string) ( $data['ledger_amount_label'] ?? 'n/a' )
			);
		};

		$create_mock_order( $merchant_alpha, 'Alpha paid order 120 gross / 117 payable', [
			'gross_usdt'            => 120.0,
			'merchant_payable_usdt' => 117.0,
			'platform_fee_usdt'     => 3.0,
			'requested_rub'         => 11100.0,
			'payment_rub'           => 11095.0,
		] );
		$create_mock_order( $merchant_alpha, 'Alpha paid order 70 gross / 68.25 payable', [
			'gross_usdt'            => 70.0,
			'merchant_payable_usdt' => 68.25,
			'platform_fee_usdt'     => 1.75,
			'requested_rub'         => 6480.0,
			'payment_rub'           => 6478.5,
		] );
		$create_mock_order( $merchant_beta, 'Beta paid order 95 gross / 92.65 payable', [
			'gross_usdt'            => 95.0,
			'merchant_payable_usdt' => 92.65,
			'platform_fee_usdt'     => 1.9,
			'referral_usdt'         => 0.45,
			'requested_rub'         => 8790.0,
			'payment_rub'           => 8788.0,
		] );
		$create_mock_order( $merchant_gamma, 'Gamma paid order 55 gross / 53.5 payable', [
			'gross_usdt'            => 55.0,
			'merchant_payable_usdt' => 53.5,
			'platform_fee_usdt'     => 1.5,
			'requested_rub'         => 5090.0,
			'payment_rub'           => 5088.0,
		] );

		$insert_company_order = static function ( array $order ) use ( $wpdb, $company_id, $company_user_id, $seed_key, $dt, &$messages ): int {
			$slug              = sanitize_key( (string) ( $order['slug'] ?? '' ) );
			$provider_code     = sanitize_key( (string) ( $order['provider_code'] ?? 'kanyon' ) );
			$source_channel    = sanitize_key( (string) ( $order['source_channel'] ?? 'web' ) );
			$status_code       = sanitize_key( (string) ( $order['status_code'] ?? 'paid' ) );
			$amount_usdt       = max( 0.00000001, (float) ( $order['amount_usdt'] ?? 1 ) );
			$payment_rub       = max( 1, (float) ( $order['payment_rub'] ?? 100 ) );
			$created_at        = $dt( (int) ( $order['days_ago'] ?? 1 ) );
			$paid_at           = $status_code === 'paid' ? $dt( (int) ( $order['days_ago'] ?? 1 ), 1800 ) : null;
			$expires_at        = $status_code === 'paid' ? null : $dt( 0, 86400 );
			$merchant_order_id = 'demo-c2-' . $slug;

			if ( $slug === '' || $provider_code === '' ) {
				return 0;
			}

			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM crm_fintech_payment_orders WHERE merchant_order_id = %s LIMIT 1',
					$merchant_order_id
				)
			);
			if ( $existing_id > 0 ) {
				$messages[] = sprintf( 'Company order skipped, already exists: %s (#%d).', $merchant_order_id, $existing_id );
				return $existing_id;
			}

			$payment_link = 'mock://company-2/' . rawurlencode( $merchant_order_id );
			$row = [
				'company_id'                    => $company_id,
				'provider_code'                 => $provider_code,
				'source_channel'                => $source_channel,
				'created_for_type'              => 'company',
				'local_order_ref'               => $seed_key,
				'merchant_order_id'             => $merchant_order_id,
				'provider_order_id'             => 'provider-' . $merchant_order_id,
				'provider_external_order_id'    => 'external-' . $merchant_order_id,
				'status_code'                   => $status_code,
				'provider_status_code'          => $status_code === 'paid' ? 'IPS_ACCEPTED' : 'CREATED',
				'amount_asset_code'             => 'USDT',
				'amount_asset_value'            => number_format( $amount_usdt, 8, '.', '' ),
				'payment_currency_code'         => 'RUB',
				'payment_amount_value'          => number_format( $payment_rub, 2, '.', '' ),
				'payment_link'                  => $payment_link,
				'qrc_id'                        => 'qrc-' . $merchant_order_id,
				'provider_public_link'          => $payment_link,
				'provider_requires_verification'=> 0,
				'first_callback_at'             => $paid_at,
				'last_callback_at'              => $paid_at,
				'expires_at'                    => $expires_at,
				'paid_at'                       => $paid_at,
				'meta_json'                     => wp_json_encode(
					[
						'purpose' => $seed_key,
						'label'   => (string) ( $order['label'] ?? $slug ),
					],
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				),
				'notes'                         => $seed_key . ': ' . (string) ( $order['label'] ?? $slug ),
				'created_by_user_id'            => $company_user_id > 0 ? $company_user_id : null,
				'created_at'                    => $created_at,
				'updated_at'                    => $created_at,
			];

			$inserted = $wpdb->insert( 'crm_fintech_payment_orders', $row );
			if ( $inserted === false ) {
				$messages[] = sprintf( 'Company order insert failed %s: %s.', $merchant_order_id, (string) $wpdb->last_error );
				return 0;
			}

			$order_id = (int) $wpdb->insert_id;
			$wpdb->insert(
				'crm_fintech_payment_order_status_history',
				[
					'payment_order_id'     => $order_id,
					'status_code'          => 'created',
					'provider_status_code' => 'CREATED',
					'source_code'          => 'seed',
					'message'              => 'Company 2 demo order created.',
					'created_by_user_id'   => $company_user_id > 0 ? $company_user_id : null,
					'created_at'           => $created_at,
				]
			);

			if ( $status_code === 'paid' ) {
				$wpdb->insert(
					'crm_fintech_payment_order_status_history',
					[
						'payment_order_id'     => $order_id,
						'status_code'          => 'paid',
						'provider_status_code' => 'IPS_ACCEPTED',
						'source_code'          => 'seed',
						'message'              => 'Company 2 demo order marked paid.',
						'created_by_user_id'   => $company_user_id > 0 ? $company_user_id : null,
						'created_at'           => $paid_at ?: $created_at,
					]
				);
			}

			$messages[] = sprintf( 'Company order created: %s (#%d, %s, %s).', $merchant_order_id, $order_id, $provider_code, $status_code );
			return $order_id;
		};

		$insert_company_order( [
			'slug'           => 'web-kanyon-paid-001',
			'label'          => 'Web direct paid order, Kanyon',
			'provider_code'  => 'kanyon',
			'source_channel' => 'web',
			'status_code'    => 'paid',
			'amount_usdt'    => 150.0,
			'payment_rub'    => 13890.0,
			'days_ago'       => 4,
		] );
		$insert_company_order( [
			'slug'           => 'tg-operator-kanyon-paid-001',
			'label'          => 'Telegram operator paid order, Kanyon',
			'provider_code'  => 'kanyon',
			'source_channel' => 'telegram_operator',
			'status_code'    => 'paid',
			'amount_usdt'    => 62.5,
			'payment_rub'    => 5790.0,
			'days_ago'       => 3,
		] );
		$insert_company_order( [
			'slug'           => 'web-kanyon-open-001',
			'label'          => 'Web direct open order, Kanyon',
			'provider_code'  => 'kanyon',
			'source_channel' => 'web',
			'status_code'    => 'created',
			'amount_usdt'    => 40.0,
			'payment_rub'    => 3710.0,
			'days_ago'       => 0,
		] );

		$allowed_providers = function_exists( 'crm_fintech_get_allowed_providers' )
			? crm_fintech_get_allowed_providers( $company_id )
			: [ 'kanyon' ];
		if ( in_array( 'doverka', $allowed_providers, true ) ) {
			$insert_company_order( [
				'slug'           => 'web-doverka-paid-001',
				'label'          => 'Web direct paid order, Doverka',
				'provider_code'  => 'doverka',
				'source_channel' => 'web',
				'status_code'    => 'paid',
				'amount_usdt'    => 88.0,
				'payment_rub'    => 8150.0,
				'days_ago'       => 2,
			] );
		} else {
			$messages[] = 'Doverka demo order skipped: provider is not allowed for company 2.';
		}

		$insert_payout = static function ( string $provider_code, string $reference, float $amount, int $days_ago, string $notes ) use ( $wpdb, $company_id, $company_user_id, $seed_key, $date, $dt, &$messages ): void {
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM crm_acquirer_payouts WHERE company_id = %d AND provider_code = %s AND reference = %s',
					$company_id,
					$provider_code,
					$reference
				)
			);
			if ( $exists > 0 ) {
				$messages[] = sprintf( 'EP payout skipped, already exists: %s.', $reference );
				return;
			}

			$row = [
				'company_id'          => $company_id,
				'provider_code'       => $provider_code,
				'amount'              => number_format( $amount, 8, '.', '' ),
				'currency_code'       => 'USDT',
				'period_from'         => $date( $days_ago + 3 ),
				'period_to'           => $date( $days_ago ),
				'reference'           => $reference,
				'notes'               => $seed_key . ': ' . $notes,
				'recorded_by_user_id' => $company_user_id > 0 ? $company_user_id : null,
				'created_at'          => $dt( $days_ago ),
				'updated_at'          => $dt( $days_ago ),
			];

			$inserted = $wpdb->insert( 'crm_acquirer_payouts', $row );
			if ( $inserted === false ) {
				$messages[] = sprintf( 'EP payout insert failed %s: %s.', $reference, (string) $wpdb->last_error );
				return;
			}

			$messages[] = sprintf( 'EP payout created: %s (#%d, %s USDT, %s).', $reference, (int) $wpdb->insert_id, number_format( $amount, 8, '.', '' ), $provider_code );
		};

		$insert_payout( 'kanyon', 'DEMO-C2-KANYON-EP-001', 180.0, 2, 'Partial incoming payout from Kanyon.' );
		$insert_payout( 'kanyon', 'DEMO-C2-KANYON-EP-002', 75.5, 1, 'Second incoming payout from Kanyon.' );
		if ( in_array( 'doverka', $allowed_providers, true ) ) {
			$insert_payout( 'doverka', 'DEMO-C2-DOVERKA-EP-001', 40.0, 1, 'Partial incoming payout from Doverka.' );
		}

		crm_log( 'demo.seed_company_2', [
			'category'    => 'system',
			'level'       => 'info',
			'action'      => 'seed',
			'message'     => 'Demo data seeded for company 2.',
			'target_type' => 'company',
			'target_id'   => $company_id,
			'org_id'      => $company_id,
			'is_success'  => true,
			'context'     => [
				'seed_key' => $seed_key,
			],
		] );

		return [
			'summary'  => 'Company 2 demo data seeded.',
			'messages' => $messages,
		];
	},
];
