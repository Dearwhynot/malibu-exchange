<?php
/**
 * Malibu Exchange — Company-scoped Telegram bot helpers
 *
 * The project has two Telegram contours per company:
 * - merchant: merchant onboarding/menu/invoices;
 * - operator: internal operator workflows.
 *
 * Legacy functions without an explicit context intentionally point to the
 * merchant contour, because the existing Telegram functionality is merchant-led.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'crm_telegram_bot_context_labels' ) ) {
	function crm_telegram_bot_context_labels(): array {
		return [
			'merchant' => 'Мерчантский бот',
			'operator' => 'Операторский бот',
		];
	}
}

if ( ! function_exists( 'crm_telegram_normalize_bot_context' ) ) {
	function crm_telegram_normalize_bot_context( string $context ): string {
		$context = sanitize_key( $context );

		return array_key_exists( $context, crm_telegram_bot_context_labels() ) ? $context : 'merchant';
	}
}

if ( ! function_exists( 'crm_telegram_setting_key' ) ) {
	function crm_telegram_setting_key( string $context, string $suffix ): string {
		$context = crm_telegram_normalize_bot_context( $context );
		$suffix  = sanitize_key( $suffix );

		$allowed_suffixes = [
			'bot_token',
			'bot_username',
			'webhook_url',
			'webhook_connected_at',
			'webhook_last_error',
			'webhook_lock',
		];

		if ( ! in_array( $suffix, $allowed_suffixes, true ) ) {
			return '';
		}

		return 'telegram_' . $context . '_' . $suffix;
	}
}

if ( ! function_exists( 'crm_telegram_default_settings' ) ) {
	function crm_telegram_default_settings( ?string $context = null ): array {
		if ( $context === null ) {
			return [
				'telegram_bot_token'            => '',
				'telegram_bot_username'         => '',
				'telegram_webhook_url'          => '',
				'telegram_webhook_connected_at' => '',
				'telegram_webhook_last_error'   => '',
				'telegram_webhook_lock'         => '0',
			];
		}

		$context = crm_telegram_normalize_bot_context( $context );

		return [
			crm_telegram_setting_key( $context, 'bot_token' )            => '',
			crm_telegram_setting_key( $context, 'bot_username' )         => '',
			crm_telegram_setting_key( $context, 'webhook_url' )          => '',
			crm_telegram_setting_key( $context, 'webhook_connected_at' ) => '',
			crm_telegram_setting_key( $context, 'webhook_last_error' )   => '',
			crm_telegram_setting_key( $context, 'webhook_lock' )         => '0',
		];
	}
}

if ( ! function_exists( 'crm_telegram_seed_company_settings' ) ) {
	function crm_telegram_seed_company_settings( int $company_id, ?string $context = null ): void {
		if ( $company_id <= 0 ) {
			return;
		}

		global $wpdb;

		$contexts = $context === null
			? array_keys( crm_telegram_bot_context_labels() )
			: [ crm_telegram_normalize_bot_context( $context ) ];

		foreach ( $contexts as $bot_context ) {
			foreach ( crm_telegram_default_settings( $bot_context ) as $key => $value ) {
				$wpdb->query(
					$wpdb->prepare(
						'INSERT IGNORE INTO crm_settings (org_id, setting_key, setting_value) VALUES (%d, %s, %s)',
						$company_id,
						$key,
						$value
					)
				);
			}
		}

		// Keep legacy keys seeded for compatibility while old webhooks are still alive.
		if ( $context === null ) {
			foreach ( crm_telegram_default_settings() as $key => $value ) {
				$wpdb->query(
					$wpdb->prepare(
						'INSERT IGNORE INTO crm_settings (org_id, setting_key, setting_value) VALUES (%d, %s, %s)',
						$company_id,
						$key,
						$value
					)
				);
			}
		}
	}
}

if ( ! function_exists( 'crm_telegram_sanitize_bot_username' ) ) {
	function crm_telegram_sanitize_bot_username( string $username ): string {
		$username = trim( ltrim( trim( $username ), '@' ) );
		if ( $username === '' ) {
			return '';
		}

		if ( ! preg_match( '/^[A-Za-z0-9_]{5,64}$/', $username ) ) {
			return '';
		}

		return $username;
	}
}

if ( ! function_exists( 'crm_telegram_company_callback_url' ) ) {
	function crm_telegram_company_callback_url( int $company_id, string $context = 'merchant' ): string {
		if ( $company_id <= 0 ) {
			return '';
		}

		$context = crm_telegram_normalize_bot_context( $context );
		$route   = $context === 'operator'
			? 'malibu-exchange/v1/telegram/operator-callback'
			: 'malibu-exchange/v1/telegram/merchant-callback';

		return add_query_arg(
			[
				'company' => $company_id,
			],
			rest_url( $route )
		);
	}
}

if ( ! function_exists( 'crm_telegram_legacy_callback_url' ) ) {
	function crm_telegram_legacy_callback_url( int $company_id ): string {
		if ( $company_id <= 0 ) {
			return '';
		}

		return add_query_arg(
			[
				'company' => $company_id,
			],
			rest_url( 'malibu-exchange/v1/telegram/callback-universal' )
		);
	}
}

if ( ! function_exists( 'crm_telegram_normalize_url_for_compare' ) ) {
	function crm_telegram_normalize_url_for_compare( string $url ): array {
		$url = trim( $url );
		if ( $url === '' ) {
			return [];
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return [];
		}

		$query = [];
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
			ksort( $query );
		}

		return [
			'scheme' => strtolower( (string) ( $parts['scheme'] ?? '' ) ),
			'host'   => strtolower( (string) $parts['host'] ),
			'path'   => rtrim( '/' . ltrim( (string) ( $parts['path'] ?? '' ), '/' ), '/' ),
			'query'  => $query,
		];
	}
}

if ( ! function_exists( 'crm_telegram_urls_equivalent' ) ) {
	function crm_telegram_urls_equivalent( string $left, string $right ): bool {
		$left  = trim( $left );
		$right = trim( $right );

		if ( $left === '' || $right === '' ) {
			return false;
		}

		if ( $left === $right ) {
			return true;
		}

		$left_parts  = crm_telegram_normalize_url_for_compare( $left );
		$right_parts = crm_telegram_normalize_url_for_compare( $right );

		if ( empty( $left_parts ) || empty( $right_parts ) ) {
			return false;
		}

		return $left_parts === $right_parts;
	}
}

if ( ! function_exists( 'crm_telegram_read_context_setting' ) ) {
	function crm_telegram_read_context_setting( int $company_id, string $context, string $suffix, string $default = '' ): string {
		$key = crm_telegram_setting_key( $context, $suffix );
		if ( $key === '' ) {
			return $default;
		}

		$value = crm_get_setting( $key, $company_id, null );

		// Merchant context falls back to legacy keys so current production
		// webhooks keep working before the migration/backfill is applied.
		if ( ( $value === null || trim( (string) $value ) === '' ) && crm_telegram_normalize_bot_context( $context ) === 'merchant' ) {
			$legacy_key = 'telegram_' . $suffix;
			$legacy_value = crm_get_setting( $legacy_key, $company_id, $default );
			if ( trim( (string) $legacy_value ) !== '' ) {
				$value = $legacy_value;
			}
		}

		return (string) ( $value ?? $default );
	}
}

if ( ! function_exists( 'crm_telegram_collect_settings' ) ) {
	function crm_telegram_collect_settings( int $company_id, string $context = 'merchant' ): array {
		$context = crm_telegram_normalize_bot_context( $context );

		return [
			'context'              => $context,
			'context_label'        => crm_telegram_bot_context_labels()[ $context ],
			'bot_token'            => trim( crm_telegram_read_context_setting( $company_id, $context, 'bot_token' ) ),
			'bot_username'         => crm_telegram_sanitize_bot_username( crm_telegram_read_context_setting( $company_id, $context, 'bot_username' ) ),
			'webhook_url'          => trim( crm_telegram_read_context_setting( $company_id, $context, 'webhook_url' ) ),
			'webhook_connected_at' => trim( crm_telegram_read_context_setting( $company_id, $context, 'webhook_connected_at' ) ),
			'webhook_last_error'   => trim( crm_telegram_read_context_setting( $company_id, $context, 'webhook_last_error' ) ),
			'webhook_lock'         => crm_telegram_read_context_setting( $company_id, $context, 'webhook_lock', '0' ) === '1',
		];
	}
}

if ( ! function_exists( 'crm_telegram_opposite_bot_context' ) ) {
	function crm_telegram_opposite_bot_context( string $context ): string {
		return crm_telegram_normalize_bot_context( $context ) === 'operator' ? 'merchant' : 'operator';
	}
}

if ( ! function_exists( 'crm_telegram_find_duplicate_bot_setting' ) ) {
	function crm_telegram_find_duplicate_bot_setting( int $company_id, string $context, string $bot_username, string $bot_token ): array {
		$context       = crm_telegram_normalize_bot_context( $context );
		$other_context = crm_telegram_opposite_bot_context( $context );
		$labels        = crm_telegram_bot_context_labels();
		$fields        = [];

		if ( $company_id <= 0 ) {
			return [
				'has_duplicate'       => false,
				'other_context'       => $other_context,
				'other_context_label' => (string) ( $labels[ $other_context ] ?? '' ),
				'fields'              => [],
				'message'             => '',
			];
		}

		$bot_username  = crm_telegram_sanitize_bot_username( $bot_username );
		$bot_token     = trim( $bot_token );
		$other_settings = crm_telegram_collect_settings( $company_id, $other_context );

		if ( $bot_token !== '' && trim( (string) ( $other_settings['bot_token'] ?? '' ) ) !== '' ) {
			$tokens_match = function_exists( 'hash_equals' )
				? hash_equals( trim( (string) $other_settings['bot_token'] ), $bot_token )
				: trim( (string) $other_settings['bot_token'] ) === $bot_token;

			if ( $tokens_match ) {
				$fields[] = [
					'id'    => crm_telegram_setting_key( $context, 'bot_token' ),
					'label' => 'Токен бота',
				];
			}
		}

		if ( $bot_username !== '' && trim( (string) ( $other_settings['bot_username'] ?? '' ) ) !== '' ) {
			if ( strcasecmp( $bot_username, trim( (string) $other_settings['bot_username'] ) ) === 0 ) {
				$fields[] = [
					'id'    => crm_telegram_setting_key( $context, 'bot_username' ),
					'label' => 'Имя бота',
				];
			}
		}

		$has_duplicate = ! empty( $fields );

		return [
			'has_duplicate'       => $has_duplicate,
			'other_context'       => $other_context,
			'other_context_label' => (string) ( $labels[ $other_context ] ?? '' ),
			'fields'              => $fields,
			'message'             => $has_duplicate
				? 'Нельзя использовать одного Telegram-бота в двух контурах. Измените токен или username: совпадение найдено в блоке «' . (string) ( $labels[ $other_context ] ?? $other_context ) . '».'
				: '',
		];
	}
}

if ( ! function_exists( 'crm_telegram_get_configuration_status' ) ) {
	function crm_telegram_get_configuration_status( int $company_id, string $context = 'merchant', bool $auto_lock_connected = false ): array {
		$context       = crm_telegram_normalize_bot_context( $context );
		$settings      = crm_telegram_collect_settings( $company_id, $context );
		$callback_url  = crm_telegram_company_callback_url( $company_id, $context );
		$missing_fields = [];
		$blocked_reason = '';
		$is_merchant    = $context === 'merchant';

		if ( $settings['bot_username'] === '' ) {
			$missing_fields[] = [
				'id'    => crm_telegram_setting_key( $context, 'bot_username' ),
				'label' => 'Имя бота',
			];
		}
		if ( $settings['bot_token'] === '' ) {
			$missing_fields[] = [
				'id'    => crm_telegram_setting_key( $context, 'bot_token' ),
				'label' => 'Токен бота',
			];
		}

		$duplicate = crm_telegram_find_duplicate_bot_setting(
			$company_id,
			$context,
			$settings['bot_username'],
			$settings['bot_token']
		);
		$duplicate_fields = array_values( $duplicate['fields'] ?? [] );
		$missing_fields   = array_merge( $missing_fields, $duplicate_fields );

		$is_configured   = empty( $missing_fields );
		$webhook_matches = crm_telegram_urls_equivalent( (string) $settings['webhook_url'], $callback_url );
		$webhook_ready   = $webhook_matches && $settings['webhook_connected_at'] !== '';
		$ready           = $is_configured && $webhook_ready;

		if ( $auto_lock_connected && $webhook_ready && ! $settings['webhook_lock'] ) {
			crm_telegram_set_config_lock( $company_id, true, $context );
			$settings['webhook_lock'] = true;
		}

		if ( ! empty( $duplicate['has_duplicate'] ) ) {
			$blocked_reason = (string) $duplicate['message'];
		} elseif ( ! $is_configured ) {
			$blocked_reason = $is_merchant
				? 'Чтобы включить приглашения мерчантов, заполните имя и токен мерчантского бота.'
				: 'Чтобы включить операторский бот, заполните имя и токен операторского бота.';
		} elseif ( ! $webhook_ready ) {
			$blocked_reason = 'Бот ещё не подключён к callback. Сначала нажмите «Подключить callback».';
		}

		return [
			'company_id'           => $company_id,
			'context'              => $context,
			'context_label'        => $settings['context_label'],
			'is_configured'        => $is_configured,
			'webhook_ready'        => $webhook_ready,
			'invite_ready'         => $is_merchant && $ready,
			'operator_ready'       => ! $is_merchant && $ready,
			'webhook_matches'      => $webhook_matches,
			'blocked_reason'       => $blocked_reason,
			'missing_fields'       => $missing_fields,
			'duplicate_fields'     => $duplicate_fields,
			'callback_url'         => $callback_url,
			'legacy_callback_url'  => $is_merchant ? crm_telegram_legacy_callback_url( $company_id ) : '',
			'bot_handle'           => $settings['bot_username'] !== '' ? '@' . $settings['bot_username'] : '',
			'bot_username'         => $settings['bot_username'],
			'webhook_connected_at' => $settings['webhook_connected_at'],
			'webhook_last_error'   => $settings['webhook_last_error'],
			'webhook_lock'         => $settings['webhook_lock'],
			'settings'             => $settings,
		];
	}
}

if ( ! function_exists( 'crm_telegram_bot_api_request' ) ) {
	function crm_telegram_bot_api_request( string $token, string $method, array $payload = [], string $http_method = 'POST' ): array {
		$token  = trim( $token );
		$method = trim( $method );
		if ( $token === '' || $method === '' ) {
			return [
				'ok'          => false,
				'description' => 'Bot token or API method is missing.',
			];
		}

		$url  = 'https://api.telegram.org/bot' . $token . '/' . ltrim( $method, '/' );
		$args = [
			'timeout' => 20,
		];

		if ( strtoupper( $http_method ) === 'GET' ) {
			$url      = ! empty( $payload ) ? add_query_arg( $payload, $url ) : $url;
			$response = wp_remote_get( $url, $args );
		} else {
			$args['body'] = $payload;
			$response     = wp_remote_post( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return [
				'ok'          => false,
				'description' => $response->get_error_message(),
			];
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( (string) $body, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		return [
			'ok'          => false,
			'description' => 'Invalid Telegram API response.',
			'raw'         => $body,
		];
	}
}

if ( ! function_exists( 'crm_telegram_register_webhook' ) ) {
	function crm_telegram_register_webhook( int $company_id, string $bot_username, string $bot_token, string $context = 'merchant' ): array {
		$context      = crm_telegram_normalize_bot_context( $context );
		$bot_username = crm_telegram_sanitize_bot_username( $bot_username );
		$bot_token    = trim( $bot_token );

		if ( $company_id <= 0 ) {
			return [
				'success' => false,
				'message' => 'Webhook можно регистрировать только в контексте компании.',
			];
		}

		if ( $bot_username === '' || $bot_token === '' ) {
			return [
				'success' => false,
				'message' => 'Сначала заполните имя бота и токен бота.',
			];
		}

		$duplicate = crm_telegram_find_duplicate_bot_setting( $company_id, $context, $bot_username, $bot_token );
		if ( ! empty( $duplicate['has_duplicate'] ) ) {
			return [
				'success'          => false,
				'message'          => (string) $duplicate['message'],
				'duplicate_fields' => array_values( $duplicate['fields'] ?? [] ),
			];
		}

		$callback_url = crm_telegram_company_callback_url( $company_id, $context );
		if ( $callback_url === '' ) {
			return [
				'success' => false,
				'message' => 'Не удалось сформировать callback URL.',
			];
		}

		$get_me = crm_telegram_bot_api_request( $bot_token, 'getMe', [], 'GET' );
		if ( empty( $get_me['ok'] ) || empty( $get_me['result']['username'] ) ) {
			$error_message = trim( (string) ( $get_me['description'] ?? 'Не удалось проверить бота через getMe.' ) );

			return [
				'success'  => false,
				'message'  => $error_message !== '' ? $error_message : 'Не удалось проверить Telegram-бота.',
				'response' => $get_me,
			];
		}

		$bot_username = crm_telegram_sanitize_bot_username( (string) $get_me['result']['username'] );
		if ( $bot_username === '' ) {
			return [
				'success' => false,
				'message' => 'Telegram вернул некорректный username бота.',
			];
		}

		$duplicate = crm_telegram_find_duplicate_bot_setting( $company_id, $context, $bot_username, $bot_token );
		if ( ! empty( $duplicate['has_duplicate'] ) ) {
			return [
				'success'          => false,
				'message'          => (string) $duplicate['message'],
				'duplicate_fields' => array_values( $duplicate['fields'] ?? [] ),
			];
		}

		crm_set_setting( crm_telegram_setting_key( $context, 'bot_username' ), $bot_username, $company_id );
		crm_set_setting( crm_telegram_setting_key( $context, 'bot_token' ), $bot_token, $company_id );
		crm_set_setting( crm_telegram_setting_key( $context, 'webhook_url' ), '', $company_id );
		crm_set_setting( crm_telegram_setting_key( $context, 'webhook_connected_at' ), '', $company_id );
		crm_set_setting( crm_telegram_setting_key( $context, 'webhook_last_error' ), '', $company_id );
		crm_set_setting( crm_telegram_setting_key( $context, 'webhook_lock' ), '0', $company_id );

		$response = crm_telegram_bot_api_request(
			$bot_token,
			'setWebhook',
			[
				'url' => $callback_url,
			]
		);

		if ( empty( $response['ok'] ) ) {
			$error_message = trim( (string) ( $response['description'] ?? 'Telegram API returned an error.' ) );
			crm_set_setting( crm_telegram_setting_key( $context, 'webhook_last_error' ), $error_message, $company_id );

			return [
				'success'  => false,
				'message'  => $error_message !== '' ? $error_message : 'Не удалось зарегистрировать callback в Telegram.',
				'response' => $response,
			];
		}

		crm_set_setting( crm_telegram_setting_key( $context, 'webhook_url' ), $callback_url, $company_id );
		crm_set_setting( crm_telegram_setting_key( $context, 'webhook_connected_at' ), current_time( 'mysql', true ), $company_id );
		crm_set_setting( crm_telegram_setting_key( $context, 'webhook_last_error' ), '', $company_id );
		crm_set_setting( crm_telegram_setting_key( $context, 'webhook_lock' ), '1', $company_id );

		return [
			'success'      => true,
			'message'      => trim( (string) ( $response['description'] ?? '' ) ),
			'callback_url' => $callback_url,
			'context'      => $context,
			'response'     => $response,
		];
	}
}

if ( ! function_exists( 'crm_telegram_set_config_lock' ) ) {
	function crm_telegram_set_config_lock( int $company_id, bool $locked, string $context = 'merchant' ): bool {
		return crm_set_setting(
			crm_telegram_setting_key( $context, 'webhook_lock' ),
			$locked ? '1' : '0',
			$company_id
		);
	}
}

if ( ! function_exists( 'crm_telegram_get_callback_bot_context' ) ) {
	function crm_telegram_get_callback_bot_context(): string {
		$context = isset( $GLOBALS['CRM_TELEGRAM_CALLBACK_CONTEXT'] )
			? (string) $GLOBALS['CRM_TELEGRAM_CALLBACK_CONTEXT']
			: '';

		if ( $context === '' && defined( 'TG_BOT_CONTEXT' ) ) {
			$context = (string) TG_BOT_CONTEXT;
		}

		if ( $context === '' && isset( $_GET['bot_context'] ) ) {
			$context = (string) $_GET['bot_context'];
		}

		return crm_telegram_normalize_bot_context( $context );
	}
}

if ( ! function_exists( 'crm_telegram_get_callback_company_id' ) ) {
	function crm_telegram_get_callback_company_id(): int {
		$company_id = isset( $_GET['company'] ) ? (int) $_GET['company'] : 0;
		if ( $company_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM crm_companies WHERE id = %d AND status = 'active' LIMIT 1",
				$company_id
			)
		);

		return $exists > 0 ? $company_id : 0;
	}
}
