<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0030_create_crm_merchants_layer',
	'title'    => 'Create crm_merchants, crm_merchant_invites, crm_merchant_wallet_ledger, crm_merchant_referrals',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_merchants` (
			  `id`                  bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`          int(10) UNSIGNED    NOT NULL,
			  `office_id`           bigint(20) UNSIGNED DEFAULT NULL,
			  `chat_id`             bigint(20)          NOT NULL,
			  `telegram_username`   varchar(191)        DEFAULT NULL,
			  `name`                varchar(191)        DEFAULT NULL,
			  `status`              enum('active','blocked','archived','pending') NOT NULL DEFAULT 'pending',
			  `base_markup_type`    enum('percent','fixed') NOT NULL DEFAULT 'percent',
			  `base_markup_value`   decimal(20,8)       NOT NULL DEFAULT 0,
			  `ref_code`            varchar(64)         DEFAULT NULL,
			  `referred_by_merchant_id` bigint(20) UNSIGNED DEFAULT NULL,
			  `note`                text                DEFAULT NULL,
			  `created_by_user_id`  bigint(20) UNSIGNED DEFAULT NULL,
			  `updated_by_user_id`  bigint(20) UNSIGNED DEFAULT NULL,
			  `created_at`          datetime            NOT NULL DEFAULT current_timestamp(),
			  `updated_at`          datetime            NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_merchants_company_chat` (`company_id`,`chat_id`),
			  KEY `idx_crm_merchants_company_status` (`company_id`,`status`),
			  KEY `idx_crm_merchants_company_username` (`company_id`,`telegram_username`),
			  KEY `idx_crm_merchants_company_ref_code` (`company_id`,`ref_code`),
			  CONSTRAINT `fk_crm_merchants_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_merchants_office`
			    FOREIGN KEY (`office_id`) REFERENCES `crm_company_offices` (`id`)
			    ON DELETE SET NULL ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_merchants_referred_by`
			    FOREIGN KEY (`referred_by_merchant_id`) REFERENCES `crm_merchants` (`id`)
			    ON DELETE SET NULL ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_merchants: table ensured.';

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_merchant_invites` (
			  `id`                  bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`          int(10) UNSIGNED    NOT NULL,
			  `merchant_id`         bigint(20) UNSIGNED DEFAULT NULL,
			  `invite_token`        varchar(96)         NOT NULL,
			  `chat_id`             bigint(20)          DEFAULT NULL,
			  `status`              enum('new','used','expired','revoked') NOT NULL DEFAULT 'new',
			  `expires_at`          datetime            NOT NULL,
			  `used_at`             datetime            DEFAULT NULL,
			  `used_by_chat_id`     bigint(20)          DEFAULT NULL,
			  `created_by_user_id`  bigint(20) UNSIGNED DEFAULT NULL,
			  `created_at`          datetime            NOT NULL DEFAULT current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_merchant_invites_token` (`invite_token`),
			  KEY `idx_crm_merchant_invites_company_status` (`company_id`,`status`,`expires_at`),
			  KEY `idx_crm_merchant_invites_merchant` (`merchant_id`,`created_at`),
			  CONSTRAINT `fk_crm_merchant_invites_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_merchant_invites_merchant`
			    FOREIGN KEY (`merchant_id`) REFERENCES `crm_merchants` (`id`)
			    ON DELETE SET NULL ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_merchant_invites: table ensured.';

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_merchant_wallet_ledger` (
			  `id`                  bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`          int(10) UNSIGNED    NOT NULL,
			  `merchant_id`         bigint(20) UNSIGNED NOT NULL,
			  `entry_type`          enum(
			    'bonus_accrual',
			    'bonus_adjustment',
			    'bonus_withdrawal',
			    'referral_accrual',
			    'referral_adjustment',
			    'manual_credit',
			    'manual_debit'
			  ) NOT NULL,
			  `amount`              decimal(20,8)       NOT NULL,
			  `currency_code`       varchar(16)         NOT NULL DEFAULT 'USDT',
			  `source_order_id`     bigint(20) UNSIGNED DEFAULT NULL,
			  `source_merchant_id`  bigint(20) UNSIGNED DEFAULT NULL,
			  `comment`             text                DEFAULT NULL,
			  `created_by_user_id`  bigint(20) UNSIGNED DEFAULT NULL,
			  `created_at`          datetime            NOT NULL DEFAULT current_timestamp(),
			  PRIMARY KEY (`id`),
			  KEY `idx_crm_merchant_wallet_ledger_merchant` (`merchant_id`,`created_at`),
			  KEY `idx_crm_merchant_wallet_ledger_company_type` (`company_id`,`entry_type`,`created_at`),
			  KEY `idx_crm_merchant_wallet_ledger_source_order` (`source_order_id`),
			  CONSTRAINT `fk_crm_merchant_wallet_ledger_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_merchant_wallet_ledger_merchant`
			    FOREIGN KEY (`merchant_id`) REFERENCES `crm_merchants` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_merchant_wallet_ledger_source_order`
			    FOREIGN KEY (`source_order_id`) REFERENCES `crm_fintech_payment_orders` (`id`)
			    ON DELETE SET NULL ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_merchant_wallet_ledger_source_merchant`
			    FOREIGN KEY (`source_merchant_id`) REFERENCES `crm_merchants` (`id`)
			    ON DELETE SET NULL ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_merchant_wallet_ledger: table ensured.';

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_merchant_referrals` (
			  `id`                   bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`           int(10) UNSIGNED    NOT NULL,
			  `referrer_merchant_id` bigint(20) UNSIGNED NOT NULL,
			  `referral_merchant_id` bigint(20) UNSIGNED NOT NULL,
			  `created_at`           datetime            NOT NULL DEFAULT current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_merchant_referrals_company_referral` (`company_id`,`referral_merchant_id`),
			  KEY `idx_crm_merchant_referrals_company_referrer` (`company_id`,`referrer_merchant_id`),
			  CONSTRAINT `fk_crm_merchant_referrals_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_merchant_referrals_referrer`
			    FOREIGN KEY (`referrer_merchant_id`) REFERENCES `crm_merchants` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_merchant_referrals_referral`
			    FOREIGN KEY (`referral_merchant_id`) REFERENCES `crm_merchants` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_merchant_referrals: table ensured.';

		return [
			'summary'  => 'Merchant layer tables ensured.',
			'messages' => $messages,
		];
	},
];
