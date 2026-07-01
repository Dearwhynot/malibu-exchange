<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0097_add_subscription_bot_identity_fields',
	'title'    => 'Add merchant subscription bot identity fields',
	'callback' => function () {
		global $wpdb;

		$table = 'crm_merchant_subscription_bots';
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);
		if ( $exists <= 0 ) {
			return [
				'summary'  => 'Merchant subscription bot identity fields skipped.',
				'messages' => [ 'crm_merchant_subscription_bots table does not exist yet.' ],
			];
		}

		$columns = [
			'identity_name'                 => "varchar(64) DEFAULT NULL AFTER `status`",
			'identity_short_description'    => "varchar(120) DEFAULT NULL AFTER `identity_name`",
			'identity_description'          => "text DEFAULT NULL AFTER `identity_short_description`",
			'identity_language_code'        => "varchar(8) DEFAULT NULL AFTER `identity_description`",
			'identity_menu_button'          => "varchar(24) NOT NULL DEFAULT 'commands' AFTER `identity_language_code`",
			'identity_default_admin_rights' => "tinyint(1) NOT NULL DEFAULT 1 AFTER `identity_menu_button`",
			'identity_photo_applied_at'     => "datetime DEFAULT NULL AFTER `identity_default_admin_rights`",
			'identity_applied_at'           => "datetime DEFAULT NULL AFTER `identity_photo_applied_at`",
			'identity_last_error'           => "text DEFAULT NULL AFTER `identity_applied_at`",
		];

		$messages = [];
		foreach ( $columns as $column => $definition ) {
			$has_column = (string) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
					$table,
					$column
				)
			);
			if ( $has_column === $column ) {
				continue;
			}

			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}" );
			$messages[] = 'Added column ' . $column . '.';
		}

		if ( empty( $messages ) ) {
			$messages[] = 'All identity columns already exist.';
		}

		return [
			'summary'  => 'Merchant subscription bot identity fields are ready.',
			'messages' => $messages,
		];
	},
];
