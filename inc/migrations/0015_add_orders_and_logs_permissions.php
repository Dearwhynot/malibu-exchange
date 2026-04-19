<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0015_add_orders_and_logs_permissions',
	'title'    => 'Add orders.view permission and fix admin/role grants for orders + logs',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		// ── 1. Добавляем orders.* permissions ─────────────────────────────────
		$new_permissions = [
			[ 'orders.view',   'orders', 'view',   'Просмотр ордеров' ],
			[ 'orders.create', 'orders', 'create', 'Создание ордеров' ],
			[ 'orders.edit',   'orders', 'edit',   'Редактирование ордеров' ],
		];

		foreach ( $new_permissions as [ $code, $module, $action, $name ] ) {
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO `crm_permissions` (`code`, `module`, `action`, `name`) VALUES (%s, %s, %s, %s)",
				$code, $module, $action, $name
			) );
		}
		$messages[] = 'Inserted permissions: orders.view, orders.create, orders.edit (IGNORE if existed).';

		// ── 2. Назначение orders.view всем ролям кроме служебных-только ───────
		// Роли, которым нужен доступ к ордерам:
		$roles_for_orders_view = [
			'owner', 'admin',
			'senior_operator', 'operator', 'cashier',
			'compliance', 'accountant', 'support', 'auditor',
		];

		// orders.create / orders.edit — операционным ролям
		$roles_for_orders_write = [
			'owner', 'admin',
			'senior_operator', 'operator',
		];

		$perm_view   = $wpdb->get_var( "SELECT id FROM `crm_permissions` WHERE code = 'orders.view'" );
		$perm_create = $wpdb->get_var( "SELECT id FROM `crm_permissions` WHERE code = 'orders.create'" );
		$perm_edit   = $wpdb->get_var( "SELECT id FROM `crm_permissions` WHERE code = 'orders.edit'" );

		if ( $perm_view ) {
			$placeholders = implode( ',', array_fill( 0, count( $roles_for_orders_view ), '%s' ) );
			$role_ids     = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM `crm_roles` WHERE code IN ($placeholders)",
				$roles_for_orders_view
			) );

			foreach ( $role_ids as $role_id ) {
				$wpdb->query( $wpdb->prepare(
					"INSERT IGNORE INTO `crm_role_permissions` (`role_id`, `permission_id`) VALUES (%d, %d)",
					(int) $role_id, (int) $perm_view
				) );
			}
			$messages[] = 'Granted orders.view to: ' . implode( ', ', $roles_for_orders_view ) . '.';
		}

		foreach ( [ $perm_create, $perm_edit ] as $perm_id ) {
			if ( ! $perm_id ) {
				continue;
			}
			$placeholders = implode( ',', array_fill( 0, count( $roles_for_orders_write ), '%s' ) );
			$role_ids     = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM `crm_roles` WHERE code IN ($placeholders)",
				$roles_for_orders_write
			) );
			foreach ( $role_ids as $role_id ) {
				$wpdb->query( $wpdb->prepare(
					"INSERT IGNORE INTO `crm_role_permissions` (`role_id`, `permission_id`) VALUES (%d, %d)",
					(int) $role_id, (int) $perm_id
				) );
			}
		}
		$messages[] = 'Granted orders.create + orders.edit to: ' . implode( ', ', $roles_for_orders_write ) . '.';

		// ── 3. Назначаем logs.view тем, кто должен видеть журнал ──────────────
		// logs.view уже добавлено в crm_permissions (migration 0007),
		// но не назначено ни одной роли через роль-пермишен.
		$perm_logs = $wpdb->get_var( "SELECT id FROM `crm_permissions` WHERE code = 'logs.view'" );

		if ( $perm_logs ) {
			$roles_for_logs = [ 'owner', 'admin', 'auditor' ];
			$placeholders   = implode( ',', array_fill( 0, count( $roles_for_logs ), '%s' ) );
			$role_ids       = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM `crm_roles` WHERE code IN ($placeholders)",
				$roles_for_logs
			) );
			foreach ( $role_ids as $role_id ) {
				$wpdb->query( $wpdb->prepare(
					"INSERT IGNORE INTO `crm_role_permissions` (`role_id`, `permission_id`) VALUES (%d, %d)",
					(int) $role_id, (int) $perm_logs
				) );
			}
			$messages[] = 'Granted logs.view to: ' . implode( ', ', $roles_for_logs ) . '.';
		} else {
			$messages[] = 'WARNING: logs.view permission not found in crm_permissions — migration 0007 may not have run.';
		}

		return [
			'summary'  => 'orders.* permissions created; admin + roles granted orders.view and logs.view.',
			'messages' => $messages,
		];
	},
];
