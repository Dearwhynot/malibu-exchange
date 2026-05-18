<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0079_finalize_release_notes_screenshot_copy',
	'title'    => 'Finalize Release Notes copy after screenshot backfill',
	'callback' => function () {
		$release = get_page_by_path( 'release-notes', OBJECT, 'page' );

		if ( ! $release instanceof WP_Post ) {
			return [
				'summary'  => 'Release Notes page is missing; screenshot copy cleanup skipped.',
				'messages' => [ 'Release Notes page not found by slug `release-notes`.' ],
			];
		}

		$content      = (string) get_post_field( 'post_content', $release->ID );
		$messages     = [];
		$replacements = [
			'Скриншоты для этой первой записи нужно добавить отдельно после визуальной проверки на живом интерфейсе.' => 'Скриншоты для этой первой записи добавлены после визуальной проверки на живом интерфейсе.',
			'Скриншоты для этой ретро-записи нужно добавить вручную позже.'                                           => 'Скриншот для этой ретро-записи уже встроен прямо в запись.',
		];

		$updated_content = str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			$content,
			$count
		);

		if ( $count < 1 ) {
			return [
				'summary'  => 'Release Notes screenshot copy cleanup already applied; nothing changed.',
				'messages' => [ 'No outdated screenshot placeholder copy found.' ],
			];
		}

		$updated = wp_update_post(
			[
				'ID'           => (int) $release->ID,
				'post_content' => $updated_content,
			],
			true
		);

		if ( is_wp_error( $updated ) ) {
			return [
				'summary'  => 'Release Notes screenshot copy cleanup failed to save.',
				'messages' => [ 'ERROR: ' . $updated->get_error_message() ],
			];
		}

		$messages[] = 'Replaced outdated screenshot placeholder copy in Release Notes.';

		return [
			'summary'  => 'Release Notes screenshot copy finalized safely.',
			'messages' => $messages,
		];
	},
];
