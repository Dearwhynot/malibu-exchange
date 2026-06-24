<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0088_create_telegram_channels_foundation',
	'title'    => 'Create Telegram channels subscription foundation',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_telegram_channels` (
			  `id`                         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`                 int(10) UNSIGNED    NOT NULL,
			  `title`                      varchar(191)        NOT NULL,
			  `telegram_channel_id`        varchar(64)         DEFAULT NULL,
			  `telegram_channel_username`  varchar(191)        DEFAULT NULL,
			  `status`                     enum('draft','active','disabled') NOT NULL DEFAULT 'draft',
			  `bot_admin_checked_at`       datetime            DEFAULT NULL,
			  `bot_admin_check_status`     varchar(32)         DEFAULT NULL,
			  `bot_admin_check_error`      text                DEFAULT NULL,
			  `created_by_user_id`         bigint(20) UNSIGNED DEFAULT NULL,
			  `created_at`                 datetime            NOT NULL DEFAULT current_timestamp(),
			  `updated_at`                 datetime            NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_telegram_channels_company` (`company_id`),
			  KEY `idx_crm_telegram_channels_company_status` (`company_id`,`status`),
			  CONSTRAINT `fk_crm_telegram_channels_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_telegram_channels: table ensured.';

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_telegram_channel_tariffs` (
			  `id`                bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`        int(10) UNSIGNED    NOT NULL,
			  `channel_id`        bigint(20) UNSIGNED NOT NULL,
			  `code`              varchar(32)         NOT NULL,
			  `title`             varchar(191)        NOT NULL,
			  `duration_days`     int(10) UNSIGNED    NOT NULL,
			  `price_amount`      decimal(18,8)       NOT NULL DEFAULT 0.00000000,
			  `price_currency`    varchar(16)         NOT NULL DEFAULT 'RUB',
			  `status`            enum('disabled','active') NOT NULL DEFAULT 'disabled',
			  `sort_order`        smallint(6)         NOT NULL DEFAULT 0,
			  `created_at`        datetime            NOT NULL DEFAULT current_timestamp(),
			  `updated_at`        datetime            NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_tg_channel_tariffs_code` (`company_id`,`channel_id`,`code`),
			  KEY `idx_crm_tg_channel_tariffs_status` (`company_id`,`status`),
			  KEY `idx_crm_tg_channel_tariffs_channel` (`channel_id`),
			  CONSTRAINT `fk_crm_tg_channel_tariffs_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_tg_channel_tariffs_channel`
			    FOREIGN KEY (`channel_id`) REFERENCES `crm_telegram_channels` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_telegram_channel_tariffs: table ensured.';

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_telegram_channel_sales` (
			  `id`                       bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`               int(10) UNSIGNED    NOT NULL,
			  `channel_id`               bigint(20) UNSIGNED NOT NULL,
			  `tariff_id`                bigint(20) UNSIGNED DEFAULT NULL,
			  `merchant_id`              bigint(20) UNSIGNED DEFAULT NULL,
			  `created_from_context`     enum('merchant_bot','web','admin') NOT NULL DEFAULT 'merchant_bot',
			  `start_payload`            varchar(96)         NOT NULL,
			  `client_telegram_user_id`  bigint(20) UNSIGNED DEFAULT NULL,
			  `client_chat_id`           varchar(32)         DEFAULT NULL,
			  `status`                   enum('new','opened','payment_created','paid','expired','cancelled') NOT NULL DEFAULT 'new',
			  `payment_order_id`         bigint(20) UNSIGNED DEFAULT NULL,
			  `expires_at`               datetime            DEFAULT NULL,
			  `opened_at`                datetime            DEFAULT NULL,
			  `created_at`               datetime            NOT NULL DEFAULT current_timestamp(),
			  `updated_at`               datetime            NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_tg_channel_sales_payload` (`start_payload`),
			  KEY `idx_crm_tg_channel_sales_company_status` (`company_id`,`status`,`created_at`),
			  KEY `idx_crm_tg_channel_sales_merchant` (`company_id`,`merchant_id`,`created_at`),
			  KEY `idx_crm_tg_channel_sales_payment` (`payment_order_id`),
			  CONSTRAINT `fk_crm_tg_channel_sales_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_tg_channel_sales_channel`
			    FOREIGN KEY (`channel_id`) REFERENCES `crm_telegram_channels` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_tg_channel_sales_tariff`
			    FOREIGN KEY (`tariff_id`) REFERENCES `crm_telegram_channel_tariffs` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_telegram_channel_sales: table ensured.';

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_telegram_channel_subscribers` (
			  `id`                         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`                 int(10) UNSIGNED    NOT NULL,
			  `channel_id`                 bigint(20) UNSIGNED NOT NULL,
			  `telegram_user_id`           bigint(20) UNSIGNED NOT NULL,
			  `chat_id`                    varchar(32)         DEFAULT NULL,
			  `username`                   varchar(191)        DEFAULT NULL,
			  `first_name`                 varchar(191)        DEFAULT NULL,
			  `last_name`                  varchar(191)        DEFAULT NULL,
			  `current_tariff_id`          bigint(20) UNSIGNED DEFAULT NULL,
			  `subscription_start`         datetime            DEFAULT NULL,
			  `subscription_until`         datetime            DEFAULT NULL,
			  `status`                     enum('active','expired','cancelled') NOT NULL DEFAULT 'active',
			  `last_payment_order_id`      bigint(20) UNSIGNED DEFAULT NULL,
			  `last_invite_link`           text                DEFAULT NULL,
			  `last_invite_created_at`     datetime            DEFAULT NULL,
			  `reminder_sent_for_until`    datetime            DEFAULT NULL,
			  `removed_from_channel_at`    datetime            DEFAULT NULL,
			  `remove_from_channel_status` varchar(32)         DEFAULT NULL,
			  `remove_from_channel_error`  text                DEFAULT NULL,
			  `created_at`                 datetime            NOT NULL DEFAULT current_timestamp(),
			  `updated_at`                 datetime            NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_tg_channel_subscriber_user` (`company_id`,`channel_id`,`telegram_user_id`),
			  KEY `idx_crm_tg_channel_subscribers_until` (`company_id`,`status`,`subscription_until`),
			  KEY `idx_crm_tg_channel_subscribers_chat` (`company_id`,`chat_id`),
			  KEY `idx_crm_tg_channel_subscribers_channel` (`channel_id`),
			  CONSTRAINT `fk_crm_tg_channel_subscribers_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_tg_channel_subscribers_channel`
			    FOREIGN KEY (`channel_id`) REFERENCES `crm_telegram_channels` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_tg_channel_subscribers_tariff`
			    FOREIGN KEY (`current_tariff_id`) REFERENCES `crm_telegram_channel_tariffs` (`id`)
			    ON DELETE SET NULL ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_telegram_channel_subscribers: table ensured.';

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_telegram_channel_payments` (
			  `id`                 bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`         int(10) UNSIGNED    NOT NULL,
			  `channel_id`         bigint(20) UNSIGNED NOT NULL,
			  `subscriber_id`      bigint(20) UNSIGNED DEFAULT NULL,
			  `tariff_id`          bigint(20) UNSIGNED NOT NULL,
			  `sale_id`            bigint(20) UNSIGNED DEFAULT NULL,
			  `payment_order_id`   bigint(20) UNSIGNED NOT NULL,
			  `provider_code`      varchar(32)         DEFAULT NULL,
			  `amount`             decimal(18,8)       NOT NULL DEFAULT 0.00000000,
			  `currency`           varchar(16)         NOT NULL,
			  `paid_at`            datetime            DEFAULT NULL,
			  `period_from`        datetime            DEFAULT NULL,
			  `period_until`       datetime            DEFAULT NULL,
			  `created_at`         datetime            NOT NULL DEFAULT current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_tg_channel_payments_order` (`payment_order_id`),
			  KEY `idx_crm_tg_channel_payments_subscriber` (`company_id`,`subscriber_id`,`paid_at`),
			  KEY `idx_crm_tg_channel_payments_channel` (`company_id`,`channel_id`,`paid_at`),
			  CONSTRAINT `fk_crm_tg_channel_payments_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_tg_channel_payments_channel`
			    FOREIGN KEY (`channel_id`) REFERENCES `crm_telegram_channels` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_tg_channel_payments_subscriber`
			    FOREIGN KEY (`subscriber_id`) REFERENCES `crm_telegram_channel_subscribers` (`id`)
			    ON DELETE SET NULL ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_tg_channel_payments_tariff`
			    FOREIGN KEY (`tariff_id`) REFERENCES `crm_telegram_channel_tariffs` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_tg_channel_payments_sale`
			    FOREIGN KEY (`sale_id`) REFERENCES `crm_telegram_channel_sales` (`id`)
			    ON DELETE SET NULL ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_telegram_channel_payments: table ensured.';

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_telegram_channel_invites` (
			  `id`                      bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`              int(10) UNSIGNED    NOT NULL,
			  `channel_id`              bigint(20) UNSIGNED NOT NULL,
			  `subscriber_id`           bigint(20) UNSIGNED DEFAULT NULL,
			  `telegram_user_id`        bigint(20) UNSIGNED DEFAULT NULL,
			  `invite_link`             text                DEFAULT NULL,
			  `telegram_invite_link_id` varchar(191)        DEFAULT NULL,
			  `expire_date`             datetime            DEFAULT NULL,
			  `member_limit`            int(10) UNSIGNED    DEFAULT NULL,
			  `status`                  enum('created','sent','failed','revoked') NOT NULL DEFAULT 'created',
			  `telegram_response_json`  longtext            DEFAULT NULL,
			  `error_message`           text                DEFAULT NULL,
			  `created_at`              datetime            NOT NULL DEFAULT current_timestamp(),
			  `sent_at`                 datetime            DEFAULT NULL,
			  PRIMARY KEY (`id`),
			  KEY `idx_crm_tg_channel_invites_subscriber` (`company_id`,`subscriber_id`,`created_at`),
			  KEY `idx_crm_tg_channel_invites_status` (`company_id`,`status`,`created_at`),
			  CONSTRAINT `fk_crm_tg_channel_invites_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_tg_channel_invites_channel`
			    FOREIGN KEY (`channel_id`) REFERENCES `crm_telegram_channels` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_tg_channel_invites_subscriber`
			    FOREIGN KEY (`subscriber_id`) REFERENCES `crm_telegram_channel_subscribers` (`id`)
			    ON DELETE SET NULL ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_telegram_channel_invites: table ensured.';

		$company_ids = $wpdb->get_col( 'SELECT id FROM crm_companies WHERE id > 0 ORDER BY id ASC' ) ?: [];
		foreach ( array_map( 'intval', $company_ids ) as $company_id ) {
			if ( $company_id <= 0 ) {
				continue;
			}

			if ( function_exists( 'crm_telegram_channels_seed_company_foundation' ) ) {
				crm_telegram_channels_seed_company_foundation( $company_id );
				$messages[] = 'Company #' . $company_id . ': Telegram channels settings/channel/tariffs seeded.';
			}
		}

		return [
			'summary'  => 'Telegram channels subscription foundation is ready.',
			'messages' => $messages,
		];
	},
];

