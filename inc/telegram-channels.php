<?php
/**
 * Malibu Exchange — Telegram channel subscriptions foundation.
 *
 * This file only prepares company-scoped storage and readiness checks.
 * Runtime bot callbacks, payment creation, paid side effects and cron are
 * intentionally implemented in later stages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'crm_telegram_channels_default_texts' ) ) {
	function crm_telegram_channels_default_texts(): array {
		return [
			'payment_success'        => 'Оплата получена. Доступ к каналу активирован.',
			'subscription_active'   => 'Ваша подписка активна до {until}.',
			'renewal_success'       => 'Подписка продлена до {until}.',
			'expiry_warning'        => 'Подписка скоро закончится: {until}.',
			'expired'               => 'Подписка закончилась.',
			'tariffs_intro'         => 'Выберите тариф подписки.',
			'not_configured'        => 'Подписка временно недоступна.',
			'payment_created'       => 'Счёт создан. Завершите оплату для доступа.',
			'invite_reissued'       => 'Новая ссылка для входа в канал готова.',
			'admin_payment_received'=> 'Получена оплата подписки.',
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_default_settings' ) ) {
	function crm_telegram_channels_default_settings(): array {
		return [
			'module_telegram_channels_enabled'          => '0',
			'telegram_subscription_bot_token'           => '',
			'telegram_subscription_bot_username'        => '',
			'telegram_subscription_webhook_url'         => '',
			'telegram_subscription_webhook_connected_at'=> '',
			'telegram_subscription_webhook_last_error'  => '',
			'telegram_subscription_webhook_lock'        => '0',
			'telegram_channels_admin_chat_id'           => '',
			'telegram_channels_reminders_enabled'       => '1',
			'telegram_channels_reminder_days'           => '3',
			'telegram_channels_invite_ttl_hours'        => '24',
			'telegram_channels_texts_json'              => (string) wp_json_encode( crm_telegram_channels_default_texts(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'telegram_channels_debug'                   => '0',
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_default_tariffs' ) ) {
	function crm_telegram_channels_default_tariffs(): array {
		return [
			'monthly'   => [
				'code'          => 'monthly',
				'title'         => 'Месяц',
				'duration_days' => 30,
				'sort_order'    => 10,
			],
			'quarterly' => [
				'code'          => 'quarterly',
				'title'         => 'Квартал',
				'duration_days' => 90,
				'sort_order'    => 20,
			],
			'yearly'    => [
				'code'          => 'yearly',
				'title'         => 'Год',
				'duration_days' => 365,
				'sort_order'    => 30,
			],
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_table_exists' ) ) {
	function crm_telegram_channels_table_exists( string $table ): bool {
		global $wpdb;

		$table = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );
		if ( $table === '' ) {
			return false;
		}

		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}

if ( ! function_exists( 'crm_telegram_channels_seed_company_settings' ) ) {
	function crm_telegram_channels_seed_company_settings( int $company_id ): void {
		if ( $company_id <= 0 ) {
			return;
		}

		global $wpdb;

		foreach ( crm_telegram_channels_default_settings() as $setting_key => $setting_value ) {
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO crm_settings (org_id, setting_key, setting_value) VALUES (%d, %s, %s)',
					$company_id,
					$setting_key,
					(string) $setting_value
				)
			);
		}
	}
}

if ( ! function_exists( 'crm_telegram_channels_ensure_default_channel' ) ) {
	function crm_telegram_channels_ensure_default_channel( int $company_id ): int {
		global $wpdb;

		if ( $company_id <= 0 || ! crm_telegram_channels_table_exists( 'crm_telegram_channels' ) ) {
			return 0;
		}

		$channel_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM crm_telegram_channels WHERE company_id = %d ORDER BY id ASC LIMIT 1',
				$company_id
			)
		);

		if ( $channel_id > 0 ) {
			return $channel_id;
		}

		$current_user_id = (int) get_current_user_id();
		if ( $current_user_id > 0 ) {
			$wpdb->insert(
				'crm_telegram_channels',
				[
					'company_id'         => $company_id,
					'title'              => 'Telegram-канал',
					'status'             => 'draft',
					'created_by_user_id' => $current_user_id,
				],
				[ '%d', '%s', '%s', '%d' ]
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO crm_telegram_channels (company_id, title, status, created_by_user_id) VALUES (%d, %s, %s, NULL)',
					$company_id,
					'Telegram-канал',
					'draft'
				)
			);
		}

		return (int) $wpdb->insert_id;
	}
}

if ( ! function_exists( 'crm_telegram_channels_seed_default_tariffs' ) ) {
	function crm_telegram_channels_seed_default_tariffs( int $company_id, int $channel_id = 0 ): void {
		global $wpdb;

		if ( $company_id <= 0 || ! crm_telegram_channels_table_exists( 'crm_telegram_channel_tariffs' ) ) {
			return;
		}

		if ( $channel_id <= 0 ) {
			$channel_id = crm_telegram_channels_ensure_default_channel( $company_id );
		}

		if ( $channel_id <= 0 ) {
			return;
		}

		foreach ( crm_telegram_channels_default_tariffs() as $tariff ) {
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO crm_telegram_channel_tariffs
						(company_id, channel_id, code, title, duration_days, price_amount, price_currency, status, sort_order)
					 VALUES (%d, %d, %s, %s, %d, %f, %s, %s, %d)',
					$company_id,
					$channel_id,
					(string) $tariff['code'],
					(string) $tariff['title'],
					(int) $tariff['duration_days'],
					0,
					'RUB',
					'disabled',
					(int) $tariff['sort_order']
				)
			);
		}
	}
}

if ( ! function_exists( 'crm_telegram_channels_seed_company_foundation' ) ) {
	function crm_telegram_channels_seed_company_foundation( int $company_id ): void {
		if ( $company_id <= 0 ) {
			return;
		}

		crm_telegram_channels_seed_company_settings( $company_id );
		$channel_id = crm_telegram_channels_ensure_default_channel( $company_id );
		crm_telegram_channels_seed_default_tariffs( $company_id, $channel_id );
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_company_channel' ) ) {
	function crm_telegram_channels_get_company_channel( int $company_id ): ?object {
		global $wpdb;

		if ( $company_id <= 0 || ! crm_telegram_channels_table_exists( 'crm_telegram_channels' ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM crm_telegram_channels WHERE company_id = %d ORDER BY id ASC LIMIT 1',
				$company_id
			)
		);

		return $row ?: null;
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_company_tariffs' ) ) {
	function crm_telegram_channels_get_company_tariffs( int $company_id, int $channel_id = 0 ): array {
		global $wpdb;

		if ( $company_id <= 0 || ! crm_telegram_channels_table_exists( 'crm_telegram_channel_tariffs' ) ) {
			return [];
		}

		$sql    = 'SELECT * FROM crm_telegram_channel_tariffs WHERE company_id = %d';
		$params = [ $company_id ];

		if ( $channel_id > 0 ) {
			$sql     .= ' AND channel_id = %d';
			$params[] = $channel_id;
		}

		$sql .= ' ORDER BY sort_order ASC, id ASC';

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?: [];
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_readiness_status' ) ) {
	function crm_telegram_channels_get_readiness_status( int $company_id, bool $log_failure = false ): array {
		global $wpdb;

		$issues = [];
		$checks = [];
		$add_issue = static function ( string $id, string $label, string $severity = 'warning' ) use ( &$issues ): void {
			$issues[] = [
				'id'       => $id,
				'label'    => $label,
				'severity' => $severity,
			];
		};
		$set_check = static function ( string $id, bool $ok, string $label, array $extra = [] ) use ( &$checks ): void {
			$checks[ $id ] = array_merge(
				[
					'id'    => $id,
					'ok'    => $ok,
					'label' => $label,
				],
				$extra
			);
		};

		if ( $company_id <= 0 ) {
			$add_issue( 'company_id', 'Некорректный company_id: модуль работает только для company_id > 0.', 'error' );
			$status = [
				'company_id' => $company_id,
				'is_ready'   => false,
				'is_enabled' => false,
				'issues'     => $issues,
				'checks'     => $checks,
			];

			return $status;
		}

		$company = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, code, name, status FROM crm_companies WHERE id = %d LIMIT 1',
				$company_id
			)
		);
		$company_active = $company && (string) $company->status === 'active';
		$set_check( 'company_active', $company_active, 'Компания активна' );
		if ( ! $company_active ) {
			$add_issue( 'company_active', 'Компания не найдена или не активна.', 'error' );
		}

		$module_enabled = function_exists( 'crm_company_contour_is_enabled' )
			? crm_company_contour_is_enabled( $company_id, 'telegram_channels' )
			: crm_get_setting( 'module_telegram_channels_enabled', $company_id, '0' ) === '1';
		$set_check( 'module_enabled', $module_enabled, 'Root включил модуль Telegram-каналы' );
		if ( ! $module_enabled ) {
			$add_issue( 'module_enabled', 'Root ещё не включил модуль Telegram-каналы для компании.' );
		}

		$token    = trim( (string) crm_get_setting( 'telegram_subscription_bot_token', $company_id, '' ) );
		$username = trim( (string) crm_get_setting( 'telegram_subscription_bot_username', $company_id, '' ) );
		$set_check( 'subscription_bot_token', $token !== '', 'Token subscription bot задан' );
		$set_check( 'subscription_bot_username', $username !== '', 'Username subscription bot задан' );
		if ( $token === '' ) {
			$add_issue( 'subscription_bot_token', 'Не задан token subscription bot.' );
		}
		if ( $username === '' ) {
			$add_issue( 'subscription_bot_username', 'Не задан username subscription bot.' );
		}

		$webhook_url = trim( (string) crm_get_setting( 'telegram_subscription_webhook_url', $company_id, '' ) );
		$webhook_ok  = $webhook_url !== '' || ( $token !== '' && $username !== '' );
		$set_check(
			'subscription_webhook',
			$webhook_ok,
			'Webhook subscription bot подключён или может быть подключён',
			[ 'webhook_url_set' => $webhook_url !== '' ]
		);
		if ( ! $webhook_ok ) {
			$add_issue( 'subscription_webhook', 'Webhook нельзя подключить без token и username subscription bot.' );
		}

		$channel = crm_telegram_channels_get_company_channel( $company_id );
		$set_check( 'channel_row', $channel !== null, 'Строка канала существует' );
		if ( ! $channel ) {
			$add_issue( 'channel_row', 'Не создана строка Telegram-канала компании.' );
		}

		$channel_id = $channel ? trim( (string) ( $channel->telegram_channel_id ?? '' ) ) : '';
		$set_check( 'channel_id', $channel_id !== '', 'Telegram channel id задан' );
		if ( $channel_id === '' ) {
			$add_issue( 'channel_id', 'Не задан Telegram channel id.' );
		}

		$admin_status = $channel ? trim( strtolower( (string) ( $channel->bot_admin_check_status ?? '' ) ) ) : '';
		$admin_ok     = in_array( $admin_status, [ 'ok', 'admin', 'administrator' ], true );
		$set_check( 'bot_admin', $admin_ok, 'Subscription bot проверен как администратор канала' );
		if ( ! $admin_ok ) {
			$add_issue( 'bot_admin', 'Не подтверждено, что subscription bot является администратором канала.' );
		}

		$tariffs         = crm_telegram_channels_get_company_tariffs( $company_id, $channel ? (int) $channel->id : 0 );
		$tariffs_by_code = [];
		foreach ( $tariffs as $tariff ) {
			$tariffs_by_code[ (string) $tariff->code ] = $tariff;
		}

		$required_tariffs = crm_telegram_channels_default_tariffs();
		foreach ( $required_tariffs as $code => $definition ) {
			$exists = isset( $tariffs_by_code[ $code ] );
			$set_check( 'tariff_' . $code . '_exists', $exists, 'Тариф существует: ' . (string) $definition['title'] );
			if ( ! $exists ) {
				$add_issue( 'tariff_' . $code . '_exists', 'Не создан тариф: ' . (string) $definition['title'] . '.' );
				continue;
			}

			$tariff      = $tariffs_by_code[ $code ];
			$price       = (float) ( $tariff->price_amount ?? 0 );
			$currency    = trim( (string) ( $tariff->price_currency ?? '' ) );
			$status_code = (string) ( $tariff->status ?? '' );
			$price_ok    = $price > 0;
			$currency_ok = $currency !== '';
			$status_ok   = $status_code === 'active';

			$set_check( 'tariff_' . $code . '_price', $price_ok, 'Цена > 0: ' . (string) $definition['title'] );
			$set_check( 'tariff_' . $code . '_currency', $currency_ok, 'Валюта задана: ' . (string) $definition['title'] );
			$set_check( 'tariff_' . $code . '_active', $status_ok, 'Тариф активен: ' . (string) $definition['title'] );

			if ( ! $price_ok ) {
				$add_issue( 'tariff_' . $code . '_price', 'Цена тарифа «' . (string) $definition['title'] . '» должна быть > 0.' );
			}
			if ( ! $currency_ok ) {
				$add_issue( 'tariff_' . $code . '_currency', 'Не задана валюта тарифа «' . (string) $definition['title'] . '».' );
			}
			if ( ! $status_ok ) {
				$add_issue( 'tariff_' . $code . '_active', 'Тариф «' . (string) $definition['title'] . '» не активен.' );
			}
		}

		$fintech_status = function_exists( 'crm_fintech_get_configuration_status' )
			? crm_fintech_get_configuration_status( $company_id )
			: [ 'is_configured' => false, 'missing_fields' => [] ];
		$fintech_ok = ! empty( $fintech_status['is_configured'] );
		$set_check(
			'fintech_provider',
			$fintech_ok,
			'Fintech provider настроен',
			[
				'provider'       => (string) ( $fintech_status['provider'] ?? '' ),
				'provider_label' => (string) ( $fintech_status['provider_label'] ?? '' ),
			]
		);
		if ( ! $fintech_ok ) {
			$add_issue( 'fintech_provider', 'Fintech provider компании не настроен.' );
		}

		$texts_raw = trim( (string) crm_get_setting( 'telegram_channels_texts_json', $company_id, '' ) );
		$texts_ok  = $texts_raw === '' || is_array( json_decode( $texts_raw, true ) );
		$set_check( 'texts_json', $texts_ok, 'Тексты модуля валидны или доступны дефолты' );
		if ( ! $texts_ok ) {
			$add_issue( 'texts_json', 'telegram_channels_texts_json содержит невалидный JSON.' );
		}

		$status = [
			'company_id'      => $company_id,
			'is_ready'        => empty( $issues ),
			'is_enabled'      => $module_enabled,
			'channel_id'      => $channel ? (int) $channel->id : 0,
			'public_blocked'  => ! empty( $issues ),
			'issues'          => $issues,
			'checks'          => $checks,
			'fintech_status'  => $fintech_status,
		];

		if ( $log_failure && ! $status['is_ready'] ) {
			crm_log_entity(
				'telegram_channels.readiness_failed',
				'settings',
				'readiness_check',
				'Модуль Telegram-каналы включён, но ещё не готов к публичному flow.',
				'company',
				$company_id,
				[
					'org_id'  => $company_id,
					'context' => [
						'issue_ids' => array_map(
							static fn( array $issue ): string => (string) ( $issue['id'] ?? '' ),
							$issues
						),
					],
				]
			);
		}

		return $status;
	}
}
