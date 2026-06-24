<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0085_restrict_friendly_pay_default_access',
	'title'    => 'Keep Friendly Pay disabled until root enables it per company',
	'callback' => function () {
		global $wpdb;

		$messages = [];
		$company_ids = $wpdb->get_col( 'SELECT id FROM crm_companies WHERE id > 0 ORDER BY id ASC' ) ?: [];

		foreach ( array_map( 'intval', $company_ids ) as $company_id ) {
			if ( $company_id <= 0 ) {
				continue;
			}

			$token    = trim( (string) crm_get_setting( 'fintech_friendly_pay_api_token', $company_id, '' ) );
			$secret   = trim( (string) crm_get_setting( 'fintech_friendly_pay_secret_key', $company_id, '' ) );

			if ( $token !== '' || $secret !== '' ) {
				$messages[] = 'Company #' . $company_id . ': Friendly Pay credentials exist, access left unchanged.';
				continue;
			}

			$allowed = crm_fintech_get_allowed_providers( $company_id );
			if ( ! in_array( 'friendly_pay', $allowed, true ) ) {
				$messages[] = 'Company #' . $company_id . ': Friendly Pay already disabled.';
				continue;
			}

			$next_allowed = array_values( array_filter(
				$allowed,
				static fn( string $provider ): bool => $provider !== 'friendly_pay'
			) );

			crm_set_setting(
				'fintech_allowed_providers',
				crm_fintech_serialize_allowed_providers( $next_allowed ),
				$company_id
			);

			$active_provider = crm_fintech_normalize_provider_code(
				(string) crm_get_setting( 'fintech_active_provider', $company_id, '' )
			);
			if ( $active_provider === 'friendly_pay' ) {
				crm_set_setting( 'fintech_active_provider', '', $company_id );
				$messages[] = 'Company #' . $company_id . ': Friendly Pay disabled and active provider cleared.';
			} else {
				$messages[] = 'Company #' . $company_id . ': Friendly Pay disabled until root enables it.';
			}
		}

		return [
			'summary'  => 'Friendly Pay default access restricted.',
			'messages' => $messages,
		];
	},
];
