<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RATES_PROVIDER_EX24',   'ex24' );
define( 'RATES_PROVIDER_SOURCE', 'phuket' );
define( 'RATES_PAIR_CODE',       'THB_RUB' );
define( 'RATES_EX24_URL',        'https://dev-mock.ex24.pro/api/tvrateQR?source=' );
define( 'RATES_EX24_TIMEOUT',    8 );
define( 'RATES_EX24_CACHE_TTL',  5 * MINUTE_IN_SECONDS );

/**
 * Получить курсы с Ex24.
 *
 * @param string $source Параметр источника (напр. 'phuket').
 * @return array{
 *   ok: bool,
 *   error: string|null,
 *   sberbank_buy: float|null,
 *   tinkoff_buy: float|null,
 *   raw: array|null
 * }
 */
function rates_get_ex24( string $source = RATES_PROVIDER_SOURCE ): array {
	$url = RATES_EX24_URL . rawurlencode( $source );

	$response = wp_remote_get( $url, [
		'timeout' => RATES_EX24_TIMEOUT,
	] );

	if ( is_wp_error( $response ) ) {
		return [
			'ok'           => false,
			'error'        => $response->get_error_message(),
			'sberbank_buy' => null,
			'tinkoff_buy'  => null,
			'raw'          => null,
		];
	}

	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( ! is_array( $data ) ) {
		return [
			'ok'           => false,
			'error'        => 'Некорректный ответ от Ex24 (не JSON).',
			'sberbank_buy' => null,
			'tinkoff_buy'  => null,
			'raw'          => null,
		];
	}

	$sber   = isset( $data['rates']['Sberbank']['buy'] ) ? (float) $data['rates']['Sberbank']['buy'] : null;
	$tinkoff = isset( $data['rates']['Tinkoff']['buy'] ) ? (float) $data['rates']['Tinkoff']['buy'] : null;

	return [
		'ok'           => true,
		'error'        => null,
		'sberbank_buy' => $sber,
		'tinkoff_buy'  => $tinkoff,
		'raw'          => $data,
	];
}

/**
 * Получить курсы Ex24 с кэшированием (для отображения на странице).
 * Кэш 5 минут — не использовать при сохранении в историю, только для отображения.
 */
function rates_get_ex24_cached( string $source = RATES_PROVIDER_SOURCE ): array {
	$cache_key = 'me_ex24_rates_' . $source;
	$cached    = get_transient( $cache_key );

	if ( $cached !== false ) {
		return $cached;
	}

	$result = rates_get_ex24( $source );

	if ( $result['ok'] ) {
		set_transient( $cache_key, $result, RATES_EX24_CACHE_TTL );
	}

	return $result;
}

/**
 * Получить активную пару для организации.
 *
 * @param string $pair_code  Код пары (напр. RATES_PAIR_CODE).
 * @param int    $org_id
 * @return object|null
 */
function rates_get_pair( string $pair_code = RATES_PAIR_CODE, int $org_id = CRM_DEFAULT_ORG_ID ): ?object {
	global $wpdb;

	return $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM crm_rate_pairs
		 WHERE organization_id = %d AND code = %s AND is_active = 1
		 LIMIT 1',
		$org_id,
		$pair_code
	) ) ?: null;
}

/**
 * Получить коэффициент для пары и провайдера.
 *
 * @param int    $pair_id
 * @param string $provider
 * @param string $source_param
 * @return float
 */
function rates_get_coefficient( int $pair_id, string $provider = RATES_PROVIDER_EX24, string $source_param = RATES_PROVIDER_SOURCE ): float {
	global $wpdb;

	$value = $wpdb->get_var( $wpdb->prepare(
		'SELECT coefficient FROM crm_pair_coefficients
		 WHERE pair_id = %d AND provider = %s AND source_param = %s
		 LIMIT 1',
		$pair_id,
		$provider,
		$source_param
	) );

	return $value !== null ? (float) $value : 0.05;
}

/**
 * Обновить коэффициент для пары и провайдера.
 *
 * @param int    $pair_id
 * @param float  $coefficient
 * @param string $provider
 * @param string $source_param
 * @return bool
 */
function rates_update_coefficient( int $pair_id, float $coefficient, string $provider = RATES_PROVIDER_EX24, string $source_param = RATES_PROVIDER_SOURCE ): bool {
	global $wpdb;

	$result = $wpdb->update(
		'crm_pair_coefficients',
		[ 'coefficient' => $coefficient ],
		[ 'pair_id' => $pair_id, 'provider' => $provider, 'source_param' => $source_param ],
		[ '%f' ],
		[ '%d', '%s', '%s' ]
	);

	return $result !== false;
}

/**
 * Рассчитать наши курсы от курсов конкурента.
 *
 * @param float|null $sberbank_buy   Курс конкурента по Сберу.
 * @param float|null $tinkoff_buy    Курс конкурента по Тинькову.
 * @param float      $coefficient    Коэффициент вычитания.
 * @return array{our_sberbank: float|null, our_tinkoff: float|null}
 */
function rates_calculate( ?float $sberbank_buy, ?float $tinkoff_buy, float $coefficient ): array {
	return [
		'our_sberbank' => $sberbank_buy !== null ? round( $sberbank_buy - $coefficient, 4 ) : null,
		'our_tinkoff'  => $tinkoff_buy  !== null ? round( $tinkoff_buy  - $coefficient, 4 ) : null,
	];
}

/**
 * Сохранить снимок курсов в историю.
 *
 * @param int        $pair_id
 * @param float|null $comp_sber
 * @param float|null $comp_tinkoff
 * @param float|null $our_sber
 * @param float|null $our_tinkoff
 * @param float      $coefficient
 * @param string     $provider
 * @param string     $source_param
 * @param int        $org_id
 * @return int|false  Inserted row ID or false on failure.
 */
function rates_save_snapshot(
	int $pair_id,
	?float $comp_sber,
	?float $comp_tinkoff,
	?float $our_sber,
	?float $our_tinkoff,
	float $coefficient,
	string $provider = RATES_PROVIDER_EX24,
	string $source_param = RATES_PROVIDER_SOURCE,
	int $org_id = CRM_DEFAULT_ORG_ID
) {
	global $wpdb;

	$inserted = $wpdb->insert(
		'crm_rate_history',
		[
			'organization_id'         => $org_id,
			'pair_id'                 => $pair_id,
			'provider'                => $provider,
			'source_param'            => $source_param,
			'competitor_sberbank_buy' => $comp_sber,
			'competitor_tinkoff_buy'  => $comp_tinkoff,
			'our_sberbank_rate'       => $our_sber,
			'our_tinkoff_rate'        => $our_tinkoff,
			'coefficient_value'       => $coefficient,
		],
		[ '%d', '%d', '%s', '%s', '%f', '%f', '%f', '%f', '%f' ]
	);

	return $inserted ? (int) $wpdb->insert_id : false;
}

// ═══════════════════════════════════════════════════════════════════════════════
// РЫНОЧНЫЕ КУРСЫ USDT — внешние источники
// Источники: rapira (USDT/RUB), bitkub (THB/USDT), binance_th (USDT/THB), cbr (USD/RUB)
//
// ВАЖНО — при добавлении нового источника:
//   1. Добавить функции rates_get_X() и rates_get_X_cached() ниже.
//   2. Добавить значение в ENUM `source` таблицы crm_market_snapshots_usdt (ALTER TABLE).
//   3. Добавить значение в MARKET_SNAPSHOT_SOURCES в inc/ajax/rates.php.
//   4. Добавить case в switch AJAX-обработчика me_ajax_market_snapshot_save().
//   5. Добавить карточку и кнопку в page-rates.php.
// ═══════════════════════════════════════════════════════════════════════════════

define( 'RATES_RAPIRA_URL',       'https://api.rapira.net/open/market/rates' );
define( 'RATES_BITKUB_URL',       'https://api.bitkub.com/api/market/ticker' );
define( 'RATES_BINANCE_TH_URL',   'https://api.binance.th/api/v1/ticker/bookTicker' );
define( 'RATES_CBR_URL',          'https://www.cbr.ru/scripts/XML_daily.asp' );
define( 'RATES_MARKET_CACHE_TTL', 3 * MINUTE_IN_SECONDS );

// ── Rapira: USDT/RUB ──────────────────────────────────────────────────────────

/**
 * Получить курс USDT/RUB с Rapira (без кэша).
 *
 * @return array{ok: bool, error: ?string, bid: ?float, ask: ?float, close: ?float, symbol: string}
 */
function rates_get_rapira(): array {
	$response = wp_remote_get( RATES_RAPIRA_URL, [ 'timeout' => 10 ] );

	if ( is_wp_error( $response ) ) {
		return [ 'ok' => false, 'error' => $response->get_error_message(), 'bid' => null, 'ask' => null, 'close' => null, 'symbol' => 'USDT/RUB' ];
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || ! is_array( $data['data'] ?? null ) ) {
		return [ 'ok' => false, 'error' => 'Неожиданная структура ответа Rapira.', 'bid' => null, 'ask' => null, 'close' => null, 'symbol' => 'USDT/RUB' ];
	}

	$pair = null;
	foreach ( $data['data'] as $row ) {
		if ( is_array( $row ) && ( $row['symbol'] ?? null ) === 'USDT/RUB' ) {
			$pair = $row;
			break;
		}
	}

	if ( ! $pair ) {
		return [ 'ok' => false, 'error' => 'USDT/RUB не найден в ответе Rapira.', 'bid' => null, 'ask' => null, 'close' => null, 'symbol' => 'USDT/RUB' ];
	}

	return [
		'ok'     => true,
		'error'  => null,
		'bid'    => isset( $pair['bidPrice'] ) ? (float) $pair['bidPrice'] : null,
		'ask'    => isset( $pair['askPrice'] ) ? (float) $pair['askPrice'] : null,
		'close'  => isset( $pair['close'] )    ? (float) $pair['close']    : null,
		'symbol' => 'USDT/RUB',
	];
}

/**
 * Получить курс USDT/RUB с Rapira с кэшем (только для отображения).
 */
function rates_get_rapira_cached(): array {
	$cached = get_transient( 'me_rapira_rates' );
	if ( $cached !== false ) {
		return $cached;
	}
	$result = rates_get_rapira();
	if ( $result['ok'] ) {
		set_transient( 'me_rapira_rates', $result, RATES_MARKET_CACHE_TTL );
	}
	return $result;
}

// ── Bitkub: THB/USDT ──────────────────────────────────────────────────────────

/**
 * Получить курс THB/USDT с Bitkub (без кэша).
 *
 * @return array{ok: bool, error: ?string, lowestAsk: ?float, highestBid: ?float, symbol: string}
 */
function rates_get_bitkub(): array {
	// Без параметра sym — возвращает все пары в плоской структуре { "THB_USDT": { highestBid, lowestAsk, ... }, ... }
	// С sym=THB_USDT API может менять структуру ответа, поэтому используем общий endpoint
	$response = wp_remote_get(
		RATES_BITKUB_URL,
		[ 'timeout' => 10 ]
	);

	if ( is_wp_error( $response ) ) {
		return [ 'ok' => false, 'error' => $response->get_error_message(), 'lowestAsk' => null, 'highestBid' => null, 'symbol' => 'THB/USDT' ];
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) ) {
		return [ 'ok' => false, 'error' => 'Некорректный JSON от Bitkub.', 'lowestAsk' => null, 'highestBid' => null, 'symbol' => 'THB/USDT' ];
	}

	// Bitkub может оборачивать ответ в result{} (v3) или возвращать объект напрямую (v1)
	$pool = isset( $data['result'] ) && is_array( $data['result'] ) ? $data['result'] : $data;

	if ( ! isset( $pool['THB_USDT'] ) ) {
		return [ 'ok' => false, 'error' => 'THB_USDT не найден в ответе Bitkub.', 'lowestAsk' => null, 'highestBid' => null, 'symbol' => 'THB/USDT' ];
	}

	$pair = $pool['THB_USDT'];

	return [
		'ok'         => true,
		'error'      => null,
		'lowestAsk'  => isset( $pair['lowestAsk'] )  ? (float) $pair['lowestAsk']  : null,
		'highestBid' => isset( $pair['highestBid'] ) ? (float) $pair['highestBid'] : null,
		'symbol'     => 'THB/USDT',
	];
}

/**
 * Получить курс THB/USDT с Bitkub с кэшем (только для отображения).
 */
function rates_get_bitkub_cached(): array {
	$cached = get_transient( 'me_bitkub_rates' );
	// Не используем кэш если предыдущий результат был ошибкой
	if ( $cached !== false && ! empty( $cached['ok'] ) ) {
		return $cached;
	}
	$result = rates_get_bitkub();
	if ( $result['ok'] ) {
		set_transient( 'me_bitkub_rates', $result, RATES_MARKET_CACHE_TTL );
	} else {
		delete_transient( 'me_bitkub_rates' );
	}
	return $result;
}

// ── Binance TH: USDT/THB ─────────────────────────────────────────────────────

/**
 * Получить bookTicker USDT/THB с Binance TH (без кэша).
 *
 * @return array{ok: bool, error: ?string, bid: ?float, ask: ?float, mid: ?float, symbol: string}
 */
function rates_get_binance_th(): array {
	$response = wp_remote_get(
		add_query_arg( 'symbol', 'USDTTHB', RATES_BINANCE_TH_URL ),
		[ 'timeout' => 10 ]
	);

	if ( is_wp_error( $response ) ) {
		return [ 'ok' => false, 'error' => $response->get_error_message(), 'bid' => null, 'ask' => null, 'mid' => null, 'symbol' => 'USDT/THB' ];
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) ) {
		return [ 'ok' => false, 'error' => 'Некорректный JSON от Binance TH.', 'bid' => null, 'ask' => null, 'mid' => null, 'symbol' => 'USDT/THB' ];
	}

	$bid = isset( $data['bidPrice'] ) ? (float) $data['bidPrice'] : null;
	$ask = isset( $data['askPrice'] ) ? (float) $data['askPrice'] : null;
	$mid = ( $bid !== null && $ask !== null ) ? round( ( $bid + $ask ) / 2, 8 ) : null;

	return [
		'ok'     => true,
		'error'  => null,
		'bid'    => $bid,
		'ask'    => $ask,
		'mid'    => $mid,
		'symbol' => 'USDT/THB',
	];
}

/**
 * Получить bookTicker USDT/THB с Binance TH с кэшем (только для отображения).
 */
function rates_get_binance_th_cached(): array {
	$cached = get_transient( 'me_binance_th_rates' );
	if ( $cached !== false ) {
		return $cached;
	}
	$result = rates_get_binance_th();
	if ( $result['ok'] ) {
		set_transient( 'me_binance_th_rates', $result, RATES_MARKET_CACHE_TTL );
	}
	return $result;
}

// ── ЦБ РФ: USD/RUB (только отображение, не сохраняется в историю) ────────────

/**
 * Получить официальный курс USD/RUB с сайта ЦБ РФ (без кэша).
 * Используется только как информационный блок — кнопки сохранения нет.
 *
 * @return array{ok: bool, error: ?string, rate: ?float, date: ?string}
 */
function rates_get_cbr_usd(): array {
	$response = wp_remote_get( RATES_CBR_URL, [ 'timeout' => 10 ] );

	if ( is_wp_error( $response ) ) {
		return [ 'ok' => false, 'error' => $response->get_error_message(), 'rate' => null, 'date' => null ];
	}

	$body = wp_remote_retrieve_body( $response );
	libxml_use_internal_errors( true );
	$xml = simplexml_load_string( $body );
	libxml_clear_errors();

	if ( $xml === false ) {
		return [ 'ok' => false, 'error' => 'Не удалось разобрать XML ЦБ РФ.', 'rate' => null, 'date' => null ];
	}

	foreach ( $xml->Valute as $v ) {
		if ( strtoupper( (string) $v->CharCode ) === 'USD' ) {
			$nominal = (int) $v->Nominal;
			$value   = (float) str_replace( ',', '.', (string) $v->Value );
			$rate    = $nominal > 0 ? round( $value / $nominal, 4 ) : $value;
			$date    = (string) ( $xml['Date'] ?? '' );
			return [ 'ok' => true, 'error' => null, 'rate' => $rate, 'date' => $date ];
		}
	}

	return [ 'ok' => false, 'error' => 'USD не найден в ленте ЦБ РФ.', 'rate' => null, 'date' => null ];
}

/**
 * Получить курс USD/RUB ЦБ РФ с кэшем.
 * Официальный курс меняется раз в сутки — кэш 1 час.
 */
function rates_get_cbr_usd_cached(): array {
	$cached = get_transient( 'me_cbr_usd_rate' );
	if ( $cached !== false ) {
		return $cached;
	}
	$result = rates_get_cbr_usd();
	if ( $result['ok'] ) {
		set_transient( 'me_cbr_usd_rate', $result, HOUR_IN_SECONDS );
	}
	return $result;
}

// ── Сохранение рыночного снимка ───────────────────────────────────────────────

/**
 * Сохранить снимок рыночного курса в crm_market_snapshots_usdt.
 *
 * @param string     $source   Значение ENUM: 'rapira' | 'bitkub' | 'binance_th'
 *                             (при добавлении нового — обновить ENUM в миграции и MARKET_SNAPSHOT_SOURCES в ajax/rates.php)
 * @param string     $symbol   Торговая пара, напр. 'USDT/RUB'
 * @param float|null $bid
 * @param float|null $ask
 * @param float|null $mid
 * @param int        $org_id
 * @return int|false  ID вставленной строки или false при ошибке.
 */
function rates_save_market_snapshot(
	string $source,
	string $symbol,
	?float $bid,
	?float $ask,
	?float $mid,
	int $org_id = CRM_DEFAULT_ORG_ID
) {
	global $wpdb;

	$inserted = $wpdb->insert(
		'crm_market_snapshots_usdt',
		[
			'organization_id' => $org_id,
			'source'          => $source,
			'symbol'          => $symbol,
			'bid'             => $bid,
			'ask'             => $ask,
			'mid'             => $mid,
		],
		[ '%d', '%s', '%s', '%f', '%f', '%f' ]
	);

	return $inserted ? (int) $wpdb->insert_id : false;
}

// ── История рыночных снимков ──────────────────────────────────────────────────

/**
 * Получить последний сохранённый снимок для источника.
 *
 * @param string $source  'rapira' | 'bitkub' | 'binance_th'
 * @return array|null
 */
function rates_get_last_market_snapshot( string $source, int $org_id = CRM_DEFAULT_ORG_ID ): ?array {
	global $wpdb;

	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT id, source, symbol, bid, ask, mid, created_at
		 FROM crm_market_snapshots_usdt
		 WHERE source = %s AND organization_id = %d
		 ORDER BY created_at DESC
		 LIMIT 1',
		$source,
		$org_id
	), ARRAY_A );

	return is_array( $row ) ? $row : null;
}

/**
 * Получить историю рыночных снимков по всем источникам, сортировка по времени.
 *
 * @param int $limit
 * @return array
 */
function rates_get_all_market_history( int $limit = 100, int $org_id = CRM_DEFAULT_ORG_ID ): array {
	global $wpdb;

	return $wpdb->get_results( $wpdb->prepare(
		'SELECT id, source, symbol, bid, ask, mid, created_at
		 FROM crm_market_snapshots_usdt
		 WHERE organization_id = %d
		 ORDER BY created_at DESC
		 LIMIT %d',
		$org_id,
		$limit
	), ARRAY_A ) ?: [];
}

/**
 * Получить историю рыночных снимков для источника.
 *
 * @param string $source  'rapira' | 'bitkub' | 'binance_th'
 * @param int    $limit
 * @return array
 */
function rates_get_market_history( string $source, int $limit = 50, int $org_id = CRM_DEFAULT_ORG_ID ): array {
	global $wpdb;

	return $wpdb->get_results( $wpdb->prepare(
		'SELECT id, symbol, bid, ask, mid, created_at
		 FROM crm_market_snapshots_usdt
		 WHERE source = %s AND organization_id = %d
		 ORDER BY created_at DESC
		 LIMIT %d',
		$source,
		$org_id,
		$limit
	), ARRAY_A ) ?: [];
}

// ── История курсов для пары ───────────────────────────────────────────────────

/**
 * Получить историю курсов для пары.
 *
 * @param int $pair_id
 * @param int $limit
 * @return array
 */
function rates_get_history( int $pair_id, int $limit = 75 ): array {
	global $wpdb;

	return $wpdb->get_results( $wpdb->prepare(
		'SELECT id, competitor_sberbank_buy, competitor_tinkoff_buy,
		        our_sberbank_rate, our_tinkoff_rate, coefficient_value, created_at
		 FROM crm_rate_history
		 WHERE pair_id = %d
		 ORDER BY created_at DESC
		 LIMIT %d',
		$pair_id,
		$limit
	), ARRAY_A ) ?: [];
}
