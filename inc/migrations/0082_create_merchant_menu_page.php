<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0082_create_merchant_menu_page',
	'title'    => 'Create public Merchant Menu page',
	'callback' => function () {
		$messages = [];
		$page_def = [
			'slug'     => 'merchant-menu',
			'title'    => 'Merchant Menu',
			'template' => 'page-merchant-menu.php',
			'content'  => implode( "\n\n", [
				'<!-- wp:heading {"level":2} --><h2>Merchant Menu</h2><!-- /wp:heading -->',
				'<!-- wp:paragraph --><p>Публичная mobile-first страница для будущего Telegram merchant menu / webapp flow.</p><!-- /wp:paragraph -->',
			] ),
		];

		$existing = get_page_by_path( $page_def['slug'], OBJECT, 'page' );

		if ( $existing instanceof WP_Post ) {
			$current_template = (string) get_post_meta( $existing->ID, '_wp_page_template', true );
			if ( $current_template !== $page_def['template'] ) {
				update_post_meta( $existing->ID, '_wp_page_template', $page_def['template'] );
				$messages[] = 'Updated template for page "' . $page_def['slug'] . '" to ' . $page_def['template'] . '.';
			} else {
				$messages[] = 'Page "' . $page_def['slug'] . '" already exists with correct template.';
			}

			$current_content = (string) get_post_field( 'post_content', $existing->ID );
			if ( trim( wp_strip_all_tags( $current_content ) ) === '' ) {
				wp_update_post( [
					'ID'           => (int) $existing->ID,
					'post_content' => $page_def['content'],
				] );
				$messages[] = 'Filled empty content for page "' . $page_def['slug'] . '".';
			}

			return [
				'summary'  => 'Public Merchant Menu page ensured.',
				'messages' => $messages,
			];
		}

		$page_id = wp_insert_post( [
			'post_title'   => $page_def['title'],
			'post_name'    => $page_def['slug'],
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => 1,
			'post_content' => $page_def['content'],
			'post_excerpt' => '',
		], true );

		if ( is_wp_error( $page_id ) ) {
			$messages[] = 'ERROR: failed to create page "' . $page_def['slug'] . '": ' . $page_id->get_error_message();
		} else {
			update_post_meta( $page_id, '_wp_page_template', $page_def['template'] );
			$messages[] = 'Created page "' . $page_def['slug'] . '" (ID ' . $page_id . ') with template ' . $page_def['template'] . '.';
		}

		return [
			'summary'  => 'Public Merchant Menu page ensured.',
			'messages' => $messages,
		];
	},
];
