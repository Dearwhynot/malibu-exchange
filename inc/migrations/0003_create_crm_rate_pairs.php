<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'   => '0003_create_crm_rate_pairs',
	'title' => 'Create crm_rate_pairs table with seed pair THB->RUB',
	'callback' => function () {
		global $wpdb;

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_rate_pairs` (
			  `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
			  `organization_id`   INT UNSIGNED  NOT NULL DEFAULT 1,
			  `from_currency_id`  INT UNSIGNED  NOT NULL,
			  `to_currency_id`    INT UNSIGNED  NOT NULL,
			  `code`              VARCHAR(32)   NOT NULL,
			  `title`             VARCHAR(128)  NOT NULL,
			  `is_active`         TINYINT(1)    NOT NULL DEFAULT 1,
			  `sort_order`        SMALLINT      NOT NULL DEFAULT 0,
			  `created_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  `updated_at`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_org_pair` (`organization_id`, `from_currency_id`, `to_currency_id`),
			  KEY `idx_org_active` (`organization_id`, `is_active`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );

		// Seed: пара THB → RUB (клиент даёт THB, получает RUB)
		// Отображается в UI как RUB/THB
		$wpdb->query( "
			INSERT IGNORE INTO `crm_rate_pairs`
			  (`organization_id`, `from_currency_id`, `to_currency_id`, `code`, `title`, `is_active`, `sort_order`)
			SELECT
			  1,
			  (SELECT id FROM crm_currencies WHERE code = 'THB'),
			  (SELECT id FROM crm_currencies WHERE code = 'RUB'),
			  'THB_RUB',
			  'RUB/THB',
			  1,
			  10
		" );

		return [
			'summary'  => 'Table crm_rate_pairs created and seeded.',
			'messages' => [
				'Created table crm_rate_pairs.',
				'Inserted pair THB->RUB (code: THB_RUB, title: RUB/THB) for org 1.',
			],
		];
	},
];
