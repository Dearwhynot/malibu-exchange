<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0052_add_company_block_metadata',
	'title'    => 'Add company block metadata columns',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		if ( ! malibu_migrations_column_exists( 'crm_companies', 'blocked_at' ) ) {
			$wpdb->query( "
				ALTER TABLE `crm_companies`
				  ADD COLUMN `blocked_at` datetime DEFAULT NULL AFTER `status`
			" );
			$messages[] = 'crm_companies.blocked_at added.';
		} else {
			$messages[] = 'crm_companies.blocked_at already exists.';
		}

		if ( ! malibu_migrations_column_exists( 'crm_companies', 'blocked_by_user_id' ) ) {
			$wpdb->query( "
				ALTER TABLE `crm_companies`
				  ADD COLUMN `blocked_by_user_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `blocked_at`
			" );
			$messages[] = 'crm_companies.blocked_by_user_id added.';
		} else {
			$messages[] = 'crm_companies.blocked_by_user_id already exists.';
		}

		if ( ! malibu_migrations_column_exists( 'crm_companies', 'block_reason' ) ) {
			$wpdb->query( "
				ALTER TABLE `crm_companies`
				  ADD COLUMN `block_reason` text DEFAULT NULL AFTER `blocked_by_user_id`
			" );
			$messages[] = 'crm_companies.block_reason added.';
		} else {
			$messages[] = 'crm_companies.block_reason already exists.';
		}

		if ( ! malibu_migrations_index_exists( 'crm_companies', 'idx_crm_companies_blocked_by' ) ) {
			$wpdb->query( "
				ALTER TABLE `crm_companies`
				  ADD KEY `idx_crm_companies_blocked_by` (`blocked_by_user_id`)
			" );
			$messages[] = 'crm_companies.idx_crm_companies_blocked_by added.';
		} else {
			$messages[] = 'crm_companies.idx_crm_companies_blocked_by already exists.';
		}

		return [
			'summary'  => 'Company block metadata schema ensured.',
			'messages' => $messages,
		];
	},
];
