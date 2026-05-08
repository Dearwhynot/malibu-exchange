<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0048_fix_rub_usdt_title',
	'title'    => 'Fix RUB_USDT pair title: RUB/USDT → USDT/RUB to match rapira source direction',
	'callback' => function () {
		global $wpdb;

		$updated = $wpdb->query(
			"UPDATE `crm_rate_pairs` SET `title` = 'USDT/RUB' WHERE `code` = 'RUB_USDT'"
		);

		return [
			'summary'  => 'Fixed RUB_USDT pair title to USDT/RUB.',
			'messages' => [ "Updated rows: {$updated}." ],
		];
	},
];
