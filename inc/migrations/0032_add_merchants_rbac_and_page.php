<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0032_add_merchants_rbac_and_page',
	'title'    => 'Sync RBAC for merchants.* and create WordPress page: Merchants (slug: merchants, template: page-merchants.php)',
	'callback' => function () {
		$messages = crm_rbac_sync();

		$existing = get_page_by_path( 'merchants', OBJECT, 'page' );

		if ( $existing instanceof WP_Post ) {
			$current_template = get_post_meta( $existing->ID, '_wp_page_template', true );
			if ( $current_template !== 'page-merchants.php' ) {
				update_post_meta( $existing->ID, '_wp_page_template', 'page-merchants.php' );
				$messages[] = 'Page "merchants" already existed; template updated to page-merchants.php.';
			} else {
				$messages[] = 'Page "merchants" already exists with correct template.';
			}
		} else {
			$page_id = wp_insert_post( [
				'post_title'   => 'Мерчанты',
				'post_name'    => 'merchants',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => 1,
				'post_content' => '',
				'post_excerpt' => '',
			], true );

			if ( is_wp_error( $page_id ) ) {
				$messages[] = 'ERROR: failed to create /merchants/ page: ' . $page_id->get_error_message();
			} else {
				update_post_meta( $page_id, '_wp_page_template', 'page-merchants.php' );
				$messages[] = 'Created WordPress page "Мерчанты" (slug: merchants, ID: ' . $page_id . ').';
				$messages[] = 'Assigned template: page-merchants.php.';
				$messages[] = 'URL: ' . get_permalink( $page_id );
			}
		}

		return [
			'summary'  => 'merchants.* RBAC synced; /merchants/ WordPress page ensured.',
			'messages' => $messages,
		];
	},
];
