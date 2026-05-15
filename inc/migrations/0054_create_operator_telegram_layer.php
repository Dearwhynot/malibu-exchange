<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0054_create_operator_telegram_layer',
	'title'    => 'Create operator Telegram bindings, invites, RBAC and WordPress page',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_user_telegram_accounts` (
			  `id`                       bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`               int(10) UNSIGNED    NOT NULL,
			  `user_id`                  bigint(20) UNSIGNED NOT NULL,
			  `chat_id`                  varchar(32)         NOT NULL,
			  `telegram_user_id`         varchar(32)         DEFAULT NULL,
			  `telegram_username`        varchar(191)        DEFAULT NULL,
			  `telegram_first_name`      varchar(191)        DEFAULT NULL,
			  `telegram_last_name`       varchar(191)        DEFAULT NULL,
			  `telegram_language_code`   varchar(16)         DEFAULT NULL,
			  `status`                   enum('active','blocked','unlinked') NOT NULL DEFAULT 'active',
			  `linked_at`                datetime            DEFAULT NULL,
			  `last_seen_at`             datetime            DEFAULT NULL,
			  `profile_json`             longtext            DEFAULT NULL,
			  `created_at`               datetime            NOT NULL DEFAULT current_timestamp(),
			  `updated_at`               datetime            NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_user_tg_accounts_company_user` (`company_id`,`user_id`),
			  UNIQUE KEY `uq_crm_user_tg_accounts_company_chat` (`company_id`,`chat_id`),
			  KEY `idx_crm_user_tg_accounts_company_status` (`company_id`,`status`),
			  CONSTRAINT `fk_crm_user_tg_accounts_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_user_telegram_accounts: table ensured.';

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_operator_telegram_invites` (
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
			  UNIQUE KEY `uq_crm_operator_tg_invites_token` (`invite_token`),
			  UNIQUE KEY `uq_crm_operator_tg_invites_payload` (`telegram_start_payload`),
			  KEY `idx_crm_operator_tg_invites_company_status` (`company_id`,`status`,`expires_at`),
			  KEY `idx_crm_operator_tg_invites_user` (`company_id`,`user_id`,`created_at`),
			  CONSTRAINT `fk_crm_operator_tg_invites_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_operator_telegram_invites: table ensured.';

		$messages = array_merge( $messages, crm_rbac_sync() );

		$existing = get_page_by_path( 'operator-telegram', OBJECT, 'page' );
		if ( $existing instanceof WP_Post ) {
			$current_template = get_post_meta( $existing->ID, '_wp_page_template', true );
			if ( $current_template !== 'page-operator-telegram.php' ) {
				update_post_meta( $existing->ID, '_wp_page_template', 'page-operator-telegram.php' );
				$messages[] = 'Page "operator-telegram" already existed; template updated to page-operator-telegram.php.';
			} else {
				$messages[] = 'Page "operator-telegram" already exists with correct template.';
			}
		} else {
			$page_id = wp_insert_post( [
				'post_title'   => 'Операторы Telegram',
				'post_name'    => 'operator-telegram',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => 1,
				'post_content' => '',
				'post_excerpt' => '',
			], true );

			if ( is_wp_error( $page_id ) ) {
				$messages[] = 'ERROR: failed to create /operator-telegram/ page: ' . $page_id->get_error_message();
			} else {
				update_post_meta( $page_id, '_wp_page_template', 'page-operator-telegram.php' );
				$messages[] = 'Created WordPress page "Операторы Telegram" (slug: operator-telegram, ID: ' . $page_id . ').';
				$messages[] = 'Assigned template: page-operator-telegram.php.';
				$messages[] = 'URL: ' . get_permalink( $page_id );
			}
		}

		return [
			'summary'  => 'Operator Telegram layer ensured.',
			'messages' => $messages,
		];
	},
];
