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

		wp_send_json_error( [ 'message' => 'Аккаунт не привязан к компании.' ], 403 );
	}

	return $org_id;
}

function me_ajax_rates_save(): void {
	check_ajax_referer( 'me_rates_save', 'nonce' );

	$current_uid = get_current_user_id();

	if ( ! crm_user_has_permission( $current_uid, 'rates.edit' ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}

	$org_id = _me_rates_require_current_company();

	$pair = rates_get_pair( RATES_PAIR_CODE, $org_id );
	if ( ! $pair ) {
		wp_send_json_error( [ 'message' => 'Активная пара не найдена.' ], 500 );
	}

	$coeff = rates_get_coefficient( (int) $pair->id, RATES_PROVIDER_EX24, RATES_PROVIDER_SOURCE );

	$ex24 = rates_get_ex24( RATES_PROVIDER_SOURCE );
	if ( ! $ex24['ok'] ) {
		wp_send_json_error( [
			'message' => 'Не удалось получить курсы Ex24: ' . $ex24['error'],
		], 502 );
	}

	$comp_sber    = $ex24['sberbank_buy'];
	$comp_tinkoff = $ex24['tinkoff_buy'];
	$calculated   = rates_calculate( $comp_sber, $comp_tinkoff, $coeff );

	$row_id = rates_save_snapshot(
		(int) $pair->id,
		$comp_sber,
		$comp_tinkoff,
		$calculated['our_sberbank'],
		$calculated['our_tinkoff'],
		$coeff,
		RATES_PROVIDER_EX24,
		RATES_PROVIDER_SOURCE,
		$org_id
	);

	if ( $row_id === false ) {
		wp_send_json_error( [ 'message' => 'Ошибка сохранения в базу данных.' ], 500 );
	}

	crm_log_entity( 'rate.snapshot_saved', 'rates', 'snapshot',
		'Сохранён снимок курсов ' . RATES_PAIR_CODE,
		'rate',
		(int) $row_id,
		[
			'context' => [
				'org_id'              => $org_id,
				'pair'                => RATES_PAIR_CODE,
				'provider'            => RATES_PROVIDER_EX24,
				'coefficient'         => $coeff,
				'competitor_sberbank' => $comp_sber,
				'competitor_tinkoff'  => $comp_tinkoff,
				'our_sberbank'        => $calculated['our_sberbank'],
				'our_tinkoff'         => $calculated['our_tinkoff'],
			],
		]
	);

	wp_send_json_success( [
		'message'             => 'Курсы сохранены.',
		'id'                  => $row_id,
		'competitor_sberbank' => $comp_sber,
		'competitor_tinkoff'  => $comp_tinkoff,
		'our_sberbank'        => $calculated['our_sberbank'],
		'our_tinkoff'         => $calculated['our_tinkoff'],
		'coefficient'         => $coeff,
		'created_at'          => current_time( 'mysql' ),
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
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}

	$org_id = _me_rates_require_current_company();

	$source = sanitize_key( $_POST['source'] ?? '' );

	if ( ! in_array( $source, MARKET_SNAPSHOT_SOURCES, true ) ) {
		wp_send_json_error( [ 'message' => 'Неизвестный источник: ' . $source ], 400 );
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
			$symbol = 'THB/USDT';
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
			wp_send_json_error( [ 'message' => 'Источник не поддерживается.' ], 400 );
			return;
	}

	if ( ! $data['ok'] ) {
		wp_send_json_error( [ 'message' => 'Ошибка получения курса: ' . ( $data['error'] ?? 'неизвестно' ) ], 502 );
	}

	$row_id = rates_save_market_snapshot( $source, $symbol, $bid, $ask, $mid, $org_id );

	if ( $row_id === false ) {
		wp_send_json_error( [ 'message' => 'Ошибка сохранения в базу данных.' ], 500 );
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
