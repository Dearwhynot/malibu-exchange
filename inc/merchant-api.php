<?php
/**
 * Malibu Exchange — Merchant API foundation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function crm_merchant_api_openapi_relative_path(): string {
	return 'docs/api/merchant/openapi.yaml';
}

function crm_merchant_api_openapi_file_path(): string {
	return trailingslashit( get_template_directory() ) . ltrim( crm_merchant_api_openapi_relative_path(), '/' );
}

function crm_get_merchant_api_spec_url(): string {
	return trailingslashit( get_stylesheet_directory_uri() ) . ltrim( crm_merchant_api_openapi_relative_path(), '/' );
}

function crm_get_merchant_api_docs_page(): ?WP_Post {
	$page = get_page_by_path( 'merchant-api', OBJECT, 'page' );

	return $page instanceof WP_Post ? $page : null;
}

function crm_get_merchant_api_console_page(): ?WP_Post {
	$page = get_page_by_path( 'merchant-api-console', OBJECT, 'page' );

	return $page instanceof WP_Post ? $page : null;
}

function crm_get_merchant_api_docs_url(): string {
	$page = crm_get_merchant_api_docs_page();

	return $page ? (string) get_permalink( $page ) : home_url( '/merchant-api/' );
}

function crm_get_merchant_api_console_url(): string {
	$page = crm_get_merchant_api_console_page();

	return $page ? (string) get_permalink( $page ) : home_url( '/merchant-api-console/' );
}

function crm_merchant_api_permission_code(): string {
	return 'merchants.manage_api';
}

function crm_can_manage_merchant_api( int $user_id = 0 ): bool {
	if ( $user_id <= 0 ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user_id = get_current_user_id();
	}

	if ( crm_is_root( $user_id ) ) {
		return true;
	}

	if ( $user_id === get_current_user_id() ) {
		return crm_can_access( crm_merchant_api_permission_code() );
	}

	if ( crm_get_user_status( $user_id ) !== CRM_STATUS_ACTIVE ) {
		return false;
	}

	if ( function_exists( 'crm_user_has_company_access_or_root' ) && ! crm_user_has_company_access_or_root( $user_id ) ) {
		return false;
	}

	return crm_user_has_permission( $user_id, crm_merchant_api_permission_code() );
}

function crm_merchant_api_client_statuses(): array {
	return [
		'active'  => 'Активен',
		'paused'  => 'Пауза',
		'revoked' => 'Отозван',
	];
}

function crm_merchant_api_client_status_badge( string $status ): string {
	if ( $status === 'revoked' ) {
		return 'danger';
	}
	if ( $status === 'paused' ) {
		return 'warning';
	}

	return 'success';
}

function crm_merchant_api_scope_definitions(): array {
	return [
		'profile:read'  => [ 'label' => 'Профиль',           'description' => 'Чтение профиля мерчанта и company capabilities.' ],
		'balances:read' => [ 'label' => 'Балансы',           'description' => 'Чтение балансов мерчанта.' ],
		'rates:read'    => [ 'label' => 'Курсы',             'description' => 'Чтение клиентских курсов и invoice capabilities.' ],
		'orders:read'   => [ 'label' => 'Счета: чтение',     'description' => 'Чтение списка и деталей merchant invoice.' ],
		'orders:write'  => [ 'label' => 'Счета: создание',   'description' => 'Создание merchant invoice.' ],
		'payouts:read'  => [ 'label' => 'Выплаты',           'description' => 'Чтение истории выплат мерчанту.' ],
		'webhooks:read' => [ 'label' => 'Webhook: чтение',   'description' => 'Чтение webhook-конфигурации интеграции.' ],
		'webhooks:write'=> [ 'label' => 'Webhook: запись',   'description' => 'Изменение webhook-конфигурации интеграции.' ],
	];
}

function crm_merchant_api_scope_labels(): array {
	$labels = [];

	foreach ( crm_merchant_api_scope_definitions() as $code => $meta ) {
		$labels[ $code ] = (string) ( $meta['label'] ?? $code );
	}

	return $labels;
}

function crm_merchant_api_default_scopes(): array {
	return array_keys( crm_merchant_api_scope_definitions() );
}

function crm_merchant_api_normalize_scopes( $scopes ): array {
	if ( is_string( $scopes ) ) {
		$decoded = json_decode( $scopes, true );
		if ( is_array( $decoded ) ) {
			$scopes = $decoded;
		} else {
			$scopes = preg_split( '/\s*,\s*/', $scopes, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		}
	}

	if ( ! is_array( $scopes ) ) {
		$scopes = [];
	}

	$allowed = crm_merchant_api_scope_definitions();
	$result  = [];

	foreach ( $scopes as $scope ) {
		$scope = strtolower( trim( (string) $scope ) );
		if ( $scope !== '' && isset( $allowed[ $scope ] ) ) {
			$result[ $scope ] = $scope;
		}
	}

	return array_values( $result );
}

function crm_merchant_api_webhook_event_definitions(): array {
	return [
		'invoice.created'  => [ 'label' => 'Invoice created' ],
		'invoice.paid'     => [ 'label' => 'Invoice paid' ],
		'invoice.expired'  => [ 'label' => 'Invoice expired' ],
		'invoice.cancelled'=> [ 'label' => 'Invoice cancelled' ],
		'payout.created'   => [ 'label' => 'Payout created' ],
	];
}

function crm_merchant_api_webhook_event_labels(): array {
	$labels = [];

	foreach ( crm_merchant_api_webhook_event_definitions() as $code => $meta ) {
		$labels[ $code ] = (string) ( $meta['label'] ?? $code );
	}

	return $labels;
}

function crm_merchant_api_normalize_webhook_events( $events ): array {
	if ( is_string( $events ) ) {
		$decoded = json_decode( $events, true );
		if ( is_array( $decoded ) ) {
			$events = $decoded;
		} else {
			$events = preg_split( '/\s*,\s*/', $events, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		}
	}

	if ( ! is_array( $events ) ) {
		$events = [];
	}

	$allowed = crm_merchant_api_webhook_event_definitions();
	$result  = [];

	foreach ( $events as $event_code ) {
		$event_code = trim( (string) $event_code );
		if ( $event_code !== '' && isset( $allowed[ $event_code ] ) ) {
			$result[ $event_code ] = $event_code;
		}
	}

	return array_values( $result );
}

function crm_merchant_api_generate_token(): string {
	return 'mapi_live_' . bin2hex( random_bytes( 24 ) );
}

function crm_merchant_api_generate_webhook_secret(): string {
	return 'mwhsec_' . bin2hex( random_bytes( 24 ) );
}

function crm_merchant_api_hash_secret( string $secret ): string {
	return hash( 'sha256', $secret );
}

function crm_merchant_api_secret_prefix( string $secret ): string {
	return substr( $secret, 0, 18 );
}

function crm_merchant_api_request_id(): string {
	return 'req_' . bin2hex( random_bytes( 8 ) );
}

function crm_merchant_api_rate_limit_policies(): array {
	return [
		'read'  => [
			'limit'  => 120,
			'window' => 60,
		],
		'write' => [
			'limit'  => 20,
			'window' => 60,
		],
	];
}

function crm_merchant_api_rate_limit_bucket( string $required_scope ): string {
	return $required_scope === 'orders:write' ? 'write' : 'read';
}

function crm_merchant_api_rate_limit_key( int $client_id, string $bucket ): string {
	return 'crm_mapi_rl_' . $bucket . '_' . $client_id;
}

function crm_merchant_api_rate_limit_headers( WP_REST_Response $response, int $limit, int $remaining, int $retry_after ): WP_REST_Response {
	$response->header( 'Retry-After', (string) max( 1, $retry_after ) );
	$response->header( 'X-RateLimit-Limit', (string) max( 1, $limit ) );
	$response->header( 'X-RateLimit-Remaining', (string) max( 0, $remaining ) );

	return $response;
}

function crm_merchant_api_rate_limit_error_response(
	string $request_id,
	string $message,
	int $limit,
	int $remaining,
	int $retry_after
): WP_REST_Response {
	$response = crm_merchant_api_error_response( $request_id, 'rate_limit_exceeded', $message, 429 );

	return crm_merchant_api_rate_limit_headers( $response, $limit, $remaining, $retry_after );
}

function crm_merchant_api_enforce_rate_limit( WP_REST_Request $request, array $context, string $required_scope ) {
	$client_id = (int) ( $context['client']->id ?? 0 );
	if ( $client_id <= 0 ) {
		return null;
	}

	$bucket   = crm_merchant_api_rate_limit_bucket( $required_scope );
	$policies = crm_merchant_api_rate_limit_policies();
	$policy   = $policies[ $bucket ] ?? $policies['read'];
	$limit    = max( 1, (int) ( $policy['limit'] ?? 60 ) );
	$window   = max( 1, (int) ( $policy['window'] ?? 60 ) );
	$key      = crm_merchant_api_rate_limit_key( $client_id, $bucket );
	$now      = time();
	$state    = get_transient( $key );

	if ( ! is_array( $state ) ) {
		$state = [
			'count'      => 0,
			'started_at' => $now,
		];
	}

	$count      = max( 0, (int) ( $state['count'] ?? 0 ) );
	$started_at = max( 0, (int) ( $state['started_at'] ?? $now ) );
	if ( $started_at <= 0 || ( $started_at + $window ) <= $now ) {
		$count      = 0;
		$started_at = $now;
	}

	$retry_after = max( 1, ( $started_at + $window ) - $now );
	if ( $count >= $limit ) {
		$remaining = 0;

		crm_log( 'merchant_api_rate_limit_exceeded', [
			'category'    => 'security',
			'level'       => 'warning',
			'action'      => 'auth',
			'message'     => 'Merchant API rate limit exceeded.',
			'is_success'  => false,
			'target_type' => 'merchant',
			'target_id'   => (int) ( $context['merchant']->id ?? 0 ),
			'org_id'      => (int) ( $context['company']->id ?? 0 ),
			'context'     => [
				'request_id'    => (string) ( $context['request_id'] ?? '' ),
				'client_id'     => $client_id,
				'client_name'   => (string) ( $context['client']->client_name ?? '' ),
				'token_prefix'  => (string) ( $context['client']->token_prefix ?? '' ),
				'bucket'        => $bucket,
				'limit'         => $limit,
				'window_seconds'=> $window,
				'request_route' => $request->get_route(),
				'request_method'=> $request->get_method(),
			],
		] );

		return crm_merchant_api_rate_limit_error_response(
			(string) $context['request_id'],
			'Rate limit exceeded. Retry later.',
			$limit,
			$remaining,
			$retry_after
		);
	}

	$count++;
	set_transient(
		$key,
		[
			'count'      => $count,
			'started_at' => $started_at,
		],
		$window
	);

	$remaining = max( 0, $limit - $count );

	return [
		'limit'       => $limit,
		'remaining'   => $remaining,
		'retry_after' => $retry_after,
	];
}

function crm_merchant_api_iso8601( ?string $mysql_datetime ): ?string {
	$mysql_datetime = trim( (string) $mysql_datetime );
	if ( $mysql_datetime === '' ) {
		return null;
	}

	try {
		$dt = new DateTimeImmutable( $mysql_datetime, new DateTimeZone( 'UTC' ) );
		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_ATOM );
	} catch ( Exception $e ) {
		return null;
	}
}

function crm_merchant_api_get_company_mode_summary( int $company_id, $merchant = null ): array {
	$order_currency = function_exists( 'crm_fintech_normalize_kanyon_order_currency' )
		? crm_fintech_normalize_kanyon_order_currency( (string) crm_get_setting( 'fintech_pay2day_order_currency', $company_id, '' ) )
		: strtoupper( trim( (string) crm_get_setting( 'fintech_pay2day_order_currency', $company_id, '' ) ) );
	$active_provider = function_exists( 'crm_fintech_normalize_provider_code' )
		? crm_fintech_normalize_provider_code( (string) crm_get_setting( 'fintech_active_provider', $company_id, '' ) )
		: sanitize_key( (string) crm_get_setting( 'fintech_active_provider', $company_id, '' ) );
	$enabled_directions = [];
	if ( $merchant && function_exists( 'crm_merchant_resolve_invoice_directions_from_row' ) ) {
		$enabled_directions = crm_merchant_resolve_invoice_directions_from_row( $merchant, true );
	} elseif ( function_exists( 'crm_company_get_enabled_invoice_directions' ) ) {
		$enabled_directions = crm_company_get_enabled_invoice_directions( $company_id );
	}
	$provider_mode = '';
	$requested_amount_currency = '';
	$payment_currency_code = 'RUB';
	$settlement_currency_code = 'USDT';

	if ( $active_provider === 'kanyon' && $order_currency === 'USDT' ) {
		$provider_mode = 'orderAmount';
		$requested_amount_currency = 'USDT';
	} elseif ( $active_provider === 'kanyon' && $order_currency === 'RUB' ) {
		$provider_mode = 'paymentAmount';
		$requested_amount_currency = 'RUB';
	} elseif ( $active_provider === 'friendly_pay' ) {
		$provider_mode = 'paymentAmount';
		$requested_amount_currency = 'RUB';
	} else {
		$payment_currency_code = '';
		$settlement_currency_code = '';
	}

	return [
		'company_id'                => $company_id,
		'merchant_id'               => $merchant ? (int) ( is_object( $merchant ) ? ( $merchant->id ?? 0 ) : ( $merchant['id'] ?? 0 ) ) : 0,
		'active_provider'           => $active_provider,
		'company_order_currency'    => $order_currency,
		'provider_mode'             => $provider_mode,
		'requested_amount_currency' => $requested_amount_currency,
		'payment_currency_code'     => $payment_currency_code,
		'settlement_currency_code'  => $settlement_currency_code,
		'enabled_directions'        => array_values( array_map( 'strval', $enabled_directions ) ),
	];
}

function crm_merchant_api_get_client( int $client_id ): ?object {
	global $wpdb;

	if ( $client_id <= 0 ) {
		return null;
	}

	return $wpdb->get_row(
		$wpdb->prepare(
			'SELECT * FROM crm_merchant_api_clients WHERE id = %d LIMIT 1',
			$client_id
		)
	) ?: null;
}

function crm_merchant_api_get_client_by_token( string $token ): ?object {
	global $wpdb;

	$token = trim( $token );
	if ( $token === '' ) {
		return null;
	}

	return $wpdb->get_row(
		$wpdb->prepare(
			'SELECT * FROM crm_merchant_api_clients WHERE token_hash = %s LIMIT 1',
			crm_merchant_api_hash_secret( $token )
		)
	) ?: null;
}

function crm_merchant_api_format_client( object $client ): array {
	$status = sanitize_key( (string) ( $client->status ?? 'active' ) );
	$scopes = crm_merchant_api_normalize_scopes( $client->scopes_json ?? '' );
	$events = crm_merchant_api_normalize_webhook_events( $client->webhook_events_json ?? '' );

	return [
		'id'                       => (int) $client->id,
		'company_id'               => (int) $client->company_id,
		'merchant_id'              => (int) $client->merchant_id,
		'client_name'              => (string) $client->client_name,
		'status'                   => $status,
		'status_label'             => (string) ( crm_merchant_api_client_statuses()[ $status ] ?? $status ),
		'status_badge'             => crm_merchant_api_client_status_badge( $status ),
		'token_prefix'             => (string) $client->token_prefix,
		'scopes'                   => $scopes,
		'scope_labels'             => array_values( array_map(
			static fn( string $scope ): string => (string) ( crm_merchant_api_scope_labels()[ $scope ] ?? $scope ),
			$scopes
		) ),
		'webhook_url'              => (string) ( $client->webhook_url ?? '' ),
		'webhook_secret_prefix'    => (string) ( $client->webhook_secret_prefix ?? '' ),
		'webhook_events'           => $events,
		'webhook_event_labels'     => array_values( array_map(
			static fn( string $event_code ): string => (string) ( crm_merchant_api_webhook_event_labels()[ $event_code ] ?? $event_code ),
			$events
		) ),
		'webhook_last_status_code' => $client->webhook_last_status_code !== null ? (int) $client->webhook_last_status_code : null,
		'webhook_last_attempt_at'  => crm_merchant_api_iso8601( $client->webhook_last_attempt_at ?? '' ),
		'webhook_last_success_at'  => crm_merchant_api_iso8601( $client->webhook_last_success_at ?? '' ),
		'last_used_at'             => crm_merchant_api_iso8601( $client->last_used_at ?? '' ),
		'last_used_ip'             => (string) ( $client->last_used_ip ?? '' ),
		'revoked_at'               => crm_merchant_api_iso8601( $client->revoked_at ?? '' ),
		'created_at'               => crm_merchant_api_iso8601( $client->created_at ?? '' ),
		'updated_at'               => crm_merchant_api_iso8601( $client->updated_at ?? '' ),
	];
}

function crm_merchant_api_list_clients( int $company_id, int $merchant_id ): array {
	global $wpdb;

	if ( $company_id <= 0 || $merchant_id <= 0 ) {
		return [];
	}

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT * FROM crm_merchant_api_clients WHERE company_id = %d AND merchant_id = %d ORDER BY created_at DESC, id DESC',
			$company_id,
			$merchant_id
		)
	) ?: [];

	return array_values( array_map( 'crm_merchant_api_format_client', $rows ) );
}

function crm_merchant_api_admin_payload_for_merchant( int $merchant_id ): array {
	$merchant = function_exists( 'crm_get_merchant_by_id' ) ? crm_get_merchant_by_id( $merchant_id ) : null;
	if ( ! $merchant ) {
		return [
			'clients'       => [],
			'mode_summary'  => [],
			'scope_labels'  => crm_merchant_api_scope_labels(),
			'default_scopes'=> crm_merchant_api_default_scopes(),
		];
	}

	$company_id = (int) ( $merchant->company_id ?? 0 );

	return [
		'clients'        => crm_merchant_api_list_clients( $company_id, $merchant_id ),
		'mode_summary'   => $company_id > 0 ? crm_merchant_api_get_company_mode_summary( $company_id, $merchant ) : [],
		'scope_labels'   => crm_merchant_api_scope_labels(),
		'default_scopes' => crm_merchant_api_default_scopes(),
	];
}

function crm_merchant_api_create_client( int $merchant_id, array $args = [] ) {
	global $wpdb;

	$merchant = function_exists( 'crm_get_merchant_by_id' ) ? crm_get_merchant_by_id( $merchant_id ) : null;
	if ( ! $merchant ) {
		return new WP_Error( 'merchant_not_found', 'Мерчант не найден.' );
	}

	$company_id = (int) ( $merchant->company_id ?? 0 );
	if ( $company_id <= 0 ) {
		return new WP_Error( 'invalid_company_scope', 'Нельзя выпускать Merchant API ключ в company_id = 0.' );
	}

	$client_name = sanitize_text_field( (string) ( $args['client_name'] ?? '' ) );
	if ( $client_name === '' ) {
		$client_name = 'Primary integration';
	}

	$scopes = crm_merchant_api_normalize_scopes( $args['scopes'] ?? crm_merchant_api_default_scopes() );
	if ( empty( $scopes ) ) {
		$scopes = crm_merchant_api_default_scopes();
	}

	$webhook_url = trim( (string) ( $args['webhook_url'] ?? '' ) );
	$webhook_url = $webhook_url !== '' ? esc_url_raw( $webhook_url ) : '';
	if ( $webhook_url !== '' && ! wp_http_validate_url( $webhook_url ) ) {
		return new WP_Error( 'invalid_webhook_url', 'Webhook URL выглядит некорректным.' );
	}

	$webhook_events = crm_merchant_api_normalize_webhook_events( $args['webhook_events'] ?? [] );
	$actor_user_id  = isset( $args['actor_user_id'] ) ? (int) $args['actor_user_id'] : get_current_user_id();
	$token          = crm_merchant_api_generate_token();
	$token_prefix   = crm_merchant_api_secret_prefix( $token );
	$webhook_secret = $webhook_url !== '' ? crm_merchant_api_generate_webhook_secret() : '';

	$inserted = $wpdb->insert(
		'crm_merchant_api_clients',
		[
			'company_id'            => $company_id,
			'merchant_id'           => $merchant_id,
			'client_name'           => $client_name,
			'status'                => 'active',
			'token_prefix'          => $token_prefix,
			'token_hash'            => crm_merchant_api_hash_secret( $token ),
			'scopes_json'           => wp_json_encode( $scopes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'webhook_url'           => $webhook_url !== '' ? $webhook_url : null,
			'webhook_secret_prefix' => $webhook_secret !== '' ? crm_merchant_api_secret_prefix( $webhook_secret ) : null,
			'webhook_secret_hash'   => $webhook_secret !== '' ? crm_merchant_api_hash_secret( $webhook_secret ) : null,
			'webhook_events_json'   => ! empty( $webhook_events ) ? wp_json_encode( $webhook_events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null,
			'created_by_user_id'    => $actor_user_id > 0 ? $actor_user_id : null,
			'updated_by_user_id'    => $actor_user_id > 0 ? $actor_user_id : null,
		],
		[
			'%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d',
		]
	);

	if ( ! $inserted ) {
		return new WP_Error( 'db_insert_failed', 'Не удалось сохранить Merchant API client.' );
	}

	$client_id = (int) $wpdb->insert_id;
	$client    = crm_merchant_api_get_client( $client_id );

	crm_log( 'merchant_api_client_created', [
		'category'    => 'integrations',
		'level'       => 'info',
		'action'      => 'create',
		'message'     => 'Создан Merchant API client.',
		'target_type' => 'merchant_api_client',
		'target_id'   => $client_id,
		'org_id'      => $company_id,
		'context'     => [
			'merchant_id'    => $merchant_id,
			'client_name'    => $client_name,
			'token_prefix'   => $token_prefix,
			'scopes'         => $scopes,
			'webhook_url'    => $webhook_url !== '' ? $webhook_url : null,
			'webhook_events' => $webhook_events,
		],
	] );

	return [
		'success'        => true,
		'client'         => $client ? crm_merchant_api_format_client( $client ) : null,
		'raw_token'      => $token,
		'raw_webhook_secret' => $webhook_secret,
	];
}

function crm_merchant_api_revoke_client( int $client_id, int $actor_user_id = 0 ) {
	global $wpdb;

	$client = crm_merchant_api_get_client( $client_id );
	if ( ! $client ) {
		return new WP_Error( 'client_not_found', 'Merchant API client не найден.' );
	}

	if ( (string) $client->status === 'revoked' ) {
		return [
			'success' => true,
			'client'  => crm_merchant_api_format_client( $client ),
		];
	}

	$updated = $wpdb->update(
		'crm_merchant_api_clients',
		[
			'status'            => 'revoked',
			'revoked_at'        => current_time( 'mysql', true ),
			'updated_by_user_id'=> $actor_user_id > 0 ? $actor_user_id : null,
		],
		[ 'id' => $client_id ],
		[ '%s', '%s', '%d' ],
		[ '%d' ]
	);

	if ( $updated === false ) {
		return new WP_Error( 'db_update_failed', 'Не удалось отозвать Merchant API client.' );
	}

	$client = crm_merchant_api_get_client( $client_id );

	crm_log( 'merchant_api_client_revoked', [
		'category'    => 'integrations',
		'level'       => 'warning',
		'action'      => 'update',
		'message'     => 'Merchant API client отозван.',
		'target_type' => 'merchant_api_client',
		'target_id'   => $client_id,
		'org_id'      => (int) ( $client->company_id ?? 0 ),
		'context'     => [
			'merchant_id'  => (int) ( $client->merchant_id ?? 0 ),
			'client_name'  => (string) ( $client->client_name ?? '' ),
			'token_prefix' => (string) ( $client->token_prefix ?? '' ),
		],
	] );

	return [
		'success' => true,
		'client'  => $client ? crm_merchant_api_format_client( $client ) : null,
	];
}

function crm_merchant_api_touch_client_usage( int $client_id ): void {
	global $wpdb;

	if ( $client_id <= 0 ) {
		return;
	}

	$wpdb->update(
		'crm_merchant_api_clients',
		[
			'last_used_at' => current_time( 'mysql', true ),
			'last_used_ip' => function_exists( 'crm_audit_log_get_ip' ) ? crm_audit_log_get_ip() : '',
		],
		[ 'id' => $client_id ],
		[ '%s', '%s' ],
		[ '%d' ]
	);
}

function crm_merchant_api_extract_bearer_token( WP_REST_Request $request ): string {
	$header = trim( (string) $request->get_header( 'authorization' ) );
	if ( $header === '' ) {
		return '';
	}

	if ( preg_match( '/^Bearer\s+(.+)$/i', $header, $matches ) ) {
		return trim( (string) $matches[1] );
	}

	return '';
}

function crm_merchant_api_error_response( string $request_id, string $code, string $message, int $status ): WP_REST_Response {
	return new WP_REST_Response(
		[
			'request_id' => $request_id,
			'error'      => [
				'code'    => $code,
				'message' => $message,
			],
		],
		$status
	);
}

function crm_merchant_api_success_response( string $request_id, array $data, array $meta = [], int $status = 200 ): WP_REST_Response {
	return new WP_REST_Response(
		[
			'request_id' => $request_id,
			'data'       => $data,
			'meta'       => $meta,
		],
		$status
	);
}

function crm_merchant_api_apply_context_headers( WP_REST_Response $response, array $context ): WP_REST_Response {
	$response->header( 'X-Request-Id', (string) ( $context['request_id'] ?? '' ) );

	$rate_limit = is_array( $context['rate_limit'] ?? null ) ? $context['rate_limit'] : [];
	if ( ! empty( $rate_limit ) ) {
		$response = crm_merchant_api_rate_limit_headers(
			$response,
			(int) ( $rate_limit['limit'] ?? 0 ),
			(int) ( $rate_limit['remaining'] ?? 0 ),
			(int) ( $rate_limit['retry_after'] ?? 0 )
		);
	}

	return $response;
}

function crm_merchant_api_auth_context( WP_REST_Request $request, string $required_scope = '' ) {
	$request_id = crm_merchant_api_request_id();
	$token      = crm_merchant_api_extract_bearer_token( $request );

	if ( $token === '' ) {
		crm_log( 'merchant_api_auth_failed', [
			'category'   => 'security',
			'level'      => 'warning',
			'action'     => 'auth',
			'message'    => 'Merchant API запрос без Bearer token.',
			'is_success' => false,
			'context'    => [ 'request_id' => $request_id ],
		] );

		return crm_merchant_api_error_response( $request_id, 'unauthorized', 'Bearer token is required.', 401 );
	}

	$client = crm_merchant_api_get_client_by_token( $token );
	if ( ! $client || (string) $client->status !== 'active' ) {
		crm_log( 'merchant_api_auth_failed', [
			'category'   => 'security',
			'level'      => 'warning',
			'action'     => 'auth',
			'message'    => 'Merchant API token rejected.',
			'is_success' => false,
			'context'    => [
				'request_id'   => $request_id,
				'token_prefix' => crm_merchant_api_secret_prefix( $token ),
			],
		] );

		return crm_merchant_api_error_response( $request_id, 'unauthorized', 'Invalid or inactive token.', 401 );
	}

	$merchant = function_exists( 'crm_get_merchant_by_id' ) ? crm_get_merchant_by_id( (int) $client->merchant_id ) : null;
	if ( ! $merchant ) {
		crm_log( 'merchant_api_access_denied', [
			'category'   => 'security',
			'level'      => 'error',
			'action'     => 'auth',
			'message'    => 'Merchant API token points to missing merchant.',
			'is_success' => false,
			'context'    => [
				'request_id'  => $request_id,
				'client_id'   => (int) $client->id,
				'token_prefix'=> (string) $client->token_prefix,
			],
		] );

		return crm_merchant_api_error_response( $request_id, 'not_found', 'Merchant record was not found.', 404 );
	}

	$company_id = (int) ( $client->company_id ?? 0 );
	if ( $company_id <= 0 || $company_id !== (int) ( $merchant->company_id ?? 0 ) ) {
		crm_log( 'merchant_api_access_denied', [
			'category'   => 'security',
			'level'      => 'error',
			'action'     => 'auth',
			'message'    => 'Merchant API company scope mismatch.',
			'is_success' => false,
			'context'    => [
				'request_id'      => $request_id,
				'client_id'       => (int) $client->id,
				'client_company'  => $company_id,
				'merchant_company'=> (int) ( $merchant->company_id ?? 0 ),
			],
		] );

		return crm_merchant_api_error_response( $request_id, 'forbidden', 'Company scope mismatch.', 403 );
	}

	$company = function_exists( 'crm_get_company_by_id' ) ? crm_get_company_by_id( $company_id ) : null;
	if ( ! $company || (string) ( $company->status ?? '' ) !== 'active' ) {
		crm_log( 'merchant_api_access_denied', [
			'category'   => 'security',
			'level'      => 'warning',
			'action'     => 'auth',
			'message'    => 'Merchant API company is inactive.',
			'is_success' => false,
			'context'    => [
				'request_id'   => $request_id,
				'client_id'    => (int) $client->id,
				'company_id'   => $company_id,
				'token_prefix' => (string) $client->token_prefix,
			],
		] );

		return crm_merchant_api_error_response( $request_id, 'company_inactive', 'Company is not active.', 403 );
	}

	$merchant_status = sanitize_key( (string) ( $merchant->status ?? '' ) );
	if ( $merchant_status === CRM_MERCHANT_STATUS_BLOCKED ) {
		crm_log( 'merchant_api_access_denied', [
			'category'   => 'security',
			'level'      => 'warning',
			'action'     => 'auth',
			'message'    => 'Merchant API merchant is blocked.',
			'is_success' => false,
			'context'    => [
				'request_id'   => $request_id,
				'client_id'    => (int) $client->id,
				'merchant_id'  => (int) $merchant->id,
				'token_prefix' => (string) $client->token_prefix,
			],
		] );

		return crm_merchant_api_error_response( $request_id, 'merchant_blocked', 'Merchant is blocked.', 403 );
	}
	if ( $merchant_status === CRM_MERCHANT_STATUS_PENDING ) {
		crm_log( 'merchant_api_access_denied', [
			'category'   => 'security',
			'level'      => 'warning',
			'action'     => 'auth',
			'message'    => 'Merchant API merchant is pending.',
			'is_success' => false,
			'context'    => [
				'request_id'   => $request_id,
				'client_id'    => (int) $client->id,
				'merchant_id'  => (int) $merchant->id,
				'token_prefix' => (string) $client->token_prefix,
			],
		] );

		return crm_merchant_api_error_response( $request_id, 'merchant_pending', 'Merchant is pending activation.', 403 );
	}
	if ( $merchant_status === CRM_MERCHANT_STATUS_ARCHIVED ) {
		crm_log( 'merchant_api_access_denied', [
			'category'   => 'security',
			'level'      => 'warning',
			'action'     => 'auth',
			'message'    => 'Merchant API merchant is archived.',
			'is_success' => false,
			'context'    => [
				'request_id'   => $request_id,
				'client_id'    => (int) $client->id,
				'merchant_id'  => (int) $merchant->id,
				'token_prefix' => (string) $client->token_prefix,
			],
		] );

		return crm_merchant_api_error_response( $request_id, 'forbidden', 'Merchant is archived.', 403 );
	}

	$scopes = crm_merchant_api_normalize_scopes( $client->scopes_json ?? '' );
	if ( $required_scope !== '' && ! in_array( $required_scope, $scopes, true ) ) {
		crm_log( 'merchant_api_access_denied', [
			'category'   => 'security',
			'level'      => 'warning',
			'action'     => 'auth',
			'message'    => 'Merchant API scope denied.',
			'is_success' => false,
			'context'    => [
				'request_id'     => $request_id,
				'client_id'      => (int) $client->id,
				'required_scope' => $required_scope,
				'scopes'         => $scopes,
			],
		] );

		return crm_merchant_api_error_response( $request_id, 'forbidden', 'Scope is not allowed for this token.', 403 );
	}

	$rate_limit = crm_merchant_api_enforce_rate_limit( $request, [
		'request_id' => $request_id,
		'client'     => $client,
		'merchant'   => $merchant,
		'company'    => $company,
	], $required_scope );
	if ( $rate_limit instanceof WP_REST_Response ) {
		return $rate_limit;
	}

	crm_merchant_api_touch_client_usage( (int) $client->id );

	return [
		'request_id' => $request_id,
		'client'     => $client,
		'merchant'   => $merchant,
		'company'    => $company,
		'scopes'     => $scopes,
		'rate_limit' => is_array( $rate_limit ) ? $rate_limit : [],
	];
}

function crm_merchant_api_currency_scale( string $currency_code ): int {
	$currency_code = strtoupper( trim( $currency_code ) );

	if ( in_array( $currency_code, [ 'RUB', 'THB' ], true ) ) {
		return 2;
	}

	return 8;
}

function crm_merchant_api_format_decimal( $value, int $scale ): ?string {
	if ( $value === null || $value === '' ) {
		return null;
	}

	return number_format( (float) $value, max( 0, $scale ), '.', '' );
}

function crm_merchant_api_money( $value, string $currency_code ): ?array {
	$currency_code = strtoupper( trim( $currency_code ) );
	if ( $currency_code === '' ) {
		return null;
	}

	$formatted = crm_merchant_api_format_decimal( $value, crm_merchant_api_currency_scale( $currency_code ) );
	if ( $formatted === null ) {
		return null;
	}

	return [
		'value'         => $formatted,
		'currency_code' => $currency_code,
	];
}

function crm_merchant_api_format_rate( $value, int $scale = 4 ): ?string {
	return crm_merchant_api_format_decimal( $value, $scale );
}

function crm_merchant_api_safe_json_decode( $value ): array {
	if ( ! is_string( $value ) || trim( $value ) === '' ) {
		return [];
	}

	$decoded = json_decode( $value, true );
	return is_array( $decoded ) ? $decoded : [];
}

function crm_merchant_api_pair_definition( string $direction_code ): array {
	$pair = function_exists( 'crm_root_get_pair_definition' ) ? crm_root_get_pair_definition( $direction_code ) : null;
	return is_array( $pair ) ? $pair : [];
}

function crm_merchant_api_normalize_direction_code( string $direction_code ): string {
	if ( function_exists( 'crm_merchant_normalize_invoice_direction_code' ) ) {
		return crm_merchant_normalize_invoice_direction_code( $direction_code );
	}

	return strtoupper( trim( $direction_code ) );
}

function crm_merchant_api_parse_status_filter( $raw_statuses ): array {
	if ( is_string( $raw_statuses ) ) {
		$raw_statuses = preg_split( '/\s*,\s*/', $raw_statuses, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
	}

	if ( ! is_array( $raw_statuses ) ) {
		return [];
	}

	$allowed = [
		'created',
		'pending',
		'paid',
		'declined',
		'cancelled',
		'expired',
		'error',
		'untracked',
	];
	$result = [];

	foreach ( $raw_statuses as $status_code ) {
		$status_code = sanitize_key( (string) $status_code );
		if ( $status_code !== '' && in_array( $status_code, $allowed, true ) ) {
			$result[ $status_code ] = $status_code;
		}
	}

	return array_values( $result );
}

function crm_merchant_api_normalize_pagination( WP_REST_Request $request ): array {
	$page = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = (int) $request->get_param( 'per_page' );

	if ( $per_page <= 0 ) {
		$per_page = 25;
	}

	$per_page = min( 100, max( 1, $per_page ) );

	return [
		'page'     => $page,
		'per_page' => $per_page,
		'offset'   => ( $page - 1 ) * $per_page,
	];
}

function crm_merchant_api_terminal_statuses(): array {
	return [ 'paid', 'declined', 'cancelled', 'expired', 'error' ];
}

function crm_merchant_api_order_is_active( object $order ): bool {
	$status_code = sanitize_key( (string) ( $order->status_code ?? '' ) );

	if ( in_array( $status_code, crm_merchant_api_terminal_statuses(), true ) ) {
		return false;
	}

	if ( ! empty( $order->expires_at ) ) {
		try {
			$now     = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			$expires = new DateTimeImmutable( (string) $order->expires_at, new DateTimeZone( 'UTC' ) );
			if ( $now > $expires ) {
				return false;
			}
		} catch ( Exception $e ) {
			return ! in_array( $status_code, [ 'expired', 'cancelled', 'declined', 'error', 'paid' ], true );
		}
	}

	return in_array( $status_code, [ 'created', 'pending' ], true );
}

function crm_merchant_api_build_payment_link( object $order ): ?string {
	$link = trim( (string) ( $order->payment_link ?? '' ) );
	return $link !== '' ? $link : null;
}

function crm_merchant_api_order_summary( object $order, bool $include_sensitive_payment_data = false ): array {
	$meta            = crm_merchant_api_safe_json_decode( $order->merchant_meta_json ?? '' );
	$request_amount  = null;
	$payable_amount  = function_exists( 'crm_merchant_order_payable_amount' )
		? crm_merchant_order_payable_amount( $order )
		: (float) ( $order->amount_asset_value ?? 0 );
	$platform_fee    = function_exists( 'crm_merchant_order_platform_fee_amount' )
		? crm_merchant_order_platform_fee_amount( $order )
		: 0.0;
	$status_code     = sanitize_key( (string) ( $order->status_code ?? '' ) );
	$amount_code     = strtoupper( trim( (string) ( $order->amount_asset_code ?? 'USDT' ) ) );
	$payment_code    = strtoupper( trim( (string) ( $order->payment_currency_code ?? 'RUB' ) ) );
	$external_order  = trim( (string) ( $order->local_order_ref ?? '' ) );
	$payment_link    = $include_sensitive_payment_data || crm_merchant_api_order_is_active( $order )
		? crm_merchant_api_build_payment_link( $order )
		: null;

	if ( $order->merchant_requested_rub_value !== null && $order->merchant_requested_rub_value !== '' ) {
		$request_amount = crm_merchant_api_money( $order->merchant_requested_rub_value, $payment_code );
	} else {
		$request_amount = crm_merchant_api_money( $order->amount_asset_value, $amount_code );
	}

	$provider_mode = isset( $meta['provider_payload_mode'] )
		? (string) $meta['provider_payload_mode']
		: ( isset( $meta['kanyon_payload_mode'] ) ? (string) $meta['kanyon_payload_mode'] : null );

	return [
		'id'                     => (int) $order->id,
		'merchant_order_id'      => (string) ( $order->merchant_order_id ?? '' ),
		'external_order_id'      => $external_order !== '' ? $external_order : null,
		'provider_order_id'      => (string) ( $order->provider_order_id ?? '' ),
		'provider_external_id'   => (string) ( $order->provider_external_order_id ?? '' ),
		'provider_code'          => (string) ( $order->provider_code ?? '' ),
		'status'                 => $status_code,
		'provider_status'        => (string) ( $order->provider_status_code ?? '' ),
		'requested_amount'       => $request_amount,
		'payment_amount'         => crm_merchant_api_money( $order->payment_amount_value, $payment_code ),
		'payout_amount'          => crm_merchant_api_money( $payable_amount, $amount_code ),
		'platform_fee_amount'    => crm_merchant_api_money( $platform_fee, $amount_code ),
		'payment_url'            => $payment_link,
		'payment_payload'        => $payment_link,
		'qrc_id'                 => ! empty( $order->qrc_id ) ? (string) $order->qrc_id : null,
		'is_active'              => crm_merchant_api_order_is_active( $order ),
		'is_paid'                => $status_code === 'paid',
		'is_expired'             => $status_code === 'expired',
		'expires_at'             => crm_merchant_api_iso8601( $order->expires_at ?? '' ),
		'created_at'             => crm_merchant_api_iso8601( $order->created_at ?? '' ),
		'updated_at'             => crm_merchant_api_iso8601( $order->updated_at ?? '' ),
		'paid_at'                => crm_merchant_api_iso8601( $order->paid_at ?? '' ),
		'merchant_rate'          => isset( $meta['merchant_rate'] ) ? crm_merchant_api_format_rate( $meta['merchant_rate'], 4 ) : null,
		'payment_purpose'        => isset( $meta['payment_purpose'] ) ? (string) $meta['payment_purpose'] : null,
		'provider_mode'          => $provider_mode,
	];
}

function crm_merchant_api_order_detail( object $order ): array {
	$summary = crm_merchant_api_order_summary( $order, true );

	$summary['status_timeline'] = [
		'created_at'        => crm_merchant_api_iso8601( $order->created_at ?? '' ),
		'first_callback_at' => crm_merchant_api_iso8601( $order->first_callback_at ?? '' ),
		'last_callback_at'  => crm_merchant_api_iso8601( $order->last_callback_at ?? '' ),
		'last_checked_at'   => crm_merchant_api_iso8601( $order->last_checked_at ?? '' ),
		'next_check_at'     => crm_merchant_api_iso8601( $order->next_check_at ?? '' ),
		'paid_at'           => crm_merchant_api_iso8601( $order->paid_at ?? '' ),
		'declined_at'       => crm_merchant_api_iso8601( $order->declined_at ?? '' ),
		'cancelled_at'      => crm_merchant_api_iso8601( $order->cancelled_at ?? '' ),
		'expired_at'        => crm_merchant_api_iso8601( $order->expired_at ?? '' ),
	];
	$summary['provider_data'] = [
		'provider_public_link'           => ! empty( $order->provider_public_link ) ? (string) $order->provider_public_link : null,
		'provider_requires_verification' => ! empty( $order->provider_requires_verification ),
		'status_reason'                  => ! empty( $order->status_reason ) ? (string) $order->status_reason : null,
		'callback_url'                   => ! empty( $order->callback_url ) ? (string) $order->callback_url : null,
	];

	return $summary;
}

function crm_merchant_api_get_order( int $company_id, int $merchant_id, int $order_id ): ?object {
	global $wpdb;

	if ( $company_id <= 0 || $merchant_id <= 0 || $order_id <= 0 ) {
		return null;
	}

	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT *
			 FROM crm_fintech_payment_orders
			 WHERE id = %d
			   AND company_id = %d
			   AND merchant_id = %d
			   AND created_for_type = 'merchant'
			 LIMIT 1",
			$order_id,
			$company_id,
			$merchant_id
		)
	) ?: null;
}

function crm_merchant_api_get_order_by_external_order_id( int $company_id, int $merchant_id, string $external_order_id ): ?object {
	global $wpdb;

	$external_order_id = trim( $external_order_id );
	if ( $company_id <= 0 || $merchant_id <= 0 || $external_order_id === '' ) {
		return null;
	}

	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT *
			 FROM crm_fintech_payment_orders
			 WHERE company_id = %d
			   AND merchant_id = %d
			   AND created_for_type = 'merchant'
			   AND local_order_ref = %s
			 LIMIT 1",
			$company_id,
			$merchant_id,
			$external_order_id
		)
	) ?: null;
}

function crm_merchant_api_list_orders( int $company_id, int $merchant_id, array $statuses, int $page, int $per_page ): array {
	global $wpdb;

	if ( $company_id <= 0 || $merchant_id <= 0 ) {
		return [
			'items'      => [],
			'total'      => 0,
			'page'       => $page,
			'per_page'   => $per_page,
			'total_pages'=> 1,
		];
	}

	$where   = 'WHERE company_id = %d AND merchant_id = %d AND created_for_type = %s';
	$params  = [ $company_id, $merchant_id, 'merchant' ];

	if ( ! empty( $statuses ) ) {
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$where       .= " AND status_code IN ($placeholders)";
		$params       = array_merge( $params, $statuses );
	}

	$total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*)
			 FROM crm_fintech_payment_orders
			 {$where}",
			$params
		)
	);

	$total_pages = max( 1, (int) ceil( $total / $per_page ) );
	$page        = min( max( 1, $page ), $total_pages );
	$offset      = ( $page - 1 ) * $per_page;
	$list_params = array_merge( $params, [ $per_page, $offset ] );

	$items = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT *
			 FROM crm_fintech_payment_orders
			 {$where}
			 ORDER BY id DESC
			 LIMIT %d OFFSET %d",
			$list_params
		)
	) ?: [];

	return [
		'items'       => $items,
		'total'       => $total,
		'page'        => $page,
		'per_page'    => $per_page,
		'total_pages' => $total_pages,
	];
}

function crm_merchant_api_build_invoice_idempotency_key( int $company_id, int $merchant_id, string $external_order_id ): string {
	return sprintf(
		'merchant_api:%d:%d:%s',
		$company_id,
		$merchant_id,
		sha1( $external_order_id )
	);
}

function crm_merchant_api_requested_amount_from_order( object $order, string $requested_currency_code ): ?string {
	$requested_currency_code = strtoupper( trim( $requested_currency_code ) );

	if ( $requested_currency_code === 'USDT' ) {
		return crm_merchant_api_format_decimal( $order->amount_asset_value ?? null, 8 );
	}

	if ( $requested_currency_code === 'RUB' ) {
		$value = $order->merchant_requested_rub_value !== null && $order->merchant_requested_rub_value !== ''
			? $order->merchant_requested_rub_value
			: ( $order->payment_amount_value ?? null );

		return crm_merchant_api_format_decimal( $value, 2 );
	}

	return null;
}

function crm_merchant_api_order_matches_invoice_request(
	object $order,
	array $mode_summary,
	string $direction_code,
	string $requested_amount_value,
	string $description
): bool {
	if ( $direction_code !== 'RUB_USDT' ) {
		return false;
	}

	$requested_currency_code = strtoupper( trim( (string) ( $mode_summary['requested_amount_currency'] ?? '' ) ) );
	$existing_amount_value   = crm_merchant_api_requested_amount_from_order( $order, $requested_currency_code );
	if ( $existing_amount_value === null || $existing_amount_value !== $requested_amount_value ) {
		return false;
	}

	$description = function_exists( 'crm_fintech_normalize_payment_purpose' )
		? crm_fintech_normalize_payment_purpose( $description )
		: trim( $description );
	if ( $description === '' ) {
		return true;
	}

	$merchant_meta        = crm_merchant_api_safe_json_decode( $order->merchant_meta_json ?? '' );
	$existing_description = function_exists( 'crm_fintech_normalize_payment_purpose' )
		? crm_fintech_normalize_payment_purpose( $merchant_meta['payment_purpose'] ?? '' )
		: trim( (string) ( $merchant_meta['payment_purpose'] ?? '' ) );

	return $existing_description === $description;
}

function crm_merchant_api_write_error_response( string $request_id, array $result ): WP_REST_Response {
	$error_code = sanitize_key( (string) ( $result['error_code'] ?? 'internal_error' ) );
	$message    = trim( (string) ( $result['error'] ?? 'Invoice creation failed.' ) );

	if ( $message === '' ) {
		$message = 'Invoice creation failed.';
	}

	$status = 422;
	if ( $error_code === 'not_found' ) {
		$status = 404;
	} elseif ( $error_code === 'conflict' ) {
		$status = 409;
	} elseif ( $error_code === 'provider_error' ) {
		$status = 502;
	} elseif ( $error_code === 'provider_unavailable' ) {
		$status = 503;
	} elseif ( $error_code === 'internal_error' ) {
		$status = 500;
	}

	return crm_merchant_api_error_response( $request_id, $error_code !== '' ? $error_code : 'internal_error', $message, $status );
}

function crm_merchant_api_payout_explorer_url( string $network, string $tx_hash ): ?string {
	$network = strtoupper( trim( $network ) );
	$tx_hash = trim( $tx_hash );

	if ( $tx_hash === '' ) {
		return null;
	}

	if ( $network === 'TRC20' ) {
		return 'https://tronscan.org/#/transaction/' . rawurlencode( $tx_hash );
	}

	return null;
}

function crm_merchant_api_receipt_url( ?string $filename ): ?string {
	$filename = trim( (string) $filename );
	if ( $filename === '' ) {
		return null;
	}

	if ( function_exists( '_me_merchant_payouts_receipt_url' ) ) {
		$url = _me_merchant_payouts_receipt_url( $filename );
		return $url !== '' ? $url : null;
	}

	$safe = preg_replace( '/[^A-Za-z0-9_\-.]/', '', $filename );
	if ( ! $safe ) {
		return null;
	}

	return get_template_directory_uri() . '/uploadbotfiles/merchant-payout-receipts/' . $safe;
}

function crm_merchant_api_format_payout( object $payout ): array {
	$network = strtoupper( trim( (string) ( $payout->network ?? '' ) ) );
	$tx_hash = trim( (string) ( $payout->tx_hash ?? '' ) );
	$status  = function_exists( 'crm_merchant_normalize_payout_status' )
		? crm_merchant_normalize_payout_status( (string) ( $payout->status_code ?? '' ) )
		: trim( (string) ( $payout->status_code ?? 'paid' ) );

	return [
		'id'             => (int) $payout->id,
		'amount'         => crm_merchant_api_money( $payout->amount ?? null, (string) ( $payout->currency_code ?? 'USDT' ) ),
		'network'        => $network !== '' ? $network : null,
		'wallet_address' => ! empty( $payout->wallet_address ) ? (string) $payout->wallet_address : null,
		'tx_hash'        => $tx_hash !== '' ? $tx_hash : null,
		'explorer_url'   => crm_merchant_api_payout_explorer_url( $network, $tx_hash ),
		'comment'        => ! empty( $payout->notes ) ? (string) $payout->notes : null,
		'receipt_url'    => crm_merchant_api_receipt_url( $payout->receipt_filename ?? '' ),
		'status'         => [
			'code'  => $status,
			'label' => function_exists( 'crm_merchant_payout_status_label' ) ? crm_merchant_payout_status_label( $status ) : $status,
		],
		'paid_at'        => crm_merchant_api_iso8601( $payout->paid_at ?? '' ),
		'confirmed_at'   => crm_merchant_api_iso8601( $payout->confirmed_at ?? '' ),
		'cancelled_at'   => crm_merchant_api_iso8601( $payout->cancelled_at ?? '' ),
		'cancellation'   => ! empty( $payout->cancellation_reason_code ) || ! empty( $payout->cancellation_comment ) ? [
			'reason_code' => ! empty( $payout->cancellation_reason_code ) ? (string) $payout->cancellation_reason_code : null,
			'reason_label'=> ! empty( $payout->cancellation_reason_code ) && function_exists( 'crm_merchant_payout_cancellation_reason_label' )
				? crm_merchant_payout_cancellation_reason_label( (string) $payout->cancellation_reason_code )
				: null,
			'comment'     => ! empty( $payout->cancellation_comment ) ? (string) $payout->cancellation_comment : null,
		] : null,
		'created_at'     => crm_merchant_api_iso8601( $payout->created_at ?? '' ),
		'updated_at'     => crm_merchant_api_iso8601( $payout->updated_at ?? '' ),
	];
}

function crm_merchant_api_get_payout( int $company_id, int $merchant_id, int $payout_id ): ?object {
	global $wpdb;

	if ( $company_id <= 0 || $merchant_id <= 0 || $payout_id <= 0 ) {
		return null;
	}

	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT *
			 FROM crm_merchant_payouts
			 WHERE id = %d
			   AND company_id = %d
			   AND merchant_id = %d
			 LIMIT 1",
			$payout_id,
			$company_id,
			$merchant_id
		)
	) ?: null;
}

function crm_merchant_api_list_payouts( int $company_id, int $merchant_id, int $page, int $per_page ): array {
	global $wpdb;

	if ( $company_id <= 0 || $merchant_id <= 0 ) {
		return [
			'items'       => [],
			'total'       => 0,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => 1,
		];
	}

	$total = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM crm_merchant_payouts WHERE company_id = %d AND merchant_id = %d',
			$company_id,
			$merchant_id
		)
	);

	$total_pages = max( 1, (int) ceil( $total / $per_page ) );
	$page        = min( max( 1, $page ), $total_pages );
	$offset      = ( $page - 1 ) * $per_page;

	$items = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT *
			 FROM crm_merchant_payouts
			 WHERE company_id = %d
			   AND merchant_id = %d
			 ORDER BY id DESC
			 LIMIT %d OFFSET %d",
			$company_id,
			$merchant_id,
			$per_page,
			$offset
		)
	) ?: [];

	return [
		'items'       => $items,
		'total'       => $total,
		'page'        => $page,
		'per_page'    => $per_page,
		'total_pages' => $total_pages,
	];
}

function crm_merchant_api_balance_snapshot( int $company_id, int $merchant_id ): array {
	global $wpdb;

	$balances = function_exists( 'crm_get_merchant_balance_summary_map' )
		? crm_get_merchant_balance_summary_map( [ $merchant_id ] )
		: [];
	$summary  = $balances[ $merchant_id ] ?? [
		'main_balance'     => 0.0,
		'bonus_balance'    => 0.0,
		'referral_balance' => 0.0,
		'total_balance'    => 0.0,
	];
	$updated_at = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT MAX(created_at)
			 FROM crm_merchant_wallet_ledger
			 WHERE company_id = %d
			   AND merchant_id = %d",
			$company_id,
			$merchant_id
		)
	);

	return [
		'available_balance' => crm_merchant_api_money( $summary['main_balance'] ?? 0, 'USDT' ),
		'payable_balance'   => crm_merchant_api_money( $summary['main_balance'] ?? 0, 'USDT' ),
		'bonus_balance'     => crm_merchant_api_money( $summary['bonus_balance'] ?? 0, 'USDT' ),
		'referral_balance'  => crm_merchant_api_money( $summary['referral_balance'] ?? 0, 'USDT' ),
		'total_balance'     => crm_merchant_api_money( $summary['total_balance'] ?? 0, 'USDT' ),
		'updated_at'        => crm_merchant_api_iso8601( is_string( $updated_at ) ? $updated_at : '' ),
	];
}

function crm_merchant_api_rates_snapshot( int $company_id, $merchant = null ): array {
	$mode_summary = crm_merchant_api_get_company_mode_summary( $company_id, $merchant );
	$enabled      = array_values( array_map( 'strval', $mode_summary['enabled_directions'] ?? [] ) );
	$snapshot     = function_exists( 'crm_merchant_tg_rates_snapshot' )
		? crm_merchant_tg_rates_snapshot( $company_id, false, false )
		: [];
	$rapira       = null;
	$items        = [];

	foreach ( $enabled as $direction_code ) {
		$pair = crm_merchant_api_pair_definition( $direction_code );
		$rate_value = null;
		$checked_at = null;
		$source_code = '';
		$invoice_supported = false;
		$payment_currency_code = null;
		$settlement_currency_code = null;
		$requested_amount_currency = null;
		$provider_mode = null;

		if ( $direction_code === 'RUB_THB' && isset( $snapshot['rub_thb']['rate'] ) && $snapshot['rub_thb']['rate'] !== null ) {
			$rate_value = (float) $snapshot['rub_thb']['rate'];
			$checked_at = (string) ( $snapshot['rub_thb']['updated_at'] ?? '' );
			$source_code = 'ex24';
		} elseif ( $direction_code === 'USDT_THB' && isset( $snapshot['usdt_thb']['rate'] ) && $snapshot['usdt_thb']['rate'] !== null ) {
			$rate_value = (float) $snapshot['usdt_thb']['rate'];
			$checked_at = (string) ( $snapshot['usdt_thb']['updated_at'] ?? '' );
			$source_code = (string) ( $snapshot['usdt_thb']['market_source'] ?? 'bitkub' );
		} elseif ( $direction_code === 'RUB_USDT' ) {
			if ( isset( $snapshot['rub_usdt']['rate'] ) && $snapshot['rub_usdt']['rate'] !== null ) {
				$rate_value = (float) $snapshot['rub_usdt']['rate'];
				$checked_at = (string) ( $snapshot['rub_usdt']['updated_at'] ?? '' );
				$source_code = (string) ( $snapshot['rub_usdt']['source'] ?? 'kanyon' );
			} else {
				if ( $rapira === null && function_exists( 'rates_get_rapira_cached' ) ) {
					$rapira = rates_get_rapira_cached();
				}
				if ( is_array( $rapira ) && ! empty( $rapira['ok'] ) && isset( $rapira['ask'] ) && (float) $rapira['ask'] > 0 ) {
					$rate_value = (float) $rapira['ask'];
					$checked_at = current_time( 'mysql', true );
					$source_code = 'rapira';
				}
			}
		}

		if ( $direction_code === 'RUB_USDT' && ! empty( $mode_summary['provider_mode'] ) ) {
			$invoice_supported = true;
			$payment_currency_code = (string) ( $mode_summary['payment_currency_code'] ?? '' );
			$settlement_currency_code = (string) ( $mode_summary['settlement_currency_code'] ?? '' );
			$requested_amount_currency = (string) ( $mode_summary['requested_amount_currency'] ?? '' );
			$provider_mode = (string) ( $mode_summary['provider_mode'] ?? '' );
		}

		$items[] = [
			'direction_code'            => $direction_code,
			'title'                     => (string) ( $pair['title'] ?? $direction_code ),
			'label'                     => (string) ( $pair['label'] ?? $direction_code ),
			'payment_currency_code'     => $payment_currency_code !== '' ? $payment_currency_code : null,
			'settlement_currency_code'  => $settlement_currency_code !== '' ? $settlement_currency_code : null,
			'requested_amount_currency' => $requested_amount_currency !== '' ? $requested_amount_currency : null,
			'provider_mode'             => $provider_mode !== '' ? $provider_mode : null,
			'invoice_create_supported'  => $invoice_supported,
			'display_rate'              => crm_merchant_api_format_rate( $rate_value, 4 ),
			'checked_at'                => crm_merchant_api_iso8601( $checked_at ),
			'source_code'               => $source_code !== '' ? $source_code : null,
			'is_supported'              => $rate_value !== null,
			'disclaimer'                => 'Informational rate. Final payment amount is fixed at invoice creation time.',
		];
	}

	return [
		'mode_summary' => $mode_summary,
		'items'        => $items,
	];
}

function crm_merchant_api_log_write_event(
	string $event_code,
	array $context,
	string $message,
	string $level = 'info',
	array $extra_context = []
): void {
	$merchant = $context['merchant'];
	$company  = $context['company'];
	$client   = $context['client'];

	crm_log( $event_code, [
		'category'    => 'integrations',
		'level'       => $level,
		'action'      => 'write',
		'message'     => $message,
		'target_type' => 'merchant',
		'target_id'   => (int) $merchant->id,
		'org_id'      => (int) $company->id,
		'context'     => array_merge(
			[
				'request_id'   => $context['request_id'],
				'client_id'    => (int) $client->id,
				'client_name'  => (string) $client->client_name,
				'token_prefix' => (string) $client->token_prefix,
			],
			$extra_context
		),
	] );
}

function crm_merchant_api_log_read_event( string $event_code, array $context, string $message ): void {
	$merchant = $context['merchant'];
	$company  = $context['company'];
	$client   = $context['client'];

	crm_log( $event_code, [
		'category'    => 'integrations',
		'level'       => 'info',
		'action'      => 'read',
		'message'     => $message,
		'target_type' => 'merchant',
		'target_id'   => (int) $merchant->id,
		'org_id'      => (int) $company->id,
		'context'     => [
			'request_id'   => $context['request_id'],
			'client_id'    => (int) $client->id,
			'client_name'  => (string) $client->client_name,
			'token_prefix' => (string) $client->token_prefix,
		],
	] );
}

function crm_merchant_api_rest_create_invoice( WP_REST_Request $request ): WP_REST_Response {
	$context = crm_merchant_api_auth_context( $request, 'orders:write' );
	if ( $context instanceof WP_REST_Response ) {
		return $context;
	}

	$payload = $request->get_json_params();
	if ( ! is_array( $payload ) ) {
		$payload = [];
	}

	$direction_code = crm_merchant_api_normalize_direction_code( (string) ( $payload['direction_code'] ?? '' ) );
	if ( $direction_code === '' ) {
		return crm_merchant_api_error_response( $context['request_id'], 'validation_failed', 'direction_code is required.', 422 );
	}

	$mode_summary = crm_merchant_api_get_company_mode_summary( (int) $context['company']->id, $context['merchant'] );
	$enabled_directions = array_values( array_map( 'strval', $mode_summary['enabled_directions'] ?? [] ) );

	if ( ! in_array( $direction_code, $enabled_directions, true ) ) {
		return crm_merchant_api_error_response( $context['request_id'], 'contour_disabled', 'Direction is not enabled for this merchant.', 403 );
	}

	if ( $direction_code !== 'RUB_USDT' ) {
		return crm_merchant_api_error_response( $context['request_id'], 'contour_not_supported', 'Invoice creation is not implemented for this direction yet.', 422 );
	}

	$provider_mode = (string) ( $mode_summary['provider_mode'] ?? '' );
	$requested_currency_code = strtoupper( trim( (string) ( $mode_summary['requested_amount_currency'] ?? '' ) ) );
	if ( ! in_array( $provider_mode, [ 'orderAmount', 'paymentAmount' ], true ) || ! in_array( $requested_currency_code, [ 'USDT', 'RUB' ], true ) ) {
		return crm_merchant_api_error_response( $context['request_id'], 'contour_not_supported', 'Invoice creation is not supported for the current company contour.', 422 );
	}

	$requested_amount = is_array( $payload['requested_amount'] ?? null ) ? $payload['requested_amount'] : [];
	$amount_currency_code = strtoupper( trim( (string) ( $requested_amount['currency_code'] ?? '' ) ) );
	if ( $amount_currency_code === '' ) {
		return crm_merchant_api_error_response( $context['request_id'], 'validation_failed', 'requested_amount.currency_code is required.', 422 );
	}
	if ( $amount_currency_code !== $requested_currency_code ) {
		return crm_merchant_api_error_response(
			$context['request_id'],
			'validation_failed',
			sprintf( 'requested_amount.currency_code must be %s for the current company contour.', $requested_currency_code ),
			422
		);
	}

	$requested_amount_raw = $requested_amount['value'] ?? null;
	$requested_amount_float = $requested_currency_code === 'USDT'
		? ( function_exists( 'crm_merchant_normalize_usdt_amount' ) ? crm_merchant_normalize_usdt_amount( $requested_amount_raw ) : round( max( 0, (float) $requested_amount_raw ), 8 ) )
		: ( function_exists( 'crm_merchant_normalize_rub_amount' ) ? crm_merchant_normalize_rub_amount( $requested_amount_raw ) : round( max( 0, (float) $requested_amount_raw ), 2 ) );
	$requested_amount_value = crm_merchant_api_format_decimal( $requested_amount_float, crm_merchant_api_currency_scale( $requested_currency_code ) );
	if ( $requested_amount_value === null || (float) $requested_amount_value <= 0 ) {
		return crm_merchant_api_error_response( $context['request_id'], 'validation_failed', 'requested_amount.value must be greater than zero.', 422 );
	}

	$external_order_id = sanitize_text_field( wp_unslash( (string) ( $payload['external_order_id'] ?? '' ) ) );
	if ( $external_order_id === '' ) {
		return crm_merchant_api_error_response( $context['request_id'], 'validation_failed', 'external_order_id is required.', 422 );
	}
	if ( strlen( $external_order_id ) > 120 ) {
		return crm_merchant_api_error_response( $context['request_id'], 'validation_failed', 'external_order_id is too long.', 422 );
	}

	$description = '';
	if ( function_exists( 'crm_fintech_normalize_payment_purpose' ) ) {
		$description = crm_fintech_normalize_payment_purpose( $payload['description'] ?? ( $payload['payment_purpose'] ?? '' ) );
	} else {
		$description = trim( sanitize_text_field( wp_unslash( (string) ( $payload['description'] ?? ( $payload['payment_purpose'] ?? '' ) ) ) ) );
	}

	$existing_order = crm_merchant_api_get_order_by_external_order_id(
		(int) $context['company']->id,
		(int) $context['merchant']->id,
		$external_order_id
	);

	if ( $existing_order ) {
		if ( ! crm_merchant_api_order_matches_invoice_request( $existing_order, $mode_summary, $direction_code, $requested_amount_value, $description ) ) {
			crm_merchant_api_log_write_event(
				'merchant_api_invoice_create_conflict',
				$context,
				'Merchant API invoice create conflict on external_order_id.',
				'warning',
				[
					'direction_code'     => $direction_code,
					'external_order_id'  => $external_order_id,
					'provider_mode'      => $provider_mode,
					'requested_amount'   => $requested_amount_value,
					'requested_currency' => $requested_currency_code,
					'order_id'           => (int) $existing_order->id,
				]
			);

			return crm_merchant_api_error_response( $context['request_id'], 'conflict', 'external_order_id already exists with different invoice parameters.', 409 );
		}

		crm_merchant_api_log_write_event(
			'merchant_api_invoice_create_replayed',
			$context,
			'Merchant API invoice create replay returned existing order.',
			'info',
			[
				'direction_code'     => $direction_code,
				'external_order_id'  => $external_order_id,
				'provider_mode'      => $provider_mode,
				'requested_amount'   => $requested_amount_value,
				'requested_currency' => $requested_currency_code,
				'order_id'           => (int) $existing_order->id,
			]
		);

		return crm_merchant_api_apply_context_headers(
			crm_merchant_api_success_response(
			$context['request_id'],
			[
				'merchant_id'        => (int) $context['merchant']->id,
				'company_id'         => (int) $context['company']->id,
				'direction_code'     => $direction_code,
				'provider_mode'      => $provider_mode,
				'idempotent_replay'  => true,
				'invoice'            => crm_merchant_api_order_detail( $existing_order ),
			],
			[],
			200
			),
			$context
		);
	}

	$idempotency_key = crm_merchant_api_build_invoice_idempotency_key(
		(int) $context['company']->id,
		(int) $context['merchant']->id,
		$external_order_id
	);

	crm_merchant_api_log_write_event(
		'merchant_api_invoice_create_started',
		$context,
		'Merchant API invoice create started.',
		'info',
		[
			'direction_code'     => $direction_code,
			'external_order_id'  => $external_order_id,
			'provider_mode'      => $provider_mode,
			'requested_amount'   => $requested_amount_value,
			'requested_currency' => $requested_currency_code,
		]
	);

	$create_args = [
		'source_channel'    => 'merchant_api',
		'external_order_id' => $external_order_id,
		'idempotency_key'   => $idempotency_key,
		'payment_purpose'   => $description,
	];

	$create = $provider_mode === 'paymentAmount'
		? crm_merchant_create_rub_invoice( (int) $context['merchant']->id, $requested_amount_float, $create_args )
		: crm_merchant_create_usdt_invoice( (int) $context['merchant']->id, $requested_amount_float, $create_args );

	if ( empty( $create['success'] ) ) {
		crm_merchant_api_log_write_event(
			'merchant_api_invoice_create_failed',
			$context,
			'Merchant API invoice create failed.',
			'error',
			[
				'direction_code'     => $direction_code,
				'external_order_id'  => $external_order_id,
				'provider_mode'      => $provider_mode,
				'requested_amount'   => $requested_amount_value,
				'requested_currency' => $requested_currency_code,
				'error_code'         => (string) ( $create['error_code'] ?? '' ),
				'error_message'      => (string) ( $create['error'] ?? '' ),
			]
		);

		return crm_merchant_api_apply_context_headers(
			crm_merchant_api_write_error_response( $context['request_id'], $create ),
			$context
		);
	}

	$order = crm_merchant_api_get_order(
		(int) $context['company']->id,
		(int) $context['merchant']->id,
		(int) ( $create['order_db_id'] ?? 0 )
	);
	if ( ! $order ) {
		return crm_merchant_api_apply_context_headers(
			crm_merchant_api_error_response( $context['request_id'], 'internal_error', 'Invoice was created but could not be reloaded from storage.', 500 ),
			$context
		);
	}

	crm_merchant_api_log_write_event(
		'merchant_api_invoice_created',
		$context,
		'Merchant API invoice created successfully.',
		'info',
		[
			'direction_code'     => $direction_code,
			'external_order_id'  => $external_order_id,
			'provider_mode'      => $provider_mode,
			'requested_amount'   => $requested_amount_value,
			'requested_currency' => $requested_currency_code,
			'order_id'           => (int) $order->id,
			'merchant_order_id'  => (string) ( $order->merchant_order_id ?? '' ),
		]
	);

	return crm_merchant_api_apply_context_headers(
		crm_merchant_api_success_response(
		$context['request_id'],
		[
			'merchant_id'       => (int) $context['merchant']->id,
			'company_id'        => (int) $context['company']->id,
			'direction_code'    => $direction_code,
			'provider_mode'     => $provider_mode,
			'idempotent_replay' => false,
			'invoice'           => crm_merchant_api_order_detail( $order ),
		],
		[],
		201
		),
		$context
	);
}

function crm_merchant_api_rest_me( WP_REST_Request $request ): WP_REST_Response {
	$context = crm_merchant_api_auth_context( $request, 'profile:read' );
	if ( $context instanceof WP_REST_Response ) {
		return $context;
	}

	$merchant = $context['merchant'];
	$company  = $context['company'];
	$client   = $context['client'];

	crm_log( 'merchant_api_profile_read', [
		'category'    => 'integrations',
		'level'       => 'info',
		'action'      => 'read',
		'message'     => 'Merchant API profile endpoint accessed.',
		'target_type' => 'merchant',
		'target_id'   => (int) $merchant->id,
		'org_id'      => (int) $company->id,
		'context'     => [
			'request_id'   => $context['request_id'],
			'client_id'    => (int) $client->id,
			'client_name'  => (string) $client->client_name,
			'token_prefix' => (string) $client->token_prefix,
		],
	] );

	return crm_merchant_api_apply_context_headers(
		crm_merchant_api_success_response(
		$context['request_id'],
		[
			'merchant' => [
				'id'                => (int) $merchant->id,
				'company_id'        => (int) $merchant->company_id,
				'name'              => (string) ( $merchant->name ?? '' ),
				'status'            => sanitize_key( (string) ( $merchant->status ?? '' ) ),
				'telegram_username' => (string) ( $merchant->telegram_username ?? '' ),
				'created_at'        => crm_merchant_api_iso8601( $merchant->created_at ?? '' ),
			],
			'company' => [
				'id'     => (int) $company->id,
				'code'   => (string) ( $company->code ?? '' ),
				'name'   => (string) ( $company->name ?? '' ),
				'status' => (string) ( $company->status ?? '' ),
			],
			'api_client' => [
				'id'           => (int) $client->id,
				'client_name'  => (string) $client->client_name,
				'status'       => (string) $client->status,
				'token_prefix' => (string) $client->token_prefix,
				'scopes'       => $context['scopes'],
			],
			'capabilities' => crm_merchant_api_get_company_mode_summary( (int) $company->id, $merchant ),
		]
		),
		$context
	);
}

function crm_merchant_api_rest_balances( WP_REST_Request $request ): WP_REST_Response {
	$context = crm_merchant_api_auth_context( $request, 'balances:read' );
	if ( $context instanceof WP_REST_Response ) {
		return $context;
	}

	crm_merchant_api_log_read_event( 'merchant_api_balances_read', $context, 'Merchant API balances endpoint accessed.' );

	return crm_merchant_api_apply_context_headers(
		crm_merchant_api_success_response(
		$context['request_id'],
		[
			'merchant_id' => (int) $context['merchant']->id,
			'company_id'  => (int) $context['company']->id,
			'balances'    => crm_merchant_api_balance_snapshot(
				(int) $context['company']->id,
				(int) $context['merchant']->id
			),
		]
		),
		$context
	);
}

function crm_merchant_api_rest_rates( WP_REST_Request $request ): WP_REST_Response {
	$context = crm_merchant_api_auth_context( $request, 'rates:read' );
	if ( $context instanceof WP_REST_Response ) {
		return $context;
	}

	crm_merchant_api_log_read_event( 'merchant_api_rates_read', $context, 'Merchant API rates endpoint accessed.' );
	$rates = crm_merchant_api_rates_snapshot( (int) $context['company']->id, $context['merchant'] );

	return crm_merchant_api_apply_context_headers(
		crm_merchant_api_success_response(
		$context['request_id'],
		[
			'merchant_id'   => (int) $context['merchant']->id,
			'company_id'    => (int) $context['company']->id,
			'mode_summary'  => $rates['mode_summary'],
			'directions'    => $rates['items'],
		]
		),
		$context
	);
}

function crm_merchant_api_rest_orders( WP_REST_Request $request ): WP_REST_Response {
	$context = crm_merchant_api_auth_context( $request, 'orders:read' );
	if ( $context instanceof WP_REST_Response ) {
		return $context;
	}

	$pagination = crm_merchant_api_normalize_pagination( $request );
	$statuses   = crm_merchant_api_parse_status_filter( $request->get_param( 'status' ) );
	$orders     = crm_merchant_api_list_orders(
		(int) $context['company']->id,
		(int) $context['merchant']->id,
		$statuses,
		$pagination['page'],
		$pagination['per_page']
	);

	crm_merchant_api_log_read_event( 'merchant_api_orders_read', $context, 'Merchant API orders list endpoint accessed.' );

	return crm_merchant_api_apply_context_headers(
		crm_merchant_api_success_response(
		$context['request_id'],
		[
			'items' => array_values( array_map( 'crm_merchant_api_order_summary', $orders['items'] ) ),
		],
		[
			'page'        => (int) $orders['page'],
			'per_page'    => (int) $orders['per_page'],
			'total'       => (int) $orders['total'],
			'total_pages' => (int) $orders['total_pages'],
			'statuses'    => $statuses,
		]
		),
		$context
	);
}

function crm_merchant_api_rest_order_detail( WP_REST_Request $request ): WP_REST_Response {
	$context = crm_merchant_api_auth_context( $request, 'orders:read' );
	if ( $context instanceof WP_REST_Response ) {
		return $context;
	}

	$order_id = (int) $request->get_param( 'order_id' );
	$order    = crm_merchant_api_get_order(
		(int) $context['company']->id,
		(int) $context['merchant']->id,
		$order_id
	);

	if ( ! $order ) {
		return crm_merchant_api_apply_context_headers(
			crm_merchant_api_error_response( $context['request_id'], 'not_found', 'Order was not found.', 404 ),
			$context
		);
	}

	crm_merchant_api_log_read_event( 'merchant_api_order_read', $context, 'Merchant API order detail endpoint accessed.' );

	return crm_merchant_api_apply_context_headers(
		crm_merchant_api_success_response(
		$context['request_id'],
		[
			'order' => crm_merchant_api_order_detail( $order ),
		]
		),
		$context
	);
}

function crm_merchant_api_rest_payouts( WP_REST_Request $request ): WP_REST_Response {
	$context = crm_merchant_api_auth_context( $request, 'payouts:read' );
	if ( $context instanceof WP_REST_Response ) {
		return $context;
	}

	$pagination = crm_merchant_api_normalize_pagination( $request );
	$payouts    = crm_merchant_api_list_payouts(
		(int) $context['company']->id,
		(int) $context['merchant']->id,
		$pagination['page'],
		$pagination['per_page']
	);

	crm_merchant_api_log_read_event( 'merchant_api_payouts_read', $context, 'Merchant API payouts list endpoint accessed.' );

	return crm_merchant_api_apply_context_headers(
		crm_merchant_api_success_response(
		$context['request_id'],
		[
			'items' => array_values( array_map( 'crm_merchant_api_format_payout', $payouts['items'] ) ),
		],
		[
			'page'        => (int) $payouts['page'],
			'per_page'    => (int) $payouts['per_page'],
			'total'       => (int) $payouts['total'],
			'total_pages' => (int) $payouts['total_pages'],
		]
		),
		$context
	);
}

function crm_merchant_api_rest_payout_detail( WP_REST_Request $request ): WP_REST_Response {
	$context = crm_merchant_api_auth_context( $request, 'payouts:read' );
	if ( $context instanceof WP_REST_Response ) {
		return $context;
	}

	$payout_id = (int) $request->get_param( 'payout_id' );
	$payout    = crm_merchant_api_get_payout(
		(int) $context['company']->id,
		(int) $context['merchant']->id,
		$payout_id
	);

	if ( ! $payout ) {
		return crm_merchant_api_apply_context_headers(
			crm_merchant_api_error_response( $context['request_id'], 'not_found', 'Payout was not found.', 404 ),
			$context
		);
	}

	crm_merchant_api_log_read_event( 'merchant_api_payout_read', $context, 'Merchant API payout detail endpoint accessed.' );

	return crm_merchant_api_apply_context_headers(
		crm_merchant_api_success_response(
		$context['request_id'],
		[
			'payout' => crm_merchant_api_format_payout( $payout ),
		]
		),
		$context
	);
}

add_action( 'rest_api_init', 'crm_merchant_api_register_routes' );
function crm_merchant_api_register_routes(): void {
	register_rest_route(
		'malibu/v1',
		'/merchant/me',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'crm_merchant_api_rest_me',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'malibu/v1',
		'/merchant/balances',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'crm_merchant_api_rest_balances',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'malibu/v1',
		'/merchant/rates',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'crm_merchant_api_rest_rates',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'malibu/v1',
		'/merchant/orders',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'crm_merchant_api_rest_orders',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'malibu/v1',
		'/merchant/invoices',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'crm_merchant_api_rest_create_invoice',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'malibu/v1',
		'/merchant/orders/(?P<order_id>\d+)',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'crm_merchant_api_rest_order_detail',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'malibu/v1',
		'/merchant/payouts',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'crm_merchant_api_rest_payouts',
			'permission_callback' => '__return_true',
		]
	);

	register_rest_route(
		'malibu/v1',
		'/merchant/payouts/(?P<payout_id>\d+)',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'crm_merchant_api_rest_payout_detail',
			'permission_callback' => '__return_true',
		]
	);
}
