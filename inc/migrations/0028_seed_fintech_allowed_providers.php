<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'   => '0028_seed_fintech_allowed_providers',
	'title' => 'Seed fintech allowed providers per company',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO `crm_settings` (`org_id`, `setting_key`, `setting_value`) VALUES (%d, %s, %s)',
				0,
				'fintech_allowed_providers',
				crm_fintech_serialize_allowed_providers( crm_fintech_default_allowed_providers() )
			)
		);
		$messages[] = 'Seeded fintech_allowed_providers for root system context (org_id=0).';

		$company_ids = $wpdb->get_col( 'SELECT id FROM crm_companies WHERE id > 0 ORDER BY id ASC' ) ?: [];
		foreach ( $company_ids as $company_id ) {
			$company_id       = (int) $company_id;
			$doverka_enabled  = crm_get_setting( 'doverka_enabled', $company_id, '1' );
			$allowed_providers = $doverka_enabled === '0'
				? [ 'kanyon' ]
				: crm_fintech_default_allowed_providers();

			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO `crm_settings` (`org_id`, `setting_key`, `setting_value`) VALUES (%d, %s, %s)',
					$company_id,
					'fintech_allowed_providers',
					crm_fintech_serialize_allowed_providers( $allowed_providers )
				)
			);

			$messages[] = sprintf(
				'Seeded fintech_allowed_providers for company %d: %s.',
				$company_id,
				implode( ', ', $allowed_providers )
			);
		}

		return [
			'summary'  => 'Seeded fintech_allowed_providers settings.',
			'messages' => $messages,
		];
	},
];
