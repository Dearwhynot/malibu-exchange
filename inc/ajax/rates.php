<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX: сохранить снимок курсов в историю.
 * Action: me_rates_save
 * Доступ: только залогиненные с правом rates.edit
 */
add_action( 'wp_ajax_me_rates_save',              'me_ajax_rates_save' );
add_action( 'wp_ajax_me_market_snapshot_save',    'me_ajax_market_snapshot_save' );

function _me_rates_fail( string $message, int $status, array $context = [] ): void {
	error_log( sprintf(
		'[rates.ajax] %d %s | uid=%d | %s',
		$status,
		$message,
		get_current_user_id(),
		$context ? wp_json_encode( $context ) : '{}'
	) );
	wp_send_json_error( [ 'message' => $message ], $status );
}

function _me_rates_require_current_company(): int {
	$current_uid = get_current_user_id();
	$is_root     = crm_is_root( $current_uid );

	$org_id = crm_get_current_user_company_id( $current_uid );
	if ( ! $is_root && $org_id <= 0 ) {
		crm_log_company_scope_violation(
			'rates.scope.user_without_company',
			'Попытка сохранить курс без привязки к компании',
			[
				'user_id'            => $current_uid,
				'current_company_id' => $org_id,
			]
		);

		_me_rates_fail( 'Аккаунт не привязан к компании.', 403, [ 'reason' => 'no_company' ] );
	}

	return $org_id;
}

function me_ajax_rates_save(): void {
	check_ajax_referer( 'me_rates_save', 'nonce' );

	$current_uid = get_current_user_id();

	if ( ! crm_user_has_permission( $current_uid, 'rates.edit' ) ) {
		_me_rates_fail( 'Недостаточно прав.', 403, [ 'permission' => 'rates.edit' ] );
	}

	$org_id = _me_rates_require_current_company();

	$result = rates_refresh_ex24_snapshot( $org_id, 'web', RATES_PAIR_CODE, RATES_PROVIDER_SOURCE );
	if ( empty( $result['ok'] ) ) {
		_me_rates_fail(
			(string) ( $result['message'] ?? 'Не удалось сохранить курсы.' ),
			(int) ( $result['status'] ?? 500 ),
			is_array( $result['context'] ?? null ) ? $result['context'] : []
		);
	}

	wp_send_json_success( [
		'message'             => ! empty( $result['saved'] )
			? 'Курсы сохранены.'
			: 'Курс не изменился. Новая запись не создана.',
		'id'                  => (int) ( $result['id'] ?? 0 ),
		'saved'               => ! empty( $result['saved'] ),
		'unchanged'           => ! empty( $result['unchanged'] ),
		'competitor_sberbank' => $result['competitor_sberbank'] ?? null,
		'competitor_tinkoff'  => $result['competitor_tinkoff'] ?? null,
		'our_sberbank'        => $result['our_sberbank'] ?? null,
		'our_tinkoff'         => $result['our_tinkoff'] ?? null,
		'coefficient'         => $result['coefficient'] ?? null,
		'created_at'          => (string) ( $result['created_at'] ?? '' ),
		'checked_at'          => (string) ( $result['checked_at'] ?? '' ),
	] );
}

/**
 * AJAX: сохранить снимок рыночного курса (Rapira / Bitkub / Binance TH).
 * Action: me_market_snapshot_save
 *
 * ВАЖНО — ENUM source:
 * Список источников ниже должен совпадать с ENUM в crm_market_snapshots_usdt (миграция 0006).
 * При добавлении нового источника — обновить MARKET_SNAPSHOT_SOURCES здесь,
 * обновить ENUM в БД (ALTER TABLE), добавить функцию в inc/rates.php и карточку в page-rates.php.
 */

// Допустимые источники (должны совпадать с ENUM в crm_market_snapshots_usdt).
// При добавлении нового — обновить здесь и в DDL таблицы.
define( 'MARKET_SNAPSHOT_SOURCES', [ 'rapira', 'bitkub', 'binance_th' ] );

function me_ajax_market_snapshot_save(): void {
	check_ajax_referer( 'me_market_snapshot_save', 'nonce' );

	$current_uid = get_current_user_id();

	if ( ! crm_user_has_permission( $current_uid, 'rates.edit' ) ) {
		_me_rates_fail( 'Недостаточно прав.', 403, [ 'permission' => 'rates.edit' ] );
	}

	$org_id = _me_rates_require_current_company();

	$source = sanitize_key( $_POST['source'] ?? '' );

	if ( ! in_array( $source, MARKET_SNAPSHOT_SOURCES, true ) ) {
		_me_rates_fail( 'Неизвестный источник: ' . $source, 400, [ 'source' => $source ] );
	}

	// Получаем свежие данные (не кэш — сохраняем актуальный срез)
	switch ( $source ) {
		case 'rapira':
			$data   = rates_get_rapira();
			$symbol = 'USDT/RUB';
			$bid    = $data['bid']  ?? null;
			$ask    = $data['ask']  ?? null;
			$mid    = ( $bid !== null && $ask !== null ) ? round( ( $bid + $ask ) / 2, 8 ) : null;
			break;

		case 'bitkub':
			$data   = rates_get_bitkub();
			$symbol = 'USDT/THB';
			$bid    = $data['highestBid'] ?? null;
			$ask    = $data['lowestAsk']  ?? null;
			$mid    = ( $bid !== null && $ask !== null ) ? round( ( $bid + $ask ) / 2, 8 ) : null;
			break;

		case 'binance_th':
			$data   = rates_get_binance_th();
			$symbol = 'USDT/THB';
			$bid    = $data['bid'] ?? null;
			$ask    = $data['ask'] ?? null;
			$mid    = $data['mid'] ?? null;
			break;

		default:
			_me_rates_fail( 'Источник не поддерживается.', 400, [ 'source' => $source ] );
			return;
	}

	if ( ! $data['ok'] ) {
		_me_rates_fail( 'Ошибка получения курса: ' . ( $data['error'] ?? 'неизвестно' ), 502, [
			'source' => $source,
			'symbol' => $symbol,
			'error'  => $data['error'] ?? null,
		] );
	}

	$row_id = rates_save_market_snapshot( $source, $symbol, $bid, $ask, $mid, $org_id );

	if ( $row_id === false ) {
		_me_rates_fail( 'Ошибка сохранения в базу данных.', 500, [
			'where'  => 'rates_save_market_snapshot',
			'source' => $source,
			'symbol' => $symbol,
			'org_id' => $org_id,
			'db_err' => $GLOBALS['wpdb']->last_error ?? null,
		] );
	}

	crm_log_entity( 'rate.market_snapshot_saved', 'rates', 'snapshot',
		"Сохранён рыночный снимок {$source} ({$symbol})",
		'market_snapshot',
		(int) $row_id,
		[ 'context' => [ 'org_id' => $org_id, 'source' => $source, 'symbol' => $symbol, 'bid' => $bid, 'ask' => $ask, 'mid' => $mid ] ]
	);

	// Читаем created_at прямо из БД — чтобы JS получил точное время из MySQL,
	// а не current_time('mysql') WordPress, которое может отличаться по timezone.
	global $wpdb;
	$created_at = $wpdb->get_var( $wpdb->prepare(
		'SELECT created_at FROM crm_market_snapshots_usdt WHERE id = %d',
		$row_id
	) );

	wp_send_json_success( [
		'message'    => 'Снимок сохранён.',
		'id'         => $row_id,
		'source'     => $source,
		'symbol'     => $symbol,
		'bid'        => $bid,
		'ask'        => $ask,
		'mid'        => $mid,
		'created_at' => $created_at,
	] );
}

// ════════════════════════════════════════════════════════════════════════════
// Kanyon USDT/RUB — проверка курса через тестовый ордер 100 USDT
// ════════════════════════════════════════════════════════════════════════════
add_action( 'wp_ajax_me_kanyon_rate_check', 'me_ajax_kanyon_rate_check' );
function me_ajax_kanyon_rate_check(): void {
	check_ajax_referer( 'me_kanyon_rate', '_nonce' );

	$current_uid = get_current_user_id();
	if ( ! crm_user_has_permission( $current_uid, 'rates.edit' ) ) {
		_me_rates_fail( 'Недостаточно прав.', 403, [ 'permission' => 'rates.edit' ] );
	}

	$org_id = _me_rates_require_current_company();

	if ( ! rates_kanyon_is_enabled_for_company( $org_id ) ) {
		_me_rates_fail( 'Kanyon не активен или не настроен для вашей компании.', 403, [
			'pair_code' => RATES_KANYON_PAIR_CODE,
			'provider'  => RATES_KANYON_PROVIDER,
			'org_id'    => $org_id,
		] );
	}

	$result = rates_kanyon_fetch_and_record( $org_id, 'web' );

	if ( ! $result['ok'] ) {
		if ( isset( $result['cooldown'] ) ) {
			error_log( '[rates.ajax] 429 kanyon cooldown | org_id=' . $org_id . ' | remaining=' . $result['cooldown'] );
			wp_send_json_error( [
				'message'  => $result['error'],
				'cooldown' => $result['cooldown'],
			], 429 );
		}
		_me_rates_fail( $result['error'] ?? 'Ошибка Kanyon API.', 502, [ 'org_id' => $org_id ] );
	}

	$history     = rates_kanyon_get_history( $org_id, 50 );
	$cooldown    = rates_kanyon_cooldown_remaining( $org_id );
	$created_at  = '';
	$history_id  = (int) ( $result['history_id'] ?? 0 );
	if ( $history_id > 0 ) {
		global $wpdb;
		$created_at = (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT created_at FROM crm_rate_history_rub_usdt WHERE id = %d AND organization_id = %d',
			$history_id,
			$org_id
		) );
	}

	wp_send_json_success( [
		'message'            => 'Курс получен и сохранён.',
		'history_id'         => $history_id,
		'payment_order_id'   => $result['payment_order_id'] ?? null,
		'kanyon_rate'        => $result['kanyon_rate'],
		'rapira_rate'        => $result['rapira_rate'],
		'source'             => $result['source'] ?? 'web',
		'created_at'         => $created_at,
		'cooldown_remaining' => $cooldown,
		'history'            => $history,
	] );
}
