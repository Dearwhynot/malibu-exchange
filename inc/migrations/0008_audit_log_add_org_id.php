<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0008_audit_log_add_org_id',
	'title'    => 'Add organization_id to crm_audit_log',
	'callback' => function () {
		global $wpdb;

		if ( malibu_migrations_column_exists( 'crm_audit_log', 'organization_id' ) ) {
			return [
				'summary'  => 'Column organization_id already exists — skipped.',
				'messages' => [],
			];
		}

		$wpdb->query( "
			ALTER TABLE `crm_audit_log`
			ADD COLUMN `organization_id` INT UNSIGNED NOT NULL DEFAULT 1
			    COMMENT 'Организация, к которой относится событие'
			AFTER `id`,
			ADD KEY `idx_org_id` (`organization_id`)
		" );

		return [
			'summary'  => 'Column organization_id added to crm_audit_log.',
			'messages' => [
				'Added column organization_id INT UNSIGNED NOT NULL DEFAULT 1.',
				'Added index idx_org_id.',
			],
		];
	},
];
