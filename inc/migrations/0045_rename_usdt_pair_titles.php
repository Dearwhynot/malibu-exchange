<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0045_rename_usdt_pair_titles',
	'title'    => 'Rename pair titles: THB/USDT → USDT/THB and USDT/RUB → RUB/USDT to match direction-of-sale convention',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		$updated_usdt_thb = $wpdb->query(
			"UPDATE `crm_rate_pairs` SET `title` = 'USDT/THB' WHERE `code` = 'USDT_THB'"
		);
		$messages[] = "Updated USDT_THB rows: {$updated_usdt_thb}.";

		$updated_rub_usdt = $wpdb->query(
			"UPDATE `crm_rate_pairs` SET `title` = 'RUB/USDT' WHERE `code` = 'RUB_USDT'"
		);
		$messages[] = "Updated RUB_USDT rows: {$updated_rub_usdt}.";

		return [
			'summary'  => 'Renamed USDT pair titles to direction-of-sale form.',
			'messages' => $messages,
		];
	},
];
