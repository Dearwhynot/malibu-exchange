<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0012_create_crm_fintech_payment_order_status_history',
	'title'    => 'Create crm_fintech_payment_order_status_history',
	'callback' => function () {
		global $wpdb;

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_fintech_payment_order_status_history` (
			  `id`                   bigint(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			  `payment_order_id`     bigint(20) UNSIGNED  NOT NULL,
			  `status_code`          varchar(32)          NOT NULL,
			  `provider_status_code` varchar(64)          DEFAULT NULL,
			  `source_code`          varchar(32)          NOT NULL COMMENT 'create|callback|cron|manual|reconcile',
			  `message`              varchar(255)         DEFAULT NULL,
			  `raw_payload_json`     longtext             DEFAULT NULL,
			  `created_by_user_id`   bigint(20) UNSIGNED  DEFAULT NULL,
			  `created_at`           datetime             NOT NULL DEFAULT current_timestamp(),
			  PRIMARY KEY (`id`),
			  KEY `idx_fintech_order_history_order` (`payment_order_id`,`created_at`),
			  KEY `idx_fintech_order_history_status` (`status_code`),
			  CONSTRAINT `fk_fintech_history_order`
			    FOREIGN KEY (`payment_order_id`) REFERENCES `crm_fintech_payment_orders` (`id`)
			    ON DELETE CASCADE ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );

		return [
			'summary'  => 'crm_fintech_payment_order_status_history created.',
			'messages' => [ 'Table crm_fintech_payment_order_status_history ensured.' ],
		];
	},
];
