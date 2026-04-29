<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0033_seed_merchant_company_settings',
	'title'    => 'Seed merchant settings for all existing companies',
	'callback' => function () {
		global $wpdb;

		$company_ids = $wpdb->get_col( "SELECT id FROM crm_companies WHERE id > 0 ORDER BY id ASC" ) ?: [];
		$messages    = [];

		foreach ( $company_ids as $company_id ) {
			$company_id = (int) $company_id;
			if ( $company_id <= 0 ) {
				continue;
			}
			crm_merchants_seed_company_settings( $company_id );
			$messages[] = 'Merchant settings ensured for company #' . $company_id . '.';
		}

		return [
			'summary'  => 'Merchant settings seeded for existing companies.',
			'messages' => $messages,
		];
	},
];
