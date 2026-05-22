<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0083_cleanup_retired_demo_seed_data',
	'title'    => 'Remove retired demo seed data and merchant mock traces',
	'callback' => function () {
		global $wpdb;

		$company_id      = 2;
		$seed_key        = 'company_2_demo_seed_0060';
		$mock_chat_ids   = [ 9200002001, 9200002002, 9200002003 ];
		$mock_usernames  = [ 'demo_alpha_c2', 'demo_beta_c2', 'demo_gamma_c2' ];
		$mock_ref_codes  = [ 'demo-alpha-c2', 'demo-beta-c2', 'demo-gamma-c2' ];
		$mock_names      = [ 'DEMO Merchant Alpha', 'DEMO Merchant Beta', 'DEMO Merchant Gamma' ];
		$messages        = [];
		$deleted_counts  = [];
		$mock_merchant_ids = [];
		$mock_order_ids    = [];
		$mock_ledger_ids   = [];

		$delete_rows = static function ( string $table, string $where_sql, array $params = [] ) use ( $wpdb ) {
			$sql = sprintf( 'DELETE FROM `%s` WHERE %s', $table, $where_sql );
			if ( ! empty( $params ) ) {
				$sql = $wpdb->prepare( $sql, $params );
			}

			$result = $wpdb->query( $sql );
			if ( $result === false ) {
				throw new RuntimeException( sprintf( '%s delete failed: %s', $table, (string) $wpdb->last_error ) );
			}

			return (int) $result;
		};

		if ( ! malibu_migrations_table_exists( 'crm_fintech_payment_orders' ) ) {
			return [
				'summary'  => 'Demo cleanup skipped: crm_fintech_payment_orders is missing.',
				'messages' => [ 'Nothing to clean before the payment orders table exists.' ],
			];
		}

		if ( malibu_migrations_table_exists( 'crm_merchants' ) ) {
			$mock_merchant_ids = array_map(
				'intval',
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT id
						 FROM crm_merchants
						 WHERE company_id = %d
						   AND (
						     chat_id IN (9200002001,9200002002,9200002003)
						     OR telegram_username IN (%s,%s,%s)
						     OR ref_code IN (%s,%s,%s)
						     OR name IN (%s,%s,%s)
						   )",
						array_merge(
							[ $company_id ],
							$mock_usernames,
							$mock_ref_codes,
							$mock_names
						)
					)
				) ?: []
			);
		}

		$merchant_id_sql = implode( ',', array_map( 'intval', $mock_merchant_ids ) );

		$order_sql    = "SELECT id
			FROM crm_fintech_payment_orders
			WHERE company_id = %d
			  AND (
			    merchant_order_id LIKE %s
			    OR notes LIKE %s
			    OR local_order_ref = %s
			    OR source_channel = %s";
		$order_params = [
			$company_id,
			'demo-c2-%',
			$seed_key . ':%',
			'merchant_mock_paid',
			'merchant_mock',
		];

		if ( $merchant_id_sql !== '' ) {
			$order_sql .= " OR merchant_id IN ({$merchant_id_sql})";
		}

		$order_sql      .= "\n  )";
		$mock_order_ids = array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( $order_sql, $order_params ) ) ?: []
		);
		$order_id_sql = implode( ',', array_map( 'intval', $mock_order_ids ) );

		if ( malibu_migrations_table_exists( 'crm_merchant_wallet_ledger' ) ) {
			$ledger_where = [];
			if ( $order_id_sql !== '' ) {
				$ledger_where[] = "source_order_id IN ({$order_id_sql})";
			}
			if ( $merchant_id_sql !== '' ) {
				$ledger_where[] = "merchant_id IN ({$merchant_id_sql})";
				$ledger_where[] = "source_merchant_id IN ({$merchant_id_sql})";
			}

			if ( ! empty( $ledger_where ) ) {
				$mock_ledger_ids = array_map(
					'intval',
					$wpdb->get_col(
						"SELECT id
						 FROM crm_merchant_wallet_ledger
						 WHERE " . implode( ' OR ', $ledger_where )
					) ?: []
				);
			}
		}
		$ledger_id_sql = implode( ',', array_map( 'intval', $mock_ledger_ids ) );

		$wpdb->query( 'START TRANSACTION' );

		try {
			if ( malibu_migrations_table_exists( 'crm_fintech_payment_callbacks' ) ) {
				$callback_conditions = [
					'merchant_order_id_hint LIKE %s',
					'merchant_order_id_hint LIKE %s',
					'order_id_hint LIKE %s',
					'body_raw LIKE %s',
				];
				$callback_params     = [
					'demo-c2-%',
					'mockm-%',
					'mockk-%',
					'%mock://merchant-paid/%',
				];

				if ( $order_id_sql !== '' ) {
					$callback_conditions[] = "payment_order_id IN ({$order_id_sql})";
				}

				$deleted_counts['crm_fintech_payment_callbacks'] = $delete_rows(
					'crm_fintech_payment_callbacks',
					implode( ' OR ', $callback_conditions ),
					$callback_params
				);
			}

			if ( malibu_migrations_table_exists( 'crm_fintech_payment_order_status_history' ) && $order_id_sql !== '' ) {
				$deleted_counts['crm_fintech_payment_order_status_history'] = $delete_rows(
					'crm_fintech_payment_order_status_history',
					"payment_order_id IN ({$order_id_sql})"
				);
			}

			if ( malibu_migrations_table_exists( 'crm_merchant_wallet_ledger' ) ) {
				$ledger_delete_where = [];
				if ( $order_id_sql !== '' ) {
					$ledger_delete_where[] = "source_order_id IN ({$order_id_sql})";
				}
				if ( $merchant_id_sql !== '' ) {
					$ledger_delete_where[] = "merchant_id IN ({$merchant_id_sql})";
					$ledger_delete_where[] = "source_merchant_id IN ({$merchant_id_sql})";
				}

				if ( ! empty( $ledger_delete_where ) ) {
					$deleted_counts['crm_merchant_wallet_ledger'] = $delete_rows(
						'crm_merchant_wallet_ledger',
						implode( ' OR ', $ledger_delete_where )
					);
				}
			}

			if ( malibu_migrations_table_exists( 'crm_merchant_payouts' ) && $merchant_id_sql !== '' ) {
				$deleted_counts['crm_merchant_payouts'] = $delete_rows(
					'crm_merchant_payouts',
					"merchant_id IN ({$merchant_id_sql})"
				);
			}

			if ( malibu_migrations_table_exists( 'crm_merchant_api_clients' ) && $merchant_id_sql !== '' ) {
				$deleted_counts['crm_merchant_api_clients'] = $delete_rows(
					'crm_merchant_api_clients',
					"merchant_id IN ({$merchant_id_sql})"
				);
			}

			if ( malibu_migrations_table_exists( 'crm_merchant_telegram_sessions' ) && $merchant_id_sql !== '' ) {
				$deleted_counts['crm_merchant_telegram_sessions'] = $delete_rows(
					'crm_merchant_telegram_sessions',
					"merchant_id IN ({$merchant_id_sql})"
				);
			}

			if ( malibu_migrations_table_exists( 'crm_merchant_invites' ) ) {
				$invite_conditions = [
					'chat_id IN (9200002001,9200002002,9200002003)',
				];
				if ( $merchant_id_sql !== '' ) {
					$invite_conditions[] = "merchant_id IN ({$merchant_id_sql})";
				}
				$deleted_counts['crm_merchant_invites'] = $delete_rows(
					'crm_merchant_invites',
					implode( ' OR ', $invite_conditions )
				);
			}

			if ( malibu_migrations_table_exists( 'crm_merchant_referrals' ) && $merchant_id_sql !== '' ) {
				$deleted_counts['crm_merchant_referrals'] = $delete_rows(
					'crm_merchant_referrals',
					"referrer_merchant_id IN ({$merchant_id_sql}) OR referral_merchant_id IN ({$merchant_id_sql})"
				);
			}

			if ( $order_id_sql !== '' ) {
				$deleted_counts['crm_fintech_payment_orders'] = $delete_rows(
					'crm_fintech_payment_orders',
					"id IN ({$order_id_sql})"
				);
			}

			if ( malibu_migrations_table_exists( 'crm_merchants' ) && $merchant_id_sql !== '' ) {
				$deleted_counts['crm_merchants'] = $delete_rows(
					'crm_merchants',
					"id IN ({$merchant_id_sql})"
				);
			}

			if ( malibu_migrations_table_exists( 'crm_acquirer_payouts' ) ) {
				$deleted_counts['crm_acquirer_payouts'] = $delete_rows(
					'crm_acquirer_payouts',
					'reference LIKE %s OR notes LIKE %s',
					[
						'DEMO-C2-%',
						$seed_key . ':%',
					]
				);
			}

			if ( malibu_migrations_table_exists( 'crm_audit_log' ) ) {
				$audit_conditions = [
					'event_code = %s',
					'event_code = %s',
					'event_code = %s',
				];
				$audit_params     = [
					'demo.seed_company_2',
					'merchant.mock_paid_order_created',
					'merchant.mock_paid_order_failed',
				];

				if ( $order_id_sql !== '' ) {
					$audit_conditions[] = "(target_type = 'payment_order' AND target_id IN ({$order_id_sql}))";
				}
				if ( $merchant_id_sql !== '' ) {
					$audit_conditions[] = "(target_type = 'merchant' AND target_id IN ({$merchant_id_sql}))";
				}
				if ( $ledger_id_sql !== '' ) {
					$audit_conditions[] = "(target_type = 'merchant_ledger_entry' AND target_id IN ({$ledger_id_sql}))";
				}

				$deleted_counts['crm_audit_log'] = $delete_rows(
					'crm_audit_log',
					implode( ' OR ', $audit_conditions ),
					$audit_params
				);
			}

			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error(
				'demo_cleanup_failed',
				'Failed to remove retired demo seed data.',
				[
					'messages' => [
						$e->getMessage(),
					],
				]
			);
		}

		foreach ( $deleted_counts as $table => $count ) {
			$messages[] = sprintf( '%s: deleted %d row(s).', $table, (int) $count );
		}

		if ( function_exists( 'crm_log' ) ) {
			crm_log( 'system.cleanup_retired_demo_seed', [
				'category'    => 'system',
				'level'       => 'info',
				'action'      => 'delete',
				'message'     => 'Retired demo seed data and merchant mock traces removed.',
				'target_type' => 'company',
				'target_id'   => $company_id,
				'org_id'      => $company_id,
				'is_success'  => true,
				'context'     => [
					'seed_key'          => $seed_key,
					'mock_chat_ids'     => $mock_chat_ids,
					'mock_usernames'    => $mock_usernames,
					'mock_ref_codes'    => $mock_ref_codes,
					'mock_merchant_ids' => $mock_merchant_ids,
					'mock_order_ids'    => $mock_order_ids,
					'deleted_counts'    => $deleted_counts,
				],
			] );
		}

		if ( empty( $messages ) ) {
			$messages[] = 'No retired demo merchants, orders, payouts, callbacks, or mock traces were found.';
		}

		return [
			'summary'  => 'Retired demo seed cleanup completed.',
			'messages' => $messages,
		];
	},
];
