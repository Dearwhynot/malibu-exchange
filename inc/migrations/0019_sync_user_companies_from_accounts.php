<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Populate crm_user_companies for users who have default_company_id set in
 * crm_user_accounts but no primary row in crm_user_companies.
 *
 * This situation arises when users were created before migration 0010 (which
 * added default_company_id with DEFAULT 1) or when the shortcut column was set
 * directly without going through crm_assign_user_to_company().
 */
return [
	'key'      => '0019_sync_user_companies_from_accounts',
	'title'    => 'Sync crm_user_companies from crm_user_accounts.default_company_id for orphaned users',
	'callback' => function () {
		global $wpdb;

		$orphans = $wpdb->get_results( "
			SELECT ua.user_id, ua.default_company_id
			FROM crm_user_accounts ua
			WHERE ua.default_company_id > 0
			  AND ua.user_id != 1
			  AND NOT EXISTS (
			      SELECT 1 FROM crm_user_companies uc
			      WHERE uc.user_id = ua.user_id
			        AND uc.is_primary = 1
			        AND uc.status = 'active'
			  )
		" );

		$synced = 0;
		foreach ( $orphans as $row ) {
			$uid  = (int) $row->user_id;
			$coid = (int) $row->default_company_id;

			// Skip if the company doesn't exist or is inactive.
			$co_ok = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM crm_companies WHERE id = %d AND status = 'active'",
				$coid
			) );
			if ( ! $co_ok ) {
				continue;
			}

			// If an inactive/blocked row already exists — reactivate it.
			$existing_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM crm_user_companies WHERE user_id = %d AND company_id = %d",
				$uid, $coid
			) );

			if ( $existing_id ) {
				$wpdb->update(
					'crm_user_companies',
					[ 'is_primary' => 1, 'status' => 'active' ],
					[ 'id' => (int) $existing_id ],
					[ '%d', '%s' ],
					[ '%d' ]
				);
			} else {
				$wpdb->insert(
					'crm_user_companies',
					[
						'user_id'          => $uid,
						'company_id'       => $coid,
						'is_primary'       => 1,
						'is_company_admin' => 0,
						'status'           => 'active',
					],
					[ '%d', '%d', '%d', '%d', '%s' ]
				);
			}

			$synced++;
		}

		return [
			'summary'  => "Synced crm_user_companies for {$synced} orphaned user(s).",
			'messages' => [ "Created/reactivated {$synced} crm_user_companies row(s) from default_company_id." ],
		];
	},
];
