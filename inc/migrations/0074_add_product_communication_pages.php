<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0074_add_product_communication_pages',
	'title'    => 'Sync RBAC for roadmap/release notes, seed product version, create Roadmap and Release Notes pages',
	'callback' => function () {
		$messages = crm_rbac_sync();

		crm_set_setting( 'product_release_version', '0.1.0', 0 );
		$messages[] = 'Seeded system setting product_release_version for org_id=0.';

		$pages = [
			[
				'slug'     => 'roadmap',
				'title'    => 'Roadmap',
				'template' => 'page-roadmap.php',
				'content'  => implode( "\n\n", [
					'<!-- wp:heading {"level":2} --><h2>О разделе</h2><!-- /wp:heading -->',
					'<!-- wp:paragraph --><p>Здесь фиксируются планы по развитию Malibu Exchange CRM без смешивания с операционными журналами и company-scoped данными.</p><!-- /wp:paragraph -->',
					'<!-- wp:list --><ul><li>Публикуем ближайшие направления, этапы и изменения статуса.</li><li>Скриншоты для Roadmap допустимы, но не обязательны по умолчанию.</li><li>Редактура страницы выполняется через WordPress page editor root-аккаунтом.</li></ul><!-- /wp:list -->',
					'<!-- wp:paragraph --><p>После первого наполнения этот текст можно заменить рабочим содержимым roadmap.</p><!-- /wp:paragraph -->',
				] ),
			],
			[
				'slug'     => 'release-notes',
				'title'    => 'Release Notes',
				'template' => 'page-release-notes.php',
				'content'  => implode( "\n\n", [
					'<!-- wp:heading {"level":2} --><h2>О разделе</h2><!-- /wp:heading -->',
					'<!-- wp:paragraph --><p>Здесь фиксируются уже выпущенные изменения Malibu Exchange CRM.</p><!-- /wp:paragraph -->',
					'<!-- wp:list --><ul><li>Для заметных функциональных изменений обязателен хотя бы один скриншот изменённого узла.</li><li>Если изменение не видно в интерфейсе, приложите скрин итогового состояния или результата проверки.</li><li>Редактура страницы выполняется через WordPress page editor root-аккаунтом.</li></ul><!-- /wp:list -->',
					'<!-- wp:paragraph --><p>После первого опубликованного релиза этот текст можно заменить реальными release notes.</p><!-- /wp:paragraph -->',
				] ),
			],
		];

		foreach ( $pages as $page_def ) {
			$existing = get_page_by_path( $page_def['slug'], OBJECT, 'page' );

			if ( $existing instanceof WP_Post ) {
				$current_template = get_post_meta( $existing->ID, '_wp_page_template', true );
				if ( $current_template !== $page_def['template'] ) {
					update_post_meta( $existing->ID, '_wp_page_template', $page_def['template'] );
					$messages[] = 'Updated template for page "' . $page_def['slug'] . '" to ' . $page_def['template'] . '.';
				} else {
					$messages[] = 'Page "' . $page_def['slug'] . '" already exists with correct template.';
				}

				$current_content = (string) get_post_field( 'post_content', $existing->ID );
				if ( trim( wp_strip_all_tags( $current_content ) ) === '' ) {
					wp_update_post( [
						'ID'           => $existing->ID,
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
			'summary'  => 'Product communication pages ensured; product version seeded; RBAC synced.',
			'messages' => $messages,
		];
	},
];
