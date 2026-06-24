<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0087_remove_friendly_pay_base_url_setting',
	'title'    => 'Remove Friendly Pay API base URL from company settings',
	'callback' => function () {
		global $wpdb;

		$deleted = (int) $wpdb->delete(
			'crm_settings',
			[ 'setting_key' => 'fintech_friendly_pay_base_url' ],
			[ '%s' ]
		);

		return [
			'summary'  => 'Friendly Pay API base URL moved to code-level infrastructure config.',
			'messages' => [
				sprintf( 'Deleted %d obsolete fintech_friendly_pay_base_url setting rows.', $deleted ),
			],
		];
	},
];
