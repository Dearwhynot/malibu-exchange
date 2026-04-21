<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0029_create_page_root_dashboard',
	'title'    => 'Create WordPress page: Root Dashboard (slug: root-dashboard, template: page-root-dashboard.php)',
	'callback' => function () {
		$existing = get_page_by_path( 'root-dashboard', OBJECT, 'page' );

		if ( $existing instanceof WP_Post ) {
			$current_template = get_post_meta( $existing->ID, '_wp_page_template', true );

			if ( $current_template !== 'page-root-dashboard.php' ) {
				update_post_meta( $existing->ID, '_wp_page_template', 'page-root-dashboard.php' );

				return [
					'summary'  => 'Page "root-dashboard" already existed; template updated to page-root-dashboard.php.',
					'messages' => [ 'Updated _wp_page_template on existing page ID ' . $existing->ID . '.' ],
				];
			}

			return [
				'summary'  => 'Page "root-dashboard" already exists with correct template — skipped.',
				'messages' => [ 'Page ID: ' . $existing->ID . ', template: page-root-dashboard.php.' ],
			];
		}

		$page_id = wp_insert_post( [
			'post_title'   => 'Root Dashboard',
			'post_name'    => 'root-dashboard',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => 1,
			'post_content' => '',
			'post_excerpt' => '',
		], true );

		if ( is_wp_error( $page_id ) ) {
			return new WP_Error(
				'create_root_dashboard_page_failed',
				'Failed to create page "root-dashboard": ' . $page_id->get_error_message()
			);
		}

		update_post_meta( $page_id, '_wp_page_template', 'page-root-dashboard.php' );

		return [
			'summary'  => 'WordPress page "Root Dashboard" (slug: root-dashboard) created.',
			'messages' => [
				'Created page ID: ' . $page_id . '.',
				'Assigned template: page-root-dashboard.php.',
				'URL: ' . get_permalink( $page_id ),
			],
		];
	},
];
