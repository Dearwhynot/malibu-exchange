<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0069_sync_rbac_add_service_telegram_permissions',
	'title'    => 'Sync RBAC constitution and add service Telegram permissions',
	'callback' => function () {
		$report = function_exists( 'crm_rbac_sync' ) ? crm_rbac_sync() : [ 'RBAC sync helper is not available.' ];

		return [
			'summary'  => 'RBAC synced with service Telegram permissions.',
			'messages' => is_array( $report ) ? $report : [ 'RBAC sync finished.' ],
		];
	},
];

