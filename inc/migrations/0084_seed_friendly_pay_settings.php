<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0084_seed_friendly_pay_settings',
	'title'    => 'Seed Friendly Pay settings for existing companies',
	'callback' => function () {
		global $wpdb;

		$messages = [];
		$defaults = [
			'fintech_friendly_pay_api_token'        => '',
			'fintech_friendly_pay_secret_key'       => '',
			'fintech_friendly_pay_transaction_type' => function_exists( 'crm_fintech_default_friendly_pay_transaction_type' ) ? crm_fintech_default_friendly_pay_transaction_type() : 'sbp',
			'fintech_friendly_pay_cart_name'        => function_exists( 'crm_fintech_default_friendly_pay_cart_name' ) ? crm_fintech_default_friendly_pay_cart_name() : 'Payment',
			'fintech_friendly_pay_cart_currency'    => function_exists( 'crm_fintech_default_friendly_pay_cart_currency' ) ? crm_fintech_default_friendly_pay_cart_currency() : 'RUB',
			'fintech_friendly_pay_min_amount_rub'   => function_exists( 'crm_fintech_default_friendly_pay_min_amount_rub' ) ? crm_fintech_default_friendly_pay_min_amount_rub() : '30',
			'fintech_friendly_pay_max_amount_rub'   => function_exists( 'crm_fintech_default_friendly_pay_max_amount_rub' ) ? crm_fintech_default_friendly_pay_max_amount_rub() : '200000',
		];

		$org_ids = $wpdb->get_col( 'SELECT id FROM crm_companies WHERE id > 0 ORDER BY id ASC' ) ?: [];
		$org_ids = array_values( array_unique( array_merge( [ 1 ], array_map( 'intval', $org_ids ) ) ) );

		foreach ( $org_ids as $org_id ) {
			$org_id = (int) $org_id;
			if ( $org_id <= 0 ) {
				continue;
			}

			foreach ( $defaults as $setting_key => $setting_value ) {
				$wpdb->query(
					$wpdb->prepare(
						'INSERT IGNORE INTO crm_settings (org_id, setting_key, setting_value) VALUES (%d, %s, %s)',
						$org_id,
						$setting_key,
						$setting_value
					)
				);
			}

			$messages[] = 'Seeded Friendly Pay settings for company/org #' . $org_id . '. Root must enable the provider per company explicitly.';
		}

		return [
			'summary'  => 'Friendly Pay settings seeded for existing companies.',
			'messages' => $messages,
		];
	},
];
