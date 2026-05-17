<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0062_create_crm_company_withdrawals',
	'title'    => 'Create company withdrawals layer and page',
	'callback' => function () {
		global $wpdb;

		$messages = crm_rbac_sync();

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `crm_company_withdrawals` (
			  `id`                 bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `company_id`         int(10) UNSIGNED    NOT NULL,
			  `amount_usdt`        decimal(20,8)       NOT NULL,
			  `network`            varchar(32)         NOT NULL DEFAULT 'TRC20',
			  `wallet_address`     varchar(255)        DEFAULT NULL,
			  `tx_hash`            varchar(255)        DEFAULT NULL,
			  `receipt_filename`   varchar(255)        DEFAULT NULL,
			  `notes`              text                DEFAULT NULL,
			  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
			  `created_at`         datetime            NOT NULL DEFAULT current_timestamp(),
			  PRIMARY KEY (`id`),
			  KEY `idx_company_withdrawals_company_created` (`company_id`,`created_at`),
			  KEY `idx_company_withdrawals_created_by` (`created_by_user_id`),
			  CONSTRAINT `fk_company_withdrawals_company`
			    FOREIGN KEY (`company_id`) REFERENCES `crm_companies` (`id`)
			    ON DELETE RESTRICT ON UPDATE CASCADE
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
		);
		$messages[] = 'crm_company_withdrawals: table ensured.';

		$dir = get_template_directory() . '/uploadbotfiles/company-withdrawal-receipts/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			$messages[] = 'Directory uploadbotfiles/company-withdrawal-receipts/ ensured.';
		}
		if ( is_dir( $dir ) && ! file_exists( $dir . '.htaccess' ) ) {
			@file_put_contents( $dir . '.htaccess', "Options -Indexes\n" );
			$messages[] = 'Directory listing disabled for company withdrawal receipts.';
		}

		$existing = get_page_by_path( 'company-withdrawals', OBJECT, 'page' );
		if ( $existing instanceof WP_Post ) {
			$current_template = get_post_meta( $existing->ID, '_wp_page_template', true );
			if ( $current_template !== 'page-company-withdrawals.php' ) {
				update_post_meta( $existing->ID, '_wp_page_template', 'page-company-withdrawals.php' );
				$messages[] = 'Page "company-withdrawals" already existed; template updated to page-company-withdrawals.php.';
			} else {
				$messages[] = 'Page "company-withdrawals" already exists with correct template.';
			}
		} else {
			$page_id = wp_insert_post( [
				'post_title'   => 'Выводы компании',
				'post_name'    => 'company-withdrawals',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => 1,
				'post_content' => '',
				'post_excerpt' => '',
			], true );

			if ( is_wp_error( $page_id ) ) {
				$messages[] = 'ERROR: failed to create /company-withdrawals/ page: ' . $page_id->get_error_message();
			} else {
				update_post_meta( $page_id, '_wp_page_template', 'page-company-withdrawals.php' );
				$messages[] = 'Created WordPress page "Выводы компании" (slug: company-withdrawals, ID: ' . $page_id . ').';
				$messages[] = 'Assigned template: page-company-withdrawals.php.';
				$messages[] = 'URL: ' . get_permalink( $page_id );
			}
		}

		return [
			'summary'  => 'Company withdrawals layer ensured.',
			'messages' => $messages,
		];
	},
];
