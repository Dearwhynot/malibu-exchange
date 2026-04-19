<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0023_payouts_add_receipt',
	'title'    => 'Add receipt_filename column to crm_acquirer_payouts',
	'callback' => function () {
		global $wpdb;

		// Добавляем колонку только если её ещё нет
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `crm_acquirer_payouts`", 0 );
		if ( ! in_array( 'receipt_filename', $cols, true ) ) {
			$wpdb->query(
				"ALTER TABLE `crm_acquirer_payouts`
				 ADD COLUMN `receipt_filename` varchar(255) DEFAULT NULL
				   COMMENT 'имя файла квитанции в uploadbotfiles/payout-receipts/'
				 AFTER `notes`"
			);
		}

		// Создаём папку на диске, если её нет
		$dir = get_template_directory() . '/uploadbotfiles/payout-receipts/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Блокируем прямой листинг
		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Options -Indexes\n" );
		}

		return [
			'summary'  => 'receipt_filename column added; payout-receipts dir ensured.',
			'messages' => [
				'Column receipt_filename added to crm_acquirer_payouts.',
				'Directory uploadbotfiles/payout-receipts/ ensured.',
			],
		];
	},
];
