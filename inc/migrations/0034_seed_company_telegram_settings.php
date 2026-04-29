<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0034_seed_company_telegram_settings',
	'title'    => 'Seed company-scoped Telegram settings for all existing companies',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		crm_telegram_seed_company_settings( CRM_DEFAULT_ORG_ID );
		$messages[] = 'Seeded Telegram settings for default org_id=1.';

		$company_ids = $wpdb->get_col( "SELECT id FROM crm_companies WHERE id > 0" ) ?: [];
		foreach ( array_map( 'intval', $company_ids ) as $company_id ) {
			crm_telegram_seed_company_settings( $company_id );
		}

		$messages[] = 'Seeded Telegram settings for ' . count( $company_ids ) . ' companies.';

		return [
			'summary'  => 'Company Telegram settings seeded.',
			'messages' => $messages,
		];
	},
];
