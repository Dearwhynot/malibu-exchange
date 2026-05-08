<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0049_create_crm_rate_history_rub_usdt',
	'title'    => 'Create crm_rate_history_rub_usdt — Kanyon USDT/RUB rate check history',
	'callback' => function () {
		global $wpdb;

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_rate_history_rub_usdt` (
				`id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
				`organization_id`  INT UNSIGNED    NOT NULL,
				`kanyon_rate`      DECIMAL(20,8)   NOT NULL,
				`rapira_rate`      DECIMAL(20,8)   DEFAULT NULL,
				`coefficient`      DECIMAL(20,8)   NOT NULL DEFAULT 0,
				`coefficient_type` VARCHAR(16)     NOT NULL DEFAULT 'absolute',
				`source`           ENUM('web','telegram','cron') NOT NULL DEFAULT 'web',
				`created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				KEY `idx_org_created` (`organization_id`, `created_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );

		return [
			'summary'  => 'Created table crm_rate_history_rub_usdt.',
			'messages' => [ 'CREATE TABLE IF NOT EXISTS crm_rate_history_rub_usdt done.' ],
		];
	},
];
