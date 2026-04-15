<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0013_create_crm_fintech_payment_callbacks',
	'title'    => 'Create crm_fintech_payment_callbacks',
	'callback' => function () {
		global $wpdb;

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_fintech_payment_callbacks` (
			  `id`                     bigint(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			  `provider_code`          varchar(32)          NOT NULL,
			  `callback_uid`           varchar(191)         DEFAULT NULL,
			  `payment_order_id`       bigint(20) UNSIGNED  DEFAULT NULL,
			  `order_id_hint`          varchar(128)         DEFAULT NULL,
			  `merchant_order_id_hint` varchar(128)         DEFAULT NULL,
			  `signature_valid`        tinyint(1)           DEFAULT NULL,
			  `processing_status`      varchar(32)          NOT NULL DEFAULT 'received',
			  `http_response_code`     smallint(5) UNSIGNED DEFAULT NULL,
			  `error_message`          varchar(255)         DEFAULT NULL,
			  `headers_raw_json`       longtext             DEFAULT NULL,
			  `body_raw`               longtext             NOT NULL,
			  `normalized_event_json`  longtext             DEFAULT NULL,
			  `received_at`            datetime             NOT NULL DEFAULT current_timestamp(),
			  `processed_at`           datetime             DEFAULT NULL,
			  PRIMARY KEY (`id`),
			  KEY `idx_fintech_callbacks_provider_status` (`provider_code`,`processing_status`),
			  KEY `idx_fintech_callbacks_payment_order` (`payment_order_id`),
			  KEY `idx_fintech_callbacks_merchant_order` (`merchant_order_id_hint`),
			  KEY `idx_fintech_callbacks_received_at` (`received_at`),
			  CONSTRAINT `fk_fintech_callbacks_order`
			    FOREIGN KEY (`payment_order_id`) REFERENCES `crm_fintech_payment_orders` (`id`)
			    ON DELETE SET NULL ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );

		return [
			'summary'  => 'crm_fintech_payment_callbacks created.',
			'messages' => [ 'Table crm_fintech_payment_callbacks ensured.' ],
		];
	},
];
