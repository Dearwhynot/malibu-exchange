<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0066_add_merchant_rub_invoice_markup_mode',
	'title'    => 'Add merchant RUB invoice markup mode',
	'callback' => function () {
		global $wpdb;

		$table    = 'crm_merchants';
		$messages = [];
		$column   = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'rub_invoice_markup_mode'", ARRAY_A );

		if ( ! $column ) {
			$wpdb->query(
				"ALTER TABLE {$table}
				 ADD COLUMN `rub_invoice_markup_mode` varchar(32) NOT NULL DEFAULT 'none'
				 AFTER `base_markup_value`"
			);
			$messages[] = 'Added crm_merchants.rub_invoice_markup_mode.';
		} else {
			$messages[] = 'crm_merchants.rub_invoice_markup_mode already exists.';
		}

		$wpdb->query(
			"UPDATE {$table}
			 SET rub_invoice_markup_mode = 'none'
			 WHERE rub_invoice_markup_mode IS NULL
			    OR rub_invoice_markup_mode = ''
			    OR rub_invoice_markup_mode NOT IN ('none', 'add_on_top')"
		);
		$messages[] = 'Backfilled invalid RUB invoice markup mode to none.';

		return [
			'summary'  => 'Merchant RUB invoice markup mode column is ready.',
			'messages' => $messages,
		];
	},
];
