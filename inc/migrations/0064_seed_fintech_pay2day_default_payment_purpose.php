<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0064_seed_fintech_pay2day_default_payment_purpose',
	'title'    => 'Seed Pay2Day default payment purpose setting for existing companies',
	'callback' => function () {
		global $wpdb;

		$messages = [];
		$default  = function_exists( 'crm_fintech_default_pay2day_payment_purpose' )
			? crm_fintech_default_pay2day_payment_purpose()
			: '';

		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO crm_settings (org_id, setting_key, setting_value) VALUES (%d, %s, %s)',
				1,
				'fintech_pay2day_default_payment_purpose',
				$default
			)
		);
		$messages[] = 'Seeded fintech_pay2day_default_payment_purpose for org_id=1.';

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
						'fintech_pay2day_default_payment_purpose',
						$default
					)
				);
			}

			$messages[] = 'Ensured fintech_pay2day_default_payment_purpose for company #' . $company_id . '.';
		}

		return [
			'summary'  => 'Pay2Day default payment purpose seeded for existing companies.',
			'messages' => $messages,
		];
	},
];
