<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0009_create_page_logs',
	'title'    => 'Create WordPress page: Logs (slug: logs, template: page-logs.php)',
	'callback' => function () {
		// Защита от дублей: проверяем по slug
		$existing = get_page_by_path( 'logs', OBJECT, 'page' );

		if ( $existing instanceof WP_Post ) {
			// Страница уже есть — убедимся что шаблон привязан
			$current_template = get_post_meta( $existing->ID, '_wp_page_template', true );
			if ( $current_template !== 'page-logs.php' ) {
				update_post_meta( $existing->ID, '_wp_page_template', 'page-logs.php' );
				return [
					'summary'  => 'Page "logs" already existed; template updated to page-logs.php.',
					'messages' => [ 'Updated _wp_page_template on existing page ID ' . $existing->ID . '.' ],
				];
			}

			return [
				'summary'  => 'Page "logs" already exists with correct template — skipped.',
				'messages' => [ 'Page ID: ' . $existing->ID . ', template: page-logs.php.' ],
			];
		}

		// Создать страницу
		$page_id = wp_insert_post( [
			'post_title'   => 'Журнал действий',
			'post_name'    => 'logs',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => 1,
			'post_content' => '',
			'post_excerpt' => '',
		], true );

		if ( is_wp_error( $page_id ) ) {
			return new WP_Error(
				'create_page_failed',
				'Failed to create page "logs": ' . $page_id->get_error_message()
			);
		}

		// Привязать шаблон
		update_post_meta( $page_id, '_wp_page_template', 'page-logs.php' );

		return [
			'summary'  => 'WordPress page "Журнал действий" (slug: logs) created.',
			'messages' => [
				'Created page ID: ' . $page_id . '.',
				'Assigned template: page-logs.php.',
				'URL: ' . get_permalink( $page_id ),
			],
		];
	},
];
