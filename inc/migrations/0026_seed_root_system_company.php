<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0026_seed_root_system_company',
	'title'    => 'Ensure root system company id 0 exists in crm_companies',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		if ( ! malibu_migrations_table_exists( 'crm_companies' ) ) {
			return [
				'summary'  => 'crm_companies missing — skipped.',
				'messages' => [ 'Table crm_companies not found.' ],
			];
		}

		$previous_sql_mode = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
		$has_zero_mode     = strpos( ',' . $previous_sql_mode . ',', ',NO_AUTO_VALUE_ON_ZERO,' ) !== false;
		$active_sql_mode   = $previous_sql_mode;

		if ( ! $has_zero_mode ) {
			$active_sql_mode = trim( $previous_sql_mode . ',NO_AUTO_VALUE_ON_ZERO', ',' );
			$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', $active_sql_mode ) );
			$messages[] = 'SESSION sql_mode temporarily extended with NO_AUTO_VALUE_ON_ZERO.';
		} else {
			$messages[] = 'SESSION sql_mode already contains NO_AUTO_VALUE_ON_ZERO.';
		}

		try {
			$row_exists = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `crm_companies` WHERE `id` = 0' ) > 0;

			if ( $row_exists ) {
				$wpdb->update(
					'crm_companies',
					[
						'code'             => 'root_system',
						'name'             => 'Root System',
						'legal_name'       => 'Root System',
						'default_timezone' => 'Asia/Bangkok',
						'status'           => 'active',
						'note'             => 'System company for root scope (company_id = 0). Hidden from business company lists.',
					],
					[ 'id' => 0 ],
					[ '%s', '%s', '%s', '%s', '%s', '%s' ],
					[ '%d' ]
				);
				$messages[] = 'crm_companies: root system row id=0 refreshed.';
			} else {
				$code     = 'root_system';
				$suffix   = 0;

				while ( (int) $wpdb->get_var( $wpdb->prepare(
					'SELECT COUNT(*) FROM `crm_companies` WHERE `code` = %s AND `id` <> 0',
					$code
				) ) > 0 ) {
					$suffix++;
					$code = 'root_system_' . $suffix;
				}

				$inserted = $wpdb->insert(
					'crm_companies',
					[
						'id'               => 0,
						'code'             => $code,
						'name'             => 'Root System',
						'legal_name'       => 'Root System',
						'default_timezone' => 'Asia/Bangkok',
						'status'           => 'active',
						'note'             => 'System company for root scope (company_id = 0). Hidden from business company lists.',
					],
					[ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
				);

				if ( ! $inserted ) {
					return new WP_Error(
						'root_system_company_insert_failed',
						'Unable to insert root system company row.',
						[
							'messages' => [
								'Insert into crm_companies failed: ' . (string) $wpdb->last_error,
							],
						]
					);
				}

				$messages[] = 'crm_companies: root system row id=0 inserted.';
			}
		} finally {
			if ( ! $has_zero_mode ) {
				$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', $previous_sql_mode ) );
				$messages[] = 'SESSION sql_mode restored.';
			}
		}

		return [
			'summary'  => 'Root system company id=0 ensured.',
			'messages' => $messages,
		];
	},
];
