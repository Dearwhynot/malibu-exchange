<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0093_scope_telegram_channels_to_merchants',
	'title'    => 'Scope Telegram channel profiles to merchants',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		$column_exists = static function ( string $table, string $column ) use ( $wpdb ): bool {
			return (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COLUMN_NAME
					 FROM INFORMATION_SCHEMA.COLUMNS
					 WHERE TABLE_SCHEMA = DATABASE()
					   AND TABLE_NAME = %s
					   AND COLUMN_NAME = %s
					 LIMIT 1",
					$table,
					$column
				)
			) === $column;
		};

		$index_exists = static function ( string $table, string $index ) use ( $wpdb ): bool {
			return (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT INDEX_NAME
					 FROM INFORMATION_SCHEMA.STATISTICS
					 WHERE TABLE_SCHEMA = DATABASE()
					   AND TABLE_NAME = %s
					   AND INDEX_NAME = %s
					 LIMIT 1",
					$table,
					$index
				)
			) === $index;
		};

		$add_column = static function ( string $table, string $column, string $definition, string $after ) use ( $wpdb, $column_exists, &$messages ): void {
			if ( ! $column_exists( $table, $column ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition} AFTER `{$after}`" );
				$messages[] = "{$table}.{$column}: column added.";
			} else {
				$messages[] = "{$table}.{$column}: column already exists.";
			}
		};

		$add_index = static function ( string $table, string $index, string $definition ) use ( $wpdb, $index_exists, &$messages ): void {
			if ( ! $index_exists( $table, $index ) ) {
				$wpdb->query( "ALTER TABLE `{$table}` ADD {$definition}" );
				$messages[] = "{$table}.{$index}: index added.";
			} else {
				$messages[] = "{$table}.{$index}: index already exists.";
			}
		};

		$add_column( 'crm_telegram_channels', 'merchant_id', 'bigint(20) UNSIGNED NOT NULL DEFAULT 0', 'company_id' );
		$add_column( 'crm_telegram_channel_setup_sessions', 'merchant_id', 'bigint(20) UNSIGNED NOT NULL DEFAULT 0', 'company_id' );
		$add_column( 'crm_telegram_channel_subscribers', 'merchant_id', 'bigint(20) UNSIGNED NOT NULL DEFAULT 0', 'channel_id' );
		$add_column( 'crm_telegram_channel_payments', 'merchant_id', 'bigint(20) UNSIGNED NOT NULL DEFAULT 0', 'channel_id' );
		$add_column( 'crm_telegram_channel_invites', 'merchant_id', 'bigint(20) UNSIGNED NOT NULL DEFAULT 0', 'channel_id' );

		foreach ( [ 'crm_telegram_channels', 'crm_telegram_channel_setup_sessions', 'crm_telegram_channel_subscribers', 'crm_telegram_channel_payments', 'crm_telegram_channel_invites' ] as $table ) {
			$wpdb->query( "UPDATE `{$table}` SET `merchant_id` = 0 WHERE `merchant_id` IS NULL" );
			$wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `merchant_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0" );
			$messages[] = "{$table}.merchant_id: normalized to NOT NULL DEFAULT 0.";
		}

		if ( $index_exists( 'crm_telegram_channels', 'uq_crm_telegram_channels_company' ) ) {
			$wpdb->query( 'ALTER TABLE `crm_telegram_channels` DROP INDEX `uq_crm_telegram_channels_company`' );
			$messages[] = 'crm_telegram_channels.uq_crm_telegram_channels_company: old company unique index dropped.';
		}

		$add_index(
			'crm_telegram_channels',
			'uq_crm_telegram_channels_company_merchant',
			'UNIQUE KEY `uq_crm_telegram_channels_company_merchant` (`company_id`,`merchant_id`)'
		);
		$add_index(
			'crm_telegram_channels',
			'idx_crm_telegram_channels_company_merchant_status',
			'KEY `idx_crm_telegram_channels_company_merchant_status` (`company_id`,`merchant_id`,`status`)'
		);
		$add_index(
			'crm_telegram_channel_setup_sessions',
			'idx_crm_tg_channel_setup_merchant_status',
			'KEY `idx_crm_tg_channel_setup_merchant_status` (`company_id`,`merchant_id`,`status`,`expires_at`)'
		);
		$add_index(
			'crm_telegram_channel_subscribers',
			'idx_crm_tg_channel_subscribers_merchant',
			'KEY `idx_crm_tg_channel_subscribers_merchant` (`company_id`,`merchant_id`,`status`,`subscription_until`)'
		);
		$add_index(
			'crm_telegram_channel_payments',
			'idx_crm_tg_channel_payments_merchant',
			'KEY `idx_crm_tg_channel_payments_merchant` (`company_id`,`merchant_id`,`paid_at`)'
		);
		$add_index(
			'crm_telegram_channel_invites',
			'idx_crm_tg_channel_invites_merchant',
			'KEY `idx_crm_tg_channel_invites_merchant` (`company_id`,`merchant_id`,`created_at`)'
		);

		$wpdb->query(
			"UPDATE crm_telegram_channel_payments p
			 INNER JOIN crm_telegram_channel_sales sl ON sl.id = p.sale_id
			 SET p.merchant_id = sl.merchant_id
			 WHERE p.merchant_id IS NULL
			   AND sl.merchant_id IS NOT NULL"
		);

		$wpdb->query(
			"UPDATE crm_telegram_channel_subscribers s
			 INNER JOIN crm_telegram_channel_payments p ON p.subscriber_id = s.id
			 SET s.merchant_id = p.merchant_id
			 WHERE s.merchant_id IS NULL
			   AND p.merchant_id IS NOT NULL"
		);

		$wpdb->query(
			"UPDATE crm_telegram_channel_invites i
			 INNER JOIN crm_telegram_channel_subscribers s ON s.id = i.subscriber_id
			 SET i.merchant_id = s.merchant_id
			 WHERE i.merchant_id IS NULL
			   AND s.merchant_id IS NOT NULL"
		);

		return [
			'summary'  => 'Telegram channels are ready for merchant-scoped profiles.',
			'messages' => $messages,
		];
	},
];
