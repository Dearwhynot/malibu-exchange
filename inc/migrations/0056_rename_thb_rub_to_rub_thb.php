<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0056_rename_thb_rub_to_rub_thb',
	'title'    => 'Rename legacy THB_RUB rate pair code to canonical RUB_THB',
	'callback' => function () {
		global $wpdb;

		$legacy_before = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `crm_rate_pairs` WHERE `code` = 'THB_RUB'"
		);
		$canonical_before = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `crm_rate_pairs` WHERE `code` = 'RUB_THB'"
		);

		$messages = [
			sprintf( 'Legacy rows before rename: %d.', $legacy_before ),
			sprintf( 'Canonical rows before rename: %d.', $canonical_before ),
		];

		if ( $legacy_before > 0 ) {
			$updated = $wpdb->query(
				"UPDATE `crm_rate_pairs`
				 SET `code` = 'RUB_THB',
				     `title` = 'RUB/THB'
				 WHERE `code` = 'THB_RUB'"
			);

			if ( $updated === false ) {
				return new WP_Error(
					'rename_pair_failed',
					'Не удалось переименовать legacy pair code THB_RUB -> RUB_THB: ' . $wpdb->last_error
				);
			}

			$messages[] = sprintf( 'Renamed legacy rows: %d.', (int) $updated );
		} else {
			$messages[] = 'Legacy rows not found; rename skipped.';
		}

		$wpdb->query(
			"UPDATE `crm_rate_pairs`
			 SET `title` = 'RUB/THB'
			 WHERE `code` = 'RUB_THB'"
		);

		$legacy_after = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `crm_rate_pairs` WHERE `code` = 'THB_RUB'"
		);
		$canonical_after = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `crm_rate_pairs` WHERE `code` = 'RUB_THB'"
		);

		$messages[] = sprintf( 'Legacy rows after rename: %d.', $legacy_after );
		$messages[] = sprintf( 'Canonical rows after rename: %d.', $canonical_after );

		return [
			'summary'  => 'Legacy pair code THB_RUB renamed to RUB_THB.',
			'messages' => $messages,
		];
	},
];
