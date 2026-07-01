<?php
/**
 * Malibu Exchange — Merchant Telegram Commands
 *
 * Здесь хранится source of truth для slash-команд merchant bot.
 * После изменения списка вызовите sync-route:
 * /wp-json/malibu-exchange/v1/merchant-commands-sync?company=<ID>&token=merchant-commands-20260516
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'crm_merchant_tg_command_definitions' ) ) {
	function crm_merchant_tg_command_definitions(): array {
		return [
			'/start'   => [
				'command'     => 'start',
				'description' => '🏢 Открыть кабинет',
				'screen'      => 'main',
			],
			'/menu'    => [
				'command'     => 'menu',
				'description' => '📋 Главное меню',
				'screen'      => 'main',
			],
			'/invoice' => [
				'command'     => 'invoice',
				'description' => '🧾 Выставить счёт',
				'entrypoint'  => 'invoice',
			],
			'/orders'  => [
				'command'     => 'orders',
				'description' => '📂 Мои счета',
				'screen'      => 'orders',
			],
			'/balance' => [
				'command'     => 'balance',
				'description' => '💼 Балансы',
				'screen'      => 'balances',
			],
			'/rates'   => [
				'command'     => 'rates',
				'description' => '💹 Курсы',
				'screen'      => 'rates',
			],
			'/channel' => [
				'command'     => 'channel',
				'description' => '📣 Продать подписку',
				'screen'      => 'channel_subscription',
			],
			'/profile' => [
				'command'     => 'profile',
				'description' => '👤 Профиль',
				'screen'      => 'profile',
			],
			'/help'    => [
				'command'     => 'help',
				'description' => 'ℹ️ Помощь',
				'screen'      => 'help',
			],
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_commands_for_api' ) ) {
	function crm_merchant_tg_commands_for_api(): array {
		$commands = [];

		foreach ( crm_merchant_tg_command_definitions() as $definition ) {
			$command = trim( (string) ( $definition['command'] ?? '' ) );
			if ( $command === '' ) {
				continue;
			}

			$commands[] = [
				'command'     => $command,
				'description' => trim( (string) ( $definition['description'] ?? '' ) ),
			];
		}

		return $commands;
	}
}

if ( ! function_exists( 'crm_merchant_tg_commands_sync_token' ) ) {
	function crm_merchant_tg_commands_sync_token(): string {
		return 'merchant-commands-20260516';
	}
}

if ( ! function_exists( 'crm_merchant_tg_set_my_commands' ) ) {
	function crm_merchant_tg_set_my_commands( int $company_id ): array {
		if ( $company_id <= 0 ) {
			return [
				'success' => false,
				'message' => 'Команды можно устанавливать только в контексте компании.',
			];
		}

		$settings = function_exists( 'crm_telegram_collect_settings' )
			? crm_telegram_collect_settings( $company_id, 'merchant' )
			: [];
		$bot_token = trim( (string) ( $settings['bot_token'] ?? '' ) );

		if ( $bot_token === '' ) {
			return [
				'success' => false,
				'message' => 'У merchant-бота этой компании не заполнен токен.',
			];
		}

		$payload = [
			'commands' => wp_json_encode(
				crm_merchant_tg_commands_for_api(),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
		];

		$response = function_exists( 'crm_telegram_bot_api_request' )
			? crm_telegram_bot_api_request( $bot_token, 'setMyCommands', $payload )
			: [ 'ok' => false, 'description' => 'Telegram helper недоступен.' ];

		if ( empty( $response['ok'] ) ) {
			return [
				'success'  => false,
				'message'  => trim( (string) ( $response['description'] ?? 'Telegram API returned an error.' ) ),
				'response' => $response,
			];
		}

		return [
			'success'      => true,
			'message'      => trim( (string) ( $response['description'] ?? 'Команды бота обновлены.' ) ),
			'company_id'   => $company_id,
			'bot_username' => trim( (string) ( $settings['bot_username'] ?? '' ) ),
			'commands'     => crm_merchant_tg_commands_for_api(),
			'response'     => $response,
		];
	}
}

if ( ! function_exists( 'crm_merchant_tg_rest_sync_commands' ) ) {
	function crm_merchant_tg_rest_sync_commands( WP_REST_Request $request ) {
		$token = trim( (string) $request->get_param( 'token' ) );
		if ( ! hash_equals( crm_merchant_tg_commands_sync_token(), $token ) ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => 'Forbidden.',
				],
				403
			);
		}

		$company_id = (int) $request->get_param( 'company' );
		$result     = crm_merchant_tg_set_my_commands( $company_id );
		$settings   = function_exists( 'crm_telegram_collect_settings' )
			? crm_telegram_collect_settings( $company_id, 'merchant' )
			: [];
		$bot_token  = trim( (string) ( $settings['bot_token'] ?? '' ) );
		$verify     = $bot_token !== '' && function_exists( 'crm_telegram_bot_api_request' )
			? crm_telegram_bot_api_request( $bot_token, 'getMyCommands', [], 'GET' )
			: null;

		if ( empty( $result['success'] ) ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => (string) ( $result['message'] ?? 'Failed to set merchant bot commands.' ),
					'result'  => $result,
					'verify'  => $verify,
				],
				500
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => (string) ( $result['message'] ?? 'Merchant bot commands updated.' ),
				'result'  => $result,
				'verify'  => $verify,
			],
			200
		);
	}
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'malibu-exchange/v1',
			'/merchant-commands-sync',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'crm_merchant_tg_rest_sync_commands',
				'permission_callback' => '__return_true',
			]
		);
	}
);
