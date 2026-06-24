<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0086_seed_empty_fintech_allowed_providers',
	'title'    => 'Seed empty fintech provider allowlists for companies without contour settings',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO `crm_settings` (`org_id`, `setting_key`, `setting_value`) VALUES (%d, %s, %s)',
				0,
				'fintech_allowed_providers',
				crm_fintech_serialize_allowed_providers( [] )
			)
		);
		$messages[] = 'Ensured empty fintech_allowed_providers for root system context.';

		$company_ids = $wpdb->get_col( 'SELECT id FROM crm_companies WHERE id > 0 ORDER BY id ASC' ) ?: [];
		foreach ( $company_ids as $company_id ) {
			$company_id = (int) $company_id;
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO `crm_settings` (`org_id`, `setting_key`, `setting_value`) VALUES (%d, %s, %s)',
					$company_id,
					'fintech_allowed_providers',
					crm_fintech_serialize_allowed_providers( [] )
				)
			);

			$messages[] = sprintf(
				'Ensured fintech_allowed_providers setting exists for company %d.',
				$company_id
			);
		}

		return [
			'summary'  => 'Seeded empty fintech provider allowlists where missing.',
			'messages' => $messages,
		];
	},
];
