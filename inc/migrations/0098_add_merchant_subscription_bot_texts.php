<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0098_add_merchant_subscription_bot_texts',
	'title'    => 'Add merchant-scoped subscription bot texts',
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
				'summary'  => 'Merchant subscription bot texts skipped.',
				'messages' => [ 'crm_merchant_subscription_bots table does not exist yet.' ],
			];
		}

		$messages = [];
		$has_column = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
				$table,
				'texts_json'
			)
		);
		if ( $has_column !== 'texts_json' ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `texts_json` text DEFAULT NULL AFTER `debug`" );
			$messages[] = 'Added column texts_json.';
		}

		$backfilled = (int) $wpdb->query(
			"UPDATE crm_merchant_subscription_bots b
			 LEFT JOIN crm_settings s
			   ON s.org_id = b.company_id
			  AND s.setting_key = 'telegram_channels_texts_json'
			 SET b.texts_json = s.setting_value
			 WHERE (b.texts_json IS NULL OR b.texts_json = '')
			   AND s.setting_value IS NOT NULL
			   AND s.setting_value <> ''"
		);
		$deleted = (int) $wpdb->query(
			"DELETE FROM crm_settings WHERE setting_key = 'telegram_channels_texts_json'"
		);

		$messages[] = 'Backfilled merchant bot texts: ' . $backfilled . '.';
		$messages[] = 'Removed company-scoped text settings: ' . $deleted . '.';

		return [
			'summary'  => 'Merchant-scoped subscription bot texts are ready.',
			'messages' => $messages,
		];
	},
];
