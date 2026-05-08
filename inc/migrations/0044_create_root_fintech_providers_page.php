<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0044_create_root_fintech_providers_page',
	'title'    => 'Create WordPress page: /root-fintech-providers/ (root-only управление доступом к платёжным системам по компаниям)',
	'callback' => function () {
		$messages = [];

		$page = [
			'title'    => 'Root Fintech Providers',
			'slug'     => 'root-fintech-providers',
			'template' => 'page-root-fintech-providers.php',
		];

		$existing = get_page_by_path( $page['slug'], OBJECT, 'page' );

		if ( $existing instanceof WP_Post ) {
			$current_template = get_post_meta( $existing->ID, '_wp_page_template', true );
			if ( $current_template !== $page['template'] ) {
				update_post_meta( $existing->ID, '_wp_page_template', $page['template'] );
				$messages[] = 'Page "' . $page['slug'] . '" already existed; template updated to ' . $page['template'] . '.';
			} else {
				$messages[] = 'Page "' . $page['slug'] . '" already exists with correct template.';
			}
		} else {
			$page_id = wp_insert_post(
				[
					'post_title'   => $page['title'],
					'post_name'    => $page['slug'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_author'  => 1,
					'post_content' => '',
					'post_excerpt' => '',
				],
				true
			);

			if ( is_wp_error( $page_id ) ) {
				$messages[] = 'ERROR: failed to create /' . $page['slug'] . '/ page: ' . $page_id->get_error_message();
			} else {
				update_post_meta( $page_id, '_wp_page_template', $page['template'] );
				$messages[] = 'Created WordPress page "' . $page['title'] . '" (slug: ' . $page['slug'] . ', ID: ' . $page_id . ').';
				$messages[] = 'Assigned template: ' . $page['template'] . '.';
				$messages[] = 'URL: ' . get_permalink( $page_id );
			}
		}

		return [
			'summary'  => 'Root fintech providers page ensured.',
			'messages' => $messages,
		];
	},
];
