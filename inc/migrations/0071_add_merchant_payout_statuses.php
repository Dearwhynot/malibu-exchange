<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0071_add_merchant_payout_statuses',
	'title'    => 'Add merchant payout statuses, cancellation metadata and reversal ledger entry',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		if ( ! malibu_migrations_column_exists( 'crm_merchant_payouts', 'status_code' ) ) {
			$wpdb->query(
				"ALTER TABLE `crm_merchant_payouts`
				 ADD COLUMN `status_code` varchar(32) NOT NULL DEFAULT 'paid' AFTER `notes`"
			);
			$messages[] = 'crm_merchant_payouts: status_code column added.';
		} else {
			$messages[] = 'crm_merchant_payouts: status_code column already exists.';
		}

		if ( ! malibu_migrations_column_exists( 'crm_merchant_payouts', 'confirmed_at' ) ) {
			$wpdb->query(
				"ALTER TABLE `crm_merchant_payouts`
				 ADD COLUMN `confirmed_at` datetime DEFAULT NULL AFTER `paid_at`"
			);
			$messages[] = 'crm_merchant_payouts: confirmed_at column added.';
		}

		if ( ! malibu_migrations_column_exists( 'crm_merchant_payouts', 'cancelled_at' ) ) {
			$wpdb->query(
				"ALTER TABLE `crm_merchant_payouts`
				 ADD COLUMN `cancelled_at` datetime DEFAULT NULL AFTER `confirmed_at`"
			);
			$messages[] = 'crm_merchant_payouts: cancelled_at column added.';
		}

		if ( ! malibu_migrations_column_exists( 'crm_merchant_payouts', 'cancelled_by_user_id' ) ) {
			$wpdb->query(
				"ALTER TABLE `crm_merchant_payouts`
				 ADD COLUMN `cancelled_by_user_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `cancelled_at`"
			);
			$messages[] = 'crm_merchant_payouts: cancelled_by_user_id column added.';
		}

		if ( ! malibu_migrations_column_exists( 'crm_merchant_payouts', 'cancellation_reason_code' ) ) {
			$wpdb->query(
				"ALTER TABLE `crm_merchant_payouts`
				 ADD COLUMN `cancellation_reason_code` varchar(64) DEFAULT NULL AFTER `cancelled_by_user_id`"
			);
			$messages[] = 'crm_merchant_payouts: cancellation_reason_code column added.';
		}

		if ( ! malibu_migrations_column_exists( 'crm_merchant_payouts', 'cancellation_comment' ) ) {
			$wpdb->query(
				"ALTER TABLE `crm_merchant_payouts`
				 ADD COLUMN `cancellation_comment` text DEFAULT NULL AFTER `cancellation_reason_code`"
			);
			$messages[] = 'crm_merchant_payouts: cancellation_comment column added.';
		}

		$wpdb->query( "UPDATE `crm_merchant_payouts` SET `status_code` = 'paid' WHERE `status_code` IS NULL OR `status_code` = ''" );
		$messages[] = 'crm_merchant_payouts: existing rows backfilled with status_code=paid.';

		if ( ! malibu_migrations_index_exists( 'crm_merchant_payouts', 'idx_merchant_payouts_company_status_paid' ) ) {
			$wpdb->query(
				"ALTER TABLE `crm_merchant_payouts`
				 ADD KEY `idx_merchant_payouts_company_status_paid` (`company_id`,`status_code`,`paid_at`)"
			);
			$messages[] = 'crm_merchant_payouts: company/status/paid_at index added.';
		}

		$ledger_entry_type = $wpdb->get_row( "SHOW COLUMNS FROM `crm_merchant_wallet_ledger` LIKE 'entry_type'" );
		$ledger_type_sql   = isset( $ledger_entry_type->Type ) ? (string) $ledger_entry_type->Type : '';

		if ( $ledger_type_sql !== '' && stripos( $ledger_type_sql, "'merchant_payout_reversal'" ) === false ) {
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
				  'merchant_accrual',
				  'merchant_payout',
				  'merchant_payout_reversal'
				 ) NOT NULL"
			);
			$messages[] = 'crm_merchant_wallet_ledger: entry_type extended with merchant_payout_reversal.';
		} elseif ( $ledger_type_sql !== '' ) {
			$messages[] = 'crm_merchant_wallet_ledger: entry_type already contains merchant_payout_reversal.';
		}

		return [
			'summary'  => 'Merchant payout statuses ensured.',
			'messages' => $messages,
		];
	},
];
