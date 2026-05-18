<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0072_add_merchant_enabled_invoice_directions',
	'title'    => 'Add merchant enabled invoice directions JSON',
	'callback' => function () {
		global $wpdb;

		$table    = 'crm_merchants';
		$messages = [];
		$column   = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'enabled_invoice_directions_json'", ARRAY_A );

		if ( ! $column ) {
			$wpdb->query(
				"ALTER TABLE {$table}
				 ADD COLUMN `enabled_invoice_directions_json` longtext DEFAULT NULL
				 AFTER `rub_invoice_markup_mode`"
			);
			$messages[] = 'Added crm_merchants.enabled_invoice_directions_json.';
		} else {
			$messages[] = 'crm_merchants.enabled_invoice_directions_json already exists.';
		}

		return [
			'summary'  => 'Merchant invoice directions column is ready.',
			'messages' => $messages,
		];
	},
];
