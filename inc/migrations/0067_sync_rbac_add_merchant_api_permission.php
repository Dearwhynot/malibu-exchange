<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0067_sync_rbac_add_merchant_api_permission',
	'title'    => 'Sync RBAC constitution and add merchants.manage_api permission',
	'callback' => function () {
		$report = function_exists( 'crm_rbac_sync' ) ? crm_rbac_sync() : [ 'RBAC sync helper is not available.' ];

		return [
			'summary'  => 'RBAC synced with merchants.manage_api permission.',
			'messages' => is_array( $report ) ? $report : [ 'RBAC sync finished.' ],
		];
	},
];
