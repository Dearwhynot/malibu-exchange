<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0020_create_crm_acquirer_payouts',
	'title'    => 'Create crm_acquirer_payouts — учёт выплат от эквайринг-партнёра',
	'callback' => function () {
		global $wpdb;

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_acquirer_payouts` (
			  `id`                   bigint(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			  `company_id`           int(10) UNSIGNED     NOT NULL,
			  `amount`               decimal(20,2)        NOT NULL             COMMENT 'сумма выплаты от ЭП',
			  `currency_code`        varchar(16)          NOT NULL DEFAULT 'RUB',
			  `period_from`          date                 DEFAULT NULL         COMMENT 'начало периода, за который выплата',
			  `period_to`            date                 DEFAULT NULL         COMMENT 'конец периода, за который выплата',
			  `reference`            varchar(255)         DEFAULT NULL         COMMENT 'номер платёжного поручения / референс ЭП',
			  `notes`                text                 DEFAULT NULL         COMMENT 'произвольный комментарий',
			  `recorded_by_user_id`  bigint(20) UNSIGNED  DEFAULT NULL         COMMENT 'WP user ID, кто внёс запись',
			  `created_at`           datetime             NOT NULL DEFAULT current_timestamp(),
			  `updated_at`           datetime             NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  KEY `idx_acquirer_payouts_company`    (`company_id`),
			  KEY `idx_acquirer_payouts_created_at` (`created_at`),
			  CONSTRAINT `fk_acquirer_payouts_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );

		return [
			'summary'  => 'crm_acquirer_payouts created.',
			'messages' => [ 'Table crm_acquirer_payouts ensured.' ],
		];
	},
];
