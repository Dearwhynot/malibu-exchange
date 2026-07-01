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
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_default_settings' ) ) {
	function crm_telegram_channels_default_settings(): array {
		return [
			'module_telegram_channels_enabled'          => '0',
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

if ( ! function_exists( 'crm_telegram_channels_column_exists' ) ) {
	function crm_telegram_channels_column_exists( string $table, string $column ): bool {
		global $wpdb;

		$table  = preg_replace( '/[^a-zA-Z0-9_]/', '', $table );
		$column = preg_replace( '/[^a-zA-Z0-9_]/', '', $column );
		if ( $table === '' || $column === '' ) {
			return false;
		}

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
	}
}

if ( ! function_exists( 'crm_telegram_channels_random_hex' ) ) {
	function crm_telegram_channels_random_hex( int $bytes = 16 ): string {
		try {
			return bin2hex( random_bytes( max( 8, $bytes ) ) );
		} catch ( Exception $e ) {
			return strtolower( wp_generate_password( max( 16, $bytes * 2 ), false, false ) );
		}
	}
}

if ( ! function_exists( 'crm_telegram_channels_normalize_bot_id' ) ) {
	function crm_telegram_channels_normalize_bot_id( string $bot_id ): string {
		$bot_id = trim( $bot_id );
		return preg_match( '/^\d{5,}$/', $bot_id ) ? $bot_id : '';
	}
}

if ( ! function_exists( 'crm_telegram_channels_extract_bot_id_from_token' ) ) {
	function crm_telegram_channels_extract_bot_id_from_token( string $bot_token ): string {
		$bot_token = trim( $bot_token );
		if ( preg_match( '/^(\d{5,}):[A-Za-z0-9_-]{20,}$/', $bot_token, $matches ) ) {
			return (string) $matches[1];
		}

		return '';
	}
}

if ( ! function_exists( 'crm_telegram_channels_generate_stable_bot_key' ) ) {
	function crm_telegram_channels_generate_stable_bot_key(): string {
		global $wpdb;

		for ( $i = 0; $i < 10; $i++ ) {
			$key = 'tsb_' . crm_telegram_channels_random_hex( 16 );
			if ( ! crm_telegram_channels_table_exists( 'crm_merchant_subscription_bots' ) ) {
				return $key;
			}

			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM crm_merchant_subscription_bots WHERE stable_bot_key = %s LIMIT 1',
					$key
				)
			);
			if ( $exists <= 0 ) {
				return $key;
			}
		}

		return 'tsb_' . crm_telegram_channels_random_hex( 20 );
	}
}

if ( ! function_exists( 'crm_telegram_channels_generate_webhook_secret' ) ) {
	function crm_telegram_channels_generate_webhook_secret(): string {
		return 'tgs_' . crm_telegram_channels_random_hex( 24 );
	}
}

if ( ! function_exists( 'crm_telegram_channels_current_subscription_bot' ) ) {
	function crm_telegram_channels_current_subscription_bot(): ?object {
		$bot = $GLOBALS['CRM_TELEGRAM_CHANNELS_CALLBACK_BOT'] ?? null;
		return is_object( $bot ) ? $bot : null;
	}
}

if ( ! function_exists( 'crm_telegram_channels_current_subscription_merchant_id' ) ) {
	function crm_telegram_channels_current_subscription_merchant_id(): int {
		$merchant_id = (int) ( $GLOBALS['CRM_TELEGRAM_CHANNELS_CALLBACK_MERCHANT_ID'] ?? 0 );
		if ( $merchant_id > 0 ) {
			return $merchant_id;
		}

		$bot = crm_telegram_channels_current_subscription_bot();
		return $bot ? (int) ( $bot->merchant_id ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'crm_telegram_channels_effective_subscription_merchant_id' ) ) {
	function crm_telegram_channels_effective_subscription_merchant_id( int $merchant_id = 0 ): int {
		return $merchant_id > 0 ? $merchant_id : crm_telegram_channels_current_subscription_merchant_id();
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_subscription_bot_by_key' ) ) {
	function crm_telegram_channels_get_subscription_bot_by_key( string $stable_bot_key ): ?object {
		global $wpdb;

		$stable_bot_key = trim( $stable_bot_key );
		if ( $stable_bot_key === '' || ! crm_telegram_channels_table_exists( 'crm_merchant_subscription_bots' ) ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM crm_merchant_subscription_bots WHERE stable_bot_key = %s LIMIT 1',
				$stable_bot_key
			)
		) ?: null;
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_merchant_subscription_bot' ) ) {
	function crm_telegram_channels_get_merchant_subscription_bot( int $company_id, int $merchant_id, bool $create = false ): ?object {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 || ! crm_telegram_channels_table_exists( 'crm_merchant_subscription_bots' ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM crm_merchant_subscription_bots WHERE company_id = %d AND merchant_id = %d LIMIT 1',
				$company_id,
				$merchant_id
			)
		);
		if ( $row || ! $create ) {
			return $row ?: null;
		}

		if ( function_exists( 'crm_telegram_channels_validate_merchant_profile_access' ) ) {
			$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
			if ( is_wp_error( $merchant ) ) {
				return null;
			}
		}

		$stable_bot_key = crm_telegram_channels_generate_stable_bot_key();
		$webhook_secret = crm_telegram_channels_generate_webhook_secret();
		$inserted       = $wpdb->insert(
			'crm_merchant_subscription_bots',
			[
				'company_id'       => $company_id,
				'merchant_id'      => $merchant_id,
				'stable_bot_key'   => $stable_bot_key,
				'webhook_secret'   => $webhook_secret,
				'status'           => 'draft',
				'created_at'       => current_time( 'mysql', true ),
				'updated_at'       => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM crm_merchant_subscription_bots WHERE id = %d AND company_id = %d AND merchant_id = %d LIMIT 1',
				(int) $wpdb->insert_id,
				$company_id,
				$merchant_id
			)
		) ?: null;
	}
}

if ( ! function_exists( 'crm_telegram_channels_subscription_callback_url_from_key' ) ) {
	function crm_telegram_channels_subscription_callback_url_from_key( string $stable_bot_key ): string {
		$stable_bot_key = trim( $stable_bot_key );
		if ( $stable_bot_key === '' ) {
			return '';
		}

		return add_query_arg(
			[
				'bot' => $stable_bot_key,
			],
			rest_url( 'malibu-exchange/v1/telegram/subscription-callback' )
		);
	}
}

if ( ! function_exists( 'crm_telegram_channels_subscription_callback_url' ) ) {
	function crm_telegram_channels_subscription_callback_url( int $company_id, int $merchant_id ): string {
		$bot = crm_telegram_channels_get_merchant_subscription_bot( $company_id, $merchant_id, true );
		return $bot ? crm_telegram_channels_subscription_callback_url_from_key( (string) $bot->stable_bot_key ) : '';
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_subscription_bot_token' ) ) {
	function crm_telegram_channels_get_subscription_bot_token( int $company_id, int $merchant_id = 0 ): string {
		$merchant_id = crm_telegram_channels_effective_subscription_merchant_id( $merchant_id );
		$bot         = $merchant_id > 0 ? crm_telegram_channels_get_merchant_subscription_bot( $company_id, $merchant_id, false ) : null;
		if ( ! $bot || (string) ( $bot->status ?? '' ) === 'disabled' ) {
			return '';
		}

		return trim( (string) ( $bot->bot_token ?? '' ) );
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_subscription_bot_username' ) ) {
	function crm_telegram_channels_get_subscription_bot_username( int $company_id, int $merchant_id = 0 ): string {
		$merchant_id = crm_telegram_channels_effective_subscription_merchant_id( $merchant_id );
		$bot         = $merchant_id > 0 ? crm_telegram_channels_get_merchant_subscription_bot( $company_id, $merchant_id, false ) : null;
		if ( ! $bot || (string) ( $bot->status ?? '' ) === 'disabled' ) {
			return '';
		}

		return function_exists( 'crm_telegram_sanitize_bot_username' )
			? crm_telegram_sanitize_bot_username( (string) ( $bot->bot_username ?? '' ) )
			: trim( ltrim( (string) ( $bot->bot_username ?? '' ), '@' ) );
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_merchant_bot_option' ) ) {
	function crm_telegram_channels_get_merchant_bot_option( int $company_id, int $merchant_id, string $key, $default = '' ) {
		$bot = crm_telegram_channels_get_merchant_subscription_bot( $company_id, $merchant_id, false );
		if ( ! $bot ) {
			return $default;
		}

		return isset( $bot->{$key} ) && $bot->{$key} !== null ? $bot->{$key} : $default;
	}
}

if ( ! function_exists( 'crm_telegram_channels_trim_limit' ) ) {
	function crm_telegram_channels_trim_limit( string $value, int $limit ): string {
		$value = trim( wp_strip_all_tags( $value ) );
		if ( $value === '' || $limit <= 0 ) {
			return '';
		}

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $limit, 'UTF-8' );
		}

		return substr( $value, 0, $limit );
	}
}

if ( ! function_exists( 'crm_telegram_channels_identity_language_options' ) ) {
	function crm_telegram_channels_identity_language_options(): array {
		return [
			''   => 'Все языки',
			'ru' => 'Русский',
			'en' => 'English',
			'th' => 'ไทย',
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_identity_menu_options' ) ) {
	function crm_telegram_channels_identity_menu_options(): array {
		return [
			'commands' => 'Команды бота',
			'default'  => 'По умолчанию Telegram',
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_default_bot_identity' ) ) {
	function crm_telegram_channels_default_bot_identity( int $company_id, int $merchant_id ): array {
		$merchant = crm_telegram_channels_get_merchant( $company_id, $merchant_id );
		$channel  = crm_telegram_channels_get_merchant_channel( $company_id, $merchant_id );

		$name_source = trim( (string) ( $channel->title ?? '' ) );
		if ( $name_source === '' ) {
			$name_source = trim( (string) ( $merchant->name ?? '' ) );
		}
		if ( $name_source === '' ) {
			$name_source = 'Subscription bot';
		}

		return [
			'name'                  => crm_telegram_channels_trim_limit( $name_source, 64 ),
			'short_description'     => crm_telegram_channels_trim_limit( 'Оплата доступа к закрытому Telegram-каналу.', 120 ),
			'description'           => crm_telegram_channels_trim_limit( 'Выберите тариф, оплатите подписку и получите invite-ссылку в закрытый Telegram-канал. Доступ продлевается автоматически после успешной оплаты.', 512 ),
			'language_code'         => '',
			'menu_button'           => 'commands',
			'default_admin_rights'  => true,
			'applied_at'            => '',
			'photo_applied_at'      => '',
			'last_error'            => '',
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_sanitize_bot_identity' ) ) {
	function crm_telegram_channels_sanitize_bot_identity( int $company_id, int $merchant_id, array $data ): array {
		$defaults = crm_telegram_channels_default_bot_identity( $company_id, $merchant_id );
		$name     = crm_telegram_channels_trim_limit( (string) ( $data['identity_name'] ?? $defaults['name'] ), 64 );
		$short    = crm_telegram_channels_trim_limit( (string) ( $data['identity_short_description'] ?? $defaults['short_description'] ), 120 );
		$desc     = crm_telegram_channels_trim_limit( (string) ( $data['identity_description'] ?? $defaults['description'] ), 512 );
		if ( $name === '' ) {
			$name = $defaults['name'];
		}

		$language_code = sanitize_key( (string) ( $data['identity_language_code'] ?? '' ) );
		if ( ! array_key_exists( $language_code, crm_telegram_channels_identity_language_options() ) ) {
			$language_code = '';
		}

		$menu_button = sanitize_key( (string) ( $data['identity_menu_button'] ?? 'commands' ) );
		if ( ! array_key_exists( $menu_button, crm_telegram_channels_identity_menu_options() ) ) {
			$menu_button = 'commands';
		}

		return [
			'name'                 => $name,
			'short_description'    => $short,
			'description'          => $desc,
			'language_code'        => $language_code,
			'menu_button'          => $menu_button,
			'default_admin_rights' => ! empty( $data['identity_default_admin_rights'] ),
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_bot_identity_payload' ) ) {
	function crm_telegram_channels_bot_identity_payload( int $company_id, int $merchant_id, ?object $bot = null ): array {
		$bot      = $bot ?: crm_telegram_channels_get_merchant_subscription_bot( $company_id, $merchant_id, false );
		$defaults = crm_telegram_channels_default_bot_identity( $company_id, $merchant_id );

		$language_code = $bot ? sanitize_key( (string) ( $bot->identity_language_code ?? '' ) ) : '';
		if ( ! array_key_exists( $language_code, crm_telegram_channels_identity_language_options() ) ) {
			$language_code = '';
		}
		$menu_button = $bot ? sanitize_key( (string) ( $bot->identity_menu_button ?? 'commands' ) ) : 'commands';
		if ( ! array_key_exists( $menu_button, crm_telegram_channels_identity_menu_options() ) ) {
			$menu_button = 'commands';
		}

		return [
			'name'                 => $bot && trim( (string) ( $bot->identity_name ?? '' ) ) !== '' ? (string) $bot->identity_name : $defaults['name'],
			'short_description'    => $bot && trim( (string) ( $bot->identity_short_description ?? '' ) ) !== '' ? (string) $bot->identity_short_description : $defaults['short_description'],
			'description'          => $bot && trim( (string) ( $bot->identity_description ?? '' ) ) !== '' ? (string) $bot->identity_description : $defaults['description'],
			'language_code'        => $language_code,
			'menu_button'          => $menu_button,
			'default_admin_rights' => ! $bot || (int) ( $bot->identity_default_admin_rights ?? 1 ) === 1,
			'applied_at'           => $bot ? trim( (string) ( $bot->identity_applied_at ?? '' ) ) : '',
			'photo_applied_at'     => $bot ? trim( (string) ( $bot->identity_photo_applied_at ?? '' ) ) : '',
			'last_error'           => $bot ? trim( (string) ( $bot->identity_last_error ?? '' ) ) : '',
			'promo_image_url'      => $bot && (int) ( $bot->identity_promo_image_attachment_id ?? 0 ) > 0 ? (string) wp_get_attachment_url( (int) $bot->identity_promo_image_attachment_id ) : '',
			'promo_image_uploaded_at' => $bot ? trim( (string) ( $bot->identity_promo_image_uploaded_at ?? '' ) ) : '',
			'language_options'     => crm_telegram_channels_identity_language_options(),
			'menu_options'         => crm_telegram_channels_identity_menu_options(),
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_merchant_subscription_status' ) ) {
	function crm_telegram_channels_merchant_subscription_status( int $company_id, int $merchant_id ): array {
		$bot          = crm_telegram_channels_get_merchant_subscription_bot( $company_id, $merchant_id, true );
		$callback_url = $bot ? crm_telegram_channels_subscription_callback_url_from_key( (string) $bot->stable_bot_key ) : '';
		$missing      = [];

		$bot_username = $bot ? crm_telegram_channels_get_subscription_bot_username( $company_id, $merchant_id ) : '';
		$bot_token    = $bot ? trim( (string) ( $bot->bot_token ?? '' ) ) : '';
		$secret       = $bot ? trim( (string) ( $bot->webhook_secret ?? '' ) ) : '';
		$webhook_url  = $bot ? trim( (string) ( $bot->webhook_url ?? '' ) ) : '';

		if ( $bot_username === '' ) {
			$missing[] = [ 'id' => 'telegram_subscription_bot_username', 'label' => 'Имя бота' ];
		}
		if ( $bot_token === '' ) {
			$missing[] = [ 'id' => 'telegram_subscription_bot_token', 'label' => 'Токен бота' ];
		}
		if ( $secret === '' ) {
			$missing[] = [ 'id' => 'telegram_subscription_webhook_secret', 'label' => 'Webhook secret' ];
		}
		if ( $bot && (string) ( $bot->status ?? '' ) === 'disabled' ) {
			$missing[] = [ 'id' => 'telegram_subscription_bot_status', 'label' => 'Бот отключён' ];
		}

		$webhook_matches = $callback_url !== ''
			&& $webhook_url !== ''
			&& (
				function_exists( 'crm_telegram_urls_equivalent' )
					? crm_telegram_urls_equivalent( $webhook_url, $callback_url )
					: $webhook_url === $callback_url
			);
		$webhook_ready = empty( $missing )
			&& $webhook_matches
			&& $bot
			&& trim( (string) ( $bot->webhook_connected_at ?? '' ) ) !== '';

		return [
			'company_id'           => $company_id,
			'merchant_id'          => $merchant_id,
			'is_configured'        => empty( $missing ),
			'webhook_ready'        => $webhook_ready,
			'subscription_ready'   => empty( $missing ) && $webhook_ready,
			'webhook_matches'      => $webhook_matches,
			'missing_fields'       => $missing,
			'callback_url'         => $callback_url,
			'bot_handle'           => $bot_username !== '' ? '@' . $bot_username : '',
			'bot_username'         => $bot_username,
			'webhook_url'          => $webhook_url,
			'webhook_connected_at' => $bot ? trim( (string) ( $bot->webhook_connected_at ?? '' ) ) : '',
			'webhook_last_error'   => $bot ? trim( (string) ( $bot->webhook_last_error ?? '' ) ) : '',
			'webhook_lock'         => $bot ? ( (int) ( $bot->webhook_lock ?? 0 ) === 1 ) : false,
			'stable_bot_key'       => $bot ? (string) ( $bot->stable_bot_key ?? '' ) : '',
			'status'               => $bot ? (string) ( $bot->status ?? '' ) : 'missing',
			'settings'             => [
				'token_set'         => $bot_token !== '',
				'bot_token_masked'  => $bot_token !== '' ? crm_telegram_channels_mask_token( $bot_token ) : '',
				'bot_username'      => $bot_username,
				'reminders_enabled' => ! $bot || (int) ( $bot->reminders_enabled ?? 1 ) === 1,
				'reminder_days'     => $bot ? (string) max( 1, min( 30, (int) ( $bot->reminder_days ?? 3 ) ) ) : '3',
				'invite_ttl_hours'  => $bot ? (string) max( 1, min( 168, (int) ( $bot->invite_ttl_hours ?? 24 ) ) ) : '24',
			],
			'texts'                => crm_telegram_channels_get_texts( $company_id, $merchant_id ),
			'identity'             => crm_telegram_channels_bot_identity_payload( $company_id, $merchant_id, $bot ),
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_merchant' ) ) {
	function crm_telegram_channels_get_merchant( int $company_id, int $merchant_id ): ?object {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM crm_merchants WHERE id = %d AND company_id = %d LIMIT 1',
				$merchant_id,
				$company_id
			)
		) ?: null;
	}
}

if ( ! function_exists( 'crm_telegram_channels_merchant_feature_enabled' ) ) {
	function crm_telegram_channels_merchant_feature_enabled( int $company_id, int $merchant_id ): bool {
		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return false;
		}

		return function_exists( 'crm_merchant_has_feature_access' )
			&& crm_merchant_has_feature_access( $company_id, $merchant_id, 'telegram_channels' );
	}
}

if ( ! function_exists( 'crm_telegram_channels_validate_merchant_profile_access' ) ) {
	function crm_telegram_channels_validate_merchant_profile_access( int $company_id, int $merchant_id ) {
		$merchant = crm_telegram_channels_get_merchant( $company_id, $merchant_id );
		if ( ! $merchant ) {
			return new WP_Error( 'telegram_channels_merchant_not_found', 'Мерчант не найден в текущей компании.' );
		}

		if ( (string) ( $merchant->status ?? '' ) !== 'active' ) {
			return new WP_Error( 'telegram_channels_merchant_inactive', 'Платные Telegram-каналы доступны только активному мерчанту.' );
		}

		if ( ! crm_telegram_channels_merchant_feature_enabled( $company_id, $merchant_id ) ) {
			return new WP_Error( 'telegram_channels_merchant_feature_disabled', 'Этому мерчанту не выдан доступ к платным Telegram-каналам.' );
		}

		return $merchant;
	}
}

if ( ! function_exists( 'crm_telegram_channels_mode_summary' ) ) {
	function crm_telegram_channels_mode_summary( int $company_id, int $merchant_id = 0 ): array {
		$merchant = $merchant_id > 0 ? crm_telegram_channels_get_merchant( $company_id, $merchant_id ) : null;
		$summary  = function_exists( 'crm_merchant_api_get_company_mode_summary' )
			? crm_merchant_api_get_company_mode_summary( $company_id, $merchant )
			: [];

		$provider_mode = (string) ( $summary['provider_mode'] ?? '' );
		$requested     = strtoupper( trim( (string) ( $summary['requested_amount_currency'] ?? '' ) ) );

		$allowed = [];
		if ( $provider_mode === 'orderAmount' && $requested === 'USDT' ) {
			$allowed = [ 'USDT' ];
		} elseif ( $provider_mode === 'paymentAmount' && $requested === 'RUB' ) {
			$allowed = [ 'RUB' ];
		}

		$summary['allowed_price_currencies'] = $allowed;
		$summary['price_currency']           = $allowed[0] ?? '';

		return $summary;
	}
}

if ( ! function_exists( 'crm_telegram_channels_price_currency_options' ) ) {
	function crm_telegram_channels_price_currency_options( int $company_id, int $merchant_id = 0, string $current_currency = '' ): array {
		$summary = crm_telegram_channels_mode_summary( $company_id, $merchant_id );
		return array_values( array_filter( array_map( 'strval', $summary['allowed_price_currencies'] ?? [] ) ) );
	}
}

if ( ! function_exists( 'crm_telegram_channels_tariff_currency_supported' ) ) {
	function crm_telegram_channels_tariff_currency_supported( int $company_id, int $merchant_id, string $currency ): bool {
		$currency = strtoupper( trim( $currency ) );
		$summary  = crm_telegram_channels_mode_summary( $company_id, $merchant_id );
		$allowed  = array_values( array_filter( array_map( 'strval', $summary['allowed_price_currencies'] ?? [] ) ) );

		return $currency !== '' && in_array( $currency, $allowed, true );
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
		return 0;
	}
}

if ( ! function_exists( 'crm_telegram_channels_ensure_merchant_channel' ) ) {
	function crm_telegram_channels_ensure_merchant_channel( int $company_id, int $merchant_id ): int {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 || ! crm_telegram_channels_table_exists( 'crm_telegram_channels' ) ) {
			return 0;
		}

		$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
		if ( is_wp_error( $merchant ) ) {
			return 0;
		}

		$channel_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM crm_telegram_channels WHERE company_id = %d AND merchant_id = %d LIMIT 1',
				$company_id,
				$merchant_id
			)
		);

		if ( $channel_id > 0 ) {
			return $channel_id;
		}

		$title = trim( (string) ( $merchant->name ?? '' ) );
		if ( $title === '' ) {
			$title = 'Merchant #' . $merchant_id;
		}
		$title .= ' Telegram-канал';

		$current_user_id = (int) get_current_user_id();
		$data = [
			'company_id'         => $company_id,
			'merchant_id'        => $merchant_id,
			'title'              => $title,
			'status'             => 'draft',
			'created_by_user_id' => $current_user_id > 0 ? $current_user_id : null,
		];
		$formats = [ '%d', '%d', '%s', '%s', '%d' ];

		$inserted = $wpdb->insert( 'crm_telegram_channels', $data, $formats );
		if ( $inserted === false ) {
			return 0;
		}

		$channel_id = (int) $wpdb->insert_id;
		crm_log_entity(
			'telegram_channels.merchant_profile_created',
			'telegram_channels',
			'create',
			'Создан профиль Telegram-канала мерчанта.',
			'telegram_channel',
			$channel_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'merchant_id' => $merchant_id,
				],
			]
		);

		return $channel_id;
	}
}

if ( ! function_exists( 'crm_telegram_channels_seed_default_tariffs' ) ) {
	function crm_telegram_channels_seed_default_tariffs( int $company_id, int $channel_id = 0 ): void {
		global $wpdb;

		if ( $company_id <= 0 || ! crm_telegram_channels_table_exists( 'crm_telegram_channel_tariffs' ) ) {
			return;
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
	}
}

if ( ! function_exists( 'crm_telegram_channels_seed_merchant_foundation' ) ) {
	function crm_telegram_channels_seed_merchant_foundation( int $company_id, int $merchant_id ): void {
		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return;
		}

		crm_telegram_channels_seed_company_settings( $company_id );
		$channel_id = crm_telegram_channels_ensure_merchant_channel( $company_id, $merchant_id );
		crm_telegram_channels_seed_default_tariffs( $company_id, $channel_id );
		crm_telegram_channels_get_merchant_subscription_bot( $company_id, $merchant_id, true );
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_company_channel' ) ) {
	function crm_telegram_channels_get_company_channel( int $company_id ): ?object {
		return null;
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_merchant_channel' ) ) {
	function crm_telegram_channels_get_merchant_channel( int $company_id, int $merchant_id ): ?object {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 || ! crm_telegram_channels_table_exists( 'crm_telegram_channels' ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM crm_telegram_channels WHERE company_id = %d AND merchant_id = %d LIMIT 1',
				$company_id,
				$merchant_id
			)
		);

		return $row ?: null;
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_channel_by_id' ) ) {
	function crm_telegram_channels_get_channel_by_id( int $company_id, int $channel_id ): ?object {
		global $wpdb;

		if ( $company_id <= 0 || $channel_id <= 0 || ! crm_telegram_channels_table_exists( 'crm_telegram_channels' ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM crm_telegram_channels WHERE id = %d AND company_id = %d AND merchant_id > 0 LIMIT 1',
				$channel_id,
				$company_id
			)
		);

		return $row ?: null;
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_company_tariffs' ) ) {
	function crm_telegram_channels_get_company_tariffs( int $company_id, int $channel_id = 0 ): array {
		global $wpdb;

		if ( $company_id <= 0 || $channel_id <= 0 || ! crm_telegram_channels_table_exists( 'crm_telegram_channel_tariffs' ) ) {
			return [];
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM crm_telegram_channel_tariffs WHERE company_id = %d AND channel_id = %d ORDER BY sort_order ASC, id ASC',
				$company_id,
				$channel_id
			)
		) ?: [];
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_readiness_status' ) ) {
	function crm_telegram_channels_get_readiness_status( int $company_id, bool $log_failure = false, int $merchant_id = 0 ): array {
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
				'merchant_id' => $merchant_id,
				'is_ready'   => false,
				'is_enabled' => false,
				'issues'     => $issues,
				'checks'     => $checks,
			];

			return $status;
		}

		if ( $merchant_id <= 0 ) {
			$add_issue( 'merchant_id', 'Не выбран мерчант: профиль Telegram-канала настраивается только для конкретного мерчанта.', 'error' );
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

		$merchant = null;
		if ( $merchant_id > 0 ) {
			$merchant_check = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
			if ( is_wp_error( $merchant_check ) ) {
				$add_issue( $merchant_check->get_error_code(), $merchant_check->get_error_message(), 'error' );
				$set_check( 'merchant_access', false, 'Мерчант активен и имеет доступ к Telegram-каналам' );
			} else {
				$merchant = $merchant_check;
				$set_check( 'merchant_access', true, 'Мерчант активен и имеет доступ к Telegram-каналам' );
			}
		} else {
			$set_check( 'merchant_access', false, 'Мерчант активен и имеет доступ к Telegram-каналам' );
		}

		$subscription_status = $merchant_id > 0
			? crm_telegram_channels_merchant_subscription_status( $company_id, $merchant_id )
			: [];
		$token_set = ! empty( $subscription_status['settings']['token_set'] );
		$username = trim( (string) ( $subscription_status['bot_username'] ?? '' ) );
		$set_check( 'subscription_bot_token', $token_set, 'Token subscription bot задан' );
		$set_check( 'subscription_bot_username', $username !== '', 'Username subscription bot задан' );
		if ( ! $token_set ) {
			$add_issue( 'subscription_bot_token', 'Не задан token subscription bot выбранного мерчанта.' );
		}
		if ( $username === '' ) {
			$add_issue( 'subscription_bot_username', 'Не задан username subscription bot выбранного мерчанта.' );
		}

		$webhook_url = trim( (string) ( $subscription_status['webhook_url'] ?? '' ) );
		$webhook_ok  = ! empty( $subscription_status['webhook_ready'] );
		$set_check(
			'subscription_webhook',
			$webhook_ok,
			'Webhook subscription bot подключён',
			[ 'webhook_url_set' => $webhook_url !== '' ]
		);
		if ( ! $webhook_ok ) {
			$add_issue( 'subscription_webhook', 'Webhook subscription bot выбранного мерчанта не подключён.' );
		}

		$channel = $merchant_id > 0 ? crm_telegram_channels_get_merchant_channel( $company_id, $merchant_id ) : null;
		$set_check( 'channel_row', $channel !== null, 'Строка канала мерчанта существует' );
		if ( ! $channel ) {
			$add_issue( 'channel_row', 'Не создан профиль Telegram-канала выбранного мерчанта.' );
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

		$mode_summary = $merchant_id > 0 ? crm_telegram_channels_mode_summary( $company_id, $merchant_id ) : [];
		$allowed_price_currencies = array_values(
			array_filter(
				array_map(
					static fn( $currency ): string => strtoupper( trim( (string) $currency ) ),
					(array) ( $mode_summary['allowed_price_currencies'] ?? [] )
				)
			)
		);
		$mode_ok = ! empty( $allowed_price_currencies );
		$set_check(
			'merchant_payment_mode',
			$mode_ok,
			'Платёжный контур мерчанта поддерживает валюту тарифа',
			[
				'provider_mode'       => (string) ( $mode_summary['provider_mode'] ?? '' ),
				'requested_currency'  => (string) ( $mode_summary['requested_amount_currency'] ?? '' ),
				'allowed_currencies'  => $allowed_price_currencies,
			]
		);
		if ( ! $mode_ok ) {
			$add_issue( 'merchant_payment_mode', 'Для текущего fintech-контура не определена валюта тарифа мерчанта.' );
		}

		$tariffs         = crm_telegram_channels_get_company_tariffs( $company_id, $channel ? (int) $channel->id : 0 );
		$tariffs_by_code = [];
		foreach ( $tariffs as $tariff ) {
			$tariffs_by_code[ (string) $tariff->code ] = $tariff;
		}

		$required_tariffs   = crm_telegram_channels_default_tariffs();
		$active_tariff_count = 0;
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
			$mode_currency_ok = ! $currency_ok || in_array( strtoupper( $currency ), $allowed_price_currencies, true );
			$status_ok   = $status_code === 'active';

			$set_check( 'tariff_' . $code . '_price', $price_ok, 'Цена > 0: ' . (string) $definition['title'] );
			$set_check( 'tariff_' . $code . '_currency', $currency_ok, 'Валюта задана: ' . (string) $definition['title'] );
			$set_check( 'tariff_' . $code . '_currency_mode', $mode_currency_ok, 'Валюта тарифа соответствует fintech-контуру: ' . (string) $definition['title'] );
			$set_check( 'tariff_' . $code . '_active', $status_ok, 'Тариф активен: ' . (string) $definition['title'] );

			if ( $status_ok && $price_ok && $currency_ok && $mode_currency_ok ) {
				$active_tariff_count++;
			}
			if ( $status_ok && ! $price_ok ) {
				$add_issue( 'tariff_' . $code . '_price', 'Цена тарифа «' . (string) $definition['title'] . '» должна быть > 0.' );
			}
			if ( $status_ok && ! $currency_ok ) {
				$add_issue( 'tariff_' . $code . '_currency', 'Не задана валюта тарифа «' . (string) $definition['title'] . '».' );
			}
			if ( $status_ok && $currency_ok && ! $mode_currency_ok ) {
				$add_issue(
					'tariff_' . $code . '_currency_mode',
					'Валюта тарифа «' . (string) $definition['title'] . '» не соответствует fintech-контуру мерчанта. Доступно: ' . ( ! empty( $allowed_price_currencies ) ? implode( ', ', $allowed_price_currencies ) : 'не задано' ) . '.'
				);
			}
		}

		$has_active_tariff = $active_tariff_count > 0;
		$set_check( 'active_tariff', $has_active_tariff, 'Есть хотя бы один активный тариф' );
		if ( ! $has_active_tariff ) {
			$add_issue( 'active_tariff', 'Нужно включить хотя бы один тариф с ценой > 0.' );
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

		$texts_raw = '';
		if ( $merchant_id > 0 ) {
			$texts_bot = crm_telegram_channels_get_merchant_subscription_bot( $company_id, $merchant_id, false );
			$texts_raw = $texts_bot ? trim( (string) ( $texts_bot->texts_json ?? '' ) ) : '';
		}
		$texts_ok  = $texts_raw === '' || is_array( json_decode( $texts_raw, true ) );
		$set_check( 'texts_json', $texts_ok, 'Тексты модуля валидны или доступны дефолты' );
		if ( ! $texts_ok ) {
			$add_issue( 'texts_json', 'Тексты subscription bot выбранного мерчанта содержат невалидный JSON.' );
		}

		$status = [
			'company_id'      => $company_id,
			'merchant_id'     => $merchant_id,
			'is_ready'        => empty( $issues ),
			'is_enabled'      => $module_enabled,
			'channel_id'      => $channel ? (int) $channel->id : 0,
			'public_blocked'  => ! empty( $issues ),
			'issues'          => $issues,
			'checks'          => $checks,
			'fintech_status'  => $fintech_status,
			'mode_summary'    => $mode_summary,
			'merchant'        => $merchant ? [
				'id'       => (int) $merchant->id,
				'name'     => (string) ( $merchant->name ?? '' ),
				'status'   => (string) ( $merchant->status ?? '' ),
				'username' => (string) ( $merchant->telegram_username ?? '' ),
			] : null,
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
						'merchant_id' => $merchant_id,
					],
				]
			);
		}

		return $status;
	}
}

if ( ! function_exists( 'crm_telegram_channels_source_channel' ) ) {
	function crm_telegram_channels_source_channel(): string {
		return 'telegram_channel_subscription';
	}
}

if ( ! function_exists( 'crm_telegram_channels_setting_keys' ) ) {
	function crm_telegram_channels_setting_keys(): array {
		return array_keys( crm_telegram_channels_default_settings() );
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_texts' ) ) {
	function crm_telegram_channels_get_texts( int $company_id, int $merchant_id = 0 ): array {
		$defaults = crm_telegram_channels_default_texts();
		$merchant_id = crm_telegram_channels_effective_subscription_merchant_id( $merchant_id );
		$raw = '';
		if ( $merchant_id > 0 ) {
			$bot = crm_telegram_channels_get_merchant_subscription_bot( $company_id, $merchant_id, false );
			$raw = $bot && isset( $bot->texts_json ) ? trim( (string) $bot->texts_json ) : '';
		}
		$custom   = $raw !== '' ? json_decode( $raw, true ) : [];

		if ( ! is_array( $custom ) ) {
			$custom = [];
		}

		$texts = $defaults;
		foreach ( $defaults as $key => $default ) {
			if ( isset( $custom[ $key ] ) && trim( (string) $custom[ $key ] ) !== '' ) {
				$texts[ $key ] = trim( (string) $custom[ $key ] );
			}
		}

		return $texts;
	}
}

if ( ! function_exists( 'crm_telegram_channels_text' ) ) {
	function crm_telegram_channels_text( int $company_id, string $key, array $vars = [], int $merchant_id = 0 ): string {
		$texts = crm_telegram_channels_get_texts( $company_id, $merchant_id );
		$text  = (string) ( $texts[ $key ] ?? $key );

		foreach ( $vars as $var_key => $value ) {
			$text = str_replace( '{' . $var_key . '}', (string) $value, $text );
		}

		return $text;
	}
}

if ( ! function_exists( 'crm_telegram_channels_command_definitions' ) ) {
	function crm_telegram_channels_command_definitions(): array {
		return [
			[
				'command'     => 'start',
				'description' => 'Открыть подписку',
			],
			[
				'command'     => 'status',
				'description' => 'Моя подписка',
			],
			[
				'command'     => 'tariffs',
				'description' => 'Тарифы',
			],
			[
				'command'     => 'renew',
				'description' => 'Продлить подписку',
			],
			[
				'command'     => 'help',
				'description' => 'Помощь',
			],
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_set_subscription_commands' ) ) {
	function crm_telegram_channels_set_subscription_commands( int $company_id, int $merchant_id = 0 ): array {
		$merchant_id = crm_telegram_channels_effective_subscription_merchant_id( $merchant_id );
		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return [
				'success' => false,
				'message' => 'Команды subscription bot можно устанавливать только в контексте мерчанта.',
			];
		}

		$bot_token = crm_telegram_channels_get_subscription_bot_token( $company_id, $merchant_id );
		if ( $bot_token === '' ) {
			return [
				'success' => false,
				'message' => 'У subscription bot этого мерчанта не заполнен token.',
			];
		}

		$response = function_exists( 'crm_telegram_bot_api_request' )
			? crm_telegram_bot_api_request(
				$bot_token,
				'setMyCommands',
				[
					'commands' => wp_json_encode(
						crm_telegram_channels_command_definitions(),
						JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
					),
				]
			)
			: [ 'ok' => false, 'description' => 'Telegram helper недоступен.' ];

		if ( empty( $response['ok'] ) ) {
			return [
				'success'  => false,
				'message'  => trim( (string) ( $response['description'] ?? 'Не удалось обновить команды subscription bot.' ) ),
				'response' => $response,
			];
		}

		return [
			'success'  => true,
			'message'  => trim( (string) ( $response['description'] ?? 'Команды subscription bot обновлены.' ) ),
			'commands' => crm_telegram_channels_command_definitions(),
			'response' => $response,
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_mask_token' ) ) {
	function crm_telegram_channels_mask_token( string $token ): string {
		$token = trim( $token );
		if ( $token === '' ) {
			return '';
		}

		return substr( $token, 0, 6 ) . '…' . substr( $token, -4 );
	}
}

if ( ! function_exists( 'crm_telegram_channels_update_channel' ) ) {
	function crm_telegram_channels_update_channel( int $company_id, array $data, int $merchant_id ): array {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return [ 'success' => false, 'message' => 'Некорректный company_id или merchant_id.' ];
		}

		$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
		if ( is_wp_error( $merchant ) ) {
			return [ 'success' => false, 'message' => $merchant->get_error_message() ];
		}

		$channel_id = crm_telegram_channels_ensure_merchant_channel( $company_id, $merchant_id );
		if ( $channel_id <= 0 ) {
			return [ 'success' => false, 'message' => 'Не удалось создать профиль канала мерчанта.' ];
		}

		$title = trim( wp_strip_all_tags( (string) ( $data['title'] ?? '' ) ) );
		if ( $title === '' ) {
			$title = trim( (string) ( $merchant->name ?? '' ) );
			$title = ( $title !== '' ? $title : 'Merchant #' . $merchant_id ) . ' Telegram-канал';
		}

		$telegram_channel_id       = trim( (string) ( $data['telegram_channel_id'] ?? '' ) );
		$telegram_channel_username = trim( ltrim( (string) ( $data['telegram_channel_username'] ?? '' ), '@' ) );
		$status                    = sanitize_key( (string) ( $data['status'] ?? 'draft' ) );
		if ( ! in_array( $status, [ 'draft', 'active', 'disabled' ], true ) ) {
			$status = 'draft';
		}

		$wpdb->update(
			'crm_telegram_channels',
			[
				'title'                     => $title,
				'telegram_channel_id'       => $telegram_channel_id !== '' ? $telegram_channel_id : null,
				'telegram_channel_username' => $telegram_channel_username !== '' ? $telegram_channel_username : null,
				'status'                    => $status,
				'updated_at'                => current_time( 'mysql', true ),
			],
			[
				'id'         => $channel_id,
				'company_id' => $company_id,
				'merchant_id' => $merchant_id,
			],
			[ '%s', '%s', '%s', '%s', '%s' ],
			[ '%d', '%d', '%d' ]
		);

		crm_log_entity(
			'telegram_channels.settings_updated',
			'settings',
			'update',
			'Обновлены настройки Telegram-канала.',
			'telegram_channel',
			$channel_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'merchant_id'          => $merchant_id,
					'status'               => $status,
					'telegram_channel_set' => $telegram_channel_id !== '',
					'username_set'         => $telegram_channel_username !== '',
				],
			]
		);

		return [ 'success' => true, 'message' => 'Канал сохранён.', 'channel_id' => $channel_id ];
	}
}

if ( ! function_exists( 'crm_telegram_channels_find_subscription_bot_duplicate' ) ) {
	function crm_telegram_channels_find_subscription_bot_duplicate( int $company_id, int $merchant_id, string $bot_username, string $bot_token, string $telegram_bot_id = '' ): array {
		global $wpdb;

		$result = [
			'has_duplicate' => false,
			'message'       => '',
			'fields'        => [],
		];

		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return $result;
		}

		$bot_username = function_exists( 'crm_telegram_sanitize_bot_username' )
			? crm_telegram_sanitize_bot_username( $bot_username )
			: trim( ltrim( $bot_username, '@' ) );
		$bot_token = trim( $bot_token );
		$telegram_bot_id = crm_telegram_channels_normalize_bot_id( $telegram_bot_id );
		if ( $telegram_bot_id === '' ) {
			$telegram_bot_id = crm_telegram_channels_extract_bot_id_from_token( $bot_token );
		}

		if ( crm_telegram_channels_table_exists( 'crm_merchant_subscription_bots' ) && ( $telegram_bot_id !== '' || $bot_username !== '' || $bot_token !== '' ) ) {
			$where  = [ 'NOT (company_id = %d AND merchant_id = %d)' ];
			$params = [ $company_id, $merchant_id ];
			$or     = [];
			if ( $telegram_bot_id !== '' && crm_telegram_channels_column_exists( 'crm_merchant_subscription_bots', 'telegram_bot_id' ) ) {
				$or[]     = 'telegram_bot_id = %s';
				$params[] = $telegram_bot_id;
			}
			if ( $bot_username !== '' ) {
				$or[]     = 'LOWER(bot_username) = LOWER(%s)';
				$params[] = $bot_username;
			}
			if ( $bot_token !== '' ) {
				$or[]     = 'bot_token = %s';
				$params[] = $bot_token;
			}

			if ( ! empty( $or ) ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT merchant_id, bot_username, bot_token
						 FROM crm_merchant_subscription_bots
						 WHERE ' . implode( ' AND ', $where ) . '
						   AND (' . implode( ' OR ', $or ) . ')
						 LIMIT 1',
						$params
					)
				) ?: [];

				if ( ! empty( $rows ) ) {
					$result['has_duplicate'] = true;
					$result['message']       = 'Этот subscription bot уже используется в другом профиле. У одного Telegram-бота может быть только один webhook.';
					$result['fields'][]      = 'merchant_subscription_bot';
					return $result;
				}
			}
		}

		if ( crm_telegram_channels_table_exists( 'crm_settings' ) ) {
			$token_keys = [
				'telegram_bot_token',
				'telegram_merchant_bot_token',
				'telegram_operator_bot_token',
				'telegram_service_bot_token',
				'telegram_subscription_bot_token',
			];
			$username_keys = [
				'telegram_bot_username',
				'telegram_merchant_bot_username',
				'telegram_operator_bot_username',
				'telegram_service_bot_username',
				'telegram_subscription_bot_username',
			];

			if ( $bot_token !== '' ) {
				$placeholders = implode( ',', array_fill( 0, count( $token_keys ), '%s' ) );
				$settings_hit = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM crm_settings WHERE setting_key IN ({$placeholders}) AND setting_value = %s LIMIT 1",
						array_merge( $token_keys, [ $bot_token ] )
					)
				);
				if ( $settings_hit > 0 ) {
					$result['has_duplicate'] = true;
					$result['message']       = 'Этот token уже используется в другом Telegram-контуре системы.';
					$result['fields'][]      = 'bot_token';
					return $result;
				}
			}

			if ( $bot_username !== '' ) {
				$placeholders = implode( ',', array_fill( 0, count( $username_keys ), '%s' ) );
				$settings_hit = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM crm_settings WHERE setting_key IN ({$placeholders}) AND LOWER(setting_value) = LOWER(%s) LIMIT 1",
						array_merge( $username_keys, [ $bot_username ] )
					)
				);
				if ( $settings_hit > 0 ) {
					$result['has_duplicate'] = true;
					$result['message']       = 'Этот username уже используется в другом Telegram-контуре системы.';
					$result['fields'][]      = 'bot_username';
					return $result;
				}
			}
		}

		return $result;
	}
}

if ( ! function_exists( 'crm_telegram_channels_is_valid_subscription_bot_token' ) ) {
	function crm_telegram_channels_is_valid_subscription_bot_token( string $bot_token ): bool {
		$bot_token = trim( $bot_token );
		return $bot_token !== '' && preg_match( '/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $bot_token ) === 1;
	}
}

if ( ! function_exists( 'crm_telegram_channels_fetch_subscription_bot_username' ) ) {
	function crm_telegram_channels_fetch_subscription_bot_username( string $bot_token ): array {
		$bot_token = trim( $bot_token );
		if ( ! crm_telegram_channels_is_valid_subscription_bot_token( $bot_token ) ) {
			return [
				'success' => false,
				'message' => 'Bot token выглядит некорректно. Вставьте token из BotFather полностью.',
				'fields'  => [ 'telegram_subscription_bot_token' ],
			];
		}
		if ( ! function_exists( 'crm_telegram_bot_api_request' ) ) {
			return [
				'success' => false,
				'message' => 'Telegram API helper недоступен.',
				'fields'  => [ 'telegram_subscription_bot_token' ],
			];
		}

		$get_me = crm_telegram_bot_api_request( $bot_token, 'getMe', [], 'GET' );
		if ( empty( $get_me['ok'] ) || empty( $get_me['result']['username'] ) ) {
			$error_message = trim( (string) ( $get_me['description'] ?? 'Не удалось получить username бота через Telegram getMe.' ) );
			return [
				'success' => false,
				'message' => $error_message !== '' ? $error_message : 'Не удалось получить username бота через Telegram.',
				'fields'  => [ 'telegram_subscription_bot_token' ],
			];
		}

		$username = function_exists( 'crm_telegram_sanitize_bot_username' )
			? crm_telegram_sanitize_bot_username( (string) $get_me['result']['username'] )
			: trim( ltrim( (string) $get_me['result']['username'], '@' ) );
		if ( $username === '' ) {
			return [
				'success' => false,
				'message' => 'Telegram вернул некорректный username бота.',
				'fields'  => [ 'telegram_subscription_bot_token' ],
			];
		}

		return [
			'success'      => true,
			'bot_username' => $username,
			'bot_id'       => crm_telegram_channels_normalize_bot_id( isset( $get_me['result']['id'] ) ? (string) $get_me['result']['id'] : '' ) ?: crm_telegram_channels_extract_bot_id_from_token( $bot_token ),
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_save_settings' ) ) {
	function crm_telegram_channels_save_settings( int $company_id, array $data, int $merchant_id = 0 ): array {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return [ 'success' => false, 'message' => 'Некорректный company_id или merchant_id.' ];
		}

		$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
		if ( is_wp_error( $merchant ) ) {
			return [ 'success' => false, 'message' => $merchant->get_error_message() ];
		}

		$bot = crm_telegram_channels_get_merchant_subscription_bot( $company_id, $merchant_id, true );
		if ( ! $bot ) {
			return [ 'success' => false, 'message' => 'Не удалось создать профиль subscription bot мерчанта.' ];
		}

		$new_token = trim( (string) ( $data['telegram_subscription_bot_token'] ?? '' ) );
		$old_token = trim( (string) ( $bot->bot_token ?? '' ) );
		$token     = $new_token !== '' ? $new_token : $old_token;
		$old_username = function_exists( 'crm_telegram_sanitize_bot_username' )
			? crm_telegram_sanitize_bot_username( (string) ( $bot->bot_username ?? '' ) )
			: trim( ltrim( (string) ( $bot->bot_username ?? '' ), '@' ) );
		$username      = $old_username;
		$token_changed = $new_token !== '' && $old_token !== $new_token;
		$telegram_bot_id = crm_telegram_channels_normalize_bot_id( (string) ( $bot->telegram_bot_id ?? '' ) );
		if ( $telegram_bot_id === '' ) {
			$telegram_bot_id = crm_telegram_channels_extract_bot_id_from_token( $token );
		}

		$validation_errors = [];
		$validation_fields = [];
		if ( $new_token === '' && $old_token === '' ) {
			$validation_errors[] = 'Bot token';
			$validation_fields[] = 'telegram_subscription_bot_token';
		} elseif ( $new_token !== '' && ! crm_telegram_channels_is_valid_subscription_bot_token( $new_token ) ) {
			return [
				'success' => false,
				'message' => 'Bot token выглядит некорректно. Вставьте token из BotFather полностью.',
				'fields'  => [ 'telegram_subscription_bot_token' ],
			];
		}
		if ( ! empty( $validation_errors ) ) {
			return [
				'success' => false,
				'message' => 'Заполните обязательные поля subscription bot: ' . implode( ', ', $validation_errors ) . '.',
				'fields'  => $validation_fields,
			];
		}

		$username_refreshed = false;
		if ( $token !== '' && ( $new_token !== '' || $username === '' ) ) {
			$get_me = crm_telegram_channels_fetch_subscription_bot_username( $token );
			if ( empty( $get_me['success'] ) ) {
				return $get_me;
			}

			$username           = (string) $get_me['bot_username'];
			$telegram_bot_id    = crm_telegram_channels_normalize_bot_id( (string) ( $get_me['bot_id'] ?? '' ) ) ?: $telegram_bot_id;
			$username_refreshed = true;
		}

		if ( $username === '' ) {
			return [
				'success' => false,
				'message' => 'Не удалось определить Bot username по token.',
				'fields'  => [ 'telegram_subscription_bot_token' ],
			];
		}

		$duplicate = crm_telegram_channels_find_subscription_bot_duplicate( $company_id, $merchant_id, $username, $token, $telegram_bot_id );
		if ( ! empty( $duplicate['has_duplicate'] ) ) {
			return [
				'success' => false,
				'message' => (string) ( $duplicate['message'] ?? 'Subscription bot уже используется.' ),
				'fields'  => [ 'telegram_subscription_bot_token' ],
			];
		}

		$reminders_enabled = ! empty( $data['telegram_channels_reminders_enabled'] ) ? 1 : 0;
		$reminder_days     = max( 1, min( 30, (int) ( $data['telegram_channels_reminder_days'] ?? 3 ) ) );
		$invite_ttl_hours  = max( 1, min( 168, (int) ( $data['telegram_channels_invite_ttl_hours'] ?? 24 ) ) );

		if ( $old_token !== '' && $token_changed && function_exists( 'crm_telegram_unregister_webhook' ) ) {
			crm_telegram_unregister_webhook( $old_token, true );
		}

		$webhook_secret = trim( (string) ( $bot->webhook_secret ?? '' ) );
		if ( $webhook_secret === '' || $token_changed ) {
			$webhook_secret = crm_telegram_channels_generate_webhook_secret();
		}

		$update = [
			'bot_username'         => $username !== '' ? $username : null,
			'bot_token'            => $token !== '' ? $token : null,
			'webhook_secret'       => $webhook_secret,
			'reminders_enabled'    => $reminders_enabled,
			'reminder_days'        => $reminder_days,
			'invite_ttl_hours'     => $invite_ttl_hours,
			'status'               => ( $username !== '' && $token !== '' ) ? 'active' : 'draft',
			'updated_at'           => current_time( 'mysql', true ),
		];
		$formats = [ '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ];
		if ( crm_telegram_channels_column_exists( 'crm_merchant_subscription_bots', 'telegram_bot_id' ) ) {
			$update['telegram_bot_id'] = $telegram_bot_id !== '' ? $telegram_bot_id : null;
			$formats[] = '%s';
		}

		if ( $token_changed ) {
			$update['webhook_url']          = null;
			$update['webhook_connected_at'] = null;
			$update['webhook_last_error']   = null;
			$update['webhook_lock']         = 0;
			$formats = array_merge( $formats, [ '%s', '%s', '%s', '%d' ] );
		}

		$updated = $wpdb->update(
			'crm_merchant_subscription_bots',
			$update,
			[
				'id'         => (int) $bot->id,
				'company_id' => $company_id,
				'merchant_id'=> $merchant_id,
			],
			$formats,
			[ '%d', '%d', '%d' ]
		);
		if ( false === $updated ) {
			return [
				'success' => false,
				'message' => 'Не удалось сохранить subscription bot. Проверьте, не используется ли этот Telegram-бот в другом профиле.',
			];
		}

		crm_log_entity(
			'telegram_channels.subscription_bot_settings_updated',
			'settings',
			'update',
			'Обновлены настройки subscription bot мерчанта.',
			'merchant',
			$merchant_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'bot_username'      => $username,
					'telegram_bot_id'   => $telegram_bot_id,
					'bot_token_changed' => $token_changed,
					'bot_token_set'     => $token !== '',
					'reminders_enabled' => $reminders_enabled === 1,
				],
			]
		);

		return [
			'success' => true,
			'message' => $username_refreshed ? 'Настройки сохранены. Username определён по token: @' . $username . '.' : 'Настройки subscription bot мерчанта сохранены.',
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_save_texts' ) ) {
	function crm_telegram_channels_save_texts( int $company_id, int $merchant_id, array $texts_input ): array {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return [ 'success' => false, 'message' => 'Некорректный company_id или merchant_id.' ];
		}

		$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
		if ( is_wp_error( $merchant ) ) {
			return [ 'success' => false, 'message' => $merchant->get_error_message() ];
		}

		$bot = crm_telegram_channels_get_merchant_subscription_bot( $company_id, $merchant_id, true );
		if ( ! $bot ) {
			return [ 'success' => false, 'message' => 'Не удалось создать профиль subscription bot мерчанта.' ];
		}
		if ( ! crm_telegram_channels_column_exists( 'crm_merchant_subscription_bots', 'texts_json' ) ) {
			return [ 'success' => false, 'message' => 'Колонка texts_json ещё не создана. Обновите страницу, чтобы применить миграции.' ];
		}

		$texts = crm_telegram_channels_default_texts();
		foreach ( $texts as $key => $default ) {
			$value = isset( $texts_input[ $key ] ) ? trim( wp_strip_all_tags( (string) $texts_input[ $key ] ) ) : '';
			$texts[ $key ] = $value !== '' ? $value : $default;
		}

		$updated = $wpdb->update(
			'crm_merchant_subscription_bots',
			[
				'texts_json' => (string) wp_json_encode( $texts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at' => current_time( 'mysql', true ),
			],
			[
				'id'          => (int) $bot->id,
				'company_id'  => $company_id,
				'merchant_id' => $merchant_id,
			],
			[ '%s', '%s' ],
			[ '%d', '%d', '%d' ]
		);
		if ( false === $updated ) {
			return [ 'success' => false, 'message' => 'Не удалось сохранить тексты subscription bot.' ];
		}

		crm_log_entity(
			'telegram_channels.subscription_bot_texts_updated',
			'settings',
			'update',
			'Обновлены тексты subscription bot мерчанта.',
			'merchant',
			$merchant_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'text_keys' => array_keys( $texts ),
				],
			]
		);

		return [ 'success' => true, 'message' => 'Тексты subscription bot мерчанта сохранены.' ];
	}
}

if ( ! function_exists( 'crm_telegram_channels_admin_rights_payload' ) ) {
	function crm_telegram_channels_admin_rights_payload(): array {
		return [
			'is_anonymous'           => false,
			'can_manage_chat'        => true,
			'can_delete_messages'    => true,
			'can_manage_video_chats' => false,
			'can_restrict_members'   => true,
			'can_promote_members'    => false,
			'can_change_info'        => false,
			'can_invite_users'       => true,
			'can_post_stories'       => false,
			'can_edit_stories'       => false,
			'can_delete_stories'     => false,
			'can_post_messages'      => false,
			'can_edit_messages'      => false,
			'can_pin_messages'       => false,
			'can_manage_topics'      => false,
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_validate_identity_photo_upload' ) ) {
	function crm_telegram_channels_validate_identity_photo_upload( array $file ) {
		if ( empty( $file ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_NO_FILE ) {
			return [
				'has_file' => false,
			];
		}

		if ( (int) ( $file['error'] ?? UPLOAD_ERR_OK ) !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'telegram_identity_photo_upload_error', 'Файл аватара не загружен.' );
		}

		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		$name     = sanitize_file_name( (string) ( $file['name'] ?? 'bot-profile-photo.jpg' ) );
		$size     = (int) ( $file['size'] ?? 0 );
		if ( $tmp_name === '' || ! is_uploaded_file( $tmp_name ) || ! is_readable( $tmp_name ) ) {
			return new WP_Error( 'telegram_identity_photo_unreadable', 'Файл аватара недоступен для чтения.' );
		}
		if ( $size <= 0 || $size > 5 * 1024 * 1024 ) {
			return new WP_Error( 'telegram_identity_photo_size', 'Аватар должен быть файлом до 5 MB.' );
		}

		$check = wp_check_filetype_and_ext( $tmp_name, $name );
		$mime  = (string) ( $check['type'] ?? '' );
		if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) {
			return new WP_Error( 'telegram_identity_photo_type', 'Аватар должен быть изображением JPG, PNG или WebP.' );
		}

		return [
			'has_file' => true,
			'path'     => $tmp_name,
			'name'     => $name !== '' ? $name : 'bot-profile-photo.jpg',
			'type'     => $mime,
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_store_identity_promo_image_upload' ) ) {
	function crm_telegram_channels_store_identity_promo_image_upload( array $file ) {
		if ( empty( $file ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_NO_FILE ) {
			return [
				'has_file' => false,
			];
		}

		if ( (int) ( $file['error'] ?? UPLOAD_ERR_OK ) !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'telegram_identity_promo_upload_error', 'Файл промо-картинки не загружен.' );
		}

		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		$name     = sanitize_file_name( (string) ( $file['name'] ?? 'bot-promo-image.jpg' ) );
		$size     = (int) ( $file['size'] ?? 0 );
		if ( $tmp_name === '' || ! is_uploaded_file( $tmp_name ) || ! is_readable( $tmp_name ) ) {
			return new WP_Error( 'telegram_identity_promo_unreadable', 'Файл промо-картинки недоступен для чтения.' );
		}
		if ( $size <= 0 || $size > 5 * 1024 * 1024 ) {
			return new WP_Error( 'telegram_identity_promo_size', 'Промо-картинка должна быть файлом до 5 MB.' );
		}

		$check = wp_check_filetype_and_ext( $tmp_name, $name );
		$mime  = (string) ( $check['type'] ?? '' );
		if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) {
			return new WP_Error( 'telegram_identity_promo_type', 'Промо-картинка должна быть изображением JPG, PNG или WebP.' );
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$file_array = [
			'name'     => $name !== '' ? $name : 'bot-promo-image.jpg',
			'type'     => $mime,
			'tmp_name' => $tmp_name,
			'error'    => (int) $file['error'],
			'size'     => $size,
		];
		$attachment_id = media_handle_sideload( $file_array, 0, 'Telegram bot promo image' );
		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error( 'telegram_identity_promo_store_failed', 'Промо-картинка не сохранена: ' . $attachment_id->get_error_message() );
		}

		return [
			'has_file'      => true,
			'attachment_id' => (int) $attachment_id,
			'url'           => (string) wp_get_attachment_url( (int) $attachment_id ),
			'uploaded_at'   => current_time( 'mysql', true ),
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_apply_bot_identity' ) ) {
	function crm_telegram_channels_apply_bot_identity( int $company_id, int $merchant_id, array $data, array $photo_file = [], array $promo_image_file = [] ): array {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return [ 'success' => false, 'message' => 'Некорректный company_id или merchant_id.' ];
		}

		$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
		if ( is_wp_error( $merchant ) ) {
			return [ 'success' => false, 'message' => $merchant->get_error_message() ];
		}

		$bot = crm_telegram_channels_get_merchant_subscription_bot( $company_id, $merchant_id, true );
		if ( ! $bot ) {
			return [ 'success' => false, 'message' => 'Не найден профиль subscription bot мерчанта.' ];
		}

		$token = trim( (string) ( $bot->bot_token ?? '' ) );
		if ( $token === '' ) {
			return [ 'success' => false, 'message' => 'Сначала сохраните token subscription bot выбранного мерчанта.' ];
		}
		if ( ! function_exists( 'crm_telegram_bot_api_request' ) ) {
			return [ 'success' => false, 'message' => 'Telegram API helper недоступен.' ];
		}

		$identity = crm_telegram_channels_sanitize_bot_identity( $company_id, $merchant_id, $data );
		$photo    = crm_telegram_channels_validate_identity_photo_upload( $photo_file );
		if ( is_wp_error( $photo ) ) {
			return [ 'success' => false, 'message' => $photo->get_error_message() ];
		}
		$promo_image_requested = ! empty( $promo_image_file )
			&& (int) ( $promo_image_file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_NO_FILE;
		if ( $promo_image_requested && ! crm_telegram_channels_column_exists( 'crm_merchant_subscription_bots', 'identity_promo_image_attachment_id' ) ) {
			return [ 'success' => false, 'message' => 'Хранилище промо-картинок ещё не готово. Обновите страницу после выполнения миграций.' ];
		}
		$promo_image = crm_telegram_channels_store_identity_promo_image_upload( $promo_image_file );
		if ( is_wp_error( $promo_image ) ) {
			return [ 'success' => false, 'message' => $promo_image->get_error_message() ];
		}

		$steps      = [];
		$last_error = '';
		$language   = (string) $identity['language_code'];
		$with_lang  = static function ( array $payload ) use ( $language ): array {
			if ( $language !== '' ) {
				$payload['language_code'] = $language;
			}
			return $payload;
		};
		$run_step = static function ( string $step, string $method, array $payload ) use ( $token, &$steps, &$last_error ): bool {
			$response = crm_telegram_bot_api_request( $token, $method, $payload );
			$ok       = ! empty( $response['ok'] );
			$message  = trim( (string) ( $response['description'] ?? '' ) );
			$steps[ $step ] = [
				'success' => $ok,
				'message' => $message,
			];
			if ( ! $ok ) {
				$last_error = $message !== '' ? $message : 'Telegram API returned an error.';
			}
			return $ok;
		};

		if ( ! $run_step( 'name', 'setMyName', $with_lang( [ 'name' => $identity['name'] ] ) ) ) {
			goto telegram_identity_failed;
		}
		if ( ! $run_step( 'short_description', 'setMyShortDescription', $with_lang( [ 'short_description' => $identity['short_description'] ] ) ) ) {
			goto telegram_identity_failed;
		}
		if ( ! $run_step( 'description', 'setMyDescription', $with_lang( [ 'description' => $identity['description'] ] ) ) ) {
			goto telegram_identity_failed;
		}

		$commands_result = crm_telegram_channels_set_subscription_commands( $company_id, $merchant_id );
		$steps['commands'] = [
			'success' => ! empty( $commands_result['success'] ),
			'message' => (string) ( $commands_result['message'] ?? '' ),
		];
		if ( empty( $commands_result['success'] ) ) {
			$last_error = trim( (string) ( $commands_result['message'] ?? 'Не удалось обновить команды subscription bot.' ) );
			goto telegram_identity_failed;
		}

		$menu_button = [ 'type' => $identity['menu_button'] === 'default' ? 'default' : 'commands' ];
		if ( ! $run_step( 'menu_button', 'setChatMenuButton', [ 'menu_button' => wp_json_encode( $menu_button, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ] ) ) {
			goto telegram_identity_failed;
		}

		if ( ! empty( $identity['default_admin_rights'] ) ) {
			$rights = crm_telegram_channels_admin_rights_payload();
			if ( ! $run_step(
				'default_admin_rights',
				'setMyDefaultAdministratorRights',
				[
					'rights'       => wp_json_encode( $rights, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'for_channels' => 'true',
				]
			) ) {
				goto telegram_identity_failed;
			}
		} else {
			$steps['default_admin_rights'] = [
				'success' => true,
				'message' => 'Skipped by settings.',
			];
		}

		$photo_applied_at = null;
		if ( ! empty( $photo['has_file'] ) ) {
			if ( ! function_exists( 'crm_telegram_bot_api_multipart_request' ) ) {
				$last_error = 'Telegram multipart helper недоступен.';
				goto telegram_identity_failed;
			}
			$photo_response = crm_telegram_bot_api_multipart_request(
				$token,
				'setMyProfilePhoto',
				[
					'photo' => wp_json_encode(
						[
							'type'  => 'static',
							'photo' => 'attach://profile_photo',
						],
						JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
					),
				],
				[
					'profile_photo' => [
						'path' => (string) $photo['path'],
						'name' => (string) $photo['name'],
						'type' => (string) $photo['type'],
					],
				]
			);
			$photo_ok = ! empty( $photo_response['ok'] );
			$steps['profile_photo'] = [
				'success' => $photo_ok,
				'message' => trim( (string) ( $photo_response['description'] ?? '' ) ),
			];
			if ( ! $photo_ok ) {
				$last_error = trim( (string) ( $photo_response['description'] ?? 'Не удалось обновить аватар bot.' ) );
				goto telegram_identity_failed;
			}
			$photo_applied_at = current_time( 'mysql', true );
		}

		$update = [
			'identity_name'                 => $identity['name'] !== '' ? $identity['name'] : null,
			'identity_short_description'    => $identity['short_description'] !== '' ? $identity['short_description'] : null,
			'identity_description'          => $identity['description'] !== '' ? $identity['description'] : null,
			'identity_language_code'        => $identity['language_code'] !== '' ? $identity['language_code'] : null,
			'identity_menu_button'          => $identity['menu_button'],
			'identity_default_admin_rights' => ! empty( $identity['default_admin_rights'] ) ? 1 : 0,
			'identity_applied_at'           => current_time( 'mysql', true ),
			'identity_last_error'           => null,
			'updated_at'                    => current_time( 'mysql', true ),
		];
		$formats = [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ];
		if ( $photo_applied_at !== null ) {
			$update['identity_photo_applied_at'] = $photo_applied_at;
			$formats[] = '%s';
		}
		if ( ! empty( $promo_image['has_file'] ) && crm_telegram_channels_column_exists( 'crm_merchant_subscription_bots', 'identity_promo_image_attachment_id' ) ) {
			$update['identity_promo_image_attachment_id'] = (int) $promo_image['attachment_id'];
			$update['identity_promo_image_uploaded_at']   = (string) $promo_image['uploaded_at'];
			$formats[] = '%d';
			$formats[] = '%s';
			$steps['promo_image'] = [
				'success' => true,
				'message' => 'Saved for manual setup in BotFather.',
			];
		}

		$wpdb->update(
			'crm_merchant_subscription_bots',
			$update,
			[
				'id'          => (int) $bot->id,
				'company_id'  => $company_id,
				'merchant_id' => $merchant_id,
			],
			$formats,
			[ '%d', '%d', '%d' ]
		);

		crm_log_entity(
			'telegram_channels.subscription_bot_identity_applied',
			'settings',
			'update',
			'Оформление subscription bot мерчанта применено.',
			'merchant',
			$merchant_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'language_code'       => $identity['language_code'],
					'menu_button'         => $identity['menu_button'],
					'profile_photo_sent'  => ! empty( $photo['has_file'] ),
					'promo_image_saved'   => ! empty( $promo_image['has_file'] ),
					'default_admin_rights'=> ! empty( $identity['default_admin_rights'] ),
				],
			]
		);

		return [
			'success' => true,
			'message' => ! empty( $promo_image['has_file'] )
				? 'Оформление применено в Telegram. Промо-картинка сохранена для ручной установки через BotFather.'
				: 'Оформление subscription bot применено в Telegram.',
			'steps'   => $steps,
		];

		telegram_identity_failed:
		if ( ! empty( $promo_image['has_file'] ) && ! empty( $promo_image['attachment_id'] ) && function_exists( 'wp_delete_attachment' ) ) {
			wp_delete_attachment( (int) $promo_image['attachment_id'], true );
		}

		$wpdb->update(
			'crm_merchant_subscription_bots',
			[
				'identity_last_error' => $last_error,
				'updated_at'          => current_time( 'mysql', true ),
			],
			[
				'id'          => (int) $bot->id,
				'company_id'  => $company_id,
				'merchant_id' => $merchant_id,
			],
			[ '%s', '%s' ],
			[ '%d', '%d', '%d' ]
		);

		crm_log_entity(
			'telegram_channels.subscription_bot_identity_failed',
			'settings',
			'error',
			'Не удалось применить оформление subscription bot мерчанта.',
			'merchant',
			$merchant_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'error' => $last_error,
					'steps' => $steps,
				],
			]
		);

		return [
			'success' => false,
			'message' => $last_error !== '' ? $last_error : 'Не удалось применить оформление subscription bot.',
			'steps'   => $steps,
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_connect_merchant_webhook' ) ) {
	function crm_telegram_channels_connect_merchant_webhook( int $company_id, int $merchant_id ): array {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return [ 'success' => false, 'message' => 'Некорректный company_id или merchant_id.' ];
		}

		$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
		if ( is_wp_error( $merchant ) ) {
			return [ 'success' => false, 'message' => $merchant->get_error_message() ];
		}

		$bot = crm_telegram_channels_get_merchant_subscription_bot( $company_id, $merchant_id, true );
		if ( ! $bot ) {
			return [ 'success' => false, 'message' => 'Не найден профиль subscription bot мерчанта.' ];
		}

		$bot_token    = trim( (string) ( $bot->bot_token ?? '' ) );
		$bot_username = function_exists( 'crm_telegram_sanitize_bot_username' )
			? crm_telegram_sanitize_bot_username( (string) ( $bot->bot_username ?? '' ) )
			: trim( ltrim( (string) ( $bot->bot_username ?? '' ), '@' ) );
		if ( $bot_token === '' || $bot_username === '' ) {
			return [ 'success' => false, 'message' => 'Сначала заполните username и token subscription bot выбранного мерчанта.' ];
		}

		if ( ! function_exists( 'crm_telegram_bot_api_request' ) ) {
			return [ 'success' => false, 'message' => 'Telegram API helper недоступен.' ];
		}

		$get_me = crm_telegram_bot_api_request( $bot_token, 'getMe', [], 'GET' );
		if ( empty( $get_me['ok'] ) || empty( $get_me['result']['username'] ) ) {
			$error_message = trim( (string) ( $get_me['description'] ?? 'Не удалось проверить subscription bot через getMe.' ) );
			return [
				'success'  => false,
				'message'  => $error_message !== '' ? $error_message : 'Не удалось проверить Telegram-бота.',
				'response' => $get_me,
			];
		}

		$api_username = function_exists( 'crm_telegram_sanitize_bot_username' )
			? crm_telegram_sanitize_bot_username( (string) $get_me['result']['username'] )
			: trim( ltrim( (string) $get_me['result']['username'], '@' ) );
		if ( $api_username === '' ) {
			return [ 'success' => false, 'message' => 'Telegram вернул некорректный username бота.' ];
		}
		$telegram_bot_id = crm_telegram_channels_normalize_bot_id( isset( $get_me['result']['id'] ) ? (string) $get_me['result']['id'] : '' ) ?: crm_telegram_channels_extract_bot_id_from_token( $bot_token );

		$duplicate = crm_telegram_channels_find_subscription_bot_duplicate( $company_id, $merchant_id, $api_username, $bot_token, $telegram_bot_id );
		if ( ! empty( $duplicate['has_duplicate'] ) ) {
			return [
				'success' => false,
				'message' => (string) ( $duplicate['message'] ?? 'Subscription bot уже используется.' ),
			];
		}

		$webhook_secret = trim( (string) ( $bot->webhook_secret ?? '' ) );
		if ( $webhook_secret === '' ) {
			$webhook_secret = crm_telegram_channels_generate_webhook_secret();
		}

		$callback_url = crm_telegram_channels_subscription_callback_url_from_key( (string) $bot->stable_bot_key );
		if ( $callback_url === '' ) {
			return [ 'success' => false, 'message' => 'Не удалось сформировать callback URL.' ];
		}

		$response = crm_telegram_bot_api_request(
			$bot_token,
			'setWebhook',
			[
				'url'          => $callback_url,
				'secret_token' => $webhook_secret,
			]
		);

		if ( empty( $response['ok'] ) ) {
			$error_message = trim( (string) ( $response['description'] ?? 'Telegram API returned an error.' ) );
			$wpdb->update(
				'crm_merchant_subscription_bots',
				[
					'webhook_last_error' => $error_message,
					'updated_at'         => current_time( 'mysql', true ),
				],
				[ 'id' => (int) $bot->id, 'company_id' => $company_id, 'merchant_id' => $merchant_id ],
				[ '%s', '%s' ],
				[ '%d', '%d', '%d' ]
			);

			return [
				'success'  => false,
				'message'  => $error_message !== '' ? $error_message : 'Не удалось зарегистрировать callback в Telegram.',
				'response' => $response,
			];
		}

		$update = [
			'bot_username'         => $api_username,
			'webhook_secret'       => $webhook_secret,
			'webhook_url'          => $callback_url,
			'webhook_connected_at' => current_time( 'mysql', true ),
			'webhook_last_error'   => null,
			'webhook_lock'         => 1,
			'status'               => 'active',
			'updated_at'           => current_time( 'mysql', true ),
		];
		$formats = [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ];
		if ( crm_telegram_channels_column_exists( 'crm_merchant_subscription_bots', 'telegram_bot_id' ) ) {
			$update['telegram_bot_id'] = $telegram_bot_id !== '' ? $telegram_bot_id : null;
			$formats[] = '%s';
		}

		$updated = $wpdb->update(
			'crm_merchant_subscription_bots',
			$update,
			[ 'id' => (int) $bot->id, 'company_id' => $company_id, 'merchant_id' => $merchant_id ],
			$formats,
			[ '%d', '%d', '%d' ]
		);
		if ( false === $updated ) {
			return [
				'success' => false,
				'message' => 'Webhook зарегистрирован в Telegram, но локально subscription bot не сохранился. Проверьте, не используется ли этот бот в другом профиле, и подключите callback заново.',
			];
		}

		$commands_result = crm_telegram_channels_set_subscription_commands( $company_id, $merchant_id );

		crm_log_entity(
			'telegram_channels.subscription_bot_webhook_connected',
			'settings',
			'update',
			'Подключён webhook subscription bot мерчанта.',
			'merchant',
			$merchant_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'bot_username'    => $api_username,
					'telegram_bot_id' => $telegram_bot_id,
					'callback_url'    => $callback_url,
					'commands_ok'     => ! empty( $commands_result['success'] ),
				],
			]
		);

		return [
			'success'      => true,
			'message'      => trim( (string) ( $response['description'] ?? 'Webhook was set.' ) ),
			'callback_url' => $callback_url,
			'post_connect' => $commands_result,
			'response'     => $response,
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_update_tariffs' ) ) {
	function crm_telegram_channels_update_tariffs( int $company_id, array $input, int $merchant_id ): array {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return [ 'success' => false, 'message' => 'Некорректный company_id или merchant_id.' ];
		}

		$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
		if ( is_wp_error( $merchant ) ) {
			return [ 'success' => false, 'message' => $merchant->get_error_message() ];
		}

		$channel_id = crm_telegram_channels_ensure_merchant_channel( $company_id, $merchant_id );
		crm_telegram_channels_seed_default_tariffs( $company_id, $channel_id );
		$definitions = crm_telegram_channels_default_tariffs();
		$updated     = [];
		$mode_summary = crm_telegram_channels_mode_summary( $company_id, $merchant_id );
		$allowed_currencies = array_values( array_filter( array_map( 'strval', $mode_summary['allowed_price_currencies'] ?? [] ) ) );
		if ( empty( $allowed_currencies ) ) {
			return [ 'success' => false, 'message' => 'Для текущего fintech-контура не определена валюта тарифа мерчанта.' ];
		}

		foreach ( $definitions as $code => $definition ) {
			$row_input = is_array( $input[ $code ] ?? null ) ? $input[ $code ] : [];
			$price_raw = str_replace( ',', '.', trim( (string) ( $row_input['price_amount'] ?? '0' ) ) );
			$price     = is_numeric( $price_raw ) ? max( 0, round( (float) $price_raw, 2 ) ) : 0.0;
			$currency  = strtoupper( trim( (string) ( $row_input['price_currency'] ?? 'RUB' ) ) );
			$status    = ! empty( $row_input['active'] ) ? 'active' : 'disabled';

			if ( ! in_array( $currency, [ 'RUB', 'USDT' ], true ) ) {
				$currency = (string) $allowed_currencies[0];
			}

			if ( ! in_array( $currency, $allowed_currencies, true ) ) {
				if ( $status === 'active' ) {
					return [
						'success' => false,
						'message' => sprintf(
							'Валюта тарифа «%s» не соответствует fintech-контуру мерчанта. Доступно: %s.',
							(string) $definition['title'],
							implode( ', ', $allowed_currencies )
						),
					];
				}
				$currency = (string) $allowed_currencies[0];
			}

			$wpdb->update(
				'crm_telegram_channel_tariffs',
				[
					'title'          => (string) $definition['title'],
					'duration_days'  => (int) $definition['duration_days'],
					'price_amount'   => $price,
					'price_currency' => $currency,
					'status'         => $status,
					'sort_order'     => (int) $definition['sort_order'],
					'updated_at'     => current_time( 'mysql', true ),
				],
				[
					'company_id' => $company_id,
					'channel_id' => $channel_id,
					'code'       => (string) $code,
				],
				[ '%s', '%d', '%f', '%s', '%s', '%d', '%s' ],
				[ '%d', '%d', '%s' ]
			);

			$updated[ $code ] = [
				'price_amount'   => $price,
				'price_currency' => $currency,
				'status'         => $status,
			];
		}

		crm_log_entity(
			'telegram_channels.tariffs_updated',
			'settings',
			'update',
			'Обновлены тарифы Telegram-канала.',
			'telegram_channel',
			$channel_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'merchant_id' => $merchant_id,
					'tariffs' => $updated,
					'allowed_currencies' => $allowed_currencies,
				],
			]
		);

		return [ 'success' => true, 'message' => 'Тарифы сохранены.' ];
	}
}

if ( ! function_exists( 'crm_telegram_channels_check_bot_admin' ) ) {
	function crm_telegram_channels_check_bot_admin( int $company_id, int $merchant_id ): array {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return [ 'success' => false, 'message' => 'Некорректный company_id или merchant_id.' ];
		}

		$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
		if ( is_wp_error( $merchant ) ) {
			return [ 'success' => false, 'message' => $merchant->get_error_message() ];
		}

		$channel = crm_telegram_channels_get_merchant_channel( $company_id, $merchant_id );
		if ( ! $channel ) {
			return [ 'success' => false, 'message' => 'Профиль канала мерчанта не создан.' ];
		}

		$token = crm_telegram_channels_get_subscription_bot_token( $company_id, $merchant_id );
		if ( $token === '' ) {
			return [ 'success' => false, 'message' => 'Не задан token subscription bot выбранного мерчанта.' ];
		}

		$chat_id = trim( (string) ( $channel->telegram_channel_id ?? '' ) );
		if ( $chat_id === '' && ! empty( $channel->telegram_channel_username ) ) {
			$chat_id = '@' . ltrim( (string) $channel->telegram_channel_username, '@' );
		}
		if ( $chat_id === '' ) {
			return [ 'success' => false, 'message' => 'Не задан Telegram channel id или username.' ];
		}

		$response = function_exists( 'crm_telegram_bot_api_request' )
			? crm_telegram_bot_api_request( $token, 'getMe', [], 'GET' )
			: [ 'ok' => false, 'description' => 'Telegram helper недоступен.' ];
		if ( empty( $response['ok'] ) || empty( $response['result']['id'] ) ) {
			$message = trim( (string) ( $response['description'] ?? 'Не удалось проверить subscription bot.' ) );
			return [ 'success' => false, 'message' => $message !== '' ? $message : 'Не удалось проверить subscription bot.' ];
		}

		$bot_id = (string) $response['result']['id'];
		$member = crm_telegram_bot_api_request(
			$token,
			'getChatMember',
			[
				'chat_id' => $chat_id,
				'user_id' => $bot_id,
			]
		);

		$member_result = is_array( $member['result'] ?? null ) ? $member['result'] : [];
		$member_status = (string) ( $member_result['status'] ?? '' );
		$is_admin      = ! empty( $member['ok'] ) && in_array( $member_status, [ 'administrator', 'creator' ], true );
		$is_creator    = $member_status === 'creator';
		$can_invite    = $is_creator || ! empty( $member_result['can_invite_users'] );
		$can_restrict  = $is_creator || ! empty( $member_result['can_restrict_members'] );
		$missing       = [];
		if ( $is_admin && ! $can_invite ) {
			$missing[] = 'создавать invite-ссылки';
		}
		if ( $is_admin && ! $can_restrict ) {
			$missing[] = 'удалять истёкших подписчиков';
		}

		$ok     = $is_admin && $can_invite && $can_restrict;
		$status = $ok ? 'administrator' : 'failed';
		$error  = $ok ? '' : trim( (string) ( $member['description'] ?? 'Bot is not an administrator.' ) );
		if ( $is_admin && ! $ok && ! empty( $missing ) ) {
			$error = 'Subscription bot является администратором, но ему не хватает прав: ' . implode( ', ', $missing ) . '.';
		}

		$wpdb->update(
			'crm_telegram_channels',
			[
				'bot_admin_checked_at'   => current_time( 'mysql', true ),
				'bot_admin_check_status' => $status,
				'bot_admin_check_error'  => $error !== '' ? $error : null,
				'updated_at'             => current_time( 'mysql', true ),
			],
			[
				'id'         => (int) $channel->id,
				'company_id' => $company_id,
				'merchant_id' => $merchant_id,
			],
			[ '%s', '%s', '%s', '%s' ],
			[ '%d', '%d', '%d' ]
		);

		return [
			'success' => $ok,
			'message' => $ok ? 'Subscription bot является администратором канала и имеет нужные права.' : $error,
			'status'  => $status,
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_setup_bot_rights' ) ) {
	function crm_telegram_channels_setup_bot_rights(): array {
		return [
			'can_manage_chat'        => true,
			'can_delete_messages'    => false,
			'can_manage_video_chats' => false,
			'can_restrict_members'   => true,
			'can_promote_members'    => false,
			'can_change_info'        => false,
			'can_invite_users'       => true,
			'can_post_messages'      => false,
			'can_edit_messages'      => false,
			'can_pin_messages'       => false,
			'can_manage_topics'      => false,
			'can_post_stories'       => false,
			'can_edit_stories'       => false,
			'can_delete_stories'     => false,
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_setup_user_rights' ) ) {
	function crm_telegram_channels_setup_user_rights(): array {
		$rights = crm_telegram_channels_setup_bot_rights();
		$rights['can_promote_members'] = true;

		return $rights;
	}
}

if ( ! function_exists( 'crm_telegram_channels_create_channel_setup_session' ) ) {
	function crm_telegram_channels_create_channel_setup_session( int $company_id, int $merchant_id, int $requested_by_user_id = 0 ): array {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return [ 'success' => false, 'message' => 'Некорректный company_id или merchant_id.' ];
		}

		if ( ! crm_telegram_channels_table_exists( 'crm_telegram_channel_setup_sessions' ) ) {
			return [ 'success' => false, 'message' => 'Таблица setup-сессий ещё не создана. Обновите страницу после миграции.' ];
		}

		$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
		if ( is_wp_error( $merchant ) ) {
			return [ 'success' => false, 'message' => $merchant->get_error_message() ];
		}

		crm_telegram_channels_seed_merchant_foundation( $company_id, $merchant_id );

		$bot_username = crm_telegram_channels_get_subscription_bot_username( $company_id, $merchant_id );
		$bot_token    = crm_telegram_channels_get_subscription_bot_token( $company_id, $merchant_id );
		if ( $bot_username === '' || $bot_token === '' ) {
			return [ 'success' => false, 'message' => 'Сначала заполните username и token бота подписок.' ];
		}

		$telegram_status = crm_telegram_channels_merchant_subscription_status( $company_id, $merchant_id );
		if ( empty( $telegram_status['webhook_ready'] ) ) {
			return [ 'success' => false, 'message' => 'Сначала подключите callback бота подписок.' ];
		}

		$setup_token = '';
		for ( $i = 0; $i < 5; $i++ ) {
			$setup_token = 'chsetup_' . bin2hex( random_bytes( 10 ) );
			$exists      = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM crm_telegram_channel_setup_sessions WHERE setup_token = %s LIMIT 1', $setup_token ) );
			if ( $exists <= 0 ) {
				break;
			}
		}

		if ( $setup_token === '' ) {
			return [ 'success' => false, 'message' => 'Не удалось создать setup token.' ];
		}

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + 30 * MINUTE_IN_SECONDS );
		$inserted   = $wpdb->insert(
			'crm_telegram_channel_setup_sessions',
			[
				'company_id'           => $company_id,
				'merchant_id'          => $merchant_id,
				'setup_token'          => $setup_token,
				'status'               => 'new',
				'requested_by_user_id' => $requested_by_user_id > 0 ? $requested_by_user_id : null,
				'created_at'           => current_time( 'mysql', true ),
				'expires_at'           => $expires_at,
				'updated_at'           => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);

		if ( $inserted === false ) {
			return [ 'success' => false, 'message' => 'Не удалось создать setup-сессию.' ];
		}

		$setup_url = 'https://t.me/' . ltrim( $bot_username, '@' ) . '?start=' . rawurlencode( $setup_token );

		crm_log_entity(
			'telegram_channels.channel_setup_created',
			'telegram_channels',
			'create',
			'Создана setup-ссылка подключения Telegram-канала.',
			'telegram_channel_setup',
			(int) $wpdb->insert_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'merchant_id' => $merchant_id,
					'expires_at' => $expires_at,
				],
			]
		);

		return [
			'success'    => true,
			'message'    => 'Setup-ссылка создана.',
			'setup_url'  => $setup_url,
			'expires_at' => $expires_at,
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_handle_channel_setup_start' ) ) {
	function crm_telegram_channels_handle_channel_setup_start( int $company_id, array $client, string $setup_token ): bool {
		global $wpdb;

		$chat_id          = trim( (string) ( $client['chat_id'] ?? '' ) );
		$telegram_user_id = trim( (string) ( $client['telegram_user_id'] ?? '' ) );
		if ( $company_id <= 0 || $chat_id === '' || $telegram_user_id === '' || $setup_token === '' ) {
			return false;
		}

		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				 FROM crm_telegram_channel_setup_sessions
				 WHERE company_id = %d
				   AND setup_token = %s
				 LIMIT 1",
				$company_id,
				$setup_token
			)
		);

		if ( ! $session ) {
			crm_telegram_channels_send_message( $company_id, $chat_id, 'Setup-ссылка не найдена или уже устарела.' );
			return true;
		}

		$merchant_id = (int) ( $session->merchant_id ?? 0 );
		$callback_merchant_id = crm_telegram_channels_current_subscription_merchant_id();
		if ( $callback_merchant_id > 0 && $merchant_id !== $callback_merchant_id ) {
			crm_telegram_channels_send_message( $company_id, $chat_id, 'Setup-ссылка не относится к этому subscription bot.' );
			return true;
		}

		$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
		if ( $merchant_id <= 0 || is_wp_error( $merchant ) ) {
			$wpdb->update(
				'crm_telegram_channel_setup_sessions',
				[
					'status'     => 'failed',
					'last_error' => 'Мерчант setup-сессии недоступен.',
					'updated_at' => current_time( 'mysql', true ),
				],
				[ 'id' => (int) $session->id, 'company_id' => $company_id ],
				[ '%s', '%s', '%s' ],
				[ '%d', '%d' ]
			);
			crm_telegram_channels_send_message( $company_id, $chat_id, 'Setup-ссылка больше не привязана к активному мерчанту. Создайте новую ссылку в CRM.' );
			return true;
		}

		if ( ! empty( $session->expires_at ) && strtotime( (string) $session->expires_at . ' UTC' ) < time() ) {
			$wpdb->update(
				'crm_telegram_channel_setup_sessions',
				[
					'status'     => 'expired',
					'last_error' => 'Setup-ссылка истекла.',
					'updated_at' => current_time( 'mysql', true ),
				],
				[ 'id' => (int) $session->id, 'company_id' => $company_id ],
				[ '%s', '%s', '%s' ],
				[ '%d', '%d' ]
			);
			crm_telegram_channels_send_message( $company_id, $chat_id, 'Setup-ссылка истекла. Создайте новую ссылку в CRM.' );
			return true;
		}

		$request_id = random_int( 100000, 2147480000 );
		$wpdb->update(
			'crm_telegram_channel_setup_sessions',
			[
				'request_id'             => $request_id,
				'status'                 => 'opened',
				'setup_chat_id'          => $chat_id,
				'setup_telegram_user_id' => $telegram_user_id,
				'opened_at'              => current_time( 'mysql', true ),
				'updated_at'             => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $session->id, 'company_id' => $company_id ],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ],
			[ '%d', '%d' ]
		);

		$keyboard = [
			'keyboard'              => [
				[
					[
						'text'         => 'Выбрать канал',
						'request_chat' => [
							'request_id'                => $request_id,
							'chat_is_channel'           => true,
							'user_administrator_rights' => crm_telegram_channels_setup_user_rights(),
							'bot_administrator_rights'  => crm_telegram_channels_setup_bot_rights(),
							'request_title'             => true,
							'request_username'          => true,
						],
					],
				],
				[
					[ 'text' => 'Отмена' ],
				],
			],
			'resize_keyboard'       => true,
			'one_time_keyboard'     => true,
			'input_field_placeholder' => 'Выберите канал',
		];

		crm_telegram_channels_send_message(
			$company_id,
			$chat_id,
			"Подключение Telegram-канала.\n\nНажмите «Выбрать канал» и выберите закрытый канал. Если Telegram попросит добавить бота администратором, подтвердите права на invite-ссылки и удаление участников.",
			$keyboard
		);

		return true;
	}
}

if ( ! function_exists( 'crm_telegram_channels_fetch_chat_meta' ) ) {
	function crm_telegram_channels_fetch_chat_meta( int $company_id, string $chat_id, int $merchant_id = 0 ): array {
		$token = crm_telegram_channels_get_subscription_bot_token( $company_id, $merchant_id );
		if ( $company_id <= 0 || $chat_id === '' || $token === '' || ! function_exists( 'crm_telegram_bot_api_request' ) ) {
			return [];
		}

		$response = crm_telegram_bot_api_request(
			$token,
			'getChat',
			[ 'chat_id' => $chat_id ]
		);

		if ( empty( $response['ok'] ) || ! is_array( $response['result'] ?? null ) ) {
			return [];
		}

		return $response['result'];
	}
}

if ( ! function_exists( 'crm_telegram_channels_handle_setup_chat_shared' ) ) {
	function crm_telegram_channels_handle_setup_chat_shared( int $company_id, array $client, array $chat_shared ): bool {
		global $wpdb;

		$chat_id          = trim( (string) ( $client['chat_id'] ?? '' ) );
		$telegram_user_id = trim( (string) ( $client['telegram_user_id'] ?? '' ) );
		$request_id       = (int) ( $chat_shared['request_id'] ?? 0 );
		$selected_chat_id = trim( (string) ( $chat_shared['chat_id'] ?? '' ) );
		if ( $company_id <= 0 || $chat_id === '' || $telegram_user_id === '' || $request_id <= 0 || $selected_chat_id === '' ) {
			return false;
		}

		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				 FROM crm_telegram_channel_setup_sessions
				 WHERE company_id = %d
				   AND request_id = %d
				   AND setup_chat_id = %s
				   AND setup_telegram_user_id = %s
				   AND status IN ('new','opened')
				 ORDER BY id DESC
				 LIMIT 1",
				$company_id,
				$request_id,
				$chat_id,
				$telegram_user_id
			)
		);

		if ( ! $session ) {
			crm_telegram_channels_send_message( $company_id, $chat_id, 'Setup-сессия не найдена. Создайте новую ссылку в CRM.', [ 'remove_keyboard' => true ] );
			return true;
		}

		$merchant_id = (int) ( $session->merchant_id ?? 0 );
		$callback_merchant_id = crm_telegram_channels_current_subscription_merchant_id();
		if ( $callback_merchant_id > 0 && $merchant_id !== $callback_merchant_id ) {
			crm_telegram_channels_send_message( $company_id, $chat_id, 'Setup-сессия не относится к этому subscription bot.', [ 'remove_keyboard' => true ] );
			return true;
		}

		$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
		if ( $merchant_id <= 0 || is_wp_error( $merchant ) ) {
			$wpdb->update(
				'crm_telegram_channel_setup_sessions',
				[
					'status'     => 'failed',
					'last_error' => 'Мерчант setup-сессии недоступен.',
					'updated_at' => current_time( 'mysql', true ),
				],
				[ 'id' => (int) $session->id, 'company_id' => $company_id ],
				[ '%s', '%s', '%s' ],
				[ '%d', '%d' ]
			);
			crm_telegram_channels_send_message( $company_id, $chat_id, 'Setup-сессия больше не привязана к активному мерчанту. Создайте новую ссылку в CRM.', [ 'remove_keyboard' => true ] );
			return true;
		}

		if ( ! empty( $session->expires_at ) && strtotime( (string) $session->expires_at . ' UTC' ) < time() ) {
			$wpdb->update(
				'crm_telegram_channel_setup_sessions',
				[
					'status'     => 'expired',
					'last_error' => 'Setup-ссылка истекла.',
					'updated_at' => current_time( 'mysql', true ),
				],
				[ 'id' => (int) $session->id, 'company_id' => $company_id ],
				[ '%s', '%s', '%s' ],
				[ '%d', '%d' ]
			);
			crm_telegram_channels_send_message( $company_id, $chat_id, 'Setup-ссылка истекла. Создайте новую ссылку в CRM.', [ 'remove_keyboard' => true ] );
			return true;
		}

		$chat_meta = crm_telegram_channels_fetch_chat_meta( $company_id, $selected_chat_id, $merchant_id );
		$title     = trim( wp_strip_all_tags( (string) ( $chat_shared['title'] ?? ( $chat_meta['title'] ?? '' ) ) ) );
		$username  = trim( ltrim( (string) ( $chat_shared['username'] ?? ( $chat_meta['username'] ?? '' ) ), '@' ) );
		if ( $title === '' ) {
			$title = 'Telegram-канал';
		}

		crm_telegram_channels_update_channel(
			$company_id,
			[
				'title'                     => $title,
				'telegram_channel_id'       => $selected_chat_id,
				'telegram_channel_username' => $username,
				'status'                    => 'draft',
			],
			$merchant_id
		);

		$admin_check = crm_telegram_channels_check_bot_admin( $company_id, $merchant_id );
		$is_ready    = ! empty( $admin_check['success'] );
		if ( $is_ready ) {
			crm_telegram_channels_update_channel(
				$company_id,
				[
					'title'                     => $title,
					'telegram_channel_id'       => $selected_chat_id,
					'telegram_channel_username' => $username,
					'status'                    => 'active',
				],
				$merchant_id
			);
		}

		$wpdb->update(
			'crm_telegram_channel_setup_sessions',
			[
				'status'            => $is_ready ? 'completed' : 'needs_admin',
				'selected_chat_id'  => $selected_chat_id,
				'selected_title'    => $title,
				'selected_username' => $username !== '' ? $username : null,
				'last_error'        => $is_ready ? null : (string) ( $admin_check['message'] ?? 'Bot admin check failed.' ),
				'completed_at'      => current_time( 'mysql', true ),
				'updated_at'        => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $session->id, 'company_id' => $company_id ],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
			[ '%d', '%d' ]
		);

		crm_log_entity(
			$is_ready ? 'telegram_channels.channel_setup_completed' : 'telegram_channels.channel_setup_needs_admin',
			'telegram_channels',
			'update',
			$is_ready ? 'Telegram-канал подключён через setup flow.' : 'Telegram-канал выбран, но права бота не подтверждены.',
			'telegram_channel_setup',
			(int) $session->id,
			[
				'org_id'  => $company_id,
				'level'   => $is_ready ? 'info' : 'warning',
				'context' => [
					'merchant_id'  => $merchant_id,
					'chat_id'      => $selected_chat_id,
					'username_set' => $username !== '',
				],
			]
		);

		if ( $is_ready ) {
			crm_telegram_channels_send_message(
				$company_id,
				$chat_id,
				'Канал подключён: ' . esc_html( $title ) . ".\n\nДанные сохранены в CRM, статус канала переключён в active.",
				[ 'remove_keyboard' => true ]
			);
		} else {
			crm_telegram_channels_send_message(
				$company_id,
				$chat_id,
				'Канал найден и данные сохранены в CRM, но статус оставлен draft: ' . (string) ( $admin_check['message'] ?? 'боту не хватает прав администратора.' ) . "\n\nДобавьте subscription bot администратором канала с правами на invite-ссылки и удаление участников, затем нажмите «Проверить права бота» в CRM.",
				[ 'remove_keyboard' => true ]
			);
		}

		return true;
	}
}

if ( ! function_exists( 'crm_telegram_channels_list_subscribers' ) ) {
	function crm_telegram_channels_list_subscribers( int $company_id, int $limit = 50, int $merchant_id = 0, int $channel_id = 0 ): array {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return [];
		}

		$limit = max( 1, min( 200, $limit ) );
		if ( $channel_id <= 0 ) {
			$channel = crm_telegram_channels_get_merchant_channel( $company_id, $merchant_id );
			$channel_id = $channel ? (int) $channel->id : 0;
		}
		if ( $channel_id <= 0 ) {
			return [];
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, t.title AS tariff_title
				 FROM crm_telegram_channel_subscribers s
				 LEFT JOIN crm_telegram_channel_tariffs t ON t.id = s.current_tariff_id AND t.company_id = s.company_id
				 WHERE s.company_id = %d
				   AND s.merchant_id = %d
				   AND s.channel_id = %d
				 ORDER BY s.updated_at DESC
				 LIMIT %d",
				$company_id,
				$merchant_id,
				$channel_id,
				$limit
			)
		) ?: [];
	}
}

if ( ! function_exists( 'crm_telegram_channels_list_payments' ) ) {
	function crm_telegram_channels_list_payments( int $company_id, int $limit = 50, int $merchant_id = 0, int $channel_id = 0 ): array {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return [];
		}

		$limit = max( 1, min( 200, $limit ) );
		if ( $channel_id <= 0 ) {
			$channel = crm_telegram_channels_get_merchant_channel( $company_id, $merchant_id );
			$channel_id = $channel ? (int) $channel->id : 0;
		}
		if ( $channel_id <= 0 ) {
			return [];
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*, t.title AS tariff_title, s.username AS subscriber_username, o.status_code AS order_status
				 FROM crm_telegram_channel_payments p
				 LEFT JOIN crm_telegram_channel_tariffs t ON t.id = p.tariff_id AND t.company_id = p.company_id
				 LEFT JOIN crm_telegram_channel_subscribers s ON s.id = p.subscriber_id AND s.company_id = p.company_id
				 LEFT JOIN crm_fintech_payment_orders o ON o.id = p.payment_order_id AND o.company_id = p.company_id
				 WHERE p.company_id = %d
				   AND p.merchant_id = %d
				   AND p.channel_id = %d
				 ORDER BY p.created_at DESC
				 LIMIT %d",
				$company_id,
				$merchant_id,
				$channel_id,
				$limit
			)
		) ?: [];
	}
}

if ( ! function_exists( 'crm_telegram_channels_profile_payload' ) ) {
	function crm_telegram_channels_profile_payload( int $company_id, int $merchant_id, int $limit = 50 ) {
		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return new WP_Error( 'telegram_channels_invalid_profile_scope', 'Выберите мерчанта.' );
		}

		$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
		if ( is_wp_error( $merchant ) ) {
			return $merchant;
		}

		crm_telegram_channels_seed_merchant_foundation( $company_id, $merchant_id );

		$channel     = crm_telegram_channels_get_merchant_channel( $company_id, $merchant_id );
		$channel_id  = $channel ? (int) $channel->id : 0;
		$tariffs     = crm_telegram_channels_get_company_tariffs( $company_id, $channel_id );
		$readiness   = crm_telegram_channels_get_readiness_status( $company_id, false, $merchant_id );
		$subscribers = crm_telegram_channels_list_subscribers( $company_id, $limit, $merchant_id, $channel_id );
		$payments    = crm_telegram_channels_list_payments( $company_id, $limit, $merchant_id, $channel_id );
		$mode_summary = crm_telegram_channels_mode_summary( $company_id, $merchant_id );
		$subscription_status = crm_telegram_channels_merchant_subscription_status( $company_id, $merchant_id );

		$tariff_payload = [];
		foreach ( $tariffs as $tariff ) {
			$current_currency = strtoupper( trim( (string) ( $tariff->price_currency ?? '' ) ) );
			$currency_options = crm_telegram_channels_price_currency_options( $company_id, $merchant_id, $current_currency );
			if ( ! in_array( $current_currency, $currency_options, true ) && ! empty( $currency_options ) ) {
				$current_currency = (string) $currency_options[0];
			}
			$tariff_payload[] = [
				'id'               => (int) $tariff->id,
				'code'             => (string) $tariff->code,
				'title'            => (string) $tariff->title,
				'duration_days'    => (int) $tariff->duration_days,
				'price_amount'     => rtrim( rtrim( number_format( (float) $tariff->price_amount, 2, '.', '' ), '0' ), '.' ),
				'price_currency'   => $current_currency,
				'status'           => (string) $tariff->status,
				'currency_options' => $currency_options,
			];
		}

		$subscriber_payload = [];
		foreach ( $subscribers as $subscriber ) {
			$full_name = trim( (string) $subscriber->first_name . ' ' . (string) $subscriber->last_name );
			$subscriber_payload[] = [
				'id'                       => (int) $subscriber->id,
				'telegram_user_id'         => (string) $subscriber->telegram_user_id,
				'username'                 => (string) ( $subscriber->username ?? '' ),
				'label'                    => ( ! empty( $subscriber->username ) ? '@' . (string) $subscriber->username : (string) $subscriber->telegram_user_id ),
				'full_name'                => $full_name,
				'tariff_title'             => (string) ( $subscriber->tariff_title ?? '' ),
				'subscription_until'       => (string) ( $subscriber->subscription_until ?? '' ),
				'subscription_until_label' => ! empty( $subscriber->subscription_until ) ? ( crm_format_dt( (string) $subscriber->subscription_until, $company_id ) ?: (string) $subscriber->subscription_until ) : '',
				'status'                   => (string) $subscriber->status,
			];
		}

		$payment_payload = [];
		foreach ( $payments as $payment ) {
			$amount_label = rtrim( rtrim( number_format( (float) $payment->amount, 2, '.', '' ), '0' ), '.' ) . ' ' . (string) $payment->currency;
			$payment_payload[] = [
				'id'                  => (int) $payment->id,
				'payment_order_id'    => (int) $payment->payment_order_id,
				'order_status'        => (string) ( $payment->order_status ?? '' ),
				'subscriber_label'    => ! empty( $payment->subscriber_username ) ? '@' . (string) $payment->subscriber_username : '',
				'tariff_title'        => (string) ( $payment->tariff_title ?? '' ),
				'amount_label'        => $amount_label,
				'paid_at'             => (string) ( $payment->paid_at ?? '' ),
				'paid_at_label'       => ! empty( $payment->paid_at ) ? ( crm_format_dt( (string) $payment->paid_at, $company_id ) ?: (string) $payment->paid_at ) : '',
			];
		}

		return [
			'merchant'     => [
				'id'       => (int) $merchant->id,
				'name'     => (string) ( $merchant->name ?? '' ),
				'status'   => (string) ( $merchant->status ?? '' ),
				'username' => (string) ( $merchant->telegram_username ?? '' ),
				'chat_id'  => (string) ( $merchant->chat_id ?? '' ),
			],
			'channel'      => $channel ? [
				'id'                        => (int) $channel->id,
				'title'                     => (string) $channel->title,
				'telegram_channel_id'       => (string) ( $channel->telegram_channel_id ?? '' ),
				'telegram_channel_username' => (string) ( $channel->telegram_channel_username ?? '' ),
				'status'                    => (string) $channel->status,
				'bot_admin_check_status'    => (string) ( $channel->bot_admin_check_status ?? '' ),
				'bot_admin_check_error'     => (string) ( $channel->bot_admin_check_error ?? '' ),
			] : null,
			'tariffs'      => $tariff_payload,
			'subscribers'  => $subscriber_payload,
			'payments'     => $payment_payload,
			'readiness'    => $readiness,
			'mode_summary' => $mode_summary,
			'subscription_bot' => $subscription_status,
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_create_sale' ) ) {
	function crm_telegram_channels_create_sale( int $company_id, int $tariff_id, int $merchant_id = 0, string $context = 'merchant_bot' ): array {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return [ 'success' => false, 'message' => 'Некорректный company_id или merchant_id.' ];
		}

		$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
		if ( is_wp_error( $merchant ) ) {
			crm_log_entity(
				'telegram_channels.sale_denied_merchant_access',
				'telegram_channels',
				'deny',
				'Попытка создать продажу подписки без доступа мерчанта.',
				'merchant',
				max( 0, $merchant_id ),
				[
					'org_id'  => $company_id,
					'context' => [
						'tariff_id'   => $tariff_id,
						'merchant_id' => $merchant_id,
						'flow'        => $context,
					],
				]
			);

			return [ 'success' => false, 'message' => $merchant->get_error_message() ];
		}

		crm_telegram_channels_seed_merchant_foundation( $company_id, $merchant_id );

		$readiness = crm_telegram_channels_get_readiness_status( $company_id, true, $merchant_id );
		if ( empty( $readiness['is_ready'] ) ) {
			return [ 'success' => false, 'message' => 'Модуль Telegram-каналы ещё не готов к публичному flow.', 'readiness' => $readiness ];
		}

		$channel = crm_telegram_channels_get_merchant_channel( $company_id, $merchant_id );
		if ( ! $channel ) {
			return [ 'success' => false, 'message' => 'Канал мерчанта не найден.' ];
		}

		$tariff = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM crm_telegram_channel_tariffs
				 WHERE id = %d
				   AND company_id = %d
				   AND channel_id = %d
				   AND status = 'active'
				   AND price_amount > 0
				 LIMIT 1",
				$tariff_id,
				$company_id,
				(int) $channel->id
			)
		);
		if ( ! $tariff ) {
			return [ 'success' => false, 'message' => 'Тариф недоступен.' ];
		}

		$payload = '';
		for ( $i = 0; $i < 5; $i++ ) {
			$payload = 'ch_' . bin2hex( random_bytes( 12 ) );
			$exists  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM crm_telegram_channel_sales WHERE start_payload = %s LIMIT 1', $payload ) );
			if ( $exists <= 0 ) {
				break;
			}
		}

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
		$wpdb->insert(
			'crm_telegram_channel_sales',
			[
				'company_id'           => $company_id,
				'channel_id'           => (int) $channel->id,
				'tariff_id'            => (int) $tariff->id,
				'merchant_id'          => $merchant_id,
				'created_from_context' => in_array( $context, [ 'merchant_bot', 'web', 'admin' ], true ) ? $context : 'merchant_bot',
				'start_payload'        => $payload,
				'status'               => 'new',
				'expires_at'           => $expires_at,
				'created_at'           => current_time( 'mysql', true ),
				'updated_at'           => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		$sale_id = (int) $wpdb->insert_id;
		if ( $sale_id <= 0 ) {
			return [ 'success' => false, 'message' => 'Не удалось создать продажу подписки.' ];
		}

		$bot_username = crm_telegram_channels_get_subscription_bot_username( $company_id, $merchant_id );
		$url          = $bot_username !== '' ? 'https://t.me/' . ltrim( $bot_username, '@' ) . '?start=' . rawurlencode( $payload ) : '';

		crm_log_entity(
			'telegram_channels.sale_created',
			'telegram_channels',
			'create',
			'Создана ссылка продажи подписки Telegram-канала.',
			'telegram_channel_sale',
			$sale_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'channel_id'  => (int) $channel->id,
					'tariff_id'   => (int) $tariff->id,
					'merchant_id' => $merchant_id,
				],
			]
		);

		return [
			'success'       => true,
			'sale_id'       => $sale_id,
			'payload'       => $payload,
			'url'           => $url,
			'tariff'        => $tariff,
			'expires_at'    => $expires_at,
			'bot_username'  => $bot_username,
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_active_tariffs' ) ) {
	function crm_telegram_channels_get_active_tariffs( int $company_id, int $channel_id = 0 ): array {
		return array_values(
			array_filter(
				crm_telegram_channels_get_company_tariffs( $company_id, $channel_id ),
				static function ( $tariff ): bool {
					return (string) ( $tariff->status ?? '' ) === 'active' && (float) ( $tariff->price_amount ?? 0 ) > 0;
				}
			)
		);
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_merchant_active_tariffs' ) ) {
	function crm_telegram_channels_get_merchant_active_tariffs( int $company_id, int $merchant_id ): array {
		$channel = crm_telegram_channels_get_merchant_channel( $company_id, $merchant_id );
		if ( ! $channel ) {
			return [];
		}

		return crm_telegram_channels_get_active_tariffs( $company_id, (int) $channel->id );
	}
}

if ( ! function_exists( 'crm_telegram_channels_calculate_usdt_order_pricing' ) ) {
	/**
	 * Рассчитывает Telegram USDT invoice с merchant-level наценкой.
	 *
	 * Базовая цена тарифа остаётся payable суммой мерчанта, а gross amount,
	 * отправляемый в Kanyon orderAmount, увеличивается на platform fee.
	 *
	 * @return array<string,mixed>
	 */
	function crm_telegram_channels_calculate_usdt_order_pricing( int $company_id, object $merchant, float $tariff_amount_usdt ): array {
		$tariff_amount_usdt = round( max( 0, $tariff_amount_usdt ), 2 );
		if ( $company_id <= 0 || $tariff_amount_usdt <= 0 ) {
			return [
				'success' => false,
				'message' => 'Некорректные данные для расчёта Telegram-наценки.',
			];
		}

		$markup_basis = function_exists( 'crm_merchant_normalize_markup_basis' )
			? crm_merchant_normalize_markup_basis( (string) ( $merchant->telegram_channels_markup_basis ?? 'acquirer_cost' ) )
			: 'acquirer_cost';
		$markup_type = sanitize_key( (string) ( $merchant->telegram_channels_markup_type ?? 'percent' ) );
		if ( ! function_exists( 'crm_merchant_markup_types' ) || ! isset( crm_merchant_markup_types()[ $markup_type ] ) ) {
			$markup_type = 'percent';
		}
		$markup_value = max( 0, (float) ( $merchant->telegram_channels_markup_value ?? 0 ) );

		$base = [
			'success'              => true,
			'calculation_mode'     => 'pass_through',
			'tariff_amount_usdt'   => $tariff_amount_usdt,
			'provider_amount_usdt' => $tariff_amount_usdt,
			'merchant_payable_usdt'=> $tariff_amount_usdt,
			'platform_fee_usdt'    => 0.0,
			'markup_basis'         => $markup_basis,
			'markup_type'          => $markup_type,
			'markup_value'         => $markup_value,
			'cost_rate'            => null,
			'base_rate'            => null,
			'commercial_rate'      => null,
			'target_payment_rub'   => null,
			'quote_order_id'       => null,
		];

		if ( $markup_value <= 0.00000001 ) {
			return $base;
		}

		if ( ! function_exists( 'crm_fintech_fetch_live_kanyon_rub_usdt_quote' ) ) {
			return [
				'success' => false,
				'message' => 'Live quote Kanyon недоступен для расчёта Telegram-наценки.',
			];
		}

		$quote = crm_fintech_fetch_live_kanyon_rub_usdt_quote( $company_id, 'telegram_channels_markup_quote', null );
		if ( empty( $quote['ok'] ) || empty( $quote['kanyon_rate'] ) || (float) $quote['kanyon_rate'] <= 0 ) {
			return [
				'success' => false,
				'message' => (string) ( $quote['error'] ?? 'Не удалось получить live себестоимость Kanyon для Telegram-наценки.' ),
				'quote'   => $quote,
			];
		}

		$cost_rate = round( (float) $quote['kanyon_rate'], 4 );
		$base_rate = $cost_rate;
		if ( $markup_basis === 'rapira_rate' ) {
			$rapira = function_exists( 'rates_get_rapira' ) ? rates_get_rapira() : [ 'ok' => false ];
			if ( empty( $rapira['ok'] ) || ! isset( $rapira['ask'] ) || (float) $rapira['ask'] <= 0 ) {
				return [
					'success' => false,
					'message' => 'Не удалось получить Rapira Ask для расчёта Telegram-наценки.',
					'quote'   => $quote,
				];
			}
			$base_rate = round( (float) $rapira['ask'], 4 );
		}

		$commercial_rate = $markup_type === 'fixed'
			? $base_rate + $markup_value
			: $base_rate * ( 1 + ( $markup_value / 100 ) );
		$commercial_rate = round( $commercial_rate, 4 );

		if ( $commercial_rate + 0.0001 < $cost_rate ) {
			return [
				'success' => false,
				'message' => 'Telegram-наценка ниже live себестоимости Kanyon. Увеличьте наценку или смените базу расчёта.',
				'quote'   => $quote,
				'pricing' => [
					'cost_rate'       => $cost_rate,
					'base_rate'       => $base_rate,
					'commercial_rate' => $commercial_rate,
				],
			];
		}

		$target_payment_rub   = round( $tariff_amount_usdt * $commercial_rate, 2 );
		$provider_amount_usdt = ceil( ( $target_payment_rub / $cost_rate ) * 100 ) / 100;
		$provider_amount_usdt = round( max( $tariff_amount_usdt, $provider_amount_usdt ), 2 );
		$platform_fee_usdt    = round( max( 0, $provider_amount_usdt - $tariff_amount_usdt ), 8 );

		return array_merge(
			$base,
			[
				'calculation_mode'     => 'merchant_markup',
				'provider_amount_usdt' => $provider_amount_usdt,
				'platform_fee_usdt'    => $platform_fee_usdt,
				'cost_rate'            => $cost_rate,
				'base_rate'            => $base_rate,
				'commercial_rate'      => $commercial_rate,
				'target_payment_rub'   => $target_payment_rub,
				'quote_order_id'       => isset( $quote['payment_order_id'] ) ? (int) $quote['payment_order_id'] : null,
			]
		);
	}
}

if ( ! function_exists( 'crm_telegram_channels_create_payment_order' ) ) {
	function crm_telegram_channels_create_payment_order( int $company_id, int $tariff_id, array $client, int $sale_id = 0 ): array {
		global $wpdb;

		if ( $company_id <= 0 ) {
			return [ 'success' => false, 'message' => 'Некорректный company_id.' ];
		}

		$sale        = null;
		$merchant_id = 0;
		$channel_id  = 0;
		if ( $sale_id > 0 ) {
			$sale = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM crm_telegram_channel_sales WHERE id = %d AND company_id = %d AND merchant_id > 0 LIMIT 1',
					$sale_id,
					$company_id
				)
			);
			if ( ! $sale ) {
				return [ 'success' => false, 'message' => 'Ссылка продажи не найдена.' ];
			}
			$merchant_id = (int) $sale->merchant_id;
			$channel_id  = (int) $sale->channel_id;
			$tariff_id   = (int) $sale->tariff_id;
		}

		if ( $channel_id > 0 ) {
			$tariff = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT t.*, c.merchant_id AS channel_merchant_id
					 FROM crm_telegram_channel_tariffs t
					 INNER JOIN crm_telegram_channels c ON c.id = t.channel_id AND c.company_id = t.company_id
					 WHERE t.id = %d
					   AND t.company_id = %d
					   AND t.channel_id = %d
					   AND c.merchant_id = %d
					   AND c.merchant_id > 0
					   AND t.status = 'active'
					   AND t.price_amount > 0
					 LIMIT 1",
					$tariff_id,
					$company_id,
					$channel_id,
					$merchant_id
				)
			);
		} else {
			$tariff = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT t.*, c.merchant_id AS channel_merchant_id
					 FROM crm_telegram_channel_tariffs t
					 INNER JOIN crm_telegram_channels c ON c.id = t.channel_id AND c.company_id = t.company_id
					 WHERE t.id = %d
					   AND t.company_id = %d
					   AND c.merchant_id > 0
					   AND t.status = 'active'
					   AND t.price_amount > 0
					 LIMIT 1",
					$tariff_id,
					$company_id
				)
			);
			if ( $tariff ) {
				$merchant_id = (int) ( $tariff->channel_merchant_id ?? 0 );
				$channel_id  = (int) $tariff->channel_id;
			}
		}
		if ( ! $tariff ) {
			return [ 'success' => false, 'message' => 'Тариф недоступен.' ];
		}

		$callback_merchant_id = crm_telegram_channels_current_subscription_merchant_id();
		if ( $callback_merchant_id > 0 && $merchant_id !== $callback_merchant_id ) {
			return [ 'success' => false, 'message' => 'Тариф недоступен в этом subscription bot.' ];
		}

		$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
		if ( is_wp_error( $merchant ) ) {
			return [ 'success' => false, 'message' => $merchant->get_error_message() ];
		}

		$channel = crm_telegram_channels_get_channel_by_id( $company_id, $channel_id );
		if ( ! $channel || (int) $channel->merchant_id !== $merchant_id ) {
			return [ 'success' => false, 'message' => 'Канал мерчанта не найден.' ];
		}

		$readiness = crm_telegram_channels_get_readiness_status( $company_id, true, $merchant_id );
		if ( empty( $readiness['is_ready'] ) ) {
			return [ 'success' => false, 'message' => crm_telegram_channels_text( $company_id, 'not_configured', [], $merchant_id ), 'readiness' => $readiness ];
		}

		$telegram_user_id = trim( (string) ( $client['telegram_user_id'] ?? '' ) );
		$chat_id          = trim( (string) ( $client['chat_id'] ?? '' ) );
		if ( $telegram_user_id === '' || $chat_id === '' ) {
			return [ 'success' => false, 'message' => 'Не удалось определить Telegram client context.' ];
		}

		$description = sprintf( 'Telegram channel subscription: %s', (string) $tariff->title );
		$amount      = round( (float) $tariff->price_amount, 2 );
		$currency    = strtoupper( (string) $tariff->price_currency );
		if ( ! crm_telegram_channels_tariff_currency_supported( $company_id, $merchant_id, $currency ) ) {
			return [ 'success' => false, 'message' => 'Валюта тарифа не соответствует fintech-контуру мерчанта.' ];
		}

		$pricing = [
			'calculation_mode'     => 'pass_through',
			'tariff_amount_usdt'   => $currency === 'USDT' ? $amount : null,
			'provider_amount_usdt' => $currency === 'USDT' ? $amount : null,
			'merchant_payable_usdt'=> $currency === 'USDT' ? $amount : null,
			'platform_fee_usdt'    => 0.0,
			'markup_basis'         => null,
			'markup_type'          => null,
			'markup_value'         => 0.0,
			'cost_rate'            => null,
			'base_rate'            => null,
			'commercial_rate'      => null,
			'target_payment_rub'   => null,
			'quote_order_id'       => null,
		];

		if ( $currency === 'RUB' ) {
			$result = crm_fintech_create_order_by_payment_amount(
				$amount,
				'RUB',
				$company_id,
				crm_telegram_channels_source_channel(),
				null,
				$description
			);
		} elseif ( $currency === 'USDT' ) {
			$pricing = crm_telegram_channels_calculate_usdt_order_pricing( $company_id, $merchant, $amount );
			if ( empty( $pricing['success'] ) ) {
				return [
					'success' => false,
					'message' => (string) ( $pricing['message'] ?? 'Не удалось рассчитать Telegram-наценку.' ),
					'pricing' => $pricing,
				];
			}

			$result = crm_fintech_create_order(
				round( max( $amount, (float) ( $pricing['provider_amount_usdt'] ?? $amount ) ), 2 ),
				$company_id,
				crm_telegram_channels_source_channel(),
				null,
				$description
			);
		} else {
			return [ 'success' => false, 'message' => 'Поддерживаются только RUB и USDT тарифы.' ];
		}

		if ( empty( $result['success'] ) || empty( $result['order_db_id'] ) ) {
			return [
				'success' => false,
				'message' => (string) ( $result['error'] ?? 'Не удалось создать платёж.' ),
				'result'  => $result,
			];
		}

		$order_id = (int) $result['order_db_id'];
		$meta     = [
			'module'           => 'telegram_channels',
			'sale_id'          => $sale_id > 0 ? $sale_id : null,
			'channel_id'       => (int) $channel->id,
			'tariff_id'        => (int) $tariff->id,
			'tariff_code'      => (string) $tariff->code,
			'duration_days'    => (int) $tariff->duration_days,
			'telegram_user_id' => $telegram_user_id,
			'chat_id'          => $chat_id,
			'username'         => trim( (string) ( $client['username'] ?? '' ) ),
			'first_name'       => trim( (string) ( $client['first_name'] ?? '' ) ),
			'last_name'        => trim( (string) ( $client['last_name'] ?? '' ) ),
			'merchant_id'      => $merchant_id,
			'price_amount'     => $amount,
			'price_currency'   => $currency,
			'tariff_title'     => (string) $tariff->title,
			'telegram_pricing' => [
				'calculation_mode'      => (string) ( $pricing['calculation_mode'] ?? 'pass_through' ),
				'tariff_amount_usdt'    => $pricing['tariff_amount_usdt'] ?? null,
				'provider_amount_usdt'  => $pricing['provider_amount_usdt'] ?? null,
				'merchant_payable_usdt' => $pricing['merchant_payable_usdt'] ?? null,
				'platform_fee_usdt'     => $pricing['platform_fee_usdt'] ?? 0,
				'markup_basis'          => $pricing['markup_basis'] ?? null,
				'markup_type'           => $pricing['markup_type'] ?? null,
				'markup_value'          => $pricing['markup_value'] ?? 0,
				'cost_rate'             => $pricing['cost_rate'] ?? null,
				'base_rate'             => $pricing['base_rate'] ?? null,
				'commercial_rate'       => $pricing['commercial_rate'] ?? null,
				'target_payment_rub'    => $pricing['target_payment_rub'] ?? null,
				'quote_order_id'        => $pricing['quote_order_id'] ?? null,
			],
		];

		$order_update = [
			'merchant_id'      => $merchant_id,
			'created_for_type' => 'merchant',
			'meta_json'        => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'notes'            => 'Telegram channel subscription payment.',
			'updated_at'       => current_time( 'mysql', true ),
		];
		$order_update_format = [ '%d', '%s', '%s', '%s', '%s' ];

		if ( $currency === 'USDT' ) {
			$merchant_meta = [
				'merchant_flow'             => 'telegram_channels_usdt_kanyon_order_amount',
				'requested_amount_currency' => 'USDT',
				'payment_currency_code'     => 'RUB',
				'settlement_currency_code'  => 'USDT',
				'telegram_pricing'          => $meta['telegram_pricing'],
			];

			$order_update['merchant_requested_rub_value'] = isset( $pricing['target_payment_rub'] ) && $pricing['target_payment_rub'] !== null
				? number_format( (float) $pricing['target_payment_rub'], 2, '.', '' )
				: null;
			$order_update['merchant_payable_value'] = number_format( (float) ( $pricing['merchant_payable_usdt'] ?? $amount ), 8, '.', '' );
			$order_update['merchant_markup_value']  = number_format( (float) ( $pricing['platform_fee_usdt'] ?? 0 ), 8, '.', '' );
			$order_update['platform_fee_value']     = number_format( (float) ( $pricing['platform_fee_usdt'] ?? 0 ), 8, '.', '' );
			$order_update['merchant_profit_value']  = number_format( 0, 8, '.', '' );
			$order_update['referral_reward_value']  = number_format( 0, 8, '.', '' );
			$order_update['merchant_meta_json']     = wp_json_encode( $merchant_meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$order_update_format = array_merge( $order_update_format, [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );
		}

		$wpdb->update(
			'crm_fintech_payment_orders',
			$order_update,
			[
				'id'         => $order_id,
				'company_id' => $company_id,
			],
			$order_update_format,
			[ '%d', '%d' ]
		);

		if ( $sale_id > 0 ) {
			$wpdb->update(
				'crm_telegram_channel_sales',
				[
					'client_telegram_user_id' => preg_match( '/^\d+$/', $telegram_user_id ) ? $telegram_user_id : null,
					'client_chat_id'          => $chat_id,
					'status'                  => 'payment_created',
					'payment_order_id'        => $order_id,
					'opened_at'               => current_time( 'mysql', true ),
					'updated_at'              => current_time( 'mysql', true ),
				],
				[
					'id'         => $sale_id,
					'company_id' => $company_id,
					'merchant_id' => $merchant_id,
				],
				[ '%d', '%s', '%s', '%d', '%s', '%s' ],
				[ '%d', '%d', '%d' ]
			);
		}

		crm_log_entity(
			'telegram_channels.payment_created',
			'telegram_channels',
			'create',
			'Создан payment order для подписки Telegram-канала.',
			'payment_order',
			$order_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'channel_id'  => (int) $channel->id,
					'tariff_id'   => (int) $tariff->id,
					'sale_id'     => $sale_id,
					'merchant_id' => $merchant_id,
					'currency'    => $currency,
					'amount'      => $amount,
					'provider_amount_usdt' => $currency === 'USDT' ? (float) ( $pricing['provider_amount_usdt'] ?? $amount ) : null,
					'platform_fee_usdt'    => $currency === 'USDT' ? (float) ( $pricing['platform_fee_usdt'] ?? 0 ) : null,
					'telegram_markup_basis'=> $currency === 'USDT' ? ( $pricing['markup_basis'] ?? null ) : null,
					'telegram_markup_type' => $currency === 'USDT' ? ( $pricing['markup_type'] ?? null ) : null,
					'telegram_markup_value'=> $currency === 'USDT' ? (float) ( $pricing['markup_value'] ?? 0 ) : null,
				],
			]
		);

		$result['success'] = true;
		$result['message'] = crm_telegram_channels_text( $company_id, 'payment_created', [], $merchant_id );
		$result['meta']    = $meta;

		if ( empty( $result['payment_link'] ) && empty( $result['qr_url'] ) && function_exists( 'crm_telegram_channels_refresh_payment_result' ) ) {
			$refreshed = crm_telegram_channels_refresh_payment_result( $company_id, $order_id, $client );
			if ( ! empty( $refreshed['success'] ) && ( ! empty( $refreshed['payment_link'] ) || ! empty( $refreshed['qr_url'] ) ) ) {
				$result = array_merge( $result, $refreshed );
				$result['success'] = true;
				$result['message'] = crm_telegram_channels_text( $company_id, 'payment_created', [], $merchant_id );
				$result['meta']    = $meta;
			}
		}

		return $result;
	}
}

if ( ! function_exists( 'crm_telegram_channels_send_message' ) ) {
	function crm_telegram_channels_send_message( int $company_id, string $chat_id, string $text, array $reply_markup = [], int $merchant_id = 0 ): array {
		$token = crm_telegram_channels_get_subscription_bot_token( $company_id, $merchant_id );
		if ( $company_id <= 0 || $chat_id === '' || $token === '' ) {
			return [ 'ok' => false, 'description' => 'Missing company, chat or token.' ];
		}

		$payload = [
			'chat_id'                  => $chat_id,
			'text'                     => $text,
			'parse_mode'               => 'HTML',
			'disable_web_page_preview' => 'true',
		];

		if ( ! empty( $reply_markup ) ) {
			$payload['reply_markup'] = wp_json_encode( $reply_markup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		return function_exists( 'crm_telegram_bot_api_request' )
			? crm_telegram_bot_api_request( $token, 'sendMessage', $payload )
			: [ 'ok' => false, 'description' => 'Telegram helper is missing.' ];
	}
}

if ( ! function_exists( 'crm_telegram_channels_send_chat_action' ) ) {
	function crm_telegram_channels_send_chat_action( int $company_id, string $chat_id, string $action = 'typing', int $merchant_id = 0 ): void {
		$token = crm_telegram_channels_get_subscription_bot_token( $company_id, $merchant_id );
		if ( $company_id <= 0 || $chat_id === '' || $token === '' || ! function_exists( 'crm_telegram_bot_api_request' ) ) {
			return;
		}

		$allowed_actions = [
			'typing',
			'upload_photo',
			'upload_document',
			'find_location',
		];
		$action = in_array( $action, $allowed_actions, true ) ? $action : 'typing';

		crm_telegram_bot_api_request(
			$token,
			'sendChatAction',
			[
				'chat_id' => $chat_id,
				'action'  => $action,
			]
		);
	}
}

if ( ! function_exists( 'crm_telegram_channels_delete_message' ) ) {
	function crm_telegram_channels_delete_message( int $company_id, string $chat_id, int $message_id, int $merchant_id = 0 ): bool {
		$token = crm_telegram_channels_get_subscription_bot_token( $company_id, $merchant_id );
		if ( $company_id <= 0 || $chat_id === '' || $message_id <= 0 || $token === '' || ! function_exists( 'crm_telegram_bot_api_request' ) ) {
			return false;
		}

		$response = crm_telegram_bot_api_request(
			$token,
			'deleteMessage',
			[
				'chat_id'    => $chat_id,
				'message_id' => $message_id,
			]
		);

		return ! empty( $response['ok'] );
	}
}

if ( ! function_exists( 'crm_telegram_channels_response_message_id' ) ) {
	function crm_telegram_channels_response_message_id( array $response ): int {
		return isset( $response['result']['message_id'] ) ? (int) $response['result']['message_id'] : 0;
	}
}

if ( ! function_exists( 'crm_telegram_channels_telegram_response_ok' ) ) {
	function crm_telegram_channels_telegram_response_ok( array $response ): bool {
		if ( ! empty( $response['ok'] ) ) {
			return true;
		}

		$description = strtolower( trim( (string) ( $response['description'] ?? '' ) ) );
		return $description !== '' && strpos( $description, 'message is not modified' ) !== false;
	}
}

if ( ! function_exists( 'crm_telegram_channels_safe_json_decode' ) ) {
	function crm_telegram_channels_safe_json_decode( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}

		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : [];
	}
}

if ( ! function_exists( 'crm_telegram_channels_normalize_message_id_list' ) ) {
	function crm_telegram_channels_normalize_message_id_list( $raw_list ): array {
		$list = is_array( $raw_list ) ? $raw_list : [];
		$out  = [];

		foreach ( $list as $message_id ) {
			$message_id = (int) $message_id;
			if ( $message_id > 0 ) {
				$out[] = $message_id;
			}
		}

		return array_values( array_unique( $out ) );
	}
}

if ( ! function_exists( 'crm_telegram_channels_append_message_id_list' ) ) {
	function crm_telegram_channels_append_message_id_list( $raw_list, int $message_id ): array {
		$list = crm_telegram_channels_normalize_message_id_list( $raw_list );
		if ( $message_id > 0 ) {
			$list[] = $message_id;
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $list ) ) ) );
	}
}

if ( ! function_exists( 'crm_telegram_channels_merge_order_meta' ) ) {
	function crm_telegram_channels_merge_order_meta( int $order_id, int $company_id, array $meta_patch ): array {
		global $wpdb;

		$result = [
			'ok'   => false,
			'meta' => [],
		];

		if ( $order_id <= 0 || $company_id <= 0 || empty( $meta_patch ) ) {
			return $result;
		}

		$raw_meta = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_json FROM crm_fintech_payment_orders WHERE id = %d AND company_id = %d LIMIT 1',
				$order_id,
				$company_id
			)
		);

		if ( null === $raw_meta ) {
			return $result;
		}

		$meta    = crm_telegram_channels_safe_json_decode( $raw_meta );
		$updated = array_merge( $meta, $meta_patch );
		$json    = wp_json_encode( $updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $json ) || $json === '' ) {
			return $result;
		}

		$write = $wpdb->update(
			'crm_fintech_payment_orders',
			[
				'meta_json'  => $json,
				'updated_at' => current_time( 'mysql', true ),
			],
			[
				'id'         => $order_id,
				'company_id' => $company_id,
			],
			[ '%s', '%s' ],
			[ '%d', '%d' ]
		);

		$result['ok']   = false !== $write;
		$result['meta'] = $updated;

		return $result;
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_order_meta' ) ) {
	function crm_telegram_channels_get_order_meta( int $order_id, int $company_id ): array {
		global $wpdb;

		if ( $order_id <= 0 || $company_id <= 0 ) {
			return [];
		}

		$raw_meta = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT meta_json FROM crm_fintech_payment_orders WHERE id = %d AND company_id = %d LIMIT 1',
				$order_id,
				$company_id
			)
		);

		return null === $raw_meta ? [] : crm_telegram_channels_safe_json_decode( $raw_meta );
	}
}

if ( ! function_exists( 'crm_telegram_channels_store_payment_message_context' ) ) {
	function crm_telegram_channels_store_payment_message_context( int $order_id, int $company_id, string $chat_id, string $message_type, array $telegram_response ): array {
		$message_id   = crm_telegram_channels_response_message_id( $telegram_response );
		$message_type = sanitize_key( $message_type );
		$message_type = in_array( $message_type, [ 'photo', 'text' ], true ) ? $message_type : 'text';

		$result = [
			'ok'           => false,
			'message_id'   => $message_id,
			'message_type' => $message_type,
		];

		if ( $order_id <= 0 || $company_id <= 0 || $chat_id === '' || $message_id <= 0 ) {
			return $result;
		}

		$current_meta = crm_telegram_channels_get_order_meta( $order_id, $company_id );
		$meta_write   = crm_telegram_channels_merge_order_meta(
			$order_id,
			$company_id,
			[
				'subscription_tg_receipt_chat_id'      => $chat_id,
				'subscription_tg_receipt_message_id'   => $message_id,
				'subscription_tg_receipt_message_type' => $message_type,
				'subscription_tg_receipt_message_ids'  => crm_telegram_channels_append_message_id_list( $current_meta['subscription_tg_receipt_message_ids'] ?? [], $message_id ),
				'subscription_tg_receipt_stored_at'    => current_time( 'mysql', true ),
			]
		);

		$result['ok'] = ! empty( $meta_write['ok'] );

		crm_log_entity(
			$result['ok'] ? 'telegram_channels.payment_message_bound' : 'telegram_channels.payment_message_bind_failed',
			'telegram_channels',
			'update',
			$result['ok']
				? 'Subscription payment message linked to payment order.'
				: 'Failed to link subscription payment message to payment order.',
			'payment_order',
			$order_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'chat_id'      => $chat_id,
					'message_id'   => $message_id,
					'message_type' => $message_type,
				],
			]
		);

		return $result;
	}
}

if ( ! function_exists( 'crm_telegram_channels_send_preparing_message' ) ) {
	function crm_telegram_channels_send_preparing_message( int $company_id, string $chat_id, int $merchant_id = 0 ): int {
		crm_telegram_channels_send_chat_action( $company_id, $chat_id, 'typing', $merchant_id );

		$response = crm_telegram_channels_send_message(
			$company_id,
			$chat_id,
			"⏳ <b>Формируем счёт на оплату</b>\n\nПодготавливаем QR-код. Обычно это занимает несколько секунд.",
			[],
			$merchant_id
		);

		return crm_telegram_channels_response_message_id( $response );
	}
}

if ( ! function_exists( 'crm_telegram_channels_send_photo' ) ) {
	function crm_telegram_channels_send_photo( int $company_id, string $chat_id, string $photo_url, string $caption = '', array $reply_markup = [], int $merchant_id = 0 ): array {
		$token     = crm_telegram_channels_get_subscription_bot_token( $company_id, $merchant_id );
		$photo_url = trim( $photo_url );
		if ( $company_id <= 0 || $chat_id === '' || $token === '' || $photo_url === '' ) {
			return [ 'ok' => false, 'description' => 'Missing company, chat, token or photo.' ];
		}

		$payload = [
			'chat_id'    => $chat_id,
			'photo'      => $photo_url,
			'caption'    => $caption,
			'parse_mode' => 'HTML',
		];

		if ( ! empty( $reply_markup ) ) {
			$payload['reply_markup'] = wp_json_encode( $reply_markup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		return function_exists( 'crm_telegram_bot_api_request' )
			? crm_telegram_bot_api_request( $token, 'sendPhoto', $payload )
			: [ 'ok' => false, 'description' => 'Telegram helper is missing.' ];
	}
}

if ( ! function_exists( 'crm_telegram_channels_send_payment_message' ) ) {
	function crm_telegram_channels_send_payment_message( int $company_id, string $chat_id, array $payment_result, int $merchant_id = 0 ): array {
		$text     = crm_telegram_channels_format_payment_message( $company_id, $payment_result );
		$keyboard = crm_telegram_channels_payment_keyboard( $payment_result );
		$qr_url   = trim( (string) ( $payment_result['qr_url'] ?? '' ) );
		if ( $merchant_id <= 0 ) {
			$merchant_id = (int) ( $payment_result['meta']['merchant_id'] ?? 0 );
		}

		if ( $qr_url !== '' ) {
			$response = crm_telegram_channels_send_photo( $company_id, $chat_id, $qr_url, $text, $keyboard, $merchant_id );
			if ( ! empty( $response['ok'] ) ) {
				$response['message_type'] = 'photo';
				return $response;
			}
		}

		$response = crm_telegram_channels_send_message( $company_id, $chat_id, $text, $keyboard, $merchant_id );
		$response['message_type'] = 'text';

		return $response;
	}
}

if ( ! function_exists( 'crm_telegram_channels_answer_callback' ) ) {
	function crm_telegram_channels_answer_callback( int $company_id, string $callback_query_id, string $text = '', int $merchant_id = 0 ): void {
		$token = crm_telegram_channels_get_subscription_bot_token( $company_id, $merchant_id );
		if ( $token === '' || $callback_query_id === '' || ! function_exists( 'crm_telegram_bot_api_request' ) ) {
			return;
		}

		crm_telegram_bot_api_request(
			$token,
			'answerCallbackQuery',
			[
				'callback_query_id' => $callback_query_id,
				'text'              => $text,
			]
		);
	}
}

if ( ! function_exists( 'crm_telegram_channels_tariffs_keyboard' ) ) {
	function crm_telegram_channels_tariffs_keyboard( int $company_id, int $merchant_id, int $channel_id = 0 ): array {
		$tariffs = $merchant_id > 0 ? crm_telegram_channels_get_merchant_active_tariffs( $company_id, $merchant_id ) : [];
		$rows    = [];
		$status_callback = $channel_id > 0 ? 'sub:status:' . $channel_id : 'sub:status';

		foreach ( $tariffs as $tariff ) {
			$rows[] = [
				[
					'text' => sprintf(
						'%s · %s %s',
						(string) $tariff->title,
						rtrim( rtrim( number_format( (float) $tariff->price_amount, 2, '.', '' ), '0' ), '.' ),
						(string) $tariff->price_currency
					),
					'callback_data' => 'sub:pay:' . (int) $tariff->id,
				],
			];
		}

		$rows[] = [
			[ 'text' => 'Статус', 'callback_data' => $status_callback ],
			[ 'text' => 'Помощь',  'callback_data' => 'sub:help' ],
		];

		return [ 'inline_keyboard' => $rows ];
	}
}

if ( ! function_exists( 'crm_telegram_channels_main_keyboard' ) ) {
	function crm_telegram_channels_main_keyboard( int $channel_id = 0 ): array {
		$status_callback = $channel_id > 0 ? 'sub:status:' . $channel_id : 'sub:status';
		$tariffs_callback = $channel_id > 0 ? 'sub:tariffs:' . $channel_id : 'sub:tariffs';
		$invite_callback = $channel_id > 0 ? 'sub:invite:' . $channel_id : 'sub:invite';

		return [
			'inline_keyboard' => [
				[
					[ 'text' => 'Моя подписка', 'callback_data' => $status_callback ],
					[ 'text' => 'Тарифы',       'callback_data' => $tariffs_callback ],
				],
				[
					[ 'text' => 'Ссылка в канал', 'callback_data' => $invite_callback ],
					[ 'text' => 'Помощь',         'callback_data' => 'sub:help' ],
				],
			],
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_subscriber' ) ) {
	function crm_telegram_channels_get_subscriber( int $company_id, int $channel_id, string $telegram_user_id ): ?object {
		global $wpdb;

		if ( $company_id <= 0 || $channel_id <= 0 || $telegram_user_id === '' ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM crm_telegram_channel_subscribers WHERE company_id = %d AND channel_id = %d AND telegram_user_id = %s LIMIT 1',
				$company_id,
				$channel_id,
				$telegram_user_id
			)
		) ?: null;
	}
}

if ( ! function_exists( 'crm_telegram_channels_context_label' ) ) {
	function crm_telegram_channels_context_label( array $context ): string {
		$channel_title = trim( (string) ( $context['channel_title'] ?? '' ) );
		$merchant_name = trim( (string) ( $context['merchant_name'] ?? '' ) );
		$merchant_user = trim( (string) ( $context['merchant_username'] ?? '' ) );
		$merchant_id   = (int) ( $context['merchant_id'] ?? 0 );

		if ( $channel_title === '' ) {
			$channel_title = 'Telegram-канал #' . (int) ( $context['channel_id'] ?? 0 );
		}

		if ( $merchant_name === '' && $merchant_user !== '' ) {
			$merchant_name = '@' . ltrim( $merchant_user, '@' );
		}
		if ( $merchant_name === '' && $merchant_id > 0 ) {
			$merchant_name = 'Мерчант #' . $merchant_id;
		}

		return $merchant_name !== '' ? $channel_title . ' · ' . $merchant_name : $channel_title;
	}
}

if ( ! function_exists( 'crm_telegram_channels_compact_button_text' ) ) {
	function crm_telegram_channels_compact_button_text( string $text, int $limit = 56 ): string {
		$text = trim( wp_strip_all_tags( $text ) );
		if ( $text === '' ) {
			return 'Telegram-канал';
		}
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text ) > $limit ? mb_substr( $text, 0, max( 1, $limit - 3 ) ) . '...' : $text;
		}

		return strlen( $text ) > $limit ? substr( $text, 0, max( 1, $limit - 3 ) ) . '...' : $text;
	}
}

if ( ! function_exists( 'crm_telegram_channels_context_required_message' ) ) {
	function crm_telegram_channels_context_required_message(): string {
		return "Тарифы и invite-ссылки привязаны к конкретному мерчанту и каналу.\n\nОткройте персональную ссылку оплаты, которую выдал мерчант. После первого входа по ссылке subscription bot сможет восстановить ваш канал.";
	}
}

if ( ! function_exists( 'crm_telegram_channels_default_context_for_merchant_bot' ) ) {
	function crm_telegram_channels_default_context_for_merchant_bot( int $company_id, int $merchant_id = 0 ): ?array {
		$merchant_id = crm_telegram_channels_effective_subscription_merchant_id( $merchant_id );
		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return null;
		}

		$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
		if ( is_wp_error( $merchant ) ) {
			return null;
		}

		$channel = crm_telegram_channels_get_merchant_channel( $company_id, $merchant_id );
		if ( ! $channel ) {
			return null;
		}

		return [
			'channel_id'              => (int) $channel->id,
			'merchant_id'             => $merchant_id,
			'channel_title'           => (string) ( $channel->title ?? '' ),
			'merchant_name'           => (string) ( $merchant->name ?? '' ),
			'merchant_username'       => (string) ( $merchant->telegram_username ?? '' ),
			'has_active_subscription' => false,
			'source'                  => 'merchant_bot',
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_resolve_client_contexts' ) ) {
	function crm_telegram_channels_resolve_client_contexts( int $company_id, string $telegram_user_id, string $chat_id = '', int $merchant_id = 0 ): array {
		global $wpdb;

		if ( $company_id <= 0 || $telegram_user_id === '' ) {
			return [];
		}

		$now            = current_time( 'mysql', true );
		$contexts       = [];
		$merchant_id    = crm_telegram_channels_effective_subscription_merchant_id( $merchant_id );
		$merchant_where = $merchant_id > 0 ? 's.merchant_id = %d' : 's.merchant_id > 0';
		$params         = [ $company_id ];
		if ( $merchant_id > 0 ) {
			$params[] = $merchant_id;
		}
		$params[] = $telegram_user_id;
		$params[] = $now;

		$subscriber_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.channel_id,
				        s.merchant_id,
				        s.subscription_until,
				        c.title AS channel_title,
				        m.name AS merchant_name,
				        m.telegram_username AS merchant_username
				 FROM crm_telegram_channel_subscribers s
				 INNER JOIN crm_telegram_channels c
				         ON c.id = s.channel_id
				        AND c.company_id = s.company_id
				        AND c.merchant_id = s.merchant_id
				 LEFT JOIN crm_merchants m
				        ON m.id = s.merchant_id
				       AND m.company_id = s.company_id
				 WHERE s.company_id = %d
				   AND {$merchant_where}
				   AND s.telegram_user_id = %s
				   AND s.status = 'active'
				   AND s.subscription_until IS NOT NULL
				   AND s.subscription_until > %s
				 ORDER BY s.subscription_until DESC
				 LIMIT 20",
				$params
			)
		) ?: [];

		foreach ( $subscriber_rows as $row ) {
			$channel_id = (int) $row->channel_id;
			if ( $channel_id <= 0 ) {
				continue;
			}

			$contexts[ $channel_id ] = [
				'channel_id'              => $channel_id,
				'merchant_id'             => (int) $row->merchant_id,
				'channel_title'           => (string) ( $row->channel_title ?? '' ),
				'merchant_name'           => (string) ( $row->merchant_name ?? '' ),
				'merchant_username'       => (string) ( $row->merchant_username ?? '' ),
				'subscription_until'      => (string) ( $row->subscription_until ?? '' ),
				'has_active_subscription' => true,
				'source'                  => 'subscriber',
			];
		}

		$identity_conditions = [];
			$sale_merchant_where = $merchant_id > 0 ? 'sl.merchant_id = %d' : 'sl.merchant_id > 0';
			$params              = [ $company_id ];
			if ( $merchant_id > 0 ) {
				$params[] = $merchant_id;
			}
			$params[] = $now;
			if ( preg_match( '/^\d+$/', $telegram_user_id ) ) {
				$identity_conditions[] = 'sl.client_telegram_user_id = %d';
				$params[]              = (int) $telegram_user_id;
		}
		if ( $chat_id !== '' ) {
			$identity_conditions[] = 'sl.client_chat_id = %s';
			$params[]              = $chat_id;
		}

		if ( ! empty( $identity_conditions ) ) {
			$sql = "SELECT sl.id AS sale_id,
			               sl.channel_id,
			               sl.merchant_id,
			               sl.tariff_id AS sale_tariff_id,
			               sl.status AS sale_status,
			               sl.updated_at,
			               sl.expires_at,
			               c.title AS channel_title,
			               m.name AS merchant_name,
			               m.telegram_username AS merchant_username
			        FROM crm_telegram_channel_sales sl
			        INNER JOIN crm_telegram_channels c
			                ON c.id = sl.channel_id
			               AND c.company_id = sl.company_id
			               AND c.merchant_id = sl.merchant_id
			        LEFT JOIN crm_merchants m
			               ON m.id = sl.merchant_id
			              AND m.company_id = sl.company_id
			        WHERE sl.company_id = %d
			          AND {$sale_merchant_where}
			          AND sl.status IN ('new','opened','payment_created','cancelled','expired')
			          AND (sl.expires_at IS NULL OR sl.expires_at > %s)
			          AND (" . implode( ' OR ', $identity_conditions ) . ")
			        ORDER BY sl.updated_at DESC
			        LIMIT 20";

			$sale_rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?: [];
			foreach ( $sale_rows as $row ) {
				$channel_id = (int) $row->channel_id;
				if ( $channel_id <= 0 || isset( $contexts[ $channel_id ] ) ) {
					continue;
				}

				$contexts[ $channel_id ] = [
					'channel_id'              => $channel_id,
					'merchant_id'             => (int) $row->merchant_id,
					'channel_title'           => (string) ( $row->channel_title ?? '' ),
					'merchant_name'           => (string) ( $row->merchant_name ?? '' ),
					'merchant_username'       => (string) ( $row->merchant_username ?? '' ),
					'sale_id'                 => (int) $row->sale_id,
					'sale_tariff_id'          => (int) ( $row->sale_tariff_id ?? 0 ),
					'sale_status'             => (string) ( $row->sale_status ?? '' ),
					'has_active_subscription' => false,
					'source'                  => 'sale',
				];
			}
		}

		return array_values( $contexts );
	}
}

if ( ! function_exists( 'crm_telegram_channels_find_client_context' ) ) {
	function crm_telegram_channels_find_client_context( int $company_id, array $client, int $channel_id ): ?array {
		if ( $channel_id <= 0 ) {
			return null;
		}

		$contexts = crm_telegram_channels_resolve_client_contexts(
			$company_id,
			trim( (string) ( $client['telegram_user_id'] ?? '' ) ),
			trim( (string) ( $client['chat_id'] ?? '' ) )
		);

		foreach ( $contexts as $context ) {
			if ( (int) ( $context['channel_id'] ?? 0 ) === $channel_id ) {
				return $context;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'crm_telegram_channels_get_tariff_context' ) ) {
	function crm_telegram_channels_get_tariff_context( int $company_id, int $tariff_id ): ?array {
		global $wpdb;

		if ( $company_id <= 0 || $tariff_id <= 0 ) {
			return null;
		}

		$merchant_id = crm_telegram_channels_current_subscription_merchant_id();
		$merchant_sql = $merchant_id > 0 ? 'AND c.merchant_id = %d' : '';
		$params = [ $tariff_id, $company_id ];
		if ( $merchant_id > 0 ) {
			$params[] = $merchant_id;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t.id AS tariff_id,
				        t.channel_id,
				        c.merchant_id,
				        c.title AS channel_title,
				        m.name AS merchant_name,
				        m.telegram_username AS merchant_username
				 FROM crm_telegram_channel_tariffs t
				 INNER JOIN crm_telegram_channels c
				         ON c.id = t.channel_id
				        AND c.company_id = t.company_id
				 LEFT JOIN crm_merchants m
				        ON m.id = c.merchant_id
				       AND m.company_id = c.company_id
				 WHERE t.id = %d
				   AND t.company_id = %d
				   AND t.status = 'active'
				   AND t.price_amount > 0
				   AND c.merchant_id > 0
				   {$merchant_sql}
				 LIMIT 1",
				$params
			)
		);
		if ( ! $row ) {
			return null;
		}

		return [
			'tariff_id'               => (int) $row->tariff_id,
			'channel_id'              => (int) $row->channel_id,
			'merchant_id'             => (int) $row->merchant_id,
			'channel_title'           => (string) ( $row->channel_title ?? '' ),
			'merchant_name'           => (string) ( $row->merchant_name ?? '' ),
			'merchant_username'       => (string) ( $row->merchant_username ?? '' ),
			'has_active_subscription' => false,
			'source'                  => 'tariff',
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_context_selection_keyboard' ) ) {
	function crm_telegram_channels_context_selection_keyboard( array $contexts, string $action ): array {
		$action = in_array( $action, [ 'main', 'tariffs', 'invite', 'status' ], true ) ? $action : 'main';
		$rows   = [];

		foreach ( $contexts as $context ) {
			$channel_id = (int) ( $context['channel_id'] ?? 0 );
			if ( $channel_id <= 0 ) {
				continue;
			}

			$rows[] = [
				[
					'text'          => crm_telegram_channels_compact_button_text( crm_telegram_channels_context_label( $context ) ),
					'callback_data' => 'sub:' . $action . ':' . $channel_id,
				],
			];
		}

		$rows[] = [
			[ 'text' => 'Помощь', 'callback_data' => 'sub:help' ],
		];

		return [ 'inline_keyboard' => $rows ];
	}
}

if ( ! function_exists( 'crm_telegram_channels_send_tariffs_for_context' ) ) {
	function crm_telegram_channels_send_tariffs_for_context( int $company_id, array $client, array $context ): void {
		$chat_id     = trim( (string) ( $client['chat_id'] ?? '' ) );
		$merchant_id = (int) ( $context['merchant_id'] ?? 0 );
		$channel_id  = (int) ( $context['channel_id'] ?? 0 );
		if ( $chat_id === '' || $merchant_id <= 0 || $channel_id <= 0 ) {
			return;
		}

		$readiness = crm_telegram_channels_get_readiness_status( $company_id, true, $merchant_id );
		if ( empty( $readiness['is_ready'] ) ) {
			crm_telegram_channels_send_not_configured( $company_id, $chat_id, $merchant_id );
			return;
		}

		$tariffs = crm_telegram_channels_get_merchant_active_tariffs( $company_id, $merchant_id );
		if ( empty( $tariffs ) ) {
			crm_telegram_channels_send_message(
				$company_id,
				$chat_id,
				'Для выбранного канала пока нет активных тарифов.',
				crm_telegram_channels_main_keyboard( $channel_id )
			);
			return;
		}

		crm_telegram_channels_send_message(
			$company_id,
			$chat_id,
			'Канал: <b>' . esc_html( crm_telegram_channels_context_label( $context ) ) . "</b>\n\nВыберите тариф:",
			crm_telegram_channels_tariffs_keyboard( $company_id, $merchant_id, $channel_id )
		);
	}
}

if ( ! function_exists( 'crm_telegram_channels_send_context_menu' ) ) {
	function crm_telegram_channels_send_context_menu( int $company_id, array $client, array $context ): void {
		$chat_id    = trim( (string) ( $client['chat_id'] ?? '' ) );
		$channel_id = (int) ( $context['channel_id'] ?? 0 );
		if ( $chat_id === '' || $channel_id <= 0 ) {
			return;
		}

		$lines = [
			'Канал: <b>' . esc_html( crm_telegram_channels_context_label( $context ) ) . '</b>',
		];
		if ( ! empty( $context['has_active_subscription'] ) && ! empty( $context['subscription_until'] ) ) {
			$until   = crm_format_dt( (string) $context['subscription_until'], $company_id ) ?: (string) $context['subscription_until'];
			$lines[] = 'Подписка активна до: <b>' . esc_html( $until ) . '</b>';
		} else {
			$lines[] = 'Оплата по этому каналу еще не завершена.';
		}
		$lines[] = '';
		$lines[] = 'Выберите действие.';

		crm_telegram_channels_send_message( $company_id, $chat_id, implode( "\n", $lines ), crm_telegram_channels_main_keyboard( $channel_id ) );
	}
}

if ( ! function_exists( 'crm_telegram_channels_handle_contextual_tariffs_request' ) ) {
	function crm_telegram_channels_handle_contextual_tariffs_request( int $company_id, array $client, int $channel_id = 0 ): void {
		$chat_id = trim( (string) ( $client['chat_id'] ?? '' ) );
		if ( $chat_id === '' ) {
			return;
		}

		if ( $channel_id > 0 ) {
			$context = crm_telegram_channels_find_client_context( $company_id, $client, $channel_id );
			if ( ! $context ) {
				crm_telegram_channels_send_message( $company_id, $chat_id, crm_telegram_channels_context_required_message(), crm_telegram_channels_main_keyboard() );
				return;
			}
			crm_telegram_channels_send_tariffs_for_context( $company_id, $client, $context );
			return;
		}

		$contexts = crm_telegram_channels_resolve_client_contexts(
			$company_id,
			trim( (string) ( $client['telegram_user_id'] ?? '' ) ),
			$chat_id
		);
		if ( empty( $contexts ) ) {
			$default_context = crm_telegram_channels_default_context_for_merchant_bot( $company_id );
			if ( $default_context ) {
				crm_telegram_channels_send_tariffs_for_context( $company_id, $client, $default_context );
				return;
			}
			crm_telegram_channels_send_message( $company_id, $chat_id, crm_telegram_channels_context_required_message(), crm_telegram_channels_main_keyboard() );
			return;
		}
		if ( count( $contexts ) === 1 ) {
			crm_telegram_channels_send_tariffs_for_context( $company_id, $client, $contexts[0] );
			return;
		}

		crm_telegram_channels_send_message(
			$company_id,
			$chat_id,
			'Выберите канал, для которого показать тарифы:',
			crm_telegram_channels_context_selection_keyboard( $contexts, 'tariffs' )
		);
	}
}

if ( ! function_exists( 'crm_telegram_channels_handle_contextual_invite_request' ) ) {
	function crm_telegram_channels_handle_contextual_invite_request( int $company_id, array $client, int $channel_id = 0 ): void {
		$chat_id          = trim( (string) ( $client['chat_id'] ?? '' ) );
		$telegram_user_id = trim( (string) ( $client['telegram_user_id'] ?? '' ) );
		if ( $chat_id === '' || $telegram_user_id === '' ) {
			return;
		}

		$contexts = crm_telegram_channels_resolve_client_contexts( $company_id, $telegram_user_id, $chat_id );
		$active   = array_values(
			array_filter(
				$contexts,
				static function ( array $context ) use ( $channel_id ): bool {
					if ( empty( $context['has_active_subscription'] ) ) {
						return false;
					}

					return $channel_id <= 0 || (int) ( $context['channel_id'] ?? 0 ) === $channel_id;
				}
			)
		);

		if ( empty( $active ) ) {
			crm_telegram_channels_send_message( $company_id, $chat_id, 'Активная подписка не найдена.', $channel_id > 0 ? crm_telegram_channels_main_keyboard( $channel_id ) : crm_telegram_channels_main_keyboard() );
			return;
		}

		if ( count( $active ) > 1 && $channel_id <= 0 ) {
			crm_telegram_channels_send_message(
				$company_id,
				$chat_id,
				'Выберите канал, для которого нужна invite-ссылка:',
				crm_telegram_channels_context_selection_keyboard( $active, 'invite' )
			);
			return;
		}

		$target_channel_id = (int) ( $active[0]['channel_id'] ?? 0 );
		$result            = crm_telegram_channels_reissue_invite_for_client( $company_id, $telegram_user_id, $chat_id, $target_channel_id );
		crm_telegram_channels_send_message( $company_id, $chat_id, (string) $result['message'], $result['keyboard'] ?? crm_telegram_channels_main_keyboard( $target_channel_id ) );
	}
}

if ( ! function_exists( 'crm_telegram_channels_handle_contextual_main_request' ) ) {
	function crm_telegram_channels_handle_contextual_main_request( int $company_id, array $client, int $channel_id = 0 ): void {
		$chat_id = trim( (string) ( $client['chat_id'] ?? '' ) );
		if ( $chat_id === '' ) {
			return;
		}

		if ( $channel_id > 0 ) {
			$context = crm_telegram_channels_find_client_context( $company_id, $client, $channel_id );
			if ( $context ) {
				crm_telegram_channels_send_context_menu( $company_id, $client, $context );
				return;
			}
		}

		$contexts = crm_telegram_channels_resolve_client_contexts(
			$company_id,
			trim( (string) ( $client['telegram_user_id'] ?? '' ) ),
			$chat_id
		);
		if ( empty( $contexts ) ) {
			$default_context = crm_telegram_channels_default_context_for_merchant_bot( $company_id );
			if ( $default_context ) {
				crm_telegram_channels_send_context_menu( $company_id, $client, $default_context );
				return;
			}
			crm_telegram_channels_send_message( $company_id, $chat_id, crm_telegram_channels_context_required_message(), crm_telegram_channels_main_keyboard() );
			return;
		}
		if ( count( $contexts ) === 1 ) {
			crm_telegram_channels_send_context_menu( $company_id, $client, $contexts[0] );
			return;
		}

		crm_telegram_channels_send_message(
			$company_id,
			$chat_id,
			'Выберите канал:',
			crm_telegram_channels_context_selection_keyboard( $contexts, 'main' )
		);
	}
}

if ( ! function_exists( 'crm_telegram_channels_status_text' ) ) {
	function crm_telegram_channels_status_text( int $company_id, string $telegram_user_id, int $channel_id = 0 ): string {
		global $wpdb;

		if ( $company_id <= 0 || $telegram_user_id === '' ) {
			return crm_telegram_channels_text( $company_id, 'not_configured' );
		}

		$where        = '';
		$where_params = [];
		$merchant_id = crm_telegram_channels_current_subscription_merchant_id();
		if ( $merchant_id > 0 ) {
			$where .= ' AND s.merchant_id = %d';
			$where_params[] = $merchant_id;
		}
		if ( $channel_id > 0 ) {
			$where    .= ' AND s.channel_id = %d';
			$where_params[] = $channel_id;
		}
		$params = array_merge( [ $company_id, $telegram_user_id, current_time( 'mysql', true ) ], $where_params );

		$sql = "SELECT s.*, c.title AS channel_title
		        FROM crm_telegram_channel_subscribers s
		        INNER JOIN crm_telegram_channels c
		                ON c.id = s.channel_id
		               AND c.company_id = s.company_id
		               AND c.merchant_id = s.merchant_id
		        WHERE s.company_id = %d
		          AND s.merchant_id > 0
		          AND s.telegram_user_id = %s
		          AND s.status = 'active'
		          AND s.subscription_until IS NOT NULL
		          AND s.subscription_until > %s
		          {$where}
		        ORDER BY s.subscription_until DESC";

		$subscribers = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?: [];

		if ( empty( $subscribers ) ) {
			return "Подписка пока не активна.\n\nНажмите «Тарифы», чтобы выбрать срок доступа и оплатить подписку.";
		}

		if ( count( $subscribers ) === 1 ) {
			$until = crm_format_dt( (string) $subscribers[0]->subscription_until, $company_id ) ?: (string) $subscribers[0]->subscription_until;
			return crm_telegram_channels_text( $company_id, 'subscription_active', [ 'until' => $until ], $merchant_id );
		}

		$lines = [ 'Активные подписки:' ];
		foreach ( $subscribers as $subscriber ) {
			$until = crm_format_dt( (string) $subscriber->subscription_until, $company_id ) ?: (string) $subscriber->subscription_until;
			$lines[] = '• ' . (string) ( $subscriber->channel_title ?? 'Telegram-канал' ) . ' — до ' . $until;
		}

		return implode( "\n", $lines );
	}
}

if ( ! function_exists( 'crm_telegram_channels_payment_result_from_order' ) ) {
	function crm_telegram_channels_payment_result_from_order( object $order ): array {
		$meta = [];
		if ( ! empty( $order->meta_json ) ) {
			$decoded = json_decode( (string) $order->meta_json, true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}

		$payment_link      = trim( (string) ( $order->payment_link ?? '' ) );
		$qrc_id            = trim( (string) ( $order->qrc_id ?? '' ) );
		$merchant_order_id = trim( (string) ( $order->merchant_order_id ?? '' ) );
		$qr_order_id       = $merchant_order_id !== '' ? $merchant_order_id : (string) (int) ( $order->id ?? 0 );
		$qr_url            = null;

		if ( $payment_link !== '' && $qrc_id !== '' && function_exists( 'crm_fintech_qr_url' ) ) {
			$qr_url = crm_fintech_qr_url( $payment_link, $qrc_id, $qr_order_id );
		}

		$payment_amount_value = 0.0;
		if ( isset( $order->payment_amount_value ) && $order->payment_amount_value !== null ) {
			$payment_amount_value = (float) $order->payment_amount_value;
		} elseif ( isset( $order->merchant_requested_rub_value ) && $order->merchant_requested_rub_value !== null ) {
			$payment_amount_value = (float) $order->merchant_requested_rub_value;
		}

		$payment_currency_code = strtoupper( trim( (string) ( $order->payment_currency_code ?? '' ) ) );
		if ( $payment_currency_code === '' ) {
			$payment_currency_code = 'RUB';
		}

		return [
			'success'              => true,
			'order_db_id'          => (int) ( $order->id ?? 0 ),
			'merchant_order_id'    => $merchant_order_id,
			'provider_order_id'    => trim( (string) ( $order->provider_order_id ?? '' ) ),
			'status_code'          => sanitize_key( (string) ( $order->status_code ?? '' ) ),
			'payment_link'         => $payment_link,
			'qrc_id'               => $qrc_id,
			'payload'              => $payment_link,
			'qr_url'               => $qr_url,
			'provider'             => trim( (string) ( $order->provider_code ?? '' ) ),
			'amount_asset_code'    => isset( $order->amount_asset_code ) ? strtoupper( trim( (string) $order->amount_asset_code ) ) : null,
			'amount_asset_value'   => isset( $order->amount_asset_value ) && $order->amount_asset_value !== null ? (float) $order->amount_asset_value : null,
			'payment_amount_rub'   => $payment_amount_value,
			'payment_amount_value' => $payment_amount_value,
			'payment_currency_code'=> $payment_currency_code,
			'warning'              => null,
			'meta'                 => $meta,
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_refresh_payment_result' ) ) {
	function crm_telegram_channels_refresh_payment_result( int $company_id, int $order_id, array $client = [] ): array {
		global $wpdb;

		if ( $company_id <= 0 || $order_id <= 0 ) {
			return [ 'success' => false, 'message' => 'Платёж не найден.' ];
		}

		$order = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT *
				 FROM crm_fintech_payment_orders
				 WHERE id = %d
				   AND company_id = %d
				   AND source_channel = %s
				 LIMIT 1',
				$order_id,
				$company_id,
				crm_telegram_channels_source_channel()
			)
		);
		if ( ! $order ) {
			return [ 'success' => false, 'message' => 'Платёж не найден.' ];
		}

		$meta = [];
		if ( ! empty( $order->meta_json ) ) {
			$decoded = json_decode( (string) $order->meta_json, true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}
		if ( (string) ( $meta['module'] ?? '' ) !== 'telegram_channels' ) {
			return [ 'success' => false, 'message' => 'Платёж недоступен в этом контуре.' ];
		}

		$client_chat_id     = trim( (string) ( $client['chat_id'] ?? '' ) );
		$client_telegram_id = trim( (string) ( $client['telegram_user_id'] ?? '' ) );
		$meta_chat_id       = trim( (string) ( $meta['chat_id'] ?? '' ) );
		$meta_telegram_id   = trim( (string) ( $meta['telegram_user_id'] ?? '' ) );

		if ( $client_chat_id !== '' && $meta_chat_id !== '' && $client_chat_id !== $meta_chat_id ) {
			return [ 'success' => false, 'message' => 'Этот платёж относится к другому чату.' ];
		}
		if ( $client_telegram_id !== '' && $meta_telegram_id !== '' && $client_telegram_id !== $meta_telegram_id ) {
			return [ 'success' => false, 'message' => 'Этот платёж относится к другому пользователю.' ];
		}

		if (
			( empty( $order->payment_link ) || empty( $order->qrc_id ) )
			&& function_exists( 'crm_fintech_poll_order_status' )
		) {
			crm_fintech_poll_order_status( $order, 'telegram_channels_payment_refresh' );
			$order = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT *
					 FROM crm_fintech_payment_orders
					 WHERE id = %d
					   AND company_id = %d
					   AND source_channel = %s
					 LIMIT 1',
					$order_id,
					$company_id,
					crm_telegram_channels_source_channel()
				)
			);
		}

		if ( ! $order ) {
			return [ 'success' => false, 'message' => 'Платёж не найден после обновления.' ];
		}

		return crm_telegram_channels_payment_result_from_order( $order );
	}
}

if ( ! function_exists( 'crm_telegram_channels_format_payment_message' ) ) {
	function crm_telegram_channels_format_payment_message( int $company_id, array $payment_result ): string {
		$meta             = isset( $payment_result['meta'] ) && is_array( $payment_result['meta'] ) ? $payment_result['meta'] : [];
		$payment_amount   = isset( $payment_result['payment_amount_value'] ) ? (float) $payment_result['payment_amount_value'] : 0.0;
		if ( $payment_amount <= 0 && isset( $payment_result['payment_amount_rub'] ) ) {
			$payment_amount = (float) $payment_result['payment_amount_rub'];
		}
		$payment_currency = strtoupper( trim( (string) ( $payment_result['payment_currency_code'] ?? 'RUB' ) ) );
		if ( $payment_currency === '' ) {
			$payment_currency = 'RUB';
		}

		$tariff_title = trim( (string) ( $meta['tariff_title'] ?? $meta['tariff_code'] ?? '' ) );
		if ( $tariff_title === '' ) {
			$tariff_title = 'Telegram access';
		}
		$duration_days = (int) ( $meta['duration_days'] ?? 0 );
		$period_label  = $duration_days > 0 ? $duration_days . ' дн.' : '—';
		$receipt_id    = ! empty( $payment_result['order_db_id'] )
			? '#' . (int) $payment_result['order_db_id']
			: trim( (string) ( $payment_result['merchant_order_id'] ?? '' ) );
		$has_payment_payload = ! empty( $payment_result['payment_link'] ) || ! empty( $payment_result['qr_url'] );

		if ( ! $has_payment_payload ) {
			$lines = [
				'<b>Счёт создан, QR-код оплаты ещё формируется.</b>',
				'',
			];

			if ( $payment_amount > 0 ) {
				$lines[] = 'Сумма: <b>' . esc_html( crm_tg_receipt_format_amount( $payment_amount, $payment_currency, 2, true ) ) . '</b>';
			}
			if ( $receipt_id !== '' ) {
				$lines[] = 'ID: <code>' . esc_html( $receipt_id ) . '</code>';
			}

			$lines[] = '';
			$lines[] = 'Нажмите «Обновить оплату» через несколько секунд. Новый счёт при этом не создаётся.';

			return implode( "\n", $lines );
		}

		$main_rows = [
			[
				'label' => 'AMOUNT:',
				'value' => $payment_amount > 0
					? crm_tg_receipt_format_amount( $payment_amount, $payment_currency, 2, true )
					: '—',
			],
			[
				'label' => 'PLAN:',
				'value' => $tariff_title,
			],
			[
				'label' => 'PERIOD:',
				'value' => $period_label,
			],
		];

		$meta_rows = [
			[
				'label' => 'TIME:',
				'value' => current_time( 'd.m.Y H:i' ),
			],
			[
				'label' => 'ID:',
				'value' => $receipt_id !== '' ? $receipt_id : '—',
			],
			[
				'label' => 'STATUS:',
				'value' => 'Calculated',
			],
			[
				'label' => 'FEE:',
				'value' => 'included',
			],
		];

		$text = crm_tg_receipt_block(
			$main_rows,
			$meta_rows,
			[
				'Thank you for choosing us',
				'Access activates after payment',
			],
			'SUBSCRIPTION RECEIPT'
		);

		if ( ! empty( $payment_result['payment_link'] ) ) {
			$text .= "\n\nPayment link:\n<code>" . esc_html( (string) $payment_result['payment_link'] ) . '</code>';
		}
		if ( ! empty( $payment_result['warning'] ) ) {
			$text .= "\n\nNote:\n" . esc_html( (string) $payment_result['warning'] );
		}

		return $text;
	}
}

if ( ! function_exists( 'crm_telegram_channels_payment_keyboard' ) ) {
	function crm_telegram_channels_payment_keyboard( array $payment_result ): array {
		$channel_id       = (int) ( $payment_result['meta']['channel_id'] ?? 0 );
		$status_callback  = $channel_id > 0 ? 'sub:status:' . $channel_id : 'sub:status';
		$tariffs_callback = $channel_id > 0 ? 'sub:tariffs:' . $channel_id : 'sub:tariffs';
		$order_id         = (int) ( $payment_result['order_db_id'] ?? 0 );
		$rows = [];
		if ( ! empty( $payment_result['payment_link'] ) ) {
			$rows[] = [
				[ 'text' => 'Оплатить', 'url' => (string) $payment_result['payment_link'] ],
			];
		}
		if ( empty( $payment_result['payment_link'] ) && empty( $payment_result['qr_url'] ) && $order_id > 0 ) {
			$rows[] = [
				[ 'text' => 'Обновить оплату', 'callback_data' => 'sub:payment:' . $order_id ],
			];
		}
		$rows[] = [
			[ 'text' => 'Моя подписка', 'callback_data' => $status_callback ],
			[ 'text' => 'Тарифы',       'callback_data' => $tariffs_callback ],
		];

		return [ 'inline_keyboard' => $rows ];
	}
}

if ( ! function_exists( 'crm_telegram_channels_send_not_configured' ) ) {
	function crm_telegram_channels_send_not_configured( int $company_id, string $chat_id, int $merchant_id = 0 ): void {
		crm_telegram_channels_get_readiness_status( $company_id, true, $merchant_id );
		crm_telegram_channels_send_message(
			$company_id,
			$chat_id,
			crm_telegram_channels_text( $company_id, 'not_configured', [], $merchant_id ),
			[],
			$merchant_id
		);
	}
}

if ( ! function_exists( 'crm_telegram_channels_handle_client_start' ) ) {
	function crm_telegram_channels_handle_client_start( int $company_id, array $client, string $payload ): bool {
		global $wpdb;

		$chat_id          = trim( (string) ( $client['chat_id'] ?? '' ) );
		$telegram_user_id = trim( (string) ( $client['telegram_user_id'] ?? '' ) );
		if ( $company_id <= 0 || $chat_id === '' || $telegram_user_id === '' ) {
			return false;
		}

		if ( strpos( $payload, 'chsetup_' ) === 0 && function_exists( 'crm_telegram_channels_handle_channel_setup_start' ) ) {
			return crm_telegram_channels_handle_channel_setup_start( $company_id, $client, $payload );
		}

		$sale = null;
		if ( $payload !== '' ) {
			$sale = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT *
					 FROM crm_telegram_channel_sales
					 WHERE company_id = %d
					   AND start_payload = %s
					 LIMIT 1",
					$company_id,
					$payload
				)
			);
		}

		if ( $payload !== '' && ! $sale ) {
			crm_telegram_channels_send_message( $company_id, $chat_id, 'Ссылка продажи не найдена или устарела.' );
			return true;
		}

		if ( $sale ) {
			$callback_merchant_id = crm_telegram_channels_current_subscription_merchant_id();
			$sale_merchant_id     = (int) ( $sale->merchant_id ?? 0 );
			if ( $callback_merchant_id > 0 && $sale_merchant_id !== $callback_merchant_id ) {
				crm_telegram_channels_send_message( $company_id, $chat_id, 'Ссылка продажи не относится к этому subscription bot.' );
				return true;
			}
		}

		if ( $sale && ! empty( $sale->expires_at ) && strtotime( (string) $sale->expires_at . ' UTC' ) < time() ) {
			$wpdb->update(
				'crm_telegram_channel_sales',
				[ 'status' => 'expired', 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => (int) $sale->id, 'company_id' => $company_id ],
				[ '%s', '%s' ],
				[ '%d', '%d' ]
			);
			crm_telegram_channels_send_message( $company_id, $chat_id, 'Ссылка продажи истекла. Попросите новую ссылку.' );
			return true;
		}

		if ( $sale ) {
			$sale_merchant_id = (int) ( $sale->merchant_id ?? 0 );
			if ( $sale_merchant_id <= 0 ) {
				crm_telegram_channels_send_message( $company_id, $chat_id, 'Ссылка продажи устарела: она не привязана к мерчанту. Попросите новую ссылку.' );
				return true;
			}

			$wpdb->update(
				'crm_telegram_channel_sales',
				[
					'client_telegram_user_id' => preg_match( '/^\d+$/', $telegram_user_id ) ? $telegram_user_id : null,
					'client_chat_id'          => $chat_id,
					'status'                  => (string) $sale->status === 'new' ? 'opened' : (string) $sale->status,
					'opened_at'               => current_time( 'mysql', true ),
					'updated_at'              => current_time( 'mysql', true ),
				],
				[ 'id' => (int) $sale->id, 'company_id' => $company_id ],
				[ '%d', '%s', '%s', '%s', '%s' ],
				[ '%d', '%d' ]
			);

			crm_log_entity(
				'telegram_channels.client_started',
				'telegram_channels',
				'update',
				'Клиент открыл subscription bot по ссылке продажи.',
				'telegram_channel_sale',
				(int) $sale->id,
				[
					'org_id'  => $company_id,
					'context' => [
						'tariff_id'   => (int) $sale->tariff_id,
						'merchant_id' => $sale_merchant_id,
					],
				]
			);

			if ( (int) $sale->tariff_id > 0 ) {
				$preparing_message_id = crm_telegram_channels_send_preparing_message( $company_id, $chat_id );
				$result = crm_telegram_channels_create_payment_order( $company_id, (int) $sale->tariff_id, $client, (int) $sale->id );
				if ( empty( $result['success'] ) ) {
					$error_response = crm_telegram_channels_send_message( $company_id, $chat_id, (string) ( $result['message'] ?? 'Не удалось создать оплату.' ) );
					if ( ! empty( $error_response['ok'] ) && $preparing_message_id > 0 ) {
						crm_telegram_channels_delete_message( $company_id, $chat_id, $preparing_message_id );
					}
					return true;
				}

				crm_telegram_channels_send_chat_action( $company_id, $chat_id, ! empty( $result['qr_url'] ) ? 'upload_photo' : 'typing' );
				$payment_response = crm_telegram_channels_send_payment_message( $company_id, $chat_id, $result );
				if ( ! empty( $payment_response['ok'] ) && ! empty( $result['order_db_id'] ) ) {
					crm_telegram_channels_store_payment_message_context(
						(int) $result['order_db_id'],
						$company_id,
						$chat_id,
						(string) ( $payment_response['message_type'] ?? ( ! empty( $result['qr_url'] ) ? 'photo' : 'text' ) ),
						$payment_response
					);
				}
				if ( ! empty( $payment_response['ok'] ) && $preparing_message_id > 0 ) {
					crm_telegram_channels_delete_message( $company_id, $chat_id, $preparing_message_id );
				}
				return true;
			}
		}

		crm_telegram_channels_handle_contextual_main_request( $company_id, $client );

		return true;
	}
}

if ( ! function_exists( 'crm_telegram_channels_client_from_update' ) ) {
	function crm_telegram_channels_client_from_update( array $update ): array {
		$message = $update['message'] ?? $update['callback_query']['message'] ?? [];
		$from    = $update['callback_query']['from'] ?? $update['message']['from'] ?? [];
		$chat    = $message['chat'] ?? [];

		return [
			'chat_id'          => isset( $chat['id'] ) ? (string) $chat['id'] : '',
			'telegram_user_id' => isset( $from['id'] ) ? (string) $from['id'] : '',
			'username'         => isset( $from['username'] ) ? sanitize_text_field( (string) $from['username'] ) : '',
			'first_name'       => isset( $from['first_name'] ) ? sanitize_text_field( (string) $from['first_name'] ) : '',
			'last_name'        => isset( $from['last_name'] ) ? sanitize_text_field( (string) $from['last_name'] ) : '',
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_request_secret_header' ) ) {
	function crm_telegram_channels_request_secret_header( WP_REST_Request $request ): string {
		$header = trim( (string) $request->get_header( 'x-telegram-bot-api-secret-token' ) );
		if ( $header !== '' ) {
			return $header;
		}

		return trim( (string) ( $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '' ) );
	}
}

if ( ! function_exists( 'crm_telegram_channels_resolve_subscription_callback_context' ) ) {
	function crm_telegram_channels_resolve_subscription_callback_context( WP_REST_Request $request ) {
		global $wpdb;

		$stable_bot_key = trim( sanitize_text_field( (string) $request->get_param( 'bot' ) ) );
		if ( $stable_bot_key === '' ) {
			return new WP_Error( 'telegram_channels_missing_bot_key', 'Subscription bot key is missing.', [ 'status' => 400 ] );
		}

		$bot = crm_telegram_channels_get_subscription_bot_by_key( $stable_bot_key );
		if ( ! $bot ) {
			return new WP_Error( 'telegram_channels_unknown_bot_key', 'Subscription bot was not found.', [ 'status' => 404 ] );
		}

		$webhook_secret = trim( (string) ( $bot->webhook_secret ?? '' ) );
		if ( $webhook_secret === '' ) {
			return new WP_Error( 'telegram_channels_missing_webhook_secret', 'Subscription webhook secret is not configured.', [ 'status' => 403 ] );
		}

		$header_secret = crm_telegram_channels_request_secret_header( $request );
		if ( $header_secret === '' || ! hash_equals( $webhook_secret, $header_secret ) ) {
			return new WP_Error( 'telegram_channels_invalid_webhook_secret', 'Invalid subscription webhook secret.', [ 'status' => 403 ] );
		}

		if ( (string) ( $bot->status ?? '' ) !== 'active' ) {
			return new WP_Error( 'telegram_channels_bot_inactive', 'Subscription bot is not active.', [ 'status' => 403 ] );
		}

		$company_id  = (int) ( $bot->company_id ?? 0 );
		$merchant_id = (int) ( $bot->merchant_id ?? 0 );
		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return new WP_Error( 'telegram_channels_invalid_bot_scope', 'Invalid subscription bot scope.', [ 'status' => 403 ] );
		}

		$company_active = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM crm_companies WHERE id = %d AND status = 'active' LIMIT 1",
				$company_id
			)
		);
		if ( $company_active <= 0 ) {
			return new WP_Error( 'telegram_channels_company_inactive', 'Company is not active.', [ 'status' => 403 ] );
		}

		if ( ! function_exists( 'crm_company_contour_is_enabled' ) || ! crm_company_contour_is_enabled( $company_id, 'telegram_channels' ) ) {
			return new WP_Error( 'telegram_channels_company_contour_disabled', 'Telegram channels contour is disabled.', [ 'status' => 403 ] );
		}

		$merchant = crm_telegram_channels_validate_merchant_profile_access( $company_id, $merchant_id );
		if ( is_wp_error( $merchant ) ) {
			return new WP_Error( $merchant->get_error_code(), $merchant->get_error_message(), [ 'status' => 403 ] );
		}

		$GLOBALS['CRM_TELEGRAM_CHANNELS_CALLBACK_BOT']         = $bot;
		$GLOBALS['CRM_TELEGRAM_CHANNELS_CALLBACK_COMPANY_ID']  = $company_id;
		$GLOBALS['CRM_TELEGRAM_CHANNELS_CALLBACK_MERCHANT_ID'] = $merchant_id;

		return [
			'company_id'  => $company_id,
			'merchant_id' => $merchant_id,
			'bot'         => $bot,
			'merchant'    => $merchant,
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_handle_subscription_callback' ) ) {
	function crm_telegram_channels_handle_subscription_callback( WP_REST_Request $request ): WP_REST_Response {
		$callback_context = crm_telegram_channels_resolve_subscription_callback_context( $request );
		if ( is_wp_error( $callback_context ) ) {
			$error_data = $callback_context->get_error_data();
			$status     = is_array( $error_data ) && ! empty( $error_data['status'] ) ? (int) $error_data['status'] : 403;
			return new WP_REST_Response( $callback_context->get_error_message(), $status );
		}
		$company_id  = (int) ( $callback_context['company_id'] ?? 0 );
		$merchant_id = (int) ( $callback_context['merchant_id'] ?? 0 );

		$raw    = (string) $request->get_body();
		$update = json_decode( $raw, true );
		if ( ! is_array( $update ) ) {
			return new WP_REST_Response( 'Invalid JSON', 400 );
		}

		$client = crm_telegram_channels_client_from_update( $update );
		$chat_id = trim( (string) $client['chat_id'] );
		if ( $chat_id === '' ) {
			return new WP_REST_Response( 'OK', 200 );
		}

		if ( isset( $update['message']['chat_shared'] ) && is_array( $update['message']['chat_shared'] ) ) {
			crm_telegram_channels_handle_setup_chat_shared( $company_id, $client, $update['message']['chat_shared'] );
			return new WP_REST_Response( 'OK', 200 );
		}

		if ( isset( $update['callback_query'] ) && is_array( $update['callback_query'] ) ) {
			$callback_id = (string) ( $update['callback_query']['id'] ?? '' );
			$data        = (string) ( $update['callback_query']['data'] ?? '' );
			crm_telegram_channels_answer_callback( $company_id, $callback_id );

			if ( strpos( $data, 'sub:payment:' ) === 0 ) {
				$order_id = (int) substr( $data, strlen( 'sub:payment:' ) );
				$result   = crm_telegram_channels_refresh_payment_result( $company_id, $order_id, $client );

				if ( empty( $result['success'] ) ) {
					crm_telegram_channels_send_message(
						$company_id,
						$chat_id,
						(string) ( $result['message'] ?? 'Не удалось обновить оплату.' ),
						crm_telegram_channels_main_keyboard()
					);
					return new WP_REST_Response( 'OK', 200 );
				}

				$status_code = sanitize_key( (string) ( $result['status_code'] ?? '' ) );
				$channel_id  = (int) ( $result['meta']['channel_id'] ?? 0 );
				if ( $status_code === 'paid' ) {
					crm_telegram_channels_send_message(
						$company_id,
						$chat_id,
						'Оплата уже получена. Если invite-ссылка не пришла автоматически, нажмите «Ссылка в канал».',
						crm_telegram_channels_main_keyboard( $channel_id )
					);
					return new WP_REST_Response( 'OK', 200 );
				}
				if ( in_array( $status_code, [ 'declined', 'cancelled', 'expired', 'error' ], true ) ) {
					if ( ! empty( $result['order_db_id'] ) ) {
						crm_telegram_channels_handle_terminal_order( (int) $result['order_db_id'], $status_code, 'telegram_channels_payment_refresh' );
					} else {
						crm_telegram_channels_send_message(
							$company_id,
							$chat_id,
							'Этот счёт уже закрыт и не может быть оплачен. Выберите тариф заново.',
							crm_telegram_channels_terminal_keyboard( $channel_id )
						);
					}
					return new WP_REST_Response( 'OK', 200 );
				}

				$payment_response = crm_telegram_channels_send_payment_message( $company_id, $chat_id, $result );
				if ( ! empty( $payment_response['ok'] ) && ! empty( $result['order_db_id'] ) ) {
					crm_telegram_channels_store_payment_message_context(
						(int) $result['order_db_id'],
						$company_id,
						$chat_id,
						(string) ( $payment_response['message_type'] ?? ( ! empty( $result['qr_url'] ) ? 'photo' : 'text' ) ),
						$payment_response
					);
				}
				return new WP_REST_Response( 'OK', 200 );
			}

			if ( strpos( $data, 'sub:pay:' ) === 0 ) {
				$tariff_id = (int) substr( $data, strlen( 'sub:pay:' ) );
				$tariff_context = crm_telegram_channels_get_tariff_context( $company_id, $tariff_id );
				if ( ! $tariff_context ) {
					crm_telegram_channels_send_message( $company_id, $chat_id, 'Тариф недоступен.', crm_telegram_channels_main_keyboard() );
					return new WP_REST_Response( 'OK', 200 );
				}

				$channel_id     = (int) ( $tariff_context['channel_id'] ?? 0 );
				$client_context = crm_telegram_channels_find_client_context( $company_id, $client, $channel_id );
				if ( ! $client_context ) {
					$callback_merchant_id = crm_telegram_channels_current_subscription_merchant_id();
					if ( $callback_merchant_id > 0 && (int) ( $tariff_context['merchant_id'] ?? 0 ) === $callback_merchant_id ) {
						$client_context = $tariff_context;
					} else {
						crm_telegram_channels_send_message( $company_id, $chat_id, crm_telegram_channels_context_required_message(), crm_telegram_channels_main_keyboard() );
						return new WP_REST_Response( 'OK', 200 );
					}
				}

				$payment_sale_id = (int) ( $client_context['sale_id'] ?? 0 );
				if ( $payment_sale_id > 0 && (int) ( $client_context['sale_tariff_id'] ?? 0 ) !== $tariff_id ) {
					$payment_sale_id = 0;
				}

				$preparing_message_id = crm_telegram_channels_send_preparing_message( $company_id, $chat_id );
				$result = crm_telegram_channels_create_payment_order( $company_id, $tariff_id, $client, $payment_sale_id );
				if ( empty( $result['success'] ) ) {
					$error_response = crm_telegram_channels_send_message(
						$company_id,
						$chat_id,
						(string) ( $result['message'] ?? crm_telegram_channels_text( $company_id, 'not_configured', [], (int) ( $client_context['merchant_id'] ?? 0 ) ) ),
						crm_telegram_channels_main_keyboard( $channel_id ),
						(int) ( $client_context['merchant_id'] ?? 0 )
					);
					if ( ! empty( $error_response['ok'] ) && $preparing_message_id > 0 ) {
						crm_telegram_channels_delete_message( $company_id, $chat_id, $preparing_message_id );
					}
				} else {
					crm_telegram_channels_send_chat_action( $company_id, $chat_id, ! empty( $result['qr_url'] ) ? 'upload_photo' : 'typing' );
					$payment_response = crm_telegram_channels_send_payment_message( $company_id, $chat_id, $result );
					if ( ! empty( $payment_response['ok'] ) && ! empty( $result['order_db_id'] ) ) {
						crm_telegram_channels_store_payment_message_context(
							(int) $result['order_db_id'],
							$company_id,
							$chat_id,
							(string) ( $payment_response['message_type'] ?? ( ! empty( $result['qr_url'] ) ? 'photo' : 'text' ) ),
							$payment_response
						);
					}
					if ( ! empty( $payment_response['ok'] ) && $preparing_message_id > 0 ) {
						crm_telegram_channels_delete_message( $company_id, $chat_id, $preparing_message_id );
					}
				}
				return new WP_REST_Response( 'OK', 200 );
			}

			if ( preg_match( '/^sub:(status|tariffs|renew|invite|main):(\d+)$/', $data, $matches ) ) {
				$action     = (string) $matches[1];
				$channel_id = (int) $matches[2];

				if ( $action === 'status' ) {
					crm_telegram_channels_send_message(
						$company_id,
						$chat_id,
						crm_telegram_channels_status_text( $company_id, (string) $client['telegram_user_id'], $channel_id ),
						crm_telegram_channels_main_keyboard( $channel_id )
					);
				} elseif ( $action === 'tariffs' || $action === 'renew' ) {
					crm_telegram_channels_handle_contextual_tariffs_request( $company_id, $client, $channel_id );
				} elseif ( $action === 'invite' ) {
					crm_telegram_channels_handle_contextual_invite_request( $company_id, $client, $channel_id );
				} else {
					crm_telegram_channels_handle_contextual_main_request( $company_id, $client, $channel_id );
				}

				return new WP_REST_Response( 'OK', 200 );
			}

			switch ( $data ) {
				case 'sub:status':
					crm_telegram_channels_send_message( $company_id, $chat_id, crm_telegram_channels_status_text( $company_id, (string) $client['telegram_user_id'] ), crm_telegram_channels_main_keyboard() );
					break;
				case 'sub:tariffs':
				case 'sub:renew':
					crm_telegram_channels_handle_contextual_tariffs_request( $company_id, $client );
					break;
				case 'sub:invite':
					crm_telegram_channels_handle_contextual_invite_request( $company_id, $client );
					break;
				case 'sub:help':
					crm_telegram_channels_send_message( $company_id, $chat_id, "Здесь можно проверить подписку, выбрать тариф, оплатить доступ и получить ссылку в закрытый канал.", crm_telegram_channels_main_keyboard() );
					break;
				case 'sub:main':
				default:
					crm_telegram_channels_handle_contextual_main_request( $company_id, $client );
					break;
			}

			return new WP_REST_Response( 'OK', 200 );
		}

		$text = trim( (string) ( $update['message']['text'] ?? '' ) );
		if ( strpos( $text, '/start' ) === 0 ) {
			$parts   = preg_split( '/\s+/', $text, 2 );
			$payload = isset( $parts[1] ) ? trim( (string) $parts[1] ) : '';
			crm_telegram_channels_handle_client_start( $company_id, $client, $payload );
			return new WP_REST_Response( 'OK', 200 );
		}

		if ( $text === '/status' ) {
			crm_telegram_channels_send_message( $company_id, $chat_id, crm_telegram_channels_status_text( $company_id, (string) $client['telegram_user_id'] ), crm_telegram_channels_main_keyboard() );
		} elseif ( mb_strtolower( $text ) === mb_strtolower( 'Отмена' ) ) {
			crm_telegram_channels_send_message( $company_id, $chat_id, 'Действие отменено.', [ 'remove_keyboard' => true ] );
		} elseif ( $text === '/tariffs' || $text === '/renew' ) {
			crm_telegram_channels_handle_contextual_tariffs_request( $company_id, $client );
		} elseif ( $text === '/help' ) {
			crm_telegram_channels_send_message( $company_id, $chat_id, "Здесь можно проверить подписку, выбрать тариф, оплатить доступ и получить ссылку в закрытый канал.", crm_telegram_channels_main_keyboard() );
		} else {
			crm_telegram_channels_handle_contextual_main_request( $company_id, $client );
		}

		return new WP_REST_Response( 'OK', 200 );
	}
}

if ( ! function_exists( 'crm_telegram_channels_create_invite' ) ) {
	function crm_telegram_channels_create_invite( int $company_id, int $subscriber_id, string $telegram_user_id ): array {
		global $wpdb;

		$subscriber = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM crm_telegram_channel_subscribers WHERE id = %d AND company_id = %d AND merchant_id > 0 LIMIT 1',
				$subscriber_id,
				$company_id
			)
		);
		if ( ! $subscriber ) {
			return [ 'success' => false, 'message' => 'Подписчик не найден.' ];
		}

		$merchant_id = (int) $subscriber->merchant_id;
		$channel = crm_telegram_channels_get_channel_by_id( $company_id, (int) $subscriber->channel_id );
		$token   = crm_telegram_channels_get_subscription_bot_token( $company_id, $merchant_id );
		if ( ! $channel || $token === '' || trim( (string) $channel->telegram_channel_id ) === '' ) {
			return [ 'success' => false, 'message' => 'Канал или token не настроены.' ];
		}

		$ttl_hours   = max( 1, min( 168, (int) crm_telegram_channels_get_merchant_bot_option( $company_id, $merchant_id, 'invite_ttl_hours', 24 ) ) );
		$expire_unix = time() + $ttl_hours * HOUR_IN_SECONDS;
		$expire_dt   = gmdate( 'Y-m-d H:i:s', $expire_unix );

		$response = crm_telegram_bot_api_request(
			$token,
			'createChatInviteLink',
			[
				'chat_id'      => (string) $channel->telegram_channel_id,
				'expire_date'  => $expire_unix,
				'member_limit' => 1,
			]
		);

		$ok          = ! empty( $response['ok'] ) && ! empty( $response['result']['invite_link'] );
		$invite_link = $ok ? (string) $response['result']['invite_link'] : '';
		$error       = $ok ? '' : trim( (string) ( $response['description'] ?? 'Telegram API error.' ) );

		$wpdb->insert(
			'crm_telegram_channel_invites',
			[
				'company_id'             => $company_id,
				'channel_id'             => (int) $channel->id,
				'merchant_id'            => $merchant_id,
				'subscriber_id'          => $subscriber_id > 0 ? $subscriber_id : null,
				'telegram_user_id'       => preg_match( '/^\d+$/', $telegram_user_id ) ? $telegram_user_id : null,
				'invite_link'            => $invite_link !== '' ? $invite_link : null,
				'telegram_invite_link_id'=> ! empty( $response['result']['invite_link'] ) ? sha1( (string) $response['result']['invite_link'] ) : null,
				'expire_date'            => $expire_dt,
				'member_limit'           => 1,
				'status'                 => $ok ? 'created' : 'failed',
				'telegram_response_json' => wp_json_encode( $response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'error_message'          => $error !== '' ? $error : null,
				'created_at'             => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		$invite_id = (int) $wpdb->insert_id;
		if ( $ok ) {
			$wpdb->update(
				'crm_telegram_channel_subscribers',
				[
					'last_invite_link'       => $invite_link,
					'last_invite_created_at' => current_time( 'mysql', true ),
					'updated_at'             => current_time( 'mysql', true ),
				],
				[ 'id' => $subscriber_id, 'company_id' => $company_id ],
				[ '%s', '%s', '%s' ],
				[ '%d', '%d' ]
			);

			crm_log_entity(
				'telegram_channels.invite_created',
				'telegram_channels',
				'create',
				'Создана invite-ссылка Telegram-канала.',
				'telegram_channel_invite',
				$invite_id,
				[
					'org_id'  => $company_id,
					'context' => [
						'subscriber_id' => $subscriber_id,
						'channel_id'    => (int) $channel->id,
						'merchant_id'   => $merchant_id,
					],
				]
			);

			return [ 'success' => true, 'invite_id' => $invite_id, 'invite_link' => $invite_link, 'expire_date' => $expire_dt ];
		}

		crm_log_entity(
			'telegram_channels.invite_failed',
			'telegram_channels',
			'create',
			'Не удалось создать invite-ссылку Telegram-канала.',
			'telegram_channel_invite',
			$invite_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'subscriber_id' => $subscriber_id,
					'channel_id'    => (int) $channel->id,
					'merchant_id'   => $merchant_id,
					'error'         => $error,
				],
			]
		);

		return [ 'success' => false, 'message' => $error !== '' ? $error : 'Не удалось создать invite-ссылку.', 'invite_id' => $invite_id ];
	}
}

if ( ! function_exists( 'crm_telegram_channels_invite_keyboard' ) ) {
	function crm_telegram_channels_invite_keyboard( string $invite_link, int $channel_id = 0 ): array {
		$status_callback = $channel_id > 0 ? 'sub:status:' . $channel_id : 'sub:status';

		return [
			'inline_keyboard' => [
				[
					[ 'text' => 'Войти в канал', 'url' => $invite_link ],
				],
				[
					[ 'text' => 'Моя подписка', 'callback_data' => $status_callback ],
				],
			],
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_reissue_invite_for_client' ) ) {
	function crm_telegram_channels_reissue_invite_for_client( int $company_id, string $telegram_user_id, string $chat_id = '', int $channel_id = 0 ): array {
		global $wpdb;

		$where        = '';
		$where_params = [];
		$merchant_id = crm_telegram_channels_current_subscription_merchant_id();
		if ( $merchant_id > 0 ) {
			$where .= ' AND merchant_id = %d';
			$where_params[] = $merchant_id;
		}
		if ( $channel_id > 0 ) {
			$where    .= ' AND channel_id = %d';
			$where_params[] = $channel_id;
		}
		$params = array_merge( [ $company_id, $telegram_user_id, current_time( 'mysql', true ) ], $where_params );

		$sql = "SELECT *
		        FROM crm_telegram_channel_subscribers
		        WHERE company_id = %d
		          AND merchant_id > 0
		          AND telegram_user_id = %s
		          AND status = 'active'
		          AND subscription_until IS NOT NULL
		          AND subscription_until > %s
		          {$where}
		        ORDER BY subscription_until DESC
		        LIMIT 1";

		$subscriber = $wpdb->get_row( $wpdb->prepare( $sql, $params ) );
		if ( ! $subscriber || (string) $subscriber->status !== 'active' || empty( $subscriber->subscription_until ) || strtotime( (string) $subscriber->subscription_until . ' UTC' ) <= time() ) {
			return [ 'success' => false, 'message' => 'Активная подписка не найдена.' ];
		}

		$invite = crm_telegram_channels_create_invite( $company_id, (int) $subscriber->id, $telegram_user_id );
		if ( empty( $invite['success'] ) ) {
			return [ 'success' => false, 'message' => (string) ( $invite['message'] ?? 'Не удалось создать invite-ссылку.' ) ];
		}

		return [
			'success'  => true,
			'message'  => crm_telegram_channels_text( $company_id, 'invite_reissued', [], (int) ( $subscriber->merchant_id ?? 0 ) ),
			'keyboard' => crm_telegram_channels_invite_keyboard( (string) $invite['invite_link'], (int) $subscriber->channel_id ),
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_terminal_status_message' ) ) {
	function crm_telegram_channels_terminal_status_message( string $status_code ): string {
		$status_code = sanitize_key( $status_code );

		if ( $status_code === 'expired' ) {
			return 'Счёт устарел и больше не может быть оплачен.';
		}
		if ( $status_code === 'declined' ) {
			return 'Счёт отклонён и больше не может быть оплачен.';
		}
		if ( $status_code === 'cancelled' ) {
			return 'Счёт отменён и больше не может быть оплачен.';
		}

		return 'Счёт закрыт и больше не может быть оплачен.';
	}
}

if ( ! function_exists( 'crm_telegram_channels_terminal_keyboard' ) ) {
	function crm_telegram_channels_terminal_keyboard( int $channel_id = 0 ): array {
		$status_callback  = $channel_id > 0 ? 'sub:status:' . $channel_id : 'sub:status';
		$tariffs_callback = $channel_id > 0 ? 'sub:tariffs:' . $channel_id : 'sub:tariffs';

		return [
			'inline_keyboard' => [
				[
					[ 'text' => 'Выбрать тариф', 'callback_data' => $tariffs_callback ],
				],
				[
					[ 'text' => 'Моя подписка', 'callback_data' => $status_callback ],
				],
			],
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_handle_terminal_order' ) ) {
	function crm_telegram_channels_handle_terminal_order( int $order_id, string $status_code, string $source_code = 'system' ): array {
		global $wpdb;

		$status_code = sanitize_key( $status_code );
		if ( $status_code === 'paid' ) {
			return crm_telegram_channels_handle_paid_order( $order_id, $source_code );
		}

		$result = [
			'handled'       => false,
			'status'        => 'skipped',
			'message'       => '',
			'message_deleted' => false,
			'notice_sent'   => false,
			'errors'        => [],
		];

		if ( $order_id <= 0 || ! in_array( $status_code, [ 'declined', 'cancelled', 'expired', 'error' ], true ) ) {
			return $result;
		}

		$order = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT *
				 FROM crm_fintech_payment_orders
				 WHERE id = %d
				 LIMIT 1',
				$order_id
			)
		);
		if ( ! $order || (string) ( $order->source_channel ?? '' ) !== crm_telegram_channels_source_channel() ) {
			return $result;
		}

		$meta = crm_telegram_channels_safe_json_decode( (string) ( $order->meta_json ?? '' ) );
		if ( (string) ( $meta['module'] ?? '' ) !== 'telegram_channels' ) {
			return $result;
		}

		$company_id  = (int) ( $order->company_id ?? 0 );
		$channel_id  = (int) ( $meta['channel_id'] ?? 0 );
		$merchant_id = (int) ( $meta['merchant_id'] ?? 0 );
		$sale_id     = (int) ( $meta['sale_id'] ?? 0 );
		$chat_id     = trim( (string) ( $meta['subscription_tg_receipt_chat_id'] ?? $meta['chat_id'] ?? '' ) );

		$result['handled'] = true;
		$result['status']  = $status_code;

		if ( $company_id <= 0 || $chat_id === '' ) {
			$result['errors'][] = 'missing_company_or_chat';
			return $result;
		}

		$receipt_message_id  = (int) ( $meta['subscription_tg_receipt_message_id'] ?? 0 );
		$receipt_message_ids = crm_telegram_channels_normalize_message_id_list( $meta['subscription_tg_receipt_message_ids'] ?? [] );
		if ( $receipt_message_id > 0 ) {
			$receipt_message_ids = crm_telegram_channels_append_message_id_list( $receipt_message_ids, $receipt_message_id );
		}

		$deleted_message_ids = crm_telegram_channels_normalize_message_id_list( $meta['subscription_tg_receipt_deleted_message_ids'] ?? [] );
		foreach ( $receipt_message_ids as $message_id ) {
			if ( $message_id <= 0 || in_array( $message_id, $deleted_message_ids, true ) ) {
				continue;
			}

			if ( crm_telegram_channels_delete_message( $company_id, $chat_id, $message_id, $merchant_id ) ) {
				$result['message_deleted'] = true;
				$deleted_message_ids       = crm_telegram_channels_append_message_id_list( $deleted_message_ids, $message_id );
			} else {
				$result['errors'][] = 'delete_failed:' . $message_id;
			}
		}

		if ( $sale_id > 0 ) {
			$sale_status = $status_code === 'expired' ? 'expired' : 'cancelled';
			$where       = [
				'id'         => $sale_id,
				'company_id' => $company_id,
			];
			$where_format = [ '%d', '%d' ];
			if ( $merchant_id > 0 ) {
				$where['merchant_id'] = $merchant_id;
				$where_format[]       = '%d';
			}

			$wpdb->update(
				'crm_telegram_channel_sales',
				[
					'status'     => $sale_status,
					'updated_at' => current_time( 'mysql', true ),
				],
				$where,
				[ '%s', '%s' ],
				$where_format
			);
		}

		$already_notified = (string) ( $meta['subscription_tg_terminal_notice_status_code'] ?? '' ) === $status_code
			&& ! empty( $meta['subscription_tg_terminal_notice_sent_at'] );

		$notice_message_id = (int) ( $meta['subscription_tg_terminal_notice_message_id'] ?? 0 );
		if ( ! $already_notified ) {
			$notice_text = '<b>' . esc_html( crm_telegram_channels_terminal_status_message( $status_code ) ) . "</b>\n\n"
				. 'Можно выбрать тариф и попробовать оплатить снова.';
			$notice_response = crm_telegram_channels_send_message(
				$company_id,
				$chat_id,
				$notice_text,
				crm_telegram_channels_terminal_keyboard( $channel_id ),
				$merchant_id
			);

			if ( crm_telegram_channels_telegram_response_ok( $notice_response ) ) {
				$result['notice_sent'] = true;
				$notice_message_id     = crm_telegram_channels_response_message_id( $notice_response );
			} else {
				$result['errors'][] = 'notice_send_failed:' . trim( (string) ( $notice_response['description'] ?? 'unknown' ) );
			}
		}

		$meta_patch = [
			'subscription_tg_terminal_last_status_code'    => $status_code,
			'subscription_tg_terminal_last_status_sync_at' => current_time( 'mysql', true ),
			'subscription_tg_terminal_last_status_source'  => $source_code !== '' ? $source_code : 'system',
			'subscription_tg_receipt_message_id'           => 0,
			'subscription_tg_receipt_message_type'         => 'deleted',
			'subscription_tg_receipt_deleted_message_ids'  => $deleted_message_ids,
		];

		if ( $result['notice_sent'] ) {
			$meta_patch['subscription_tg_terminal_notice_status_code'] = $status_code;
			$meta_patch['subscription_tg_terminal_notice_sent_at']     = current_time( 'mysql', true );
			$meta_patch['subscription_tg_terminal_notice_source']      = $source_code !== '' ? $source_code : 'system';
			$meta_patch['subscription_tg_terminal_notice_message_id']  = $notice_message_id;
		}

		$meta_write = crm_telegram_channels_merge_order_meta( $order_id, $company_id, $meta_patch );
		if ( empty( $meta_write['ok'] ) ) {
			$result['errors'][] = 'meta_update_failed';
		}

		$result['ok'] = $result['message_deleted'] || $result['notice_sent'] || ( $already_notified && empty( $result['errors'] ) );

		crm_log_entity(
			$result['ok'] ? 'telegram_channels.payment_terminal_synced' : 'telegram_channels.payment_terminal_sync_failed',
			'telegram_channels',
			'update',
			$result['ok']
				? 'Terminal payment status synced to subscription bot.'
				: 'Terminal payment status sync to subscription bot finished with issues.',
			'payment_order',
			$order_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'status_code'       => $status_code,
					'source_code'       => $source_code,
					'channel_id'        => $channel_id,
					'merchant_id'       => $merchant_id,
					'sale_id'           => $sale_id,
					'chat_id'           => $chat_id,
					'message_deleted'   => $result['message_deleted'],
					'notice_sent'       => $result['notice_sent'],
					'already_notified'  => $already_notified,
					'errors'            => $result['errors'],
				],
			]
		);

		return $result;
	}
}

if ( ! function_exists( 'crm_telegram_channels_handle_paid_order' ) ) {
	function crm_telegram_channels_handle_paid_order( int $order_id, string $source_code = 'system' ): array {
		global $wpdb;

		$result = [
			'handled' => false,
			'status'  => 'skipped',
			'message' => '',
		];

		if ( $order_id <= 0 ) {
			return $result;
		}

		$order = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				 FROM crm_fintech_payment_orders
				 WHERE id = %d
				 LIMIT 1",
				$order_id
			)
		);
		if ( ! $order || (string) ( $order->source_channel ?? '' ) !== crm_telegram_channels_source_channel() ) {
			return $result;
		}

		$meta = [];
		if ( ! empty( $order->meta_json ) ) {
			$decoded = json_decode( (string) $order->meta_json, true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}
		if ( (string) ( $meta['module'] ?? '' ) !== 'telegram_channels' ) {
			return $result;
		}

		$company_id = (int) $order->company_id;
		if ( $company_id <= 0 ) {
			return [ 'handled' => true, 'status' => 'error', 'message' => 'Invalid company scope.' ];
		}

		$existing_payment = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM crm_telegram_channel_payments WHERE payment_order_id = %d LIMIT 1',
				$order_id
			)
		);
		if ( $existing_payment > 0 ) {
			crm_log_entity(
				'telegram_channels.payment_paid',
				'telegram_channels',
				'duplicate',
				'Duplicate paid side-effect skipped for Telegram channel subscription.',
				'payment_order',
				$order_id,
				[
					'org_id'  => $company_id,
					'context' => [
						'existing_payment_id' => $existing_payment,
						'source_code'         => $source_code,
					],
				]
			);

			return [ 'handled' => true, 'status' => 'duplicate', 'payment_id' => $existing_payment ];
		}

		$channel_id       = (int) ( $meta['channel_id'] ?? 0 );
		$tariff_id        = (int) ( $meta['tariff_id'] ?? 0 );
		$sale_id          = (int) ( $meta['sale_id'] ?? 0 );
		$merchant_id      = (int) ( $meta['merchant_id'] ?? 0 );
		$telegram_user_id = trim( (string) ( $meta['telegram_user_id'] ?? '' ) );
		$chat_id          = trim( (string) ( $meta['chat_id'] ?? '' ) );
		if ( $channel_id <= 0 || $tariff_id <= 0 || $merchant_id <= 0 || $telegram_user_id === '' || $chat_id === '' ) {
			return [ 'handled' => true, 'status' => 'error', 'message' => 'Missing subscription payment meta.' ];
		}

		$channel = crm_telegram_channels_get_channel_by_id( $company_id, $channel_id );
		if ( ! $channel || (int) $channel->merchant_id !== $merchant_id ) {
			return [ 'handled' => true, 'status' => 'error', 'message' => 'Channel merchant scope mismatch.' ];
		}

		$tariff = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM crm_telegram_channel_tariffs WHERE id = %d AND company_id = %d AND channel_id = %d LIMIT 1',
				$tariff_id,
				$company_id,
				$channel_id
			)
		);
		if ( ! $tariff ) {
			return [ 'handled' => true, 'status' => 'error', 'message' => 'Tariff not found.' ];
		}

		$now_ts = time();
		$now_dt = gmdate( 'Y-m-d H:i:s', $now_ts );
		$existing_subscriber = crm_telegram_channels_get_subscriber( $company_id, $channel_id, $telegram_user_id );
		$previous_until_ts = ( $existing_subscriber && ! empty( $existing_subscriber->subscription_until ) )
			? strtotime( (string) $existing_subscriber->subscription_until . ' UTC' )
			: 0;
		$base_ts      = max( $now_ts, (int) $previous_until_ts );
		$new_until_ts = $base_ts + (int) $tariff->duration_days * DAY_IN_SECONDS;
		$new_until    = gmdate( 'Y-m-d H:i:s', $new_until_ts );
		$period_from  = gmdate( 'Y-m-d H:i:s', $base_ts );
		$is_renewal   = $existing_subscriber && $previous_until_ts > $now_ts;

		$subscriber_data = [
			'company_id'             => $company_id,
			'channel_id'             => $channel_id,
			'merchant_id'            => $merchant_id,
			'telegram_user_id'       => $telegram_user_id,
			'chat_id'                => $chat_id,
			'username'               => trim( (string) ( $meta['username'] ?? '' ) ) ?: null,
			'first_name'             => trim( (string) ( $meta['first_name'] ?? '' ) ) ?: null,
			'last_name'              => trim( (string) ( $meta['last_name'] ?? '' ) ) ?: null,
			'current_tariff_id'      => $tariff_id,
			'subscription_start'     => $existing_subscriber && ! empty( $existing_subscriber->subscription_start ) ? (string) $existing_subscriber->subscription_start : $now_dt,
			'subscription_until'     => $new_until,
			'status'                 => 'active',
			'last_payment_order_id'  => $order_id,
			'removed_from_channel_at'=> null,
			'remove_from_channel_status' => null,
			'remove_from_channel_error'  => null,
			'updated_at'             => $now_dt,
		];

		if ( $existing_subscriber ) {
			$wpdb->update(
				'crm_telegram_channel_subscribers',
				$subscriber_data,
				[ 'id' => (int) $existing_subscriber->id, 'company_id' => $company_id ],
				null,
				[ '%d', '%d' ]
			);
			$subscriber_id = (int) $existing_subscriber->id;
		} else {
			$subscriber_data['created_at'] = $now_dt;
			$wpdb->insert( 'crm_telegram_channel_subscribers', $subscriber_data );
			$subscriber_id = (int) $wpdb->insert_id;
		}

		if ( $subscriber_id <= 0 ) {
			return [ 'handled' => true, 'status' => 'error', 'message' => 'Failed to upsert subscriber.' ];
		}

		$amount = isset( $order->payment_amount_value ) && $order->payment_amount_value !== null
			? (float) $order->payment_amount_value
			: (float) $tariff->price_amount;
		$currency = strtoupper( (string) ( $order->payment_currency_code ?: $tariff->price_currency ) );
		$paid_at  = ! empty( $order->paid_at ) ? (string) $order->paid_at : $now_dt;

		$wpdb->insert(
			'crm_telegram_channel_payments',
			[
				'company_id'       => $company_id,
				'channel_id'       => $channel_id,
				'merchant_id'      => $merchant_id,
				'subscriber_id'    => $subscriber_id,
				'tariff_id'        => $tariff_id,
				'sale_id'          => $sale_id > 0 ? $sale_id : null,
				'payment_order_id' => $order_id,
				'provider_code'    => (string) $order->provider_code,
				'amount'           => $amount,
				'currency'         => $currency,
				'paid_at'          => $paid_at,
				'period_from'      => $period_from,
				'period_until'     => $new_until,
				'created_at'       => $now_dt,
			],
			[ '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s' ]
		);
		$payment_id = (int) $wpdb->insert_id;

		if ( $sale_id > 0 ) {
			$wpdb->update(
				'crm_telegram_channel_sales',
				[
					'status'           => 'paid',
					'payment_order_id' => $order_id,
					'updated_at'       => $now_dt,
				],
				[ 'id' => $sale_id, 'company_id' => $company_id, 'merchant_id' => $merchant_id ],
				[ '%s', '%d', '%s' ],
				[ '%d', '%d', '%d' ]
			);
		}

		$event_code = $is_renewal ? 'telegram_channels.subscription_renewed' : 'telegram_channels.subscription_activated';
		crm_log_entity(
			'telegram_channels.payment_paid',
			'telegram_channels',
			'paid',
			'Оплачен payment order подписки Telegram-канала.',
			'payment_order',
			$order_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'payment_id'    => $payment_id,
					'subscriber_id' => $subscriber_id,
					'tariff_id'     => $tariff_id,
					'sale_id'       => $sale_id,
					'merchant_id'   => $merchant_id,
					'source_code'   => $source_code,
				],
			]
		);
		crm_log_entity(
			$event_code,
			'telegram_channels',
			'update',
			$is_renewal ? 'Подписка Telegram-канала продлена.' : 'Подписка Telegram-канала активирована.',
			'telegram_channel_subscriber',
			$subscriber_id,
			[
				'org_id'  => $company_id,
				'context' => [
					'payment_order_id' => $order_id,
					'payment_id'       => $payment_id,
					'period_until'     => $new_until,
					'merchant_id'      => $merchant_id,
				],
			]
		);

		$invite = crm_telegram_channels_create_invite( $company_id, $subscriber_id, $telegram_user_id );
		$until_label = crm_format_dt( $new_until, $company_id ) ?: $new_until;
		if ( ! empty( $invite['success'] ) ) {
			$text = crm_telegram_channels_text(
				$company_id,
				$is_renewal ? 'renewal_success' : 'payment_success',
				[ 'until' => $until_label ],
				$merchant_id
			) . "\n\nДоступ до: <b>" . esc_html( $until_label ) . '</b>';
			crm_telegram_channels_send_message( $company_id, $chat_id, $text, crm_telegram_channels_invite_keyboard( (string) $invite['invite_link'], $channel_id ), $merchant_id );
		} else {
			crm_telegram_channels_send_message(
				$company_id,
				$chat_id,
				crm_telegram_channels_text( $company_id, 'payment_success', [ 'until' => $until_label ], $merchant_id ) . "\n\nInvite-ссылка пока не создана. Мерчант получит уведомление об оплате.",
				[],
				$merchant_id
			);
		}

		crm_telegram_channels_notify_merchant_paid( $company_id, $merchant_id, $tariff, $amount, $currency, $new_until );

		return [
			'handled'       => true,
			'status'        => 'activated',
			'payment_id'    => $payment_id,
			'subscriber_id' => $subscriber_id,
			'invite'        => $invite,
		];
	}
}

if ( ! function_exists( 'crm_telegram_channels_notify_merchant_paid' ) ) {
	function crm_telegram_channels_notify_merchant_paid( int $company_id, int $merchant_id, object $tariff, float $amount, string $currency, string $until ): void {
		global $wpdb;

		if ( $company_id <= 0 || $merchant_id <= 0 ) {
			return;
		}

		$merchant = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT chat_id FROM crm_merchants WHERE id = %d AND company_id = %d LIMIT 1',
				$merchant_id,
				$company_id
			)
		);
		if ( ! $merchant || empty( $merchant->chat_id ) || ! function_exists( 'crm_telegram_collect_settings' ) || ! function_exists( 'crm_telegram_bot_api_request' ) ) {
			return;
		}

		$settings = crm_telegram_collect_settings( $company_id, 'merchant' );
		$token    = trim( (string) ( $settings['bot_token'] ?? '' ) );
		if ( $token === '' ) {
			return;
		}

		crm_telegram_bot_api_request(
			$token,
			'sendMessage',
			[
				'chat_id'    => (string) $merchant->chat_id,
				'parse_mode' => 'HTML',
				'text'       => "✅ Продажа подписки оплачена.\n\nТариф: <b>" . esc_html( (string) $tariff->title ) . '</b>'
					. "\nСумма: <b>" . esc_html( (string) $amount . ' ' . $currency ) . '</b>'
					. "\nДоступ до: <b>" . esc_html( crm_format_dt( $until, $company_id ) ?: $until ) . '</b>',
			]
		);
	}
}

if ( ! function_exists( 'crm_telegram_channels_remove_expired_member' ) ) {
	function crm_telegram_channels_remove_expired_member( object $subscriber ): array {
		global $wpdb;

		$company_id = (int) $subscriber->company_id;
		$merchant_id = (int) ( $subscriber->merchant_id ?? 0 );
		$channel    = crm_telegram_channels_get_channel_by_id( $company_id, (int) ( $subscriber->channel_id ?? 0 ) );
		$token      = crm_telegram_channels_get_subscription_bot_token( $company_id, $merchant_id );
		if ( ! $channel || $token === '' || empty( $channel->telegram_channel_id ) || empty( $subscriber->telegram_user_id ) ) {
			return [ 'success' => false, 'message' => 'Канал/token/subscriber не настроены.' ];
		}

		$ban = crm_telegram_bot_api_request(
			$token,
			'banChatMember',
			[
				'chat_id' => (string) $channel->telegram_channel_id,
				'user_id' => (string) $subscriber->telegram_user_id,
			]
		);

		$ok = ! empty( $ban['ok'] );
		if ( $ok ) {
			crm_telegram_bot_api_request(
				$token,
				'unbanChatMember',
				[
					'chat_id'       => (string) $channel->telegram_channel_id,
					'user_id'       => (string) $subscriber->telegram_user_id,
					'only_if_banned' => 'true',
				]
			);

			$wpdb->update(
				'crm_telegram_channel_subscribers',
				[
					'status'                     => 'expired',
					'removed_from_channel_at'    => current_time( 'mysql', true ),
					'remove_from_channel_status' => 'removed',
					'remove_from_channel_error'  => null,
					'updated_at'                 => current_time( 'mysql', true ),
				],
				[ 'id' => (int) $subscriber->id, 'company_id' => $company_id ],
				[ '%s', '%s', '%s', '%s', '%s' ],
				[ '%d', '%d' ]
			);

			crm_log_entity(
				'telegram_channels.member_removed',
				'telegram_channels',
				'update',
				'Истёкший подписчик удалён из Telegram-канала.',
				'telegram_channel_subscriber',
				(int) $subscriber->id,
				[ 'org_id' => $company_id ]
			);

			return [ 'success' => true ];
		}

		$error = trim( (string) ( $ban['description'] ?? 'Telegram API error.' ) );
		$wpdb->update(
			'crm_telegram_channel_subscribers',
			[
				'status'                     => 'expired',
				'remove_from_channel_status' => 'failed',
				'remove_from_channel_error'  => $error,
				'updated_at'                 => current_time( 'mysql', true ),
			],
			[ 'id' => (int) $subscriber->id, 'company_id' => $company_id ],
			[ '%s', '%s', '%s', '%s' ],
			[ '%d', '%d' ]
		);

		crm_log_entity(
			'telegram_channels.member_remove_failed',
			'telegram_channels',
			'update',
			'Не удалось удалить истёкшего подписчика из Telegram-канала.',
			'telegram_channel_subscriber',
			(int) $subscriber->id,
			[
				'org_id'  => $company_id,
				'context' => [ 'error' => $error ],
			]
		);

		return [ 'success' => false, 'message' => $error ];
	}
}

if ( ! function_exists( 'crm_telegram_channels_process_cron' ) ) {
	function crm_telegram_channels_process_cron(): void {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$expired = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				 FROM crm_telegram_channel_subscribers
				 WHERE status = 'active'
				   AND subscription_until IS NOT NULL
				   AND subscription_until < %s
				 LIMIT 100",
				$now
			)
		) ?: [];

		foreach ( $expired as $subscriber ) {
			crm_telegram_channels_remove_expired_member( $subscriber );
			if ( ! empty( $subscriber->chat_id ) ) {
				crm_telegram_channels_send_message(
					(int) $subscriber->company_id,
					(string) $subscriber->chat_id,
					crm_telegram_channels_text( (int) $subscriber->company_id, 'expired', [], (int) ( $subscriber->merchant_id ?? 0 ) ),
					[],
					(int) ( $subscriber->merchant_id ?? 0 )
				);
			}
			crm_log_entity(
				'telegram_channels.subscription_expired',
				'telegram_channels',
				'update',
				'Подписка Telegram-канала истекла.',
				'telegram_channel_subscriber',
				(int) $subscriber->id,
				[ 'org_id' => (int) $subscriber->company_id ]
			);
		}

		$companies = $wpdb->get_col( "SELECT id FROM crm_companies WHERE id > 0 AND status = 'active'" ) ?: [];
		foreach ( array_map( 'intval', $companies ) as $company_id ) {
			if ( ! function_exists( 'crm_company_contour_is_enabled' ) || ! crm_company_contour_is_enabled( $company_id, 'telegram_channels' ) ) {
				continue;
			}
			$max_threshold = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT *
					 FROM crm_telegram_channel_subscribers
					 WHERE company_id = %d
					   AND status = 'active'
					   AND subscription_until IS NOT NULL
					   AND subscription_until >= %s
					   AND subscription_until <= %s
					   AND (reminder_sent_for_until IS NULL OR reminder_sent_for_until <> subscription_until)
					 LIMIT 100",
					$company_id,
					$now,
					$max_threshold
				)
			) ?: [];

			foreach ( $rows as $subscriber ) {
				$merchant_id = (int) ( $subscriber->merchant_id ?? 0 );
				if ( $merchant_id <= 0 ) {
					continue;
				}
				if ( (int) crm_telegram_channels_get_merchant_bot_option( $company_id, $merchant_id, 'reminders_enabled', 1 ) !== 1 ) {
					continue;
				}
				$days      = max( 1, min( 30, (int) crm_telegram_channels_get_merchant_bot_option( $company_id, $merchant_id, 'reminder_days', 3 ) ) );
				$threshold = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
				if ( strtotime( (string) $subscriber->subscription_until . ' UTC' ) > strtotime( $threshold . ' UTC' ) ) {
					continue;
				}

				if ( ! empty( $subscriber->chat_id ) ) {
					$until_label = crm_format_dt( (string) $subscriber->subscription_until, $company_id ) ?: (string) $subscriber->subscription_until;
					crm_telegram_channels_send_message( $company_id, (string) $subscriber->chat_id, crm_telegram_channels_text( $company_id, 'expiry_warning', [ 'until' => $until_label ], $merchant_id ), crm_telegram_channels_main_keyboard( (int) $subscriber->channel_id ), $merchant_id );
				}
				$wpdb->update(
					'crm_telegram_channel_subscribers',
					[
						'reminder_sent_for_until' => (string) $subscriber->subscription_until,
						'updated_at'              => current_time( 'mysql', true ),
					],
					[ 'id' => (int) $subscriber->id, 'company_id' => $company_id ],
					[ '%s', '%s' ],
					[ '%d', '%d' ]
				);
				crm_log_entity(
					'telegram_channels.expiry_warning_sent',
					'telegram_channels',
					'notify',
					'Отправлено предупреждение об окончании подписки.',
					'telegram_channel_subscriber',
					(int) $subscriber->id,
					[ 'org_id' => $company_id ]
				);
			}
		}
	}
}

if ( ! function_exists( 'crm_telegram_channels_schedule_cron' ) ) {
	function crm_telegram_channels_schedule_cron(): void {
		if ( ! wp_next_scheduled( 'malibu_telegram_channels_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'malibu_telegram_channels_daily' );
		}
	}
}

add_action( 'init', 'crm_telegram_channels_schedule_cron' );
add_action( 'malibu_telegram_channels_daily', 'crm_telegram_channels_process_cron' );
