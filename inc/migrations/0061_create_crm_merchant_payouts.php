<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0061_create_crm_merchant_payouts',
	'title'    => 'Create merchant payouts layer and page',
	'callback' => function () {
		global $wpdb;

		$messages = crm_rbac_sync();

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `crm_merchant_payouts` (
			  `id`                  bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`          int(10) UNSIGNED    NOT NULL,
			  `merchant_id`         bigint(20) UNSIGNED NOT NULL,
			  `amount`              decimal(20,8)       NOT NULL,
			  `currency_code`       varchar(16)         NOT NULL DEFAULT 'USDT',
			  `network`             varchar(32)         NOT NULL DEFAULT 'TRC20',
			  `wallet_address`      varchar(255)        DEFAULT NULL,
			  `tx_hash`             varchar(255)        DEFAULT NULL,
			  `receipt_filename`    varchar(255)        DEFAULT NULL,
			  `notes`               text                DEFAULT NULL,
			  `paid_by_user_id`     bigint(20) UNSIGNED DEFAULT NULL,
			  `paid_at`             datetime            NOT NULL DEFAULT current_timestamp(),
			  `created_at`          datetime            NOT NULL DEFAULT current_timestamp(),
			  `updated_at`          datetime            NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  KEY `idx_merchant_payouts_company_created` (`company_id`,`created_at`),
			  KEY `idx_merchant_payouts_merchant_created` (`merchant_id`,`created_at`),
			  KEY `idx_merchant_payouts_company_merchant` (`company_id`,`merchant_id`),
			  CONSTRAINT `fk_merchant_payouts_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_merchant_payouts_merchant`
			    FOREIGN KEY (`merchant_id`) REFERENCES `crm_merchants` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);
		$messages[] = 'crm_merchant_payouts: table ensured.';

		$ledger_entry_type = $wpdb->get_row( "SHOW COLUMNS FROM `crm_merchant_wallet_ledger` LIKE 'entry_type'" );
		$ledger_type_sql   = isset( $ledger_entry_type->Type ) ? (string) $ledger_entry_type->Type : '';

		if ( $ledger_type_sql !== '' && stripos( $ledger_type_sql, "'merchant_payout'" ) === false ) {
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
				  'merchant_payout'
				 ) NOT NULL"
			);
			$messages[] = 'crm_merchant_wallet_ledger: entry_type extended with `merchant_payout`.';
		} elseif ( $ledger_type_sql !== '' ) {
			$messages[] = 'crm_merchant_wallet_ledger: entry_type already contains `merchant_payout`.';
		}

		if ( ! malibu_migrations_column_exists( 'crm_merchant_wallet_ledger', 'source_payout_id' ) ) {
			$wpdb->query(
				"ALTER TABLE `crm_merchant_wallet_ledger`
				 ADD COLUMN `source_payout_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `source_order_id`"
			);
			$messages[] = 'crm_merchant_wallet_ledger: source_payout_id column added.';
		} else {
			$messages[] = 'crm_merchant_wallet_ledger: source_payout_id column already exists.';
		}

		if ( ! malibu_migrations_index_exists( 'crm_merchant_wallet_ledger', 'idx_crm_merchant_wallet_ledger_source_payout' ) ) {
			$wpdb->query(
				"ALTER TABLE `crm_merchant_wallet_ledger`
				 ADD KEY `idx_crm_merchant_wallet_ledger_source_payout` (`source_payout_id`)"
			);
			$messages[] = 'crm_merchant_wallet_ledger: source_payout index added.';
		}

		if ( ! malibu_migrations_index_exists( 'crm_merchant_wallet_ledger', 'uq_crm_merchant_wallet_ledger_payout_entry' ) ) {
			$wpdb->query(
				"ALTER TABLE `crm_merchant_wallet_ledger`
				 ADD UNIQUE KEY `uq_crm_merchant_wallet_ledger_payout_entry` (`source_payout_id`,`merchant_id`,`entry_type`)"
			);
			$messages[] = 'crm_merchant_wallet_ledger: unique payout-entry index added.';
		}

		$existing = get_page_by_path( 'merchant-payouts', OBJECT, 'page' );
		if ( $existing instanceof WP_Post ) {
			$current_template = get_post_meta( $existing->ID, '_wp_page_template', true );
			if ( $current_template !== 'page-merchant-payouts.php' ) {
				update_post_meta( $existing->ID, '_wp_page_template', 'page-merchant-payouts.php' );
				$messages[] = 'Page "merchant-payouts" already existed; template updated to page-merchant-payouts.php.';
			} else {
				$messages[] = 'Page "merchant-payouts" already exists with correct template.';
			}
		} else {
			$page_id = wp_insert_post( [
				'post_title'   => 'Выплаты мерчантам',
				'post_name'    => 'merchant-payouts',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => 1,
				'post_content' => '',
				'post_excerpt' => '',
			], true );

			if ( is_wp_error( $page_id ) ) {
				$messages[] = 'ERROR: failed to create /merchant-payouts/ page: ' . $page_id->get_error_message();
			} else {
				update_post_meta( $page_id, '_wp_page_template', 'page-merchant-payouts.php' );
				$messages[] = 'Created WordPress page "Выплаты мерчантам" (slug: merchant-payouts, ID: ' . $page_id . ').';
				$messages[] = 'Assigned template: page-merchant-payouts.php.';
				$messages[] = 'URL: ' . get_permalink( $page_id );
			}
		}

		return [
			'summary'  => 'Merchant payouts layer ensured.',
			'messages' => $messages,
		];
	},
];
