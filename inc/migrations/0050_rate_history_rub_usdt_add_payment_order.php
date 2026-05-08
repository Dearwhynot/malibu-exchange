<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0050_rate_history_rub_usdt_add_payment_order',
	'title'    => 'Add payment_order_id to crm_rate_history_rub_usdt',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		if ( ! malibu_migrations_table_exists( 'crm_rate_history_rub_usdt' ) ) {
			$created = $wpdb->query( "
				CREATE TABLE IF NOT EXISTS `crm_rate_history_rub_usdt` (
					`id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
					`organization_id`  INT UNSIGNED    NOT NULL,
					`payment_order_id` bigint(20) UNSIGNED DEFAULT NULL,
					`kanyon_rate`      DECIMAL(20,8)   NOT NULL,
					`rapira_rate`      DECIMAL(20,8)   DEFAULT NULL,
					`coefficient`      DECIMAL(20,8)   NOT NULL DEFAULT 0,
					`coefficient_type` VARCHAR(16)     NOT NULL DEFAULT 'absolute',
					`source`           ENUM('web','telegram','cron') NOT NULL DEFAULT 'web',
					`created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY (`id`),
					KEY `idx_org_created` (`organization_id`, `created_at`),
					KEY `idx_kanyon_history_payment_order` (`payment_order_id`)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
			" );

			if ( $created === false ) {
				return new WP_Error(
					'crm_rate_history_rub_usdt_create_failed',
					'Failed to create crm_rate_history_rub_usdt: ' . (string) $wpdb->last_error
				);
			}

			$messages[] = 'crm_rate_history_rub_usdt: table created as 0050 fallback.';
		}

		if ( ! malibu_migrations_column_exists( 'crm_rate_history_rub_usdt', 'payment_order_id' ) ) {
			$wpdb->query(
				'ALTER TABLE `crm_rate_history_rub_usdt`
				 ADD COLUMN `payment_order_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `organization_id`'
			);
			$messages[] = 'crm_rate_history_rub_usdt: payment_order_id added.';
		} else {
			$messages[] = 'crm_rate_history_rub_usdt: payment_order_id already exists.';
		}

		if ( ! malibu_migrations_index_exists( 'crm_rate_history_rub_usdt', 'idx_kanyon_history_payment_order' ) ) {
			$wpdb->query(
				'ALTER TABLE `crm_rate_history_rub_usdt`
				 ADD KEY `idx_kanyon_history_payment_order` (`payment_order_id`)'
			);
			$messages[] = 'crm_rate_history_rub_usdt: idx_kanyon_history_payment_order added.';
		} else {
			$messages[] = 'crm_rate_history_rub_usdt: idx_kanyon_history_payment_order already exists.';
		}

		return [
			'summary'  => 'crm_rate_history_rub_usdt linked to untracked fintech orders.',
			'messages' => $messages,
		];
	},
];
