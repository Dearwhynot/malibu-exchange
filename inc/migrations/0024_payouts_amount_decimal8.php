<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0024_payouts_amount_decimal8',
	'title'    => 'Change crm_acquirer_payouts.amount to decimal(20,8) for USDT precision',
	'callback' => function () {
		global $wpdb;

		$wpdb->query(
			"ALTER TABLE `crm_acquirer_payouts`
			 MODIFY COLUMN `amount` decimal(20,8) NOT NULL COMMENT 'сумма выплаты от ЭП (USDT)'"
		);

		return [
			'summary'  => 'crm_acquirer_payouts.amount changed to decimal(20,8).',
			'messages' => [ 'Column amount now supports 8 decimal places for USDT.' ],
		];
	},
];
