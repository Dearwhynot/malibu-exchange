<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0037_sync_rbac_add_offices_create',
	'title'    => 'Sync RBAC for offices.create permission',
	'callback' => function () {
		$messages = crm_rbac_sync();

		return [
			'summary'  => 'offices.create permission synced to RBAC.',
			'messages' => $messages,
		];
	},
];
