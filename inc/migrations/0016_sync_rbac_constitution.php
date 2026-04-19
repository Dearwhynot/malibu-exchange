<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Синхронизирует БД с конституцией RBAC, определённой в inc/rbac.php.
 *
 * Это нормализующая миграция — приводит состояние базы
 * в соответствие с декларативным манифестом crm_rbac_permissions()
 * и crm_rbac_role_grants().
 *
 * Идемпотентна: безопасно запускать повторно.
 */
return [
	'key'      => '0016_sync_rbac_constitution',
	'title'    => 'Sync RBAC permissions and role grants from PHP constitution (rbac.php)',
	'callback' => function () {
		$messages = crm_rbac_sync();

		return [
			'summary'  => 'RBAC constitution applied: permissions and role grants synced to DB.',
			'messages' => $messages,
		];
	},
];
