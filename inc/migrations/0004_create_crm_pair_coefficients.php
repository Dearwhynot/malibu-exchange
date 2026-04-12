<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'   => '0004_create_crm_pair_coefficients',
	'title' => 'Create crm_pair_coefficients table with default 0.05 for THB_RUB / Ex24 phuket',
	'callback' => function () {
		global $wpdb;

		// Таблица коэффициентов: явно привязана к паре + провайдеру данных + параметру источника.
		// Отвечает на вопрос: какой коэффициент применяется для конкретной пары
		// от конкретного провайдера/источника?
		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_pair_coefficients` (
			  `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
			  `pair_id`      INT UNSIGNED    NOT NULL,
			  `provider`     VARCHAR(64)     NOT NULL COMMENT 'Внешний источник, напр. ex24',
			  `source_param` VARCHAR(128)    NOT NULL COMMENT 'Параметр источника, напр. phuket',
			  `coefficient`  DECIMAL(10, 4)  NOT NULL DEFAULT 0.0500,
			  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `uq_pair_provider_source` (`pair_id`, `provider`, `source_param`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );

		// Seed: коэффициент 0.05 для пары THB_RUB от Ex24/phuket
		$wpdb->query( "
			INSERT IGNORE INTO `crm_pair_coefficients`
			  (`pair_id`, `provider`, `source_param`, `coefficient`)
			SELECT id, 'ex24', 'phuket', 0.0500
			FROM crm_rate_pairs
			WHERE code = 'THB_RUB' AND organization_id = 1
			LIMIT 1
		" );

		return [
			'summary'  => 'Table crm_pair_coefficients created and seeded.',
			'messages' => [
				'Created table crm_pair_coefficients.',
				'Inserted coefficient 0.05 for THB_RUB pair, provider=ex24, source=phuket.',
			],
		];
	},
];
