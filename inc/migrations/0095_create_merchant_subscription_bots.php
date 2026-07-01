<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0095_create_merchant_subscription_bots',
	'title'    => 'Create merchant-scoped subscription bots',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_merchant_subscription_bots` (
			  `id`                       bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`               int(10) UNSIGNED    NOT NULL,
			  `merchant_id`              bigint(20) UNSIGNED NOT NULL,
			  `stable_bot_key`           varchar(64)         NOT NULL,
			  `bot_username`             varchar(191)        DEFAULT NULL,
			  `bot_token`                varchar(255)        DEFAULT NULL,
			  `webhook_secret`           varchar(128)        DEFAULT NULL,
			  `webhook_url`              text                DEFAULT NULL,
			  `webhook_connected_at`     datetime            DEFAULT NULL,
			  `webhook_last_error`       text                DEFAULT NULL,
			  `webhook_lock`             tinyint(1)          NOT NULL DEFAULT 0,
			  `admin_chat_id`            varchar(64)         DEFAULT NULL,
			  `reminders_enabled`        tinyint(1)          NOT NULL DEFAULT 1,
			  `reminder_days`            tinyint(3) UNSIGNED NOT NULL DEFAULT 3,
			  `invite_ttl_hours`         smallint(5) UNSIGNED NOT NULL DEFAULT 24,
			  `debug`                    tinyint(1)          NOT NULL DEFAULT 0,
			  `status`                   enum('draft','active','disabled') NOT NULL DEFAULT 'draft',
			  `created_at`               datetime            NOT NULL DEFAULT current_timestamp(),
			  `updated_at`               datetime            NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_merchant_subscription_bots_company_merchant` (`company_id`,`merchant_id`),
			  UNIQUE KEY `uq_crm_merchant_subscription_bots_stable_key` (`stable_bot_key`),
			  KEY `idx_crm_merchant_subscription_bots_company_status` (`company_id`,`status`),
			  KEY `idx_crm_merchant_subscription_bots_merchant` (`merchant_id`),
			  CONSTRAINT `fk_crm_merchant_subscription_bots_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_merchant_subscription_bots_merchant`
			    FOREIGN KEY (`merchant_id`) REFERENCES `crm_merchants` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_merchant_subscription_bots: table ensured.';

		return [
			'summary'  => 'Merchant-scoped subscription bots storage is ready.',
			'messages' => $messages,
		];
	},
];
