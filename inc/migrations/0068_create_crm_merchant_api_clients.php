<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0068_create_crm_merchant_api_clients',
	'title'    => 'Create crm_merchant_api_clients table',
	'callback' => function () {
		global $wpdb;

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_merchant_api_clients` (
			  `id`                       bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`               int(10) UNSIGNED    NOT NULL,
			  `merchant_id`              bigint(20) UNSIGNED NOT NULL,
			  `client_name`              varchar(191)        NOT NULL,
			  `status`                   enum('active','revoked','paused') NOT NULL DEFAULT 'active',
			  `token_prefix`             varchar(32)         NOT NULL,
			  `token_hash`               char(64)            NOT NULL,
			  `scopes_json`              longtext            DEFAULT NULL,
			  `webhook_url`              varchar(255)        DEFAULT NULL,
			  `webhook_secret_prefix`    varchar(32)         DEFAULT NULL,
			  `webhook_secret_hash`      char(64)            DEFAULT NULL,
			  `webhook_events_json`      longtext            DEFAULT NULL,
			  `webhook_last_status_code` smallint(5) UNSIGNED DEFAULT NULL,
			  `webhook_last_attempt_at`  datetime            DEFAULT NULL,
			  `webhook_last_success_at`  datetime            DEFAULT NULL,
			  `allowed_ip_cidrs`         text                DEFAULT NULL,
			  `last_used_at`             datetime            DEFAULT NULL,
			  `last_used_ip`             varchar(45)         DEFAULT NULL,
			  `revoked_at`               datetime            DEFAULT NULL,
			  `created_by_user_id`       bigint(20) UNSIGNED DEFAULT NULL,
			  `updated_by_user_id`       bigint(20) UNSIGNED DEFAULT NULL,
			  `created_at`               datetime            NOT NULL DEFAULT current_timestamp(),
			  `updated_at`               datetime            NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_merchant_api_clients_token_hash` (`token_hash`),
			  KEY `idx_crm_merchant_api_clients_company_merchant_status` (`company_id`,`merchant_id`,`status`),
			  KEY `idx_crm_merchant_api_clients_company_status` (`company_id`,`status`,`id`),
			  CONSTRAINT `fk_crm_merchant_api_clients_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_merchant_api_clients_merchant`
			    FOREIGN KEY (`merchant_id`) REFERENCES `crm_merchants` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );

		return [
			'summary'  => 'crm_merchant_api_clients table ensured.',
			'messages' => [
				'crm_merchant_api_clients: table ensured.',
			],
		];
	},
];
