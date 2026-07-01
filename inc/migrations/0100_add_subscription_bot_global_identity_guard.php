<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0100_add_subscription_bot_global_identity_guard',
	'title'    => 'Add global identity guard for merchant subscription bots',
	'callback' => function () {
		global $wpdb;

		$table = 'crm_merchant_subscription_bots';
		$messages = [];

		if ( ! function_exists( 'malibu_migrations_table_exists' ) || ! malibu_migrations_table_exists( $table ) ) {
			return [
				'summary'  => 'Subscription bot identity guard skipped.',
				'messages' => [ 'crm_merchant_subscription_bots table does not exist yet.' ],
			];
		}

		if ( ! malibu_migrations_column_exists( $table, 'telegram_bot_id' ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `telegram_bot_id` varchar(32) DEFAULT NULL AFTER `bot_username`" );
			$messages[] = 'Added column telegram_bot_id.';
		} else {
			$messages[] = 'Column telegram_bot_id already exists.';
		}

		$rows = $wpdb->get_results(
			"SELECT id, bot_token
			 FROM `{$table}`
			 WHERE bot_token IS NOT NULL
			   AND bot_token <> ''
			   AND (telegram_bot_id IS NULL OR telegram_bot_id = '')",
			ARRAY_A
		) ?: [];

		$bot_ids_by_row = [];
		$counts = [];
		foreach ( $rows as $row ) {
			$token = trim( (string) ( $row['bot_token'] ?? '' ) );
			if ( ! preg_match( '/^(\d{5,}):[A-Za-z0-9_-]{20,}$/', $token, $matches ) ) {
				continue;
			}

			$bot_id = (string) $matches[1];
			$row_id = (int) ( $row['id'] ?? 0 );
			if ( $row_id <= 0 ) {
				continue;
			}

			$bot_ids_by_row[ $row_id ] = $bot_id;
			$counts[ $bot_id ] = (int) ( $counts[ $bot_id ] ?? 0 ) + 1;
		}

		$backfilled = 0;
		$skipped_duplicates = 0;
		foreach ( $bot_ids_by_row as $row_id => $bot_id ) {
			if ( (int) ( $counts[ $bot_id ] ?? 0 ) !== 1 ) {
				$skipped_duplicates++;
				continue;
			}

			$updated = $wpdb->update(
				$table,
				[ 'telegram_bot_id' => $bot_id ],
				[ 'id' => $row_id ],
				[ '%s' ],
				[ '%d' ]
			);
			if ( false !== $updated ) {
				$backfilled++;
			}
		}
		$messages[] = 'Backfilled telegram_bot_id rows: ' . $backfilled . '.';
		if ( $skipped_duplicates > 0 ) {
			$messages[] = 'Skipped duplicate token-derived bot ids: ' . $skipped_duplicates . '. New saves are still blocked by application-level duplicate checks.';
		}

		if ( ! malibu_migrations_index_exists( $table, 'uq_crm_merchant_subscription_bots_telegram_bot_id' ) ) {
			$duplicate_ids = $wpdb->get_results(
				"SELECT telegram_bot_id, COUNT(*) AS cnt
				 FROM `{$table}`
				 WHERE telegram_bot_id IS NOT NULL
				   AND telegram_bot_id <> ''
				 GROUP BY telegram_bot_id
				 HAVING cnt > 1
				 LIMIT 5"
			) ?: [];

			if ( empty( $duplicate_ids ) ) {
				$index_result = $wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY `uq_crm_merchant_subscription_bots_telegram_bot_id` (`telegram_bot_id`)" );
				$messages[] = false === $index_result
					? 'Unique index telegram_bot_id was not added: ' . (string) $wpdb->last_error
					: 'Added unique index uq_crm_merchant_subscription_bots_telegram_bot_id.';
			} else {
				$messages[] = 'Unique index telegram_bot_id was skipped because duplicate telegram_bot_id values already exist.';
			}
		} else {
			$messages[] = 'Unique index telegram_bot_id already exists.';
		}

		return [
			'summary'  => 'Merchant subscription bot global identity guard is ready.',
			'messages' => $messages,
		];
	},
];
