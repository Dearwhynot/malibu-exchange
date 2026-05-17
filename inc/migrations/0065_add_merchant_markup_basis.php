<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0065_add_merchant_markup_basis',
	'title'    => 'Add merchant markup basis selector',
	'callback' => function () {
		global $wpdb;

		$table    = 'crm_merchants';
		$messages = [];
		$column   = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'base_markup_basis'", ARRAY_A );

		if ( ! $column ) {
			$wpdb->query(
				"ALTER TABLE {$table}
				 ADD COLUMN `base_markup_basis` varchar(32) NOT NULL DEFAULT 'acquirer_cost'
				 AFTER `base_markup_type`"
			);
			$messages[] = 'Added crm_merchants.base_markup_basis.';
		} else {
			$messages[] = 'crm_merchants.base_markup_basis already exists.';
		}

		$wpdb->query(
			"UPDATE {$table}
			 SET base_markup_basis = 'acquirer_cost'
			 WHERE base_markup_basis IS NULL
			    OR base_markup_basis = ''
			    OR base_markup_basis NOT IN ('acquirer_cost', 'rapira_rate')"
		);
		$messages[] = 'Backfilled invalid merchant markup basis to acquirer_cost.';

		return [
			'summary'  => 'Merchant markup basis column is ready.',
			'messages' => $messages,
		];
	},
];
