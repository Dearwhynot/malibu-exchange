<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Удаляет колонки default_company_id и default_office_id из crm_user_accounts.
 *
 * Эти поля были ярлыком-дублём crm_user_companies.is_primary = 1.
 * Единственный источник правды — crm_user_companies.
 * crm_get_current_user_company_id() читает именно оттуда, колонки не использовались.
 */
return [
	'key'      => '0027_drop_default_company_id',
	'title'    => 'Drop redundant default_company_id and default_office_id from crm_user_accounts',
	'callback' => function () {
		global $wpdb;
		$messages = [];

		foreach ( [ 'default_company_id', 'default_office_id' ] as $col ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = 'crm_user_accounts' AND COLUMN_NAME = %s",
				DB_NAME, $col
			) );

			if ( $exists ) {
				$wpdb->query( "ALTER TABLE `crm_user_accounts` DROP COLUMN `$col`" );
				$messages[] = "crm_user_accounts: column `$col` dropped.";
			} else {
				$messages[] = "crm_user_accounts: column `$col` not found — skipped.";
			}
		}

		// Индексы MySQL дропает вместе с колонкой автоматически, отдельно не нужно.

		return [
			'summary'  => 'Redundant default_company_id / default_office_id columns removed from crm_user_accounts.',
			'messages' => $messages,
		];
	},
];
