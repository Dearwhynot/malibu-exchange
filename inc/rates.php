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
