<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0094_add_telegram_channel_markup_to_merchants',
	'title'    => 'Add Telegram channel markup settings to merchants',
	'callback' => function () {
		global $wpdb;

		$table    = 'crm_merchants';
		$messages = [];

		if ( ! malibu_migrations_table_exists( $table ) ) {
			return new WP_Error( 'crm_merchants_missing', 'crm_merchants table is missing.' );
		}

		$basis_after_column = 'base_markup_value';
		if ( malibu_migrations_column_exists( $table, 'enabled_invoice_directions_json' ) ) {
			$basis_after_column = 'enabled_invoice_directions_json';
		} elseif ( malibu_migrations_column_exists( $table, 'rub_invoice_markup_mode' ) ) {
			$basis_after_column = 'rub_invoice_markup_mode';
		}

		if ( ! malibu_migrations_column_exists( $table, 'telegram_channels_markup_basis' ) ) {
			$wpdb->query(
				"ALTER TABLE `{$table}`
				 ADD COLUMN `telegram_channels_markup_basis` varchar(32) NOT NULL DEFAULT 'acquirer_cost'
				 AFTER `{$basis_after_column}`"
			);
			$messages[] = 'Added crm_merchants.telegram_channels_markup_basis.';
		} else {
			$messages[] = 'crm_merchants.telegram_channels_markup_basis already exists.';
		}

		if ( ! malibu_migrations_column_exists( $table, 'telegram_channels_markup_type' ) ) {
			$wpdb->query(
				"ALTER TABLE `{$table}`
				 ADD COLUMN `telegram_channels_markup_type` varchar(32) NOT NULL DEFAULT 'percent'
				 AFTER `telegram_channels_markup_basis`"
			);
			$messages[] = 'Added crm_merchants.telegram_channels_markup_type.';
		} else {
			$messages[] = 'crm_merchants.telegram_channels_markup_type already exists.';
		}

		if ( ! malibu_migrations_column_exists( $table, 'telegram_channels_markup_value' ) ) {
			$wpdb->query(
				"ALTER TABLE `{$table}`
				 ADD COLUMN `telegram_channels_markup_value` decimal(20,8) NOT NULL DEFAULT 0
				 AFTER `telegram_channels_markup_type`"
			);
			$messages[] = 'Added crm_merchants.telegram_channels_markup_value.';
		} else {
			$messages[] = 'crm_merchants.telegram_channels_markup_value already exists.';
		}

		$wpdb->query(
			"UPDATE `{$table}`
			 SET `telegram_channels_markup_basis` = 'acquirer_cost'
			 WHERE `telegram_channels_markup_basis` IS NULL
			    OR `telegram_channels_markup_basis` = ''
			    OR `telegram_channels_markup_basis` NOT IN ('acquirer_cost', 'rapira_rate')"
		);

		$wpdb->query(
			"UPDATE `{$table}`
			 SET `telegram_channels_markup_type` = 'percent'
			 WHERE `telegram_channels_markup_type` IS NULL
			    OR `telegram_channels_markup_type` = ''
			    OR `telegram_channels_markup_type` NOT IN ('percent', 'fixed')"
		);

		$wpdb->query(
			"UPDATE `{$table}`
			 SET `telegram_channels_markup_value` = 0
			 WHERE `telegram_channels_markup_value` IS NULL
			    OR `telegram_channels_markup_value` < 0"
		);

		return [
			'summary'  => 'Telegram channel merchant markup settings are ready.',
			'messages' => $messages,
		];
	},
];
