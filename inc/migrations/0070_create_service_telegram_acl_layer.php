<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0070_create_service_telegram_acl_layer',
	'title'    => 'Create service Telegram invite history and access ACL tables',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_service_telegram_invites` (
			  `id`                       bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`               int(10) UNSIGNED    NOT NULL,
			  `user_id`                  bigint(20) UNSIGNED NOT NULL,
			  `invite_token`             varchar(96)         NOT NULL,
			  `telegram_start_payload`   varchar(64)         NOT NULL,
			  `bot_username_snapshot`    varchar(191)        DEFAULT NULL,
			  `chat_id`                  varchar(32)         DEFAULT NULL,
			  `status`                   enum('new','used','expired','revoked') NOT NULL DEFAULT 'new',
			  `expires_at`               datetime            NOT NULL,
			  `used_at`                  datetime            DEFAULT NULL,
			  `used_by_chat_id`          varchar(32)         DEFAULT NULL,
			  `created_by_user_id`       bigint(20) UNSIGNED DEFAULT NULL,
			  `created_at`               datetime            NOT NULL DEFAULT current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_service_tg_invites_token` (`invite_token`),
			  UNIQUE KEY `uq_crm_service_tg_invites_payload` (`telegram_start_payload`),
			  KEY `idx_crm_service_tg_invites_company_status` (`company_id`,`status`,`expires_at`),
			  KEY `idx_crm_service_tg_invites_user` (`company_id`,`user_id`,`created_at`),
			  CONSTRAINT `fk_crm_service_tg_invites_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_service_telegram_invites: table ensured.';

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_service_telegram_access` (
			  `id`                       bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`               int(10) UNSIGNED    NOT NULL,
			  `user_id`                  bigint(20) UNSIGNED NOT NULL,
			  `status`                   enum('active','blocked','revoked') NOT NULL DEFAULT 'active',
			  `granted_by_user_id`       bigint(20) UNSIGNED DEFAULT NULL,
			  `granted_at`               datetime            DEFAULT NULL,
			  `last_invite_id`           bigint(20) UNSIGNED DEFAULT NULL,
			  `last_seen_at`             datetime            DEFAULT NULL,
			  `revoked_at`               datetime            DEFAULT NULL,
			  `revoked_by_user_id`       bigint(20) UNSIGNED DEFAULT NULL,
			  `revoke_reason`            varchar(191)        DEFAULT NULL,
			  `created_at`               datetime            NOT NULL DEFAULT current_timestamp(),
			  `updated_at`               datetime            NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_service_tg_access_company_user` (`company_id`,`user_id`),
			  KEY `idx_crm_service_tg_access_company_status` (`company_id`,`status`),
			  KEY `idx_crm_service_tg_access_seen` (`company_id`,`last_seen_at`),
			  CONSTRAINT `fk_crm_service_tg_access_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_service_telegram_access: table ensured.';

		return [
			'summary'  => 'Service Telegram ACL layer ensured.',
			'messages' => $messages,
		];
	},
];

