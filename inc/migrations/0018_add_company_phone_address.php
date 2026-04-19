<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0018_add_company_phone_address',
	'title'    => 'Add phone and address columns to crm_companies',
	'callback' => function () {
		global $wpdb;

		$db_name  = DB_NAME;
		$messages = [];

		// ── phone column ─────────────────────────────────────────────────────────
		$has_phone = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
			 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = 'crm_companies' AND COLUMN_NAME = 'phone'",
			$db_name
		) );

		if ( ! $has_phone ) {
			$wpdb->query(
				"ALTER TABLE `crm_companies`
				 ADD COLUMN `phone` varchar(64) DEFAULT NULL AFTER `tax_number`"
			);
			$messages[] = 'crm_companies: column phone added.';
		} else {
			$messages[] = 'crm_companies: column phone already exists — skipped.';
		}

		// ── address column ───────────────────────────────────────────────────────
		$has_address = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
			 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = 'crm_companies' AND COLUMN_NAME = 'address'",
			$db_name
		) );

		if ( ! $has_address ) {
			$wpdb->query(
				"ALTER TABLE `crm_companies`
				 ADD COLUMN `address` varchar(255) DEFAULT NULL AFTER `phone`"
			);
			$messages[] = 'crm_companies: column address added.';
		} else {
			$messages[] = 'crm_companies: column address already exists — skipped.';
		}

		return [
			'summary'  => 'crm_companies: phone and address columns ensured.',
			'messages' => $messages,
		];
	},
];
