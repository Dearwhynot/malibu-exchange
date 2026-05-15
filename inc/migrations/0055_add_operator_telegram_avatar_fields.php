<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0055_add_operator_telegram_avatar_fields',
	'title'    => 'Add Telegram avatar fields to operator Telegram accounts',
	'callback' => function () {
		global $wpdb;

		$messages = [];
		$columns  = [
			'telegram_avatar_file_id' => "ALTER TABLE `crm_user_telegram_accounts` ADD COLUMN `telegram_avatar_file_id` varchar(255) DEFAULT NULL AFTER `telegram_language_code`",
			'telegram_avatar_path'    => "ALTER TABLE `crm_user_telegram_accounts` ADD COLUMN `telegram_avatar_path` varchar(255) DEFAULT NULL AFTER `telegram_avatar_file_id`",
			'telegram_avatar_url'     => "ALTER TABLE `crm_user_telegram_accounts` ADD COLUMN `telegram_avatar_url` varchar(255) DEFAULT NULL AFTER `telegram_avatar_path`",
		];

		foreach ( $columns as $column => $sql ) {
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					 FROM INFORMATION_SCHEMA.COLUMNS
					 WHERE TABLE_SCHEMA = DATABASE()
					   AND TABLE_NAME = 'crm_user_telegram_accounts'
					   AND COLUMN_NAME = %s",
					$column
				)
			);

			if ( $exists > 0 ) {
				$messages[] = "crm_user_telegram_accounts.{$column}: already exists.";
				continue;
			}

			$wpdb->query( $sql );
			$messages[] = "crm_user_telegram_accounts.{$column}: added.";
		}

		return [
			'summary'  => 'Operator Telegram avatar fields ensured.',
			'messages' => $messages,
		];
	},
];
