<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0040_create_crm_merchant_telegram_sessions',
	'title'    => 'Create crm_merchant_telegram_sessions',
	'callback' => function () {
		global $wpdb;

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_merchant_telegram_sessions` (
			  `id`                   bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`           int(10) UNSIGNED    NOT NULL,
			  `merchant_id`          bigint(20) UNSIGNED NOT NULL,
			  `chat_id`              bigint(20)          NOT NULL,
			  `last_menu_message_id` bigint(20) UNSIGNED DEFAULT NULL,
			  `last_menu_screen`     varchar(64)         DEFAULT NULL,
			  `active_pipeline_code` varchar(64)         DEFAULT NULL,
			  `pipeline_state_json`  longtext            DEFAULT NULL,
			  `last_seen_at`         datetime            DEFAULT NULL,
			  `created_at`           datetime            NOT NULL DEFAULT current_timestamp(),
			  `updated_at`           datetime            NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_merchant_tg_sessions_company_chat` (`company_id`,`chat_id`),
			  KEY `idx_crm_merchant_tg_sessions_merchant_updated` (`merchant_id`,`updated_at`),
			  CONSTRAINT `fk_crm_merchant_tg_sessions_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_merchant_tg_sessions_merchant`
			    FOREIGN KEY (`merchant_id`) REFERENCES `crm_merchants` (`id`)
			    ON DELETE CASCADE ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );

		return [
			'summary'  => 'crm_merchant_telegram_sessions ensured.',
			'messages' => [
				'crm_merchant_telegram_sessions: table ensured.',
			],
		];
	},
];
