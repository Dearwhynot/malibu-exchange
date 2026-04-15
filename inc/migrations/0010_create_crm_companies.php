<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0010_create_crm_companies',
	'title'    => 'Create crm_companies, crm_company_offices, crm_user_companies, crm_user_company_offices; alter crm_user_accounts',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		// ── 1. crm_companies ────────────────────────────────────────────────────
		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_companies` (
			  `id`                    int(10) UNSIGNED     NOT NULL AUTO_INCREMENT,
			  `code`                  varchar(64)          NOT NULL,
			  `name`                  varchar(191)         NOT NULL,
			  `legal_name`            varchar(191)         DEFAULT NULL,
			  `tax_number`            varchar(64)          DEFAULT NULL,
			  `default_currency_code` varchar(16)          DEFAULT NULL,
			  `default_timezone`      varchar(64)          NOT NULL DEFAULT 'Asia/Bangkok',
			  `status`                enum('active','blocked','archived') NOT NULL DEFAULT 'active',
			  `note`                  text                 DEFAULT NULL,
			  `created_by_user_id`    bigint(20) UNSIGNED  DEFAULT NULL,
			  `updated_by_user_id`    bigint(20) UNSIGNED  DEFAULT NULL,
			  `created_at`            datetime             NOT NULL DEFAULT current_timestamp(),
			  `updated_at`            datetime             NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_companies_code` (`code`),
			  KEY `idx_crm_companies_status` (`status`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_companies: table ensured.';

		// Seed: гарантируем, что company_id=1 существует (совпадает с CRM_DEFAULT_ORG_ID = 1)
		$wpdb->query( "
			INSERT IGNORE INTO `crm_companies`
			  (`id`, `code`, `name`, `legal_name`, `default_timezone`, `status`)
			VALUES
			  (1, 'default', 'Malibu Exchange', 'Malibu Exchange', 'Asia/Bangkok', 'active')
		" );
		$messages[] = 'crm_companies: seed row id=1 ensured.';

		// ── 2. crm_company_offices ───────────────────────────────────────────────
		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_company_offices` (
			  `id`                 bigint(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			  `company_id`         int(10) UNSIGNED     NOT NULL,
			  `code`               varchar(64)          NOT NULL,
			  `name`               varchar(191)         NOT NULL,
			  `country_code`       char(2)              DEFAULT NULL,
			  `city`               varchar(128)         DEFAULT NULL,
			  `address_line`       varchar(255)         DEFAULT NULL,
			  `timezone`           varchar(64)          DEFAULT NULL,
			  `status`             enum('active','blocked','archived') NOT NULL DEFAULT 'active',
			  `is_default`         tinyint(1)           NOT NULL DEFAULT 0,
			  `sort_order`         smallint(6)          NOT NULL DEFAULT 0,
			  `note`               text                 DEFAULT NULL,
			  `created_by_user_id` bigint(20) UNSIGNED  DEFAULT NULL,
			  `updated_by_user_id` bigint(20) UNSIGNED  DEFAULT NULL,
			  `created_at`         datetime             NOT NULL DEFAULT current_timestamp(),
			  `updated_at`         datetime             NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_company_offices_code` (`company_id`,`code`),
			  KEY `idx_crm_company_offices_status` (`company_id`,`status`),
			  KEY `idx_crm_company_offices_default` (`company_id`,`is_default`),
			  CONSTRAINT `fk_crm_company_offices_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_company_offices: table ensured.';

		// ── 3. crm_user_companies ────────────────────────────────────────────────
		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_user_companies` (
			  `id`                  bigint(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			  `user_id`             bigint(20) UNSIGNED  NOT NULL,
			  `company_id`          int(10) UNSIGNED     NOT NULL,
			  `is_company_admin`    tinyint(1)           NOT NULL DEFAULT 0,
			  `is_primary`          tinyint(1)           NOT NULL DEFAULT 0,
			  `status`              enum('active','blocked','archived') NOT NULL DEFAULT 'active',
			  `assigned_by_user_id` bigint(20) UNSIGNED  DEFAULT NULL,
			  `note`                text                 DEFAULT NULL,
			  `created_at`          datetime             NOT NULL DEFAULT current_timestamp(),
			  `updated_at`          datetime             NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_user_companies_user_company` (`user_id`,`company_id`),
			  KEY `idx_crm_user_companies_company_status` (`company_id`,`status`),
			  KEY `idx_crm_user_companies_admin` (`company_id`,`is_company_admin`),
			  CONSTRAINT `fk_crm_user_companies_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_user_companies: table ensured.';

		// ── 4. crm_user_company_offices ──────────────────────────────────────────
		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_user_company_offices` (
			  `id`                  bigint(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			  `user_id`             bigint(20) UNSIGNED  NOT NULL,
			  `company_id`          int(10) UNSIGNED     NOT NULL,
			  `office_id`           bigint(20) UNSIGNED  NOT NULL,
			  `is_default`          tinyint(1)           NOT NULL DEFAULT 0,
			  `assigned_by_user_id` bigint(20) UNSIGNED  DEFAULT NULL,
			  `created_at`          datetime             NOT NULL DEFAULT current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_user_company_offices_user_office` (`user_id`,`office_id`),
			  KEY `idx_crm_user_company_offices_company` (`company_id`),
			  KEY `idx_crm_user_company_offices_default` (`user_id`,`is_default`),
			  CONSTRAINT `fk_crm_user_company_offices_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_user_company_offices_office`
			    FOREIGN KEY (`office_id`) REFERENCES `crm_company_offices` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );
		$messages[] = 'crm_user_company_offices: table ensured.';

		// ── 5. ALTER crm_user_accounts ────────────────────────────────────────────
		// Добавляем поля только если их ещё нет (ALTER TABLE IF NOT EXISTS column не поддерживается до MySQL 8,
		// поэтому проверяем через INFORMATION_SCHEMA)
		$db_name = DB_NAME;

		$has_default_company = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
			 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = 'crm_user_accounts' AND COLUMN_NAME = 'default_company_id'",
			$db_name
		) );

		if ( ! $has_default_company ) {
			$wpdb->query( "
				ALTER TABLE `crm_user_accounts`
				  ADD COLUMN `default_company_id` int(10) UNSIGNED NOT NULL DEFAULT 1
				    AFTER `user_id`,
				  ADD COLUMN `default_office_id` bigint(20) UNSIGNED DEFAULT NULL
				    AFTER `default_company_id`,
				  ADD KEY `idx_crm_ua_default_company` (`default_company_id`),
				  ADD KEY `idx_crm_ua_default_office` (`default_office_id`)
			" );
			$messages[] = 'crm_user_accounts: columns default_company_id and default_office_id added.';
		} else {
			$messages[] = 'crm_user_accounts: columns already exist — skipped.';
		}

		return [
			'summary'  => 'crm_companies layer created (4 tables + crm_user_accounts altered).',
			'messages' => $messages,
		];
	},
];
