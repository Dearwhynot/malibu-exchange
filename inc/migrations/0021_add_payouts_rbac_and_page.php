<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0021_add_payouts_rbac_and_page',
	'title'    => 'Sync RBAC for payouts.* permissions and create WordPress page: Payouts (slug: payouts, template: page-payouts.php)',
	'callback' => function () {
		$messages = crm_rbac_sync();

		// ── Создаём WordPress-страницу /payouts/ ──────────────────────────────
		$existing = get_page_by_path( 'payouts', OBJECT, 'page' );

		if ( $existing instanceof WP_Post ) {
			// Страница есть — проверяем шаблон
			$current_template = get_post_meta( $existing->ID, '_wp_page_template', true );
			if ( $current_template !== 'page-payouts.php' ) {
				update_post_meta( $existing->ID, '_wp_page_template', 'page-payouts.php' );
				$messages[] = 'Page "payouts" already existed; template updated to page-payouts.php (was: ' . ( $current_template ?: 'empty' ) . ').';
			} else {
				$messages[] = 'Page "payouts" already exists with correct template — skipped (ID ' . $existing->ID . ').';
			}
		} else {
			// Создаём страницу
			$page_id = wp_insert_post( [
				'post_title'   => 'Выплаты ЭП',
				'post_name'    => 'payouts',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => 1,
				'post_content' => '',
				'post_excerpt' => '',
			], true );

			if ( is_wp_error( $page_id ) ) {
				$messages[] = 'ERROR: failed to create /payouts/ page: ' . $page_id->get_error_message();
			} else {
				update_post_meta( $page_id, '_wp_page_template', 'page-payouts.php' );
				$messages[] = 'Created WordPress page "Выплаты ЭП" (slug: payouts, ID: ' . $page_id . ').';
				$messages[] = 'Assigned template: page-payouts.php.';
				$messages[] = 'URL: ' . get_permalink( $page_id );
			}
		}

		return [
			'summary'  => 'payouts.* RBAC synced; /payouts/ WordPress page ensured.',
			'messages' => $messages,
		];
	},
];
