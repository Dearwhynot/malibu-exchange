<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0057_seed_service_telegram_and_rub_usdt_fixation',
	'title'    => 'Seed service Telegram settings and RUB/USDT fixation mode for companies',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		foreach ( crm_telegram_default_settings( 'service' ) as $key => $value ) {
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO crm_settings (org_id, setting_key, setting_value) VALUES (%d, %s, %s)',
					1,
					$key,
					$value
				)
			);
		}

		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO crm_settings (org_id, setting_key, setting_value) VALUES (%d, %s, %s)',
				1,
				'rub_usdt_fixation_mode',
				'rapira_manual'
			)
		);
		$messages[] = 'Seeded org_id=1 defaults for service Telegram contour and RUB/USDT fixation mode.';

		$company_ids = $wpdb->get_col( "SELECT id FROM crm_companies WHERE id > 0 ORDER BY id ASC" ) ?: [];
		foreach ( array_map( 'intval', $company_ids ) as $company_id ) {
			if ( $company_id <= 0 ) {
				continue;
			}

			crm_telegram_seed_company_settings( $company_id );
			if ( function_exists( 'crm_company_seed_rub_usdt_fixation_settings' ) ) {
				crm_company_seed_rub_usdt_fixation_settings( $company_id );
			}

			$messages[] = 'Seeded service Telegram contour and RUB/USDT fixation mode for company #' . $company_id . '.';
		}

		return [
			'summary'  => 'Service Telegram settings and RUB/USDT fixation mode are ready.',
			'messages' => $messages,
		];
	},
];
