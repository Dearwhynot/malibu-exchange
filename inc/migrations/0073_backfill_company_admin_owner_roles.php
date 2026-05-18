<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0073_backfill_company_admin_owner_roles',
	'title'    => 'Mark first company users as company admins and ensure owner role',
	'callback' => function () {
		global $wpdb;

		$messages             = [];
		$admin_flags_updated  = 0;
		$owner_roles_synced   = 0;
		$owner_role_id        = crm_get_role_id_by_code( 'owner' );
		$company_ids          = $wpdb->get_col( "SELECT id FROM crm_companies WHERE id > 0 ORDER BY id ASC" ) ?: [];

		if ( $owner_role_id <= 0 ) {
			return [
				'summary'  => 'Skipped company admin owner-role backfill: owner role not found.',
				'messages' => [ 'WARNING: crm_roles.code=owner not found.' ],
			];
		}

		foreach ( array_map( 'intval', $company_ids ) as $company_id ) {
			if ( $company_id <= 0 ) {
				continue;
			}

			$first_user_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT user_id
					 FROM crm_user_companies
					 WHERE company_id = %d
					   AND is_primary = 1
					   AND status = 'active'
					 ORDER BY created_at ASC, id ASC
					 LIMIT 1",
					$company_id
				)
			);

			if ( $first_user_id <= 0 || crm_is_root( $first_user_id ) ) {
				continue;
			}

			$membership = crm_get_user_company_membership( $first_user_id, $company_id );
			if ( ! $membership ) {
				continue;
			}

			if ( (int) ( $membership->is_company_admin ?? 0 ) !== 1 ) {
				$wpdb->update(
					'crm_user_companies',
					[ 'is_company_admin' => 1 ],
					[ 'id' => (int) $membership->id ],
					[ '%d' ],
					[ '%d' ]
				);
				$admin_flags_updated++;
				$messages[] = 'Company #' . $company_id . ': user #' . $first_user_id . ' marked as is_company_admin.';
			}

			$current_role_ids = array_map(
				static fn( $role ): int => (int) $role->id,
				crm_get_user_roles( $first_user_id )
			);

			if ( ! in_array( $owner_role_id, $current_role_ids, true ) ) {
				crm_assign_roles_preserving_codes( $first_user_id, $current_role_ids, [ 'owner' ], 1 );
				$owner_roles_synced++;
				$messages[] = 'Company #' . $company_id . ': owner role granted to first user #' . $first_user_id . '.';
			}
		}

		return [
			'summary'  => 'Backfilled company admins and owner roles.',
			'messages' => array_merge(
				[
					'Company admin flags updated: ' . $admin_flags_updated . '.',
					'Owner roles synced: ' . $owner_roles_synced . '.',
				],
				$messages
			),
		];
	},
];
