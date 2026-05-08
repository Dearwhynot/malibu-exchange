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
 * Получить пару БЕЗ фильтра is_active. Нужно, чтобы отличить «отключена root'ом»
 * от «не настроена» на стороне UI/settings.
 *
 * @param string $pair_code
 * @param int    $org_id
 * @return object|null
 */
function rates_get_any_pair( string $pair_code, int $org_id ): ?object {
	global $wpdb;

	return $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM crm_rate_pairs
		 WHERE organization_id = %d AND code = %s
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
	$full = rates_get_coefficient_full( $pair_id, $provider, $source_param );
	return (float) $full['value'];
}

/**
 * Получить полную запись наценки для пары/провайдера: значение + тип.
 *
 * @param int    $pair_id
 * @param string $provider
 * @param string $source_param
 * @return array{value: float, type: string}  type ∈ {'absolute','percent'}
 */
function rates_get_coefficient_full( int $pair_id, string $provider = RATES_PROVIDER_EX24, string $source_param = RATES_PROVIDER_SOURCE ): array {
	global $wpdb;

	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT coefficient, coefficient_type FROM crm_pair_coefficients
		 WHERE pair_id = %d AND provider = %s AND source_param = %s
		 LIMIT 1',
		$pair_id,
		$provider,
		$source_param
	), ARRAY_A );

	if ( ! is_array( $row ) ) {
		return [ 'value' => 0.0, 'type' => 'absolute' ];
	}

	$type = (string) ( $row['coefficient_type'] ?? 'absolute' );
	if ( ! in_array( $type, [ 'absolute', 'percent' ], true ) ) {
		$type = 'absolute';
	}

	return [
		'value' => (float) $row['coefficient'],
		'type'  => $type,
	];
}

/**
 * Обновить наценку (значение + тип) для пары и провайдера.
 *
 * @param int    $pair_id
 * @param float  $value
 * @param string $type            'absolute' | 'percent'
 * @param string $provider
 * @param string $source_param
 * @return bool
 */
function rates_update_coefficient( int $pair_id, float $value, string $type = 'absolute', string $provider = RATES_PROVIDER_EX24, string $source_param = RATES_PROVIDER_SOURCE ): bool {
	global $wpdb;

	if ( ! in_array( $type, [ 'absolute', 'percent' ], true ) ) {
		$type = 'absolute';
	}

	$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT id FROM crm_pair_coefficients
		 WHERE pair_id = %d AND provider = %s AND source_param = %s
		 LIMIT 1',
		$pair_id,
		$provider,
		$source_param
	) );

	if ( $existing_id > 0 ) {
		$result = $wpdb->update(
			'crm_pair_coefficients',
			[
				'coefficient'      => $value,
				'coefficient_type' => $type,
			],
			[ 'id' => $existing_id ],
			[ '%f', '%s' ],
			[ '%d' ]
		);
		return $result !== false;
	}

	$result = $wpdb->insert(
		'crm_pair_coefficients',
		[
			'pair_id'          => $pair_id,
			'provider'         => $provider,
			'source_param'     => $source_param,
			'coefficient'      => $value,
			'coefficient_type' => $type,
		],
		[ '%d', '%s', '%s', '%f', '%s' ]
	);
	return $result !== false;
}

/**
 * Применить наценку к рыночному курсу.
 * - absolute: market − value
 * - percent : market × (1 + value / 100)
 *
 * @param float|null $market
 * @param float      $value
 * @param string     $type
 * @return float|null
 */
function rates_apply_margin( ?float $market, float $value, string $type = 'absolute' ): ?float {
	if ( $market === null ) {
		return null;
	}
	if ( $type === 'percent' ) {
		return round( $market * ( 1 + $value / 100 ), 4 );
	}
	return round( $market - $value, 4 );
}

/**
 * Применить наценку к payout-курсу, где значение — сколько клиент получает.
 * Для таких направлений наша маржа уменьшает рыночный курс:
 * - absolute: market − value
 * - percent : market × (1 − value / 100)
 *
 * Пример: USDT → THB, где market = THB за 1 USDT.
 *
 * @param float|null $market
 * @param float      $value
 * @param string     $type
 * @return float|null
 */
function rates_apply_payout_margin( ?float $market, float $value, string $type = 'absolute' ): ?float {
	if ( $market === null ) {
		return null;
	}
	if ( $type === 'percent' ) {
		return round( $market * ( 1 - $value / 100 ), 4 );
	}
	return round( $market - $value, 4 );
}

/**
 * Рассчитать наши курсы от курсов конкурента.
 *
 * @param float|null $sberbank_buy
 * @param float|null $tinkoff_buy
 * @param float      $value         Значение наценки.
 * @param string     $type          'absolute' | 'percent'
 * @return array{our_sberbank: float|null, our_tinkoff: float|null}
 */
function rates_calculate( ?float $sberbank_buy, ?float $tinkoff_buy, float $value, string $type = 'absolute' ): array {
	return [
		'our_sberbank' => rates_apply_margin( $sberbank_buy, $value, $type ),
		'our_tinkoff'  => rates_apply_margin( $tinkoff_buy,  $value, $type ),
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

/**
 * Последний снимок курсов пары в рамках конкретной компании.
 *
 * @param int $pair_id
 * @param int $org_id
 * @return array|null
 */
function rates_get_last_snapshot(
	int $pair_id,
	int $org_id,
	string $provider = RATES_PROVIDER_EX24,
	string $source_param = RATES_PROVIDER_SOURCE
): ?array {
	global $wpdb;

	if ( $pair_id <= 0 || $org_id <= 0 ) {
		return null;
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT id, organization_id, pair_id, provider, source_param,
			        competitor_sberbank_buy, competitor_tinkoff_buy,
			        our_sberbank_rate, our_tinkoff_rate, coefficient_value, created_at
			 FROM crm_rate_history
			 WHERE organization_id = %d
			   AND pair_id = %d
			   AND provider = %s
			   AND source_param = %s
			 ORDER BY created_at DESC, id DESC
			 LIMIT 1',
			$org_id,
			$pair_id,
			$provider,
			$source_param
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

function rates_nullable_decimal_equal( $left, $right, int $decimals = 4 ): bool {
	if ( $left === null || $right === null ) {
		return $left === null && $right === null;
	}

	return number_format( (float) $left, $decimals, '.', '' )
		=== number_format( (float) $right, $decimals, '.', '' );
}

function rates_snapshot_matches_history( array $snapshot, ?array $last ): bool {
	if ( ! is_array( $last ) || empty( $last ) ) {
		return false;
	}

	$fields = [
		'competitor_sberbank_buy' => 'competitor_sberbank',
		'competitor_tinkoff_buy'  => 'competitor_tinkoff',
		'our_sberbank_rate'       => 'our_sberbank',
		'our_tinkoff_rate'        => 'our_tinkoff',
		'coefficient_value'       => 'coefficient',
	];

	foreach ( $fields as $history_key => $snapshot_key ) {
		if ( ! rates_nullable_decimal_equal( $snapshot[ $snapshot_key ] ?? null, $last[ $history_key ] ?? null ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Получить свежий Ex24-снимок для THB/RUB и сохранить его только при изменении.
 *
 * Этот helper является общим путём для backoffice-кнопки "Сохранить в историю"
 * и Telegram-бота. Если рассчитанные значения совпадают с последней записью,
 * новая строка в crm_rate_history не создаётся.
 *
 * @param int    $org_id
 * @param string $actor_source 'web'|'telegram'|'cron'
 * @param string $pair_code
 * @param string $source_param
 * @return array
 */
function rates_refresh_ex24_snapshot(
	int $org_id,
	string $actor_source = 'web',
	string $pair_code = RATES_PAIR_CODE,
	string $source_param = RATES_PROVIDER_SOURCE
): array {
	global $wpdb;

	$actor_source = sanitize_key( $actor_source );
	if ( ! in_array( $actor_source, [ 'web', 'telegram', 'cron' ], true ) ) {
		$actor_source = 'web';
	}

	if ( $org_id <= 0 ) {
		return [
			'ok'      => false,
			'status'  => 403,
			'message' => 'Компания не определена.',
			'context' => [ 'reason' => 'invalid_company', 'org_id' => $org_id ],
		];
	}

	$pair = rates_get_pair( $pair_code, $org_id );
	if ( ! $pair ) {
		return [
			'ok'      => false,
			'status'  => 500,
			'message' => 'Активная пара не найдена.',
			'context' => [
				'pair_code' => $pair_code,
				'org_id'    => $org_id,
			],
		];
	}

	$coeff_full = rates_get_coefficient_full( (int) $pair->id, RATES_PROVIDER_EX24, $source_param );
	$coeff      = (float) $coeff_full['value'];
	$coeff_type = (string) $coeff_full['type'];

	$ex24 = rates_get_ex24( $source_param );
	if ( ! $ex24['ok'] ) {
		return [
			'ok'      => false,
			'status'  => 502,
			'message' => 'Не удалось получить курсы Ex24: ' . (string) $ex24['error'],
			'context' => [
				'provider' => RATES_PROVIDER_EX24,
				'source'   => $source_param,
				'error'    => $ex24['error'] ?? null,
				'org_id'   => $org_id,
			],
		];
	}

	$comp_sber    = $ex24['sberbank_buy'];
	$comp_tinkoff = $ex24['tinkoff_buy'];
	$calculated   = rates_calculate( $comp_sber, $comp_tinkoff, $coeff, $coeff_type );

	$snapshot = [
		'competitor_sberbank' => $comp_sber,
		'competitor_tinkoff'  => $comp_tinkoff,
		'our_sberbank'        => $calculated['our_sberbank'],
		'our_tinkoff'         => $calculated['our_tinkoff'],
		'coefficient'         => $coeff,
		'coefficient_type'    => $coeff_type,
	];

	$last = rates_get_last_snapshot( (int) $pair->id, $org_id, RATES_PROVIDER_EX24, $source_param );
	if ( rates_snapshot_matches_history( $snapshot, $last ) ) {
		return array_merge(
			[
				'ok'         => true,
				'saved'      => false,
				'unchanged'  => true,
				'id'         => isset( $last['id'] ) ? (int) $last['id'] : 0,
				'pair_id'    => (int) $pair->id,
				'pair_code'  => $pair_code,
				'provider'   => RATES_PROVIDER_EX24,
				'source'     => $source_param,
				'created_at' => (string) ( $last['created_at'] ?? '' ),
				'checked_at' => current_time( 'mysql' ),
			],
			$snapshot
		);
	}

	$row_id = rates_save_snapshot(
		(int) $pair->id,
		$comp_sber,
		$comp_tinkoff,
		$calculated['our_sberbank'],
		$calculated['our_tinkoff'],
		$coeff,
		RATES_PROVIDER_EX24,
		$source_param,
		$org_id
	);

	if ( $row_id === false ) {
		return [
			'ok'      => false,
			'status'  => 500,
			'message' => 'Ошибка сохранения в базу данных.',
			'context' => [
				'where'   => 'rates_save_snapshot',
				'pair_id' => (int) $pair->id,
				'org_id'  => $org_id,
				'db_err'  => $wpdb->last_error,
			],
		];
	}

	$created_at = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT created_at FROM crm_rate_history WHERE id = %d AND organization_id = %d',
			$row_id,
			$org_id
		)
	);

	crm_log_entity( 'rate.snapshot_saved', 'rates', 'snapshot',
		'Сохранён снимок курсов ' . $pair_code,
		'rate',
		(int) $row_id,
		[
			'org_id'  => $org_id,
			'context' => [
				'org_id'              => $org_id,
				'pair'                => $pair_code,
				'provider'            => RATES_PROVIDER_EX24,
				'source_param'        => $source_param,
				'actor_source'        => $actor_source,
				'coefficient'         => $coeff,
				'coefficient_type'    => $coeff_type,
				'competitor_sberbank' => $comp_sber,
				'competitor_tinkoff'  => $comp_tinkoff,
				'our_sberbank'        => $calculated['our_sberbank'],
				'our_tinkoff'         => $calculated['our_tinkoff'],
			],
		]
	);

	return array_merge(
		[
			'ok'         => true,
			'saved'      => true,
			'unchanged'  => false,
			'id'         => (int) $row_id,
			'pair_id'    => (int) $pair->id,
			'pair_code'  => $pair_code,
			'provider'   => RATES_PROVIDER_EX24,
			'source'     => $source_param,
			'created_at' => is_string( $created_at ) ? $created_at : current_time( 'mysql' ),
			'checked_at' => current_time( 'mysql' ),
		],
		$snapshot
	);
}

// ═══════════════════════════════════════════════════════════════════════════════
// РЫНОЧНЫЕ КУРСЫ USDT — внешние источники
// Источники: rapira (USDT/RUB), bitkub (USDT/THB), binance_th (USDT/THB), cbr (USD/RUB)
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

// ── Bitkub: USDT/THB ──────────────────────────────────────────────────────────

/**
 * Получить курс USDT/THB с Bitkub (без кэша).
 * Bitkub именует пару THB_USDT, но значения — цена 1 USDT в THB (стандарт USDT/THB).
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
		return [ 'ok' => false, 'error' => $response->get_error_message(), 'lowestAsk' => null, 'highestBid' => null, 'symbol' => 'USDT/THB' ];
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) ) {
		return [ 'ok' => false, 'error' => 'Некорректный JSON от Bitkub.', 'lowestAsk' => null, 'highestBid' => null, 'symbol' => 'USDT/THB' ];
	}

	// Bitkub может оборачивать ответ в result{} (v3) или возвращать объект напрямую (v1)
	$pool = isset( $data['result'] ) && is_array( $data['result'] ) ? $data['result'] : $data;

	if ( ! isset( $pool['THB_USDT'] ) ) {
		return [ 'ok' => false, 'error' => 'THB_USDT не найден в ответе Bitkub.', 'lowestAsk' => null, 'highestBid' => null, 'symbol' => 'USDT/THB' ];
	}

	$pair = $pool['THB_USDT'];

	return [
		'ok'         => true,
		'error'      => null,
		'lowestAsk'  => isset( $pair['lowestAsk'] )  ? (float) $pair['lowestAsk']  : null,
		'highestBid' => isset( $pair['highestBid'] ) ? (float) $pair['highestBid'] : null,
		'symbol'     => 'USDT/THB',
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

// ── Kanyon USDT/RUB — история проверок курса ─────────────────────────────────

define( 'RATES_KANYON_COOLDOWN_TTL', 30 * MINUTE_IN_SECONDS );
define( 'RATES_KANYON_PAIR_CODE', 'RUB_USDT' );
define( 'RATES_KANYON_PROVIDER', 'kanyon' );
define( 'RATES_KANYON_TEST_AMOUNT_USDT', 100.0 );

function rates_kanyon_history_table_exists(): bool {
	static $exists = null;

	if ( $exists !== null ) {
		return $exists;
	}

	if ( function_exists( 'malibu_migrations_table_exists' ) ) {
		$exists = malibu_migrations_table_exists( 'crm_rate_history_rub_usdt' );
		return $exists;
	}

	global $wpdb;

	$found = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*)
			 FROM information_schema.tables
			 WHERE table_schema = DATABASE()
			   AND table_name = %s',
			'crm_rate_history_rub_usdt'
		)
	);

	$exists = (int) $found > 0;
	return $exists;
}

function rates_kanyon_normalize_source( string $source ): string {
	$source = sanitize_key( $source );
	return in_array( $source, [ 'web', 'telegram', 'cron' ], true ) ? $source : 'web';
}

function rates_kanyon_is_enabled_for_company( int $org_id ): bool {
	if ( $org_id <= 0 || ! class_exists( 'Fintech_Payment_Gateway' ) ) {
		return false;
	}

	$pair = rates_get_pair( RATES_KANYON_PAIR_CODE, $org_id );
	if ( ! $pair || ! (int) $pair->is_active ) {
		return false;
	}

	$active_provider = crm_fintech_normalize_provider_code(
		(string) crm_get_setting( 'fintech_active_provider', $org_id, '' )
	);

	if ( $active_provider !== RATES_KANYON_PROVIDER ) {
		return false;
	}

	return crm_fintech_is_provider_allowed( $org_id, RATES_KANYON_PROVIDER )
		&& crm_fintech_is_configured( $org_id );
}

/**
 * Секунд до следующей разрешённой проверки. 0 = можно прямо сейчас.
 */
function rates_kanyon_cooldown_remaining( int $org_id ): int {
	$expires = get_transient( 'me_kanyon_ck_' . $org_id );
	if ( $expires === false ) {
		return 0;
	}
	return max( 0, (int) $expires - time() );
}

function rates_kanyon_set_cooldown( int $org_id ): void {
	set_transient(
		'me_kanyon_ck_' . $org_id,
		time() + RATES_KANYON_COOLDOWN_TTL,
		RATES_KANYON_COOLDOWN_TTL
	);
}

/**
 * Последняя запись из истории Kanyon-проверок для компании.
 */
function rates_kanyon_get_last( int $org_id ): ?array {
	global $wpdb;

	if ( ! rates_kanyon_history_table_exists() ) {
		return null;
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT * FROM `crm_rate_history_rub_usdt`
			 WHERE organization_id = %d
			 ORDER BY created_at DESC
			 LIMIT 1',
			$org_id
		),
		ARRAY_A
	);

	return is_array( $row ) ? $row : null;
}

/**
 * История Kanyon-проверок (для таблицы на странице).
 */
function rates_kanyon_get_history( int $org_id, int $limit = 50 ): array {
	global $wpdb;

	if ( ! rates_kanyon_history_table_exists() ) {
		return [];
	}

	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT * FROM `crm_rate_history_rub_usdt`
			 WHERE organization_id = %d
			 ORDER BY created_at DESC
			 LIMIT %d',
			$org_id,
			$limit
		),
		ARRAY_A
	) ?: [];
}

/**
 * Сохранить запись Kanyon-проверки в историю.
 */
function rates_kanyon_save_row(
	int $org_id,
	float $kanyon_rate,
	?float $rapira_rate,
	float $coefficient,
	string $coeff_type,
	string $source = 'web',
	?int $payment_order_id = null
) {
	global $wpdb;

	if ( ! rates_kanyon_history_table_exists() ) {
		error_log( '[rates.kanyon] crm_rate_history_rub_usdt table is missing; history row was not saved.' );
		return false;
	}

	$source = rates_kanyon_normalize_source( $source );

	$data   = [
		'organization_id'  => $org_id,
		'kanyon_rate'      => $kanyon_rate,
		'coefficient'      => $coefficient,
		'coefficient_type' => $coeff_type,
		'source'           => $source,
	];
	$format = [ '%d', '%f', '%f', '%s', '%s' ];

	if ( $rapira_rate !== null ) {
		$data['rapira_rate'] = $rapira_rate;
		$format[]            = '%f';
	}

	if (
		$payment_order_id
		&& function_exists( 'malibu_migrations_column_exists' )
		&& malibu_migrations_column_exists( 'crm_rate_history_rub_usdt', 'payment_order_id' )
	) {
		$data['payment_order_id'] = $payment_order_id;
		$format[]                 = '%d';
	}

	$inserted = $wpdb->insert( 'crm_rate_history_rub_usdt', $data, $format );
	return $inserted ? (int) $wpdb->insert_id : false;
}

function rates_kanyon_mark_order_untracked( int $order_db_id, float $kanyon_rate, ?float $rapira_rate, string $source ): void {
	global $wpdb;

	if ( $order_db_id <= 0 ) {
		return;
	}

	$now  = current_time( 'mysql' );
	$meta = [
		'purpose'     => 'kanyon_rate_check',
		'source'      => rates_kanyon_normalize_source( $source ),
		'kanyon_rate' => $kanyon_rate,
		'rapira_rate' => $rapira_rate,
	];

	$wpdb->update(
		'crm_fintech_payment_orders',
		[
			'status_code'   => 'untracked',
			'status_reason' => 'Kanyon rate check order. Not tracked by payment polling.',
			'local_order_ref' => 'kanyon_rate_check',
			'notes'         => 'check_rate',
			'meta_json'     => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'updated_at'    => $now,
		],
		[ 'id' => $order_db_id ],
		[ '%s', '%s', '%s', '%s', '%s', '%s' ],
		[ '%d' ]
	);

	$wpdb->insert(
		'crm_fintech_payment_order_status_history',
		[
			'payment_order_id'     => $order_db_id,
			'status_code'          => 'untracked',
			'provider_status_code' => null,
			'source_code'          => 'rate_check',
			'message'              => 'Тестовый ордер Kanyon для проверки курса помечен как неотслеживаемый',
			'raw_payload_json'     => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'created_at'           => $now,
		],
		[ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
	);
}

/**
 * Запросить курс у Kanyon (тестовый ордер 100 USDT) + сохранить в историю.
 *
 * Возвращает массив ['ok', 'kanyon_rate'?, 'rapira_rate'?, 'error'?, 'cooldown'?].
 * Cooldown 30 мин на компанию.
 */
function rates_kanyon_fetch_and_record( int $org_id, string $source = 'web' ): array {
	$source = rates_kanyon_normalize_source( $source );

	if ( ! rates_kanyon_is_enabled_for_company( $org_id ) ) {
		return [ 'ok' => false, 'error' => 'Kanyon не активен или не настроен для этой компании.' ];
	}

	$remaining = rates_kanyon_cooldown_remaining( $org_id );
	if ( $remaining > 0 ) {
		$mins = (int) ceil( $remaining / 60 );
		return [
			'ok'       => false,
			'cooldown' => $remaining,
			'error'    => "Следующая проверка доступна через {$mins} мин. Лимит: раз в 30 минут.",
		];
	}

	Fintech_Payment_Gateway::set_company_id( $org_id );

	// Ставим cooldown перед внешним запросом: параллельные клики не должны создать два ордера.
	rates_kanyon_set_cooldown( $org_id );

	$result = Fintech_Payment_Gateway::create_invoice( RATES_KANYON_TEST_AMOUNT_USDT, null, 'check_rate' );

	if ( empty( $result['success'] ) ) {
		crm_log( 'rate.kanyon_check_failed', [
			'category'   => 'rates',
			'level'      => 'error',
			'action'     => 'rate_check',
			'message'    => 'Kanyon rate check: provider order creation failed',
			'is_success' => false,
			'org_id'     => $org_id,
			'context'    => [
				'company_id' => $org_id,
				'source'     => $source,
				'provider'   => $result['provider'] ?? RATES_KANYON_PROVIDER,
				'error'      => $result['error'] ?? null,
			],
		] );

		return [ 'ok' => false, 'error' => $result['error'] ?? 'Ошибка Kanyon API.' ];
	}

	$order_db_id = function_exists( 'crm_fintech_save_order' )
		? crm_fintech_save_order( $result, $org_id, 'rate_check', get_current_user_id() )
		: null;

	if ( ! $order_db_id ) {
		crm_log( 'rate.kanyon_check_local_order_save_failed', [
			'category'   => 'rates',
			'level'      => 'error',
			'action'     => 'rate_check',
			'message'    => 'Kanyon rate check: provider order was created but local untracked order was not saved',
			'is_success' => false,
			'org_id'     => $org_id,
			'context'    => [
				'company_id'        => $org_id,
				'provider_order_id' => $result['orderId'] ?? null,
				'merchant_order_id' => $result['merchantOrderId'] ?? null,
			],
		] );

		return [ 'ok' => false, 'error' => 'Kanyon создал тестовый ордер, но локально он не сохранён. Курс не записан.' ];
	}

	$payment_kopecks = isset( $result['paymentAmountRub'] ) ? (int) $result['paymentAmountRub'] : null;
	$order_cents     = isset( $result['orderAmountCents'] ) ? (int) $result['orderAmountCents'] : null;

	if ( ! $payment_kopecks || ! $order_cents ) {
		rates_kanyon_mark_order_untracked( (int) $order_db_id, 0.0, null, $source );

		crm_log( 'rate.kanyon_check_invalid_response', [
			'category'   => 'rates',
			'level'      => 'error',
			'action'     => 'rate_check',
			'message'    => 'Kanyon rate check: provider response has no payment amount',
			'is_success' => false,
			'org_id'     => $org_id,
			'context'    => [
				'company_id'        => $org_id,
				'payment_order_id'  => $order_db_id,
				'provider_order_id' => $result['orderId'] ?? null,
				'merchant_order_id' => $result['merchantOrderId'] ?? null,
			],
		] );

		return [ 'ok' => false, 'error' => 'Kanyon не вернул сумму платежа.' ];
	}

	// paymentAmountRub — рубли в копейках, orderAmountCents — USDT в центах.
	// Оба в минорных единицах своей валюты → частное = RUB/USDT.
	$kanyon_rate = round( $payment_kopecks / $order_cents, 4 );

	// Живой курс Rapira — получаем сервер-сайд, не доверяя фронту.
	$rapira      = rates_get_rapira();
	$rapira_rate = ( ! empty( $rapira['ok'] ) && isset( $rapira['bid'], $rapira['ask'] ) )
		? round( ( (float) $rapira['bid'] + (float) $rapira['ask'] ) / 2, 4 )
		: null;

	$rub_usdt_pair = rates_get_pair( 'RUB_USDT', $org_id );
	$coeff_full    = $rub_usdt_pair
		? rates_get_coefficient_full( (int) $rub_usdt_pair->id, RATES_PROVIDER_EX24, RATES_PROVIDER_SOURCE )
		: [ 'value' => 0.0, 'type' => 'absolute' ];

	rates_kanyon_mark_order_untracked( (int) $order_db_id, $kanyon_rate, $rapira_rate, $source );

	$history_id = rates_kanyon_save_row(
		$org_id,
		$kanyon_rate,
		$rapira_rate,
		(float) $coeff_full['value'],
		(string) $coeff_full['type'],
		$source,
		(int) $order_db_id
	);

	if ( $history_id === false ) {
		crm_log( 'rate.kanyon_check_save_failed', [
			'category'   => 'rates',
			'level'      => 'error',
			'action'     => 'rate_check',
			'message'    => 'Kanyon rate check: history row was not saved',
			'is_success' => false,
			'org_id'     => $org_id,
			'context'    => [
				'company_id'        => $org_id,
				'payment_order_id'  => $order_db_id,
				'provider_order_id' => $result['orderId'] ?? null,
			],
		] );

		return [ 'ok' => false, 'error' => 'Курс получен, но не сохранён в историю.' ];
	}

	crm_log( 'rate.kanyon_check_saved', [
		'category'    => 'rates',
		'level'       => 'info',
		'action'      => 'rate_check',
		'message'     => 'Сохранён проверочный курс Kanyon ₽ → ₮',
		'target_type' => 'rate_history_rub_usdt',
		'target_id'   => (int) $history_id,
		'is_success'  => true,
		'org_id'      => $org_id,
		'context'     => [
			'company_id'        => $org_id,
			'payment_order_id'  => $order_db_id,
			'provider_order_id' => $result['orderId'] ?? null,
			'merchant_order_id' => $result['merchantOrderId'] ?? null,
			'kanyon_rate'       => $kanyon_rate,
			'rapira_rate'       => $rapira_rate,
			'source'            => $source,
		],
	] );

	return [
		'ok'               => true,
		'history_id'       => (int) $history_id,
		'payment_order_id' => (int) $order_db_id,
		'kanyon_rate'      => $kanyon_rate,
		'rapira_rate'      => $rapira_rate,
		'source'           => $source,
	];
}

// ── История курсов для пары ───────────────────────────────────────────────────

/**
 * Получить историю курсов для пары.
 *
 * @param int $pair_id
 * @param int $limit
 * @return array
 */
function rates_get_history( int $pair_id, int $limit = 75, int $org_id = CRM_DEFAULT_ORG_ID ): array {
	global $wpdb;

	return $wpdb->get_results( $wpdb->prepare(
		'SELECT id, competitor_sberbank_buy, competitor_tinkoff_buy,
		        our_sberbank_rate, our_tinkoff_rate, coefficient_value, created_at
		 FROM crm_rate_history
		 WHERE organization_id = %d AND pair_id = %d
		 ORDER BY created_at DESC
		 LIMIT %d',
		$org_id,
		$pair_id,
		$limit
	), ARRAY_A ) ?: [];
}
