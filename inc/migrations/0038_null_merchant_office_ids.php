<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0038_null_merchant_office_ids',
	'title'    => 'Null merchant office assignments after merchant office removal',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		if ( ! malibu_migrations_table_exists( 'crm_merchants' ) ) {
			$messages[] = 'crm_merchants table is missing, nothing to update.';

			return [
				'summary'  => 'Merchant office cleanup skipped.',
				'messages' => $messages,
			];
		}

		if ( ! malibu_migrations_column_exists( 'crm_merchants', 'office_id' ) ) {
			$messages[] = 'crm_merchants.office_id column is missing, nothing to update.';

			return [
				'summary'  => 'Merchant office cleanup skipped.',
				'messages' => $messages,
			];
		}

		$updated = $wpdb->query( "UPDATE `crm_merchants` SET `office_id` = NULL WHERE `office_id` IS NOT NULL" );
		if ( false === $updated ) {
			return new WP_Error( 'merchant_office_cleanup_failed', 'Failed to null crm_merchants.office_id values.' );
		}

		$messages[] = 'crm_merchants: nulled office_id for ' . (int) $updated . ' merchant rows.';

		return [
			'summary'  => 'Merchant office assignments cleared.',
			'messages' => $messages,
		];
	},
];
