<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0047_fix_bitkub_symbol_direction',
	'title'    => 'Fix bitkub symbol: THB/USDT → USDT/THB in crm_market_snapshots_usdt',
	'callback' => function () {
		global $wpdb;

		$updated = $wpdb->query(
			"UPDATE `crm_market_snapshots_usdt`
			 SET `symbol` = 'USDT/THB'
			 WHERE `source` = 'bitkub' AND `symbol` = 'THB/USDT'"
		);

		return [
			'summary'  => 'Fixed bitkub symbol direction.',
			'messages' => [ "Updated rows: {$updated}." ],
		];
	},
];
