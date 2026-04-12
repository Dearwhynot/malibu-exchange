<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'   => '0005_create_crm_rate_history',
	'title' => 'Create crm_rate_history table',
	'callback' => function () {
		global $wpdb;

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_rate_history` (
			  `id`                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
			  `organization_id`         INT UNSIGNED    NOT NULL DEFAULT 1,
			  `pair_id`                 INT UNSIGNED    NOT NULL,
			  `provider`                VARCHAR(64)     NOT NULL COMMENT 'Источник данных, напр. ex24',
			  `source_param`            VARCHAR(128)    NOT NULL COMMENT 'Параметр источника, напр. phuket',
			  `competitor_sberbank_buy` DECIMAL(10, 4)  DEFAULT NULL,
			  `competitor_tinkoff_buy`  DECIMAL(10, 4)  DEFAULT NULL,
			  `our_sberbank_rate`       DECIMAL(10, 4)  DEFAULT NULL,
			  `our_tinkoff_rate`        DECIMAL(10, 4)  DEFAULT NULL,
			  `coefficient_value`       DECIMAL(10, 4)  NOT NULL,
			  `created_at`              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY (`id`),
			  KEY `idx_pair_created` (`pair_id`, `created_at`),
			  KEY `idx_org_pair` (`organization_id`, `pair_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );

		return [
			'summary'  => 'Table crm_rate_history created.',
			'messages' => [
				'Created table crm_rate_history.',
			],
		];
	},
];
