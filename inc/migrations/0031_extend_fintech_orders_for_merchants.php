<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0031_extend_fintech_orders_for_merchants',
	'title'    => 'Extend crm_fintech_payment_orders with merchant scope and economics fields',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		$columns = [
			'merchant_id'            => "ALTER TABLE `crm_fintech_payment_orders` ADD COLUMN `merchant_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `office_id`",
			'created_for_type'       => "ALTER TABLE `crm_fintech_payment_orders` ADD COLUMN `created_for_type` enum('company','merchant') NOT NULL DEFAULT 'company' AFTER `source_channel`",
			'merchant_markup_value'  => "ALTER TABLE `crm_fintech_payment_orders` ADD COLUMN `merchant_markup_value` decimal(20,8) DEFAULT NULL AFTER `payment_amount_value`",
			'platform_fee_value'     => "ALTER TABLE `crm_fintech_payment_orders` ADD COLUMN `platform_fee_value` decimal(20,8) DEFAULT NULL AFTER `merchant_markup_value`",
			'merchant_profit_value'  => "ALTER TABLE `crm_fintech_payment_orders` ADD COLUMN `merchant_profit_value` decimal(20,8) DEFAULT NULL AFTER `platform_fee_value`",
			'referral_reward_value'  => "ALTER TABLE `crm_fintech_payment_orders` ADD COLUMN `referral_reward_value` decimal(20,8) DEFAULT NULL AFTER `merchant_profit_value`",
			'merchant_meta_json'     => "ALTER TABLE `crm_fintech_payment_orders` ADD COLUMN `merchant_meta_json` longtext DEFAULT NULL AFTER `meta_json`",
		];

		foreach ( $columns as $column => $sql ) {
			if ( ! malibu_migrations_column_exists( 'crm_fintech_payment_orders', $column ) ) {
				$wpdb->query( $sql );
				$messages[] = 'crm_fintech_payment_orders: column `' . $column . '` added.';
			} else {
				$messages[] = 'crm_fintech_payment_orders: column `' . $column . '` already exists.';
			}
		}

		$indexes = [
			'idx_fintech_orders_merchant_created' => "ALTER TABLE `crm_fintech_payment_orders` ADD KEY `idx_fintech_orders_merchant_created` (`merchant_id`,`created_at`)",
			'idx_fintech_orders_company_merchant_status' => "ALTER TABLE `crm_fintech_payment_orders` ADD KEY `idx_fintech_orders_company_merchant_status` (`company_id`,`merchant_id`,`status_code`)",
		];

		foreach ( $indexes as $index_name => $sql ) {
			if ( ! malibu_migrations_index_exists( 'crm_fintech_payment_orders', $index_name ) ) {
				$wpdb->query( $sql );
				$messages[] = 'crm_fintech_payment_orders: index `' . $index_name . '` added.';
			} else {
				$messages[] = 'crm_fintech_payment_orders: index `' . $index_name . '` already exists.';
			}
		}

		$constraint_exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM information_schema.table_constraints
				 WHERE table_schema = DATABASE()
				   AND table_name = %s
				   AND constraint_name = %s",
				'crm_fintech_payment_orders',
				'fk_fintech_orders_merchant'
			)
		) > 0;

		if ( ! $constraint_exists && malibu_migrations_column_exists( 'crm_fintech_payment_orders', 'merchant_id' ) ) {
			$wpdb->query(
				"ALTER TABLE `crm_fintech_payment_orders`
				 ADD CONSTRAINT `fk_fintech_orders_merchant`
				   FOREIGN KEY (`merchant_id`) REFERENCES `crm_merchants` (`id`)
				   ON DELETE SET NULL ON UPDATE CASCADE"
			);
			$messages[] = 'crm_fintech_payment_orders: foreign key `fk_fintech_orders_merchant` added.';
		} else {
			$messages[] = 'crm_fintech_payment_orders: foreign key `fk_fintech_orders_merchant` already exists.';
		}

		return [
			'summary'  => 'crm_fintech_payment_orders extended for merchant contour.',
			'messages' => $messages,
		];
	},
];
