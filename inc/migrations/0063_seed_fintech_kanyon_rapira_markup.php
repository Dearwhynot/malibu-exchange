<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0063_seed_fintech_kanyon_rapira_markup',
	'title'    => 'Seed Kanyon Rapira markup setting for existing companies',
	'callback' => function () {
		global $wpdb;

		$messages = [];
		$default  = function_exists( 'crm_fintech_default_kanyon_rapira_markup_percent' )
			? crm_fintech_default_kanyon_rapira_markup_percent()
			: '6';

		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO crm_settings (org_id, setting_key, setting_value) VALUES (%d, %s, %s)',
				1,
				'fintech_kanyon_rapira_markup_percent',
				$default
			)
		);
		$messages[] = 'Seeded fintech_kanyon_rapira_markup_percent for org_id=1.';

		$company_ids = $wpdb->get_col( "SELECT id FROM crm_companies WHERE id > 0 ORDER BY id ASC" ) ?: [];
		foreach ( array_map( 'intval', $company_ids ) as $company_id ) {
			if ( $company_id <= 0 ) {
				continue;
			}

			if ( function_exists( 'crm_fintech_seed_company_settings' ) ) {
				crm_fintech_seed_company_settings( $company_id );
			} else {
				$wpdb->query(
					$wpdb->prepare(
						'INSERT IGNORE INTO crm_settings (org_id, setting_key, setting_value) VALUES (%d, %s, %s)',
						$company_id,
						'fintech_kanyon_rapira_markup_percent',
						$default
					)
				);
			}

			$messages[] = 'Ensured fintech_kanyon_rapira_markup_percent for company #' . $company_id . '.';
		}

		return [
			'summary'  => 'Kanyon Rapira markup setting seeded for existing companies.',
			'messages' => $messages,
		];
	},
];
