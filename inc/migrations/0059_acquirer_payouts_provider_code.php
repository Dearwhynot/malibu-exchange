<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0059_acquirer_payouts_provider_code',
	'title'    => 'Add provider_code to crm_acquirer_payouts',
	'callback' => function () {
		global $wpdb;

		$messages = [];

		if ( ! malibu_migrations_column_exists( 'crm_acquirer_payouts', 'provider_code' ) ) {
			$wpdb->query(
				"ALTER TABLE `crm_acquirer_payouts`
				 ADD COLUMN `provider_code` varchar(32) DEFAULT NULL
				 AFTER `company_id`"
			);
			$messages[] = 'crm_acquirer_payouts: added column `provider_code`.';
		} else {
			$messages[] = 'crm_acquirer_payouts: column `provider_code` already exists.';
		}

		if ( malibu_migrations_column_exists( 'crm_acquirer_payouts', 'provider_code' ) ) {
			$backfilled = $wpdb->query(
				"UPDATE `crm_acquirer_payouts` p
				 LEFT JOIN `crm_settings` s
				   ON s.`org_id` = p.`company_id`
				  AND s.`setting_key` = 'fintech_active_provider'
				 SET p.`provider_code` = CASE
				   WHEN LOWER(TRIM(COALESCE(s.`setting_value`, ''))) IN ('kanyon', 'doverka')
				     THEN LOWER(TRIM(s.`setting_value`))
				   ELSE 'kanyon'
				 END
				 WHERE p.`provider_code` IS NULL
				    OR p.`provider_code` = ''
				    OR p.`provider_code` NOT IN ('kanyon', 'doverka')"
			);
			$messages[] = 'crm_acquirer_payouts: provider_code backfill affected ' . (int) $backfilled . ' row(s).';

			$wpdb->query(
				"ALTER TABLE `crm_acquirer_payouts`
				 MODIFY COLUMN `provider_code` varchar(32) NOT NULL DEFAULT 'kanyon'"
			);
			$messages[] = 'crm_acquirer_payouts: provider_code set NOT NULL with default `kanyon`.';
		}

		if ( ! malibu_migrations_index_exists( 'crm_acquirer_payouts', 'idx_acquirer_payouts_company_provider_created' ) ) {
			$wpdb->query(
				"ALTER TABLE `crm_acquirer_payouts`
				 ADD KEY `idx_acquirer_payouts_company_provider_created` (`company_id`, `provider_code`, `created_at`)"
			);
			$messages[] = 'crm_acquirer_payouts: added company-provider-created index.';
		} else {
			$messages[] = 'crm_acquirer_payouts: company-provider-created index already exists.';
		}

		return [
			'summary'  => 'crm_acquirer_payouts provider_code ensured.',
			'messages' => $messages,
		];
	},
];
