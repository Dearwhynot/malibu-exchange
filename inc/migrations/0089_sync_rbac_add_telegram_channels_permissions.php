<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0089_sync_rbac_add_telegram_channels_permissions',
	'title'    => 'Sync RBAC with Telegram channels permissions',
	'callback' => function () {
		$report = function_exists( 'crm_rbac_sync' ) ? crm_rbac_sync() : [ 'RBAC sync helper is not available.' ];

		return [
			'summary'  => 'RBAC synced with Telegram channels permissions.',
			'messages' => is_array( $report ) ? $report : [ 'RBAC sync finished.' ],
		];
	},
];

