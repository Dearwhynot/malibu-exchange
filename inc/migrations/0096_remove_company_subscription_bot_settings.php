<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0096_remove_company_subscription_bot_settings',
	'title'    => 'Remove company-scoped subscription bot settings',
	'callback' => function () {
		global $wpdb;

		$deleted = (int) $wpdb->query(
			"DELETE FROM crm_settings WHERE setting_key LIKE 'telegram_subscription_%'"
		);

		return [
			'summary'  => 'Company-scoped subscription bot settings removed.',
			'messages' => [
				'Removed crm_settings rows with telegram_subscription_% keys: ' . $deleted . '.',
			],
		];
	},
];
