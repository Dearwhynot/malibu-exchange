<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0014_create_page_create_order',
	'title'    => 'Create WordPress page: Create Order (slug: create-order, template: page-create-order.php)',
	'callback' => function () {
		// Защита от дублей: проверяем по slug
		$existing = get_page_by_path( 'create-order', OBJECT, 'page' );

		if ( $existing instanceof WP_Post ) {
			$current_template = get_post_meta( $existing->ID, '_wp_page_template', true );
			if ( $current_template !== 'page-create-order.php' ) {
				update_post_meta( $existing->ID, '_wp_page_template', 'page-create-order.php' );
				return [
					'summary'  => 'Page "create-order" already existed; template updated to page-create-order.php.',
					'messages' => [ 'Updated _wp_page_template on existing page ID ' . $existing->ID . '.' ],
				];
			}

			return [
				'summary'  => 'Page "create-order" already exists with correct template — skipped.',
				'messages' => [ 'Page ID: ' . $existing->ID . ', template: page-create-order.php.' ],
			];
		}

		// Создать страницу
		$page_id = wp_insert_post( [
			'post_title'   => 'Создать ордер',
			'post_name'    => 'create-order',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => 1,
			'post_content' => '',
			'post_excerpt' => '',
		], true );

		if ( is_wp_error( $page_id ) ) {
			return new WP_Error(
				'create_page_failed',
				'Failed to create page "create-order": ' . $page_id->get_error_message()
			);
		}

		update_post_meta( $page_id, '_wp_page_template', 'page-create-order.php' );

		return [
			'summary'  => 'WordPress page "Создать ордер" (slug: create-order) created.',
			'messages' => [
				'Created page ID: ' . $page_id . '.',
				'Assigned template: page-create-order.php.',
				'URL: ' . get_permalink( $page_id ),
			],
		];
	},
];
