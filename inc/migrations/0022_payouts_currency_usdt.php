<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0022_payouts_currency_usdt',
	'title'    => 'Change crm_acquirer_payouts default currency from RUB to USDT',
	'callback' => function () {
		global $wpdb;

		$wpdb->query(
			"ALTER TABLE `crm_acquirer_payouts`
			 MODIFY COLUMN `currency_code` varchar(16) NOT NULL DEFAULT 'USDT'"
		);

		// Исправить уже вставленные строки с RUB (если были тестовые)
		$updated = $wpdb->query( "UPDATE `crm_acquirer_payouts` SET `currency_code` = 'USDT' WHERE `currency_code` = 'RUB'" );

		return [
			'summary'  => 'crm_acquirer_payouts: default currency set to USDT.',
			'messages' => [
				'Column currency_code default changed to USDT.',
				'Rows updated from RUB→USDT: ' . (int) $updated . '.',
			],
		];
	},
];
