<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0039_create_root_management_pages',
	'title'    => 'Create WordPress pages: /root-users/, /root-roles/, /root-companies/, /root-offices/, /root-merchants/',
	'callback' => function () {
		$messages = [];

		$pages = [
			[
				'title'    => 'Root Users',
				'slug'     => 'root-users',
				'template' => 'page-root-users.php',
			],
			[
				'title'    => 'Root Roles',
				'slug'     => 'root-roles',
				'template' => 'page-root-roles.php',
			],
			[
				'title'    => 'Root Companies',
				'slug'     => 'root-companies',
				'template' => 'page-root-companies.php',
			],
			[
				'title'    => 'Root Offices',
				'slug'     => 'root-offices',
				'template' => 'page-root-offices.php',
			],
			[
				'title'    => 'Root Merchants',
				'slug'     => 'root-merchants',
				'template' => 'page-root-merchants.php',
			],
		];

		$ensure_page = static function ( array $page ) use ( &$messages ) {
			$existing = get_page_by_path( $page['slug'], OBJECT, 'page' );

			if ( $existing instanceof WP_Post ) {
				$current_template = get_post_meta( $existing->ID, '_wp_page_template', true );
				if ( $current_template !== $page['template'] ) {
					update_post_meta( $existing->ID, '_wp_page_template', $page['template'] );
					$messages[] = 'Page "' . $page['slug'] . '" already existed; template updated to ' . $page['template'] . '.';
				} else {
					$messages[] = 'Page "' . $page['slug'] . '" already exists with correct template.';
				}
				return;
			}

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
				return;
			}

			update_post_meta( $page_id, '_wp_page_template', $page['template'] );
			$messages[] = 'Created WordPress page "' . $page['title'] . '" (slug: ' . $page['slug'] . ', ID: ' . $page_id . ').';
			$messages[] = 'Assigned template: ' . $page['template'] . '.';
			$messages[] = 'URL: ' . get_permalink( $page_id );
		};

		foreach ( $pages as $page ) {
			$ensure_page( $page );
		}

		return [
			'summary'  => 'Root management WordPress pages ensured.',
			'messages' => $messages,
		];
	},
];
