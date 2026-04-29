<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0035_extend_crm_merchants_with_telegram_profile',
	'title'    => 'Extend crm_merchants with Telegram profile and avatar fields',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		$columns = [
			'telegram_first_name'    => "ALTER TABLE `crm_merchants` ADD COLUMN `telegram_first_name` varchar(191) DEFAULT NULL AFTER `telegram_username`",
			'telegram_last_name'     => "ALTER TABLE `crm_merchants` ADD COLUMN `telegram_last_name` varchar(191) DEFAULT NULL AFTER `telegram_first_name`",
			'telegram_language_code' => "ALTER TABLE `crm_merchants` ADD COLUMN `telegram_language_code` varchar(16) DEFAULT NULL AFTER `telegram_last_name`",
			'telegram_avatar_file_id'=> "ALTER TABLE `crm_merchants` ADD COLUMN `telegram_avatar_file_id` varchar(255) DEFAULT NULL AFTER `telegram_language_code`",
			'telegram_avatar_path'   => "ALTER TABLE `crm_merchants` ADD COLUMN `telegram_avatar_path` varchar(255) DEFAULT NULL AFTER `telegram_avatar_file_id`",
			'telegram_avatar_url'    => "ALTER TABLE `crm_merchants` ADD COLUMN `telegram_avatar_url` varchar(255) DEFAULT NULL AFTER `telegram_avatar_path`",
			'invited_via_invite_id'  => "ALTER TABLE `crm_merchants` ADD COLUMN `invited_via_invite_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `referred_by_merchant_id`",
			'invited_at'             => "ALTER TABLE `crm_merchants` ADD COLUMN `invited_at` datetime DEFAULT NULL AFTER `updated_at`",
			'activated_at'           => "ALTER TABLE `crm_merchants` ADD COLUMN `activated_at` datetime DEFAULT NULL AFTER `invited_at`",
			'telegram_profile_json'  => "ALTER TABLE `crm_merchants` ADD COLUMN `telegram_profile_json` longtext DEFAULT NULL AFTER `activated_at`",
		];

		foreach ( $columns as $column => $sql ) {
			if ( ! malibu_migrations_column_exists( 'crm_merchants', $column ) ) {
				$wpdb->query( $sql );
				$messages[] = 'crm_merchants: added column `' . $column . '`.';
			} else {
				$messages[] = 'crm_merchants: column `' . $column . '` already exists.';
			}
		}

		if ( ! malibu_migrations_index_exists( 'crm_merchants', 'idx_crm_merchants_company_invite' ) && malibu_migrations_column_exists( 'crm_merchants', 'invited_via_invite_id' ) ) {
			$wpdb->query( "ALTER TABLE `crm_merchants` ADD KEY `idx_crm_merchants_company_invite` (`company_id`,`invited_via_invite_id`)" );
			$messages[] = 'crm_merchants: added index `idx_crm_merchants_company_invite`.';
		}

		$constraint_exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM information_schema.table_constraints
				 WHERE table_schema = DATABASE()
				   AND table_name = %s
				   AND constraint_name = %s",
				'crm_merchants',
				'fk_crm_merchants_invite'
			)
		) > 0;

		if ( ! $constraint_exists && malibu_migrations_column_exists( 'crm_merchants', 'invited_via_invite_id' ) ) {
			$wpdb->query( "
				ALTER TABLE `crm_merchants`
				ADD CONSTRAINT `fk_crm_merchants_invite`
				FOREIGN KEY (`invited_via_invite_id`) REFERENCES `crm_merchant_invites` (`id`)
				ON DELETE SET NULL ON UPDATE CASCADE
			" );
			$messages[] = 'crm_merchants: foreign key `fk_crm_merchants_invite` added.';
		}

		return [
			'summary'  => 'crm_merchants extended with Telegram profile fields.',
			'messages' => $messages,
		];
	},
];
