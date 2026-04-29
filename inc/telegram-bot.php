<?php
/**
 * Malibu Exchange — Company-scoped Telegram bot helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'crm_telegram_default_settings' ) ) {
	function crm_telegram_default_settings(): array {
		return [
			'telegram_bot_token'            => '',
			'telegram_bot_username'         => '',
			'telegram_webhook_url'          => '',
			'telegram_webhook_connected_at' => '',
			'telegram_webhook_last_error'   => '',
			'telegram_webhook_lock'         => '0',
		];
	}
}

if ( ! function_exists( 'crm_telegram_seed_company_settings' ) ) {
	function crm_telegram_seed_company_settings( int $company_id ): void {
		if ( $company_id <= 0 ) {
			return;
		}

		global $wpdb;

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
	function crm_telegram_company_callback_url( int $company_id ): string {
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

if ( ! function_exists( 'crm_telegram_collect_settings' ) ) {
	function crm_telegram_collect_settings( int $company_id ): array {
		$defaults = crm_telegram_default_settings();

		return [
			'bot_token'            => trim( (string) crm_get_setting( 'telegram_bot_token', $company_id, $defaults['telegram_bot_token'] ) ),
			'bot_username'         => crm_telegram_sanitize_bot_username( (string) crm_get_setting( 'telegram_bot_username', $company_id, $defaults['telegram_bot_username'] ) ),
			'webhook_url'          => trim( (string) crm_get_setting( 'telegram_webhook_url', $company_id, $defaults['telegram_webhook_url'] ) ),
			'webhook_connected_at' => trim( (string) crm_get_setting( 'telegram_webhook_connected_at', $company_id, $defaults['telegram_webhook_connected_at'] ) ),
			'webhook_last_error'   => trim( (string) crm_get_setting( 'telegram_webhook_last_error', $company_id, $defaults['telegram_webhook_last_error'] ) ),
			'webhook_lock'         => crm_get_setting( 'telegram_webhook_lock', $company_id, $defaults['telegram_webhook_lock'] ) === '1',
		];
	}
}

if ( ! function_exists( 'crm_telegram_get_configuration_status' ) ) {
	function crm_telegram_get_configuration_status( int $company_id ): array {
		$settings = crm_telegram_collect_settings( $company_id );
		$callback_url = crm_telegram_company_callback_url( $company_id );
		$missing_fields = [];
		$blocked_reason = '';

		if ( $settings['bot_username'] === '' ) {
			$missing_fields[] = [ 'id' => 'telegram_bot_username', 'label' => 'Имя бота' ];
		}
		if ( $settings['bot_token'] === '' ) {
			$missing_fields[] = [ 'id' => 'telegram_bot_token', 'label' => 'Токен бота' ];
		}

		$is_configured    = empty( $missing_fields );
		$webhook_matches  = $settings['webhook_url'] !== '' && $callback_url !== '' && trim( $settings['webhook_url'] ) === trim( $callback_url );
		$webhook_ready    = $webhook_matches && $settings['webhook_connected_at'] !== '';
		$invite_ready     = $is_configured && $webhook_ready;

		if ( ! $is_configured ) {
			$blocked_reason = 'Чтобы включить приглашения мерчантов, заполните имя бота и токен бота.';
		} elseif ( ! $webhook_ready ) {
			$blocked_reason = 'Бот ещё не подключён к callback. Сначала нажмите «Подключить callback».';
		}

		return [
			'company_id'          => $company_id,
			'is_configured'       => $is_configured,
			'webhook_ready'       => $webhook_ready,
			'invite_ready'        => $invite_ready,
			'webhook_matches'     => $webhook_matches,
			'blocked_reason'      => $blocked_reason,
			'missing_fields'      => $missing_fields,
			'callback_url'        => $callback_url,
			'bot_handle'          => $settings['bot_username'] !== '' ? '@' . $settings['bot_username'] : '',
			'bot_username'        => $settings['bot_username'],
			'webhook_connected_at'=> $settings['webhook_connected_at'],
			'webhook_last_error'  => $settings['webhook_last_error'],
			'webhook_lock'        => $settings['webhook_lock'],
			'settings'            => $settings,
		];
	}
}

if ( ! function_exists( 'crm_telegram_bot_api_request' ) ) {
	function crm_telegram_bot_api_request( string $token, string $method, array $payload = [], string $http_method = 'POST' ): array {
		$token = trim( $token );
		$method = trim( $method );
		if ( $token === '' || $method === '' ) {
			return [
				'ok'          => false,
				'description' => 'Bot token or API method is missing.',
			];
		}

		$url = 'https://api.telegram.org/bot' . $token . '/' . ltrim( $method, '/' );
		$args = [
			'timeout' => 20,
		];

		if ( strtoupper( $http_method ) === 'GET' ) {
			$url = ! empty( $payload ) ? add_query_arg( $payload, $url ) : $url;
			$response = wp_remote_get( $url, $args );
		} else {
			$args['body'] = $payload;
			$response = wp_remote_post( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return [
				'ok'          => false,
				'description' => $response->get_error_message(),
			];
		}

		$body = wp_remote_retrieve_body( $response );
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
	function crm_telegram_register_webhook( int $company_id, string $bot_username, string $bot_token ): array {
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

		$callback_url = crm_telegram_company_callback_url( $company_id );
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

		crm_set_setting( 'telegram_bot_username', $bot_username, $company_id );
		crm_set_setting( 'telegram_bot_token', $bot_token, $company_id );
		crm_set_setting( 'telegram_webhook_url', '', $company_id );
		crm_set_setting( 'telegram_webhook_connected_at', '', $company_id );
		crm_set_setting( 'telegram_webhook_last_error', '', $company_id );
		crm_set_setting( 'telegram_webhook_lock', '0', $company_id );

		$response = crm_telegram_bot_api_request(
			$bot_token,
			'setWebhook',
			[
				'url' => $callback_url,
			]
		);

		if ( empty( $response['ok'] ) ) {
			$error_message = trim( (string) ( $response['description'] ?? 'Telegram API returned an error.' ) );
			crm_set_setting( 'telegram_webhook_last_error', $error_message, $company_id );

			return [
				'success'  => false,
				'message'  => $error_message !== '' ? $error_message : 'Не удалось зарегистрировать callback в Telegram.',
				'response' => $response,
			];
		}

		crm_set_setting( 'telegram_webhook_url', $callback_url, $company_id );
		crm_set_setting( 'telegram_webhook_connected_at', current_time( 'mysql', true ), $company_id );
		crm_set_setting( 'telegram_webhook_last_error', '', $company_id );
		crm_set_setting( 'telegram_webhook_lock', '1', $company_id );

		return [
			'success'      => true,
			'message'      => trim( (string) ( $response['description'] ?? '' ) ),
			'callback_url' => $callback_url,
			'response'     => $response,
		];
	}
}

if ( ! function_exists( 'crm_telegram_set_config_lock' ) ) {
	function crm_telegram_set_config_lock( int $company_id, bool $locked ): bool {
		return crm_set_setting( 'telegram_webhook_lock', $locked ? '1' : '0', $company_id );
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
