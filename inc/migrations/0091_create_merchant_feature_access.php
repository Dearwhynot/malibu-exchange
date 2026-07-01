<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0091_create_merchant_feature_access',
	'title'    => 'Create merchant feature access table',
	'callback' => function () {
		global $wpdb;

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_merchant_feature_access` (
			  `id`                 bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`         int(10) UNSIGNED    NOT NULL,
			  `merchant_id`        bigint(20) UNSIGNED NOT NULL,
			  `feature_code`       varchar(64)         NOT NULL,
			  `is_enabled`         tinyint(1)          NOT NULL DEFAULT 0,
			  `granted_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
			  `granted_at`         datetime            DEFAULT NULL,
			  `created_at`         datetime            NOT NULL DEFAULT current_timestamp(),
			  `updated_at`         datetime            NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_crm_merchant_feature_access` (`company_id`,`merchant_id`,`feature_code`),
			  KEY `idx_crm_merchant_feature_access_feature` (`company_id`,`feature_code`,`is_enabled`),
			  KEY `idx_crm_merchant_feature_access_merchant` (`merchant_id`),
			  CONSTRAINT `fk_crm_merchant_feature_access_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_crm_merchant_feature_access_merchant`
			    FOREIGN KEY (`merchant_id`) REFERENCES `crm_merchants` (`id`)
			    ON DELETE CASCADE ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );

		return [
			'summary'  => 'Merchant feature access table ensured.',
			'messages' => [
				'crm_merchant_feature_access: table ensured.',
			],
		];
	},
];
