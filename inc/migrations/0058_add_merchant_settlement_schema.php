<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0058_add_merchant_settlement_schema',
	'title'    => 'Add merchant settlement economics columns and ledger idempotency guard',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		$order_columns = [
			'merchant_requested_rub_value' => "ALTER TABLE `crm_fintech_payment_orders` ADD COLUMN `merchant_requested_rub_value` decimal(20,2) DEFAULT NULL AFTER `payment_amount_value`",
			'merchant_payable_value'      => "ALTER TABLE `crm_fintech_payment_orders` ADD COLUMN `merchant_payable_value` decimal(20,8) DEFAULT NULL AFTER `merchant_requested_rub_value`",
		];

		foreach ( $order_columns as $column => $sql ) {
			if ( ! malibu_migrations_column_exists( 'crm_fintech_payment_orders', $column ) ) {
				$wpdb->query( $sql );
				$messages[] = 'crm_fintech_payment_orders: added column `' . $column . '`.';
			} else {
				$messages[] = 'crm_fintech_payment_orders: column `' . $column . '` already exists.';
			}
		}

		$ledger_entry_type = $wpdb->get_row( "SHOW COLUMNS FROM `crm_merchant_wallet_ledger` LIKE 'entry_type'" );
		$ledger_type_sql   = isset( $ledger_entry_type->Type ) ? (string) $ledger_entry_type->Type : '';

		if ( $ledger_type_sql !== '' && stripos( $ledger_type_sql, "'merchant_accrual'" ) === false ) {
			$wpdb->query(
				"ALTER TABLE `crm_merchant_wallet_ledger`
				 MODIFY COLUMN `entry_type` enum(
				  'bonus_accrual',
				  'bonus_adjustment',
				  'bonus_withdrawal',
				  'referral_accrual',
				  'referral_adjustment',
				  'manual_credit',
				  'manual_debit',
				  'merchant_accrual'
				 ) NOT NULL"
			);
			$messages[] = 'crm_merchant_wallet_ledger: entry_type extended with `merchant_accrual`.';
		} elseif ( $ledger_type_sql !== '' ) {
			$messages[] = 'crm_merchant_wallet_ledger: entry_type already contains `merchant_accrual`.';
		}

		if ( ! malibu_migrations_index_exists( 'crm_merchant_wallet_ledger', 'uq_crm_merchant_wallet_ledger_source_entry' ) ) {
			$wpdb->query(
				"ALTER TABLE `crm_merchant_wallet_ledger`
				 ADD UNIQUE KEY `uq_crm_merchant_wallet_ledger_source_entry` (`source_order_id`,`merchant_id`,`entry_type`)"
			);
			$messages[] = 'crm_merchant_wallet_ledger: unique source-entry index added.';
		} else {
			$messages[] = 'crm_merchant_wallet_ledger: unique source-entry index already exists.';
		}

		if ( malibu_migrations_column_exists( 'crm_fintech_payment_orders', 'platform_fee_value' ) ) {
			$backfilled_fee = $wpdb->query(
				"UPDATE `crm_fintech_payment_orders`
				 SET `platform_fee_value` = `merchant_markup_value`
				 WHERE `created_for_type` = 'merchant'
				   AND `platform_fee_value` IS NULL
				   AND `merchant_markup_value` IS NOT NULL"
			);
			$messages[] = 'crm_fintech_payment_orders: platform_fee_value backfill affected ' . (int) $backfilled_fee . ' row(s).';
		}

		if ( malibu_migrations_column_exists( 'crm_fintech_payment_orders', 'merchant_profit_value' ) ) {
			$backfilled_profit = $wpdb->query(
				"UPDATE `crm_fintech_payment_orders`
				 SET `merchant_profit_value` = 0
				 WHERE `created_for_type` = 'merchant'
				   AND `merchant_profit_value` IS NULL"
			);
			$messages[] = 'crm_fintech_payment_orders: merchant_profit_value backfill affected ' . (int) $backfilled_profit . ' row(s).';
		}

		if ( malibu_migrations_column_exists( 'crm_fintech_payment_orders', 'merchant_payable_value' ) ) {
			$backfilled_payable = $wpdb->query(
				"UPDATE `crm_fintech_payment_orders`
				 SET `merchant_payable_value` = GREATEST(
				  `amount_asset_value` - COALESCE(`platform_fee_value`, `merchant_markup_value`, 0) - COALESCE(`referral_reward_value`, 0),
				  0
				 )
				 WHERE `created_for_type` = 'merchant'
				   AND `merchant_payable_value` IS NULL"
			);
			$messages[] = 'crm_fintech_payment_orders: merchant_payable_value backfill affected ' . (int) $backfilled_payable . ' row(s).';
		}

		return [
			'summary'  => 'Merchant settlement schema updated.',
			'messages' => $messages,
		];
	},
];
