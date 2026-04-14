<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ВАЖНО — ENUM source:
 * Список допустимых источников определён как ENUM прямо в DDL ниже.
 * При добавлении нового источника необходимо:
 *   1. ALTER TABLE `crm_market_snapshots_usdt` MODIFY COLUMN `source` ENUM('rapira','bitkub','binance_th', 'новый_источник') ...
 *   2. Обновить константу MARKET_SNAPSHOT_SOURCES в inc/ajax/rates.php (тот же список).
 *   3. Добавить функцию-фетчер в inc/rates.php и case в switch AJAX-обработчика.
 *   4. Добавить карточку и кнопку сохранения в page-rates.php.
 */

return [
	'key'      => '0006_create_crm_market_snapshots_usdt',
	'title'    => 'Create crm_market_snapshots_usdt table',
	'callback' => function () {
		global $wpdb;

		$wpdb->query( "
			CREATE TABLE IF NOT EXISTS `crm_market_snapshots_usdt` (
			  `id`               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
			  `organization_id`  INT UNSIGNED   NOT NULL DEFAULT 1,

			  -- source ENUM: rapira = USDT/RUB (rapira.net)
			  --               bitkub    = THB/USDT (bitkub.com)
			  --               binance_th = USDT/THB (binance.th)
			  -- При добавлении нового источника — ALTER TABLE + обновить MARKET_SNAPSHOT_SOURCES в inc/ajax/rates.php
			  `source`           ENUM('rapira','bitkub','binance_th')
			                     NOT NULL
			                     COMMENT 'Источник: rapira=USDT/RUB, bitkub=THB/USDT, binance_th=USDT/THB',

			  `symbol`           VARCHAR(32)    NOT NULL COMMENT 'Торговая пара, напр. USDT/RUB',
			  `bid`              DECIMAL(18,8)  DEFAULT NULL COMMENT 'Лучшая цена покупки',
			  `ask`              DECIMAL(18,8)  DEFAULT NULL COMMENT 'Лучшая цена продажи',
			  `mid`              DECIMAL(18,8)  DEFAULT NULL COMMENT 'Средняя (bid+ask)/2',
			  `created_at`       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,

			  PRIMARY KEY (`id`),
			  KEY `idx_source_created`  (`source`, `created_at`),
			  KEY `idx_org_source`      (`organization_id`, `source`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		" );

		return [
			'summary'  => 'Table crm_market_snapshots_usdt created.',
			'messages' => [
				'Created table crm_market_snapshots_usdt.',
			],
		];
	},
];
