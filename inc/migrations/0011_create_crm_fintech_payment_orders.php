<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0011_create_crm_fintech_payment_orders',
	'title'    => 'Create crm_fintech_payment_orders',
	'callback' => function () {
		global $wpdb;

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_fintech_payment_orders` (
			  `id`                             bigint(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			  `company_id`                     int(10) UNSIGNED     NOT NULL,
			  `office_id`                      bigint(20) UNSIGNED  DEFAULT NULL,
			  `provider_code`                  varchar(32)          NOT NULL,
			  `source_channel`                 varchar(32)          DEFAULT NULL COMMENT 'telegram|web|...',
			  `local_order_ref`                varchar(128)         DEFAULT NULL COMMENT 'внешний ключ проектного заказа',
			  `merchant_order_id`              varchar(128)         NOT NULL,
			  `provider_order_id`              varchar(128)         DEFAULT NULL,
			  `provider_external_order_id`     varchar(128)         DEFAULT NULL,
			  `idempotency_key`                varchar(128)         DEFAULT NULL,
			  `status_code`                    varchar(32)          NOT NULL DEFAULT 'created',
			  `provider_status_code`           varchar(64)          DEFAULT NULL,
			  `status_reason`                  varchar(255)         DEFAULT NULL,
			  `amount_asset_code`              varchar(16)          NOT NULL DEFAULT 'USDT',
			  `amount_asset_value`             decimal(20,8)        NOT NULL,
			  `payment_currency_code`          varchar(16)          DEFAULT 'RUB',
			  `payment_amount_value`           decimal(20,2)        DEFAULT NULL COMMENT 'сумма в валюте платежа (RUB)',
			  `payment_link`                   text                 DEFAULT NULL COMMENT 'ссылка для оплаты / SBP payload',
			  `qrc_id`                         varchar(128)         DEFAULT NULL,
			  `provider_public_link`           text                 DEFAULT NULL,
			  `provider_requires_verification` tinyint(1)           NOT NULL DEFAULT 0,
			  `callback_url`                   varchar(255)         DEFAULT NULL,
			  `first_callback_at`              datetime             DEFAULT NULL,
			  `last_callback_at`               datetime             DEFAULT NULL,
			  `last_checked_at`                datetime             DEFAULT NULL,
			  `next_check_at`                  datetime             DEFAULT NULL,
			  `expires_at`                     datetime             DEFAULT NULL,
			  `paid_at`                        datetime             DEFAULT NULL,
			  `declined_at`                    datetime             DEFAULT NULL,
			  `cancelled_at`                   datetime             DEFAULT NULL,
			  `expired_at`                     datetime             DEFAULT NULL,
			  `request_payload_json`           longtext             DEFAULT NULL,
			  `create_response_payload_json`   longtext             DEFAULT NULL,
			  `last_provider_payload_json`     longtext             DEFAULT NULL,
			  `meta_json`                      longtext             DEFAULT NULL,
			  `notes`                          text                 DEFAULT NULL,
			  `created_by_user_id`             bigint(20) UNSIGNED  DEFAULT NULL,
			  `created_at`                     datetime             NOT NULL DEFAULT current_timestamp(),
			  `updated_at`                     datetime             NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_fintech_orders_merchant_order_id` (`merchant_order_id`),
			  UNIQUE KEY `uq_fintech_orders_idempotency_key` (`idempotency_key`),
			  KEY `idx_fintech_orders_company_office` (`company_id`,`office_id`),
			  KEY `idx_fintech_orders_status` (`status_code`),
			  KEY `idx_fintech_orders_provider_status` (`provider_code`,`provider_status_code`),
			  KEY `idx_fintech_orders_reconcile` (`provider_code`,`status_code`,`next_check_at`),
			  KEY `idx_fintech_orders_provider_order` (`provider_code`,`provider_order_id`),
			  KEY `idx_fintech_orders_provider_ext_order` (`provider_code`,`provider_external_order_id`),
			  KEY `idx_fintech_orders_expires_at` (`expires_at`),
			  KEY `idx_fintech_orders_paid_at` (`paid_at`),
			  KEY `idx_fintech_orders_created_at` (`created_at`),
			  CONSTRAINT `fk_fintech_orders_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE,
			  CONSTRAINT `fk_fintech_orders_office`
			    FOREIGN KEY (`office_id`) REFERENCES `crm_company_offices` (`id`)
			    ON DELETE SET NULL ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );

		return [
			'summary'  => 'crm_fintech_payment_orders created.',
			'messages' => [ 'Table crm_fintech_payment_orders ensured.' ],
		];
	},
];
