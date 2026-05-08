<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0042_seed_usdt_rate_pairs',
	'title'    => 'Seed currency pairs USDT_THB and RUB_USDT for organization 1',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		// Пара USDT → THB (направление продажи в боте: ₮ → ฿).
		$wpdb->query( "
			INSERT IGNORE INTO `crm_rate_pairs`
			  (`organization_id`, `from_currency_id`, `to_currency_id`, `code`, `title`, `is_active`, `sort_order`)
			SELECT
			  1,
			  (SELECT id FROM crm_currencies WHERE code = 'USDT'),
			  (SELECT id FROM crm_currencies WHERE code = 'THB'),
			  'USDT_THB',
			  'THB/USDT',
			  1,
			  20
		" );
		$messages[] = 'Inserted pair USDT->THB (code: USDT_THB, title: THB/USDT) for org 1.';

		// Пара RUB → USDT (направление продажи в боте: ₽ → ₮).
		$wpdb->query( "
			INSERT IGNORE INTO `crm_rate_pairs`
			  (`organization_id`, `from_currency_id`, `to_currency_id`, `code`, `title`, `is_active`, `sort_order`)
			SELECT
			  1,
			  (SELECT id FROM crm_currencies WHERE code = 'RUB'),
			  (SELECT id FROM crm_currencies WHERE code = 'USDT'),
			  'RUB_USDT',
			  'USDT/RUB',
			  1,
			  30
		" );
		$messages[] = 'Inserted pair RUB->USDT (code: RUB_USDT, title: USDT/RUB) for org 1.';

		return [
			'summary'  => 'Currency pairs USDT_THB and RUB_USDT seeded for org 1.',
			'messages' => $messages,
		];
	},
];
