<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0046_add_market_source_to_rate_pairs',
	'title'    => 'Add market_source column to crm_rate_pairs for USDT pairs',
	'callback' => function () {
		global $wpdb;

		$col = $wpdb->get_results( "SHOW COLUMNS FROM `crm_rate_pairs` LIKE 'market_source'" );
		if ( ! empty( $col ) ) {
			return [ 'summary' => 'Column market_source already exists.', 'messages' => [] ];
		}

		$wpdb->query( "ALTER TABLE `crm_rate_pairs` ADD COLUMN `market_source` VARCHAR(32) NOT NULL DEFAULT 'bitkub'" );

		return [
			'summary'  => 'Added market_source column to crm_rate_pairs.',
			'messages' => [ 'ALTER TABLE crm_rate_pairs ADD COLUMN market_source done.' ],
		];
	},
];
