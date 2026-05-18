<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0080_create_merchant_api_public_docs_pages',
	'title'    => 'Create public Merchant API docs and console pages',
	'callback' => function () {
		$messages = [];
		$pages    = [
			[
				'slug'     => 'merchant-api',
				'title'    => 'Merchant API',
				'template' => 'page-merchant-api.php',
				'content'  => implode( "\n\n", [
					'<!-- wp:heading {"level":2} --><h2>Merchant API</h2><!-- /wp:heading -->',
					'<!-- wp:paragraph --><p>Публичная документация Merchant API рендерится из OpenAPI spec и не требует ручной вёрстки endpoint-ов.</p><!-- /wp:paragraph -->',
				] ),
			],
			[
				'slug'     => 'merchant-api-console',
				'title'    => 'Merchant API Console',
				'template' => 'page-merchant-api-console.php',
				'content'  => implode( "\n\n", [
					'<!-- wp:heading {"level":2} --><h2>Merchant API Console</h2><!-- /wp:heading -->',
					'<!-- wp:paragraph --><p>Интерактивная console-page для ручных запросов к Merchant API на базе Swagger UI.</p><!-- /wp:paragraph -->',
				] ),
			],
		];

		foreach ( $pages as $page_def ) {
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

				continue;
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
				continue;
			}

			update_post_meta( $page_id, '_wp_page_template', $page_def['template'] );
			$messages[] = 'Created page "' . $page_def['slug'] . '" (ID ' . $page_id . ') with template ' . $page_def['template'] . '.';
		}

		return [
			'summary'  => 'Public Merchant API docs pages ensured.',
			'messages' => $messages,
		];
	},
];
