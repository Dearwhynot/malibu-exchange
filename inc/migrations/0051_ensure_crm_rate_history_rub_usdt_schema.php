<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0051_ensure_crm_rate_history_rub_usdt_schema',
	'title'    => 'Ensure crm_rate_history_rub_usdt schema',
	'callback' => function () {
		global $wpdb;

		$table    = 'crm_rate_history_rub_usdt';
		$messages = [];

		if ( ! malibu_migrations_table_exists( $table ) ) {
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

			$messages[] = 'crm_rate_history_rub_usdt table created.';
		} else {
			$messages[] = 'crm_rate_history_rub_usdt table already exists.';
		}

		if ( ! malibu_migrations_column_exists( $table, 'payment_order_id' ) ) {
			$altered = $wpdb->query(
				'ALTER TABLE `crm_rate_history_rub_usdt`
				 ADD COLUMN `payment_order_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `organization_id`'
			);

			if ( $altered === false ) {
				return new WP_Error(
					'crm_rate_history_rub_usdt_payment_order_column_failed',
					'Failed to add payment_order_id: ' . (string) $wpdb->last_error
				);
			}

			$messages[] = 'payment_order_id column added.';
		} else {
			$messages[] = 'payment_order_id column already exists.';
		}

		if ( ! malibu_migrations_index_exists( $table, 'idx_org_created' ) ) {
			$indexed = $wpdb->query(
				'ALTER TABLE `crm_rate_history_rub_usdt`
				 ADD KEY `idx_org_created` (`organization_id`, `created_at`)'
			);

			if ( $indexed === false ) {
				return new WP_Error(
					'crm_rate_history_rub_usdt_org_index_failed',
					'Failed to add idx_org_created: ' . (string) $wpdb->last_error
				);
			}

			$messages[] = 'idx_org_created added.';
		} else {
			$messages[] = 'idx_org_created already exists.';
		}

		if ( ! malibu_migrations_index_exists( $table, 'idx_kanyon_history_payment_order' ) ) {
			$indexed = $wpdb->query(
				'ALTER TABLE `crm_rate_history_rub_usdt`
				 ADD KEY `idx_kanyon_history_payment_order` (`payment_order_id`)'
			);

			if ( $indexed === false ) {
				return new WP_Error(
					'crm_rate_history_rub_usdt_payment_order_index_failed',
					'Failed to add idx_kanyon_history_payment_order: ' . (string) $wpdb->last_error
				);
			}

			$messages[] = 'idx_kanyon_history_payment_order added.';
		} else {
			$messages[] = 'idx_kanyon_history_payment_order already exists.';
		}

		return [
			'summary'  => 'crm_rate_history_rub_usdt schema is ready.',
			'messages' => $messages,
		];
	},
];
