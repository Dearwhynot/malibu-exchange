<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0017_seed_timezone_setting',
	'title'    => 'Seed default timezone setting in crm_settings',
	'callback' => function () {
		global $wpdb;

		// Значение по умолчанию: Asia/Bangkok (UTC+7) — основная операционная зона проекта.
		// INSERT IGNORE не перезапишет значение, если оператор уже менял настройку вручную.
		$wpdb->query( "
			INSERT IGNORE INTO `crm_settings` (`org_id`, `setting_key`, `setting_value`)
			VALUES (1, 'timezone', 'Asia/Bangkok')
		" );

		return [
			'summary'  => 'Default timezone seeded.',
			'messages' => [
				'Inserted default row: timezone = Asia/Bangkok (skipped if already exists).',
			],
		];
	},
];
