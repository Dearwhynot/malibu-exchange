<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'   => '0002_create_crm_currencies',
	'title' => 'Create crm_currencies table with seed data',
	'callback' => function () {
		global $wpdb;

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_currencies` (
			  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
			  `code`       VARCHAR(10)   NOT NULL,
			  `name`       VARCHAR(64)   NOT NULL,
			  `symbol`     VARCHAR(8)    NOT NULL DEFAULT '',
			  `is_active`  TINYINT(1)    NOT NULL DEFAULT 1,
			  `sort_order` SMALLINT      NOT NULL DEFAULT 0,
			  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_code` (`code`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );

		$wpdb->query( "
			INSERT IGNORE INTO `crm_currencies` (`code`, `name`, `symbol`, `is_active`, `sort_order`) VALUES
			('RUB',  'Российский рубль',    '₽', 1, 10),
			('THB',  'Тайский бат',         '฿', 1, 20),
			('USDT', 'Tether USD',          '₮', 1, 30)
		" );

		return [
			'summary'  => 'Table crm_currencies created and seeded.',
			'messages' => [
				'Created table crm_currencies.',
				'Inserted currencies: RUB, THB, USDT.',
			],
		];
	},
];
