<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0092_create_telegram_channel_setup_sessions',
	'title'    => 'Create Telegram channel setup sessions',
	'callback' => function () {
		global $wpdb;

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_telegram_channel_setup_sessions` (
			  `id`                    bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`            int(10) UNSIGNED    NOT NULL,
			  `setup_token`           varchar(64)         NOT NULL,
			  `request_id`            int(10) UNSIGNED    DEFAULT NULL,
			  `status`                varchar(32)         NOT NULL DEFAULT 'new',
			  `requested_by_user_id`  bigint(20) UNSIGNED DEFAULT NULL,
			  `setup_chat_id`         varchar(64)         DEFAULT NULL,
			  `setup_telegram_user_id` varchar(64)        DEFAULT NULL,
			  `selected_chat_id`      varchar(64)         DEFAULT NULL,
			  `selected_title`        varchar(191)        DEFAULT NULL,
			  `selected_username`     varchar(191)        DEFAULT NULL,
			  `last_error`            text                DEFAULT NULL,
			  `created_at`            datetime            NOT NULL DEFAULT current_timestamp(),
			  `expires_at`            datetime            NOT NULL,
			  `opened_at`             datetime            DEFAULT NULL,
			  `completed_at`          datetime            DEFAULT NULL,
			  `updated_at`            datetime            NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_tg_channel_setup_token` (`setup_token`),
			  KEY `idx_crm_tg_channel_setup_company_status` (`company_id`,`status`,`expires_at`),
			  KEY `idx_crm_tg_channel_setup_request` (`company_id`,`request_id`),
			  CONSTRAINT `fk_crm_tg_channel_setup_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE CASCADE ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );

		return [
			'summary'  => 'Telegram channel setup sessions table ensured.',
			'messages' => [
				'crm_telegram_channel_setup_sessions: table ensured.',
			],
		];
	},
];
