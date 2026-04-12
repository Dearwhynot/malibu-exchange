<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'   => '0001_create_crm_settings',
	'title' => 'Create crm_settings table',
	'callback' => function () {
		global $wpdb;

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_settings` (
			  `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
			  `org_id`        INT UNSIGNED     NOT NULL DEFAULT 1,
			  `setting_key`   VARCHAR(128)     NOT NULL,
			  `setting_value` TEXT             DEFAULT NULL,
			  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_org_key` (`org_id`, `setting_key`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );

		// Seed: дефолтные настройки для org_id = 1
		$wpdb->query( "
			INSERT IGNORE INTO `crm_settings` (`org_id`, `setting_key`, `setting_value`)
			VALUES (1, 'telegram_bot_token', '')
		" );

		return [
			'summary'  => 'Table crm_settings created and seeded.',
			'messages' => [
				'Created table crm_settings with org_id + setting_key unique key.',
				'Inserted default row: telegram_bot_token (empty).',
			],
		];
	},
];
