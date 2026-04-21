<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0025_fix_company_scope_defaults',
	'title'    => 'Fix default_company_id defaults and normalize legacy company assignments',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		if ( ! malibu_migrations_column_exists( 'crm_user_accounts', 'default_company_id' ) ) {
			return [
				'summary'  => 'crm_user_accounts.default_company_id missing — skipped.',
				'messages' => [ 'Column default_company_id not found in crm_user_accounts.' ],
			];
		}

		$wpdb->query(
			"ALTER TABLE `crm_user_accounts`
			 MODIFY COLUMN `default_company_id` int(10) UNSIGNED NOT NULL DEFAULT 0"
		);
		$messages[] = 'default_company_id default changed from 1 to 0.';

		// Сначала поднимаем legacy-назначения из shortcut-колонки в primary-row, если компания существует.
		$wpdb->query(
			"INSERT INTO `crm_user_companies`
				(`user_id`, `company_id`, `is_company_admin`, `is_primary`, `status`, `assigned_by_user_id`)
			 SELECT ua.user_id, ua.default_company_id, 0, 1, 'active', NULL
			 FROM `crm_user_accounts` ua
			 JOIN `crm_companies` c
			   ON c.id = ua.default_company_id
			  AND c.status = 'active'
			 LEFT JOIN `crm_user_companies` uc
			   ON uc.user_id = ua.user_id
			  AND uc.is_primary = 1
			  AND uc.status = 'active'
			 WHERE ua.user_id <> 1
			   AND ua.default_company_id > 0
			   AND uc.id IS NULL
			 ON DUPLICATE KEY UPDATE
			   `is_primary` = VALUES(`is_primary`),
			   `status` = VALUES(`status`),
			   `assigned_by_user_id` = VALUES(`assigned_by_user_id`)"
		);
		$messages[] = 'Legacy default_company_id values synced into crm_user_companies where needed.';

		$aligned = $wpdb->query(
			"UPDATE `crm_user_accounts` ua
			 JOIN `crm_user_companies` uc
			   ON uc.user_id = ua.user_id
			  AND uc.is_primary = 1
			  AND uc.status = 'active'
			 SET ua.`default_company_id` = uc.`company_id`
			 WHERE ua.user_id <> 1
			   AND ua.`default_company_id` <> uc.`company_id`"
		);
		$messages[] = 'default_company_id aligned with active primary company (' . (int) $aligned . ' row(s)).';

		$root_reset = $wpdb->query(
			"UPDATE `crm_user_accounts`
			 SET `default_company_id` = 0
			 WHERE `user_id` = 1
			   AND `default_company_id` <> 0"
		);
		$messages[] = 'Root default_company_id normalized to 0 (' . (int) $root_reset . ' row(s)).';

		$zeroed = $wpdb->query(
			"UPDATE `crm_user_accounts` ua
			 LEFT JOIN `crm_user_companies` uc
			   ON uc.user_id = ua.user_id
			  AND uc.is_primary = 1
			  AND uc.status = 'active'
			 SET ua.`default_company_id` = 0
			 WHERE ua.user_id <> 1
			   AND uc.id IS NULL
			   AND ua.`default_company_id` <> 0"
		);
		$messages[] = 'Unassigned users normalized to default_company_id = 0 (' . (int) $zeroed . ' row(s)).';

		return [
			'summary'  => 'Company scope defaults normalized.',
			'messages' => $messages,
		];
	},
];
