<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0053_split_telegram_bot_settings',
	'title'    => 'Split Telegram bot settings into merchant and operator contours',
	'callback' => function () {
		global $wpdb;

		$company_ids = $wpdb->get_col( "SELECT id FROM crm_companies WHERE id > 0 ORDER BY id ASC" ) ?: [];
		$messages    = [];
		$backfilled   = 0;

		foreach ( array_map( 'intval', $company_ids ) as $company_id ) {
			if ( $company_id <= 0 ) {
				continue;
			}

			crm_telegram_seed_company_settings( $company_id );

			$legacy_map = [
				'bot_token'            => 'telegram_bot_token',
				'bot_username'         => 'telegram_bot_username',
				'webhook_url'          => 'telegram_webhook_url',
				'webhook_connected_at' => 'telegram_webhook_connected_at',
				'webhook_last_error'   => 'telegram_webhook_last_error',
				'webhook_lock'         => 'telegram_webhook_lock',
			];

			foreach ( $legacy_map as $suffix => $legacy_key ) {
				$merchant_key = crm_telegram_setting_key( 'merchant', $suffix );
				$current      = crm_get_setting( $merchant_key, $company_id, '' );
				$legacy_value = crm_get_setting( $legacy_key, $company_id, '' );

				if ( trim( (string) $current ) !== '' || trim( (string) $legacy_value ) === '' ) {
					continue;
				}

				if ( crm_set_setting( $merchant_key, (string) $legacy_value, $company_id ) ) {
					$backfilled++;
				}
			}

			$messages[] = 'Telegram merchant/operator settings ensured for company #' . $company_id . '.';
		}

		return [
			'summary'  => 'Telegram settings split into merchant and operator contours.',
			'messages' => array_merge(
				$messages,
				[ 'Backfilled merchant settings from legacy keys: ' . $backfilled . ' values.' ]
			),
		];
	},
];
