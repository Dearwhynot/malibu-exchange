<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0036_extend_crm_merchant_invites_for_telegram_flow',
	'title'    => 'Extend crm_merchant_invites with Telegram start payload and prefill fields',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		$columns = [
			'telegram_start_payload' => "ALTER TABLE `crm_merchant_invites` ADD COLUMN `telegram_start_payload` varchar(64) DEFAULT NULL AFTER `invite_token`",
			'bot_username_snapshot'  => "ALTER TABLE `crm_merchant_invites` ADD COLUMN `bot_username_snapshot` varchar(191) DEFAULT NULL AFTER `telegram_start_payload`",
			'prefill_json'           => "ALTER TABLE `crm_merchant_invites` ADD COLUMN `prefill_json` longtext DEFAULT NULL AFTER `bot_username_snapshot`",
		];

		foreach ( $columns as $column => $sql ) {
			if ( ! malibu_migrations_column_exists( 'crm_merchant_invites', $column ) ) {
				$wpdb->query( $sql );
				$messages[] = 'crm_merchant_invites: added column `' . $column . '`.';
			} else {
				$messages[] = 'crm_merchant_invites: column `' . $column . '` already exists.';
			}
		}

		if ( ! malibu_migrations_index_exists( 'crm_merchant_invites', 'uq_crm_merchant_invites_start_payload' ) && malibu_migrations_column_exists( 'crm_merchant_invites', 'telegram_start_payload' ) ) {
			$wpdb->query( "ALTER TABLE `crm_merchant_invites` ADD UNIQUE KEY `uq_crm_merchant_invites_start_payload` (`telegram_start_payload`)" );
			$messages[] = 'crm_merchant_invites: added unique key `uq_crm_merchant_invites_start_payload`.';
		}

		if ( ! malibu_migrations_index_exists( 'crm_merchant_invites', 'idx_crm_merchant_invites_company_payload' ) && malibu_migrations_column_exists( 'crm_merchant_invites', 'telegram_start_payload' ) ) {
			$wpdb->query( "ALTER TABLE `crm_merchant_invites` ADD KEY `idx_crm_merchant_invites_company_payload` (`company_id`,`telegram_start_payload`)" );
			$messages[] = 'crm_merchant_invites: added key `idx_crm_merchant_invites_company_payload`.';
		}

		if ( malibu_migrations_column_exists( 'crm_merchant_invites', 'telegram_start_payload' ) ) {
			$rows = $wpdb->get_results( "SELECT id FROM crm_merchant_invites WHERE telegram_start_payload IS NULL OR telegram_start_payload = ''", ARRAY_A ) ?: [];
			foreach ( $rows as $row ) {
				$payload = bin2hex( random_bytes( 16 ) );
				$wpdb->update(
					'crm_merchant_invites',
					[
						'telegram_start_payload' => $payload,
					],
					[
						'id' => (int) $row['id'],
					],
					[ '%s' ],
					[ '%d' ]
				);
			}
			if ( ! empty( $rows ) ) {
				$messages[] = 'crm_merchant_invites: backfilled start payload for ' . count( $rows ) . ' rows.';
			}
		}

		return [
			'summary'  => 'crm_merchant_invites extended for Telegram flow.',
			'messages' => $messages,
		];
	},
];
