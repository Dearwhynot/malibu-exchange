<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0099_add_subscription_bot_promo_image_fields',
	'title'    => 'Add merchant subscription bot promo image fields',
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
				'summary'  => 'Merchant subscription bot promo image fields skipped.',
				'messages' => [ 'crm_merchant_subscription_bots table does not exist yet.' ],
			];
		}

		$columns = [
			'identity_promo_image_attachment_id' => "bigint(20) unsigned DEFAULT NULL AFTER `identity_photo_applied_at`",
			'identity_promo_image_uploaded_at'   => "datetime DEFAULT NULL AFTER `identity_promo_image_attachment_id`",
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
			$messages[] = 'All promo image columns already exist.';
		}

		return [
			'summary'  => 'Merchant subscription bot promo image fields are ready.',
			'messages' => $messages,
		];
	},
];
