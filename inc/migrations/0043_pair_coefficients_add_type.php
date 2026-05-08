<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0043_pair_coefficients_add_type',
	'title'    => 'Add coefficient_type ENUM(absolute,percent) to crm_pair_coefficients',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		$column_exists = $wpdb->get_var(
			"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE()
			   AND TABLE_NAME = 'crm_pair_coefficients'
			   AND COLUMN_NAME = 'coefficient_type'"
		);

		if ( (int) $column_exists === 0 ) {
			$wpdb->query(
				"ALTER TABLE `crm_pair_coefficients`
				 ADD COLUMN `coefficient_type` ENUM('absolute','percent') NOT NULL DEFAULT 'absolute' AFTER `coefficient`"
			);
			$messages[] = 'Added column coefficient_type ENUM(absolute,percent) DEFAULT absolute.';
		} else {
			$messages[] = 'Column coefficient_type already exists, skipped.';
		}

		return [
			'summary'  => 'crm_pair_coefficients schema updated with coefficient_type.',
			'messages' => $messages,
		];
	},
];
