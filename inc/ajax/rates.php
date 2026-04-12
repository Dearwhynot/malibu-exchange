<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX: сохранить снимок курсов в историю.
 * Action: me_rates_save
 * Доступ: только залогиненные с правом rates.edit
 */
add_action( 'wp_ajax_me_rates_save', 'me_ajax_rates_save' );

function me_ajax_rates_save(): void {
	check_ajax_referer( 'me_rates_save', 'nonce' );

	if ( ! crm_user_has_permission( get_current_user_id(), 'rates.edit' ) ) {
		wp_send_json_error( [ 'message' => 'Недостаточно прав.' ], 403 );
	}

	$pair = rates_get_pair( RATES_PAIR_CODE, CRM_DEFAULT_ORG_ID );
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
		CRM_DEFAULT_ORG_ID
	);

	if ( $row_id === false ) {
		wp_send_json_error( [ 'message' => 'Ошибка сохранения в базу данных.' ], 500 );
	}

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
