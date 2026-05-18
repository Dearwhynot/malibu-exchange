<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0078_add_release_notes_screenshots',
	'title'    => 'Embed browser screenshots into Release Notes retrospective entries',
	'callback' => function () {
		$release = get_page_by_path( 'release-notes', OBJECT, 'page' );

		if ( ! $release instanceof WP_Post ) {
			return [
				'summary'  => 'Release Notes page is missing; screenshot backfill skipped.',
				'messages' => [ 'Release Notes page not found by slug `release-notes`.' ],
			];
		}

		$content  = (string) get_post_field( 'post_content', $release->ID );
		$messages = [];
		$changed  = false;

		$build_image_block = static function ( string $marker, string $file, string $alt, string $caption ): string {
			$url = trailingslashit( get_stylesheet_directory_uri() ) . 'assets/img/release-notes/' . ltrim( $file, '/' );
			$url = esc_url_raw( $url );

			return implode( "\n", [
				$marker,
				'<!-- wp:image {"sizeSlug":"large","linkDestination":"custom"} -->',
				'<figure class="wp-block-image size-large"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"/><figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption></figure>',
				'<!-- /wp:image -->',
			] );
		};

		$entries = [
			[
				'marker'  => '<!-- crm_release_shot:v0_1_0 -->',
				'needle'  => '<!-- wp:paragraph --><p>Первая продуктовая запись, оформленная задним числом по итогам рабочего чата.</p><!-- /wp:paragraph -->',
				'file'    => 'release-notes-v0-1-0.jpg',
				'alt'     => 'Release Notes page in Malibu Exchange backoffice',
				'caption' => 'Release Notes как отдельная продуктовая страница в backoffice после запуска базовой продуктовой коммуникации.',
			],
			[
				'marker'  => '<!-- crm_release_shot:service_bot_acl_2026_05 -->',
				'needle'  => '<!-- wp:paragraph --><p>Задним числом фиксируем крупную продуктовую веху: в Malibu Exchange появился отдельный company-scoped <strong>service Telegram contour</strong> с явным ACL-слоем и invite-based привязкой пользователя.</p><!-- /wp:paragraph -->',
				'file'    => 'release-notes-service-bot-acl.jpg',
				'alt'     => 'Users page with service bot ACL actions',
				'caption' => 'Users page: company-scoped Service bot actions с настройкой контура, историей invites и управлением доступом.',
			],
			[
				'marker'  => '<!-- crm_release_shot:merchant_rub_usdt_2026_05 -->',
				'needle'  => '<!-- wp:paragraph --><p>Задним числом фиксируем ещё одну крупную веху: merchant contour получил отдельный RUB-input flow, в котором merchant bot создаёт счёт через Kanyon, а расчёт экономики опирается на живой Rapira ask и company-scoped merchant settings.</p><!-- /wp:paragraph -->',
				'file'    => 'release-notes-merchant-contour.jpg',
				'alt'     => 'Merchants page with RUB USDT direction and markup',
				'caption' => 'Merchants page: company-scoped merchant contour с направлением RUB/USDT и видимой наценкой в рабочем списке.',
			],
			[
				'marker'  => '<!-- crm_release_shot:merchant_api_mvp_2026_05 -->',
				'needle'  => '<!-- wp:paragraph --><p>Задним числом фиксируем отдельную продуктовую веху: в Malibu Exchange появился company-scoped <strong>Merchant API</strong> для интеграций мерчантов. Контур построен на Bearer token auth, жёсткой company isolation и отдельном admin UI для выпуска и отзыва ключей.</p><!-- /wp:paragraph -->',
				'file'    => 'release-notes-merchant-api.jpg',
				'alt'     => 'Merchant API tab in merchant card',
				'caption' => 'Карточка мерчанта: вкладка Merchant API с provider mode и уже выпущенным integration key внутри company-scoped контура.',
			],
		];

		foreach ( $entries as $entry ) {
			$marker = (string) $entry['marker'];
			if ( $marker !== '' && strpos( $content, $marker ) !== false ) {
				$messages[] = 'Screenshot block already present for marker ' . $marker . ' — skipped.';
				continue;
			}

			$needle = (string) $entry['needle'];
			if ( $needle === '' || strpos( $content, $needle ) === false ) {
				$messages[] = 'Anchor not found for marker ' . $marker . ' — skipped.';
				continue;
			}

			$block   = $build_image_block(
				$marker,
				(string) $entry['file'],
				(string) $entry['alt'],
				(string) $entry['caption']
			);
			$count   = 0;
			$content = str_replace( $needle, $needle . "\n\n" . $block, $content, $count );

			if ( $count > 0 ) {
				$changed    = true;
				$messages[] = 'Embedded screenshot block for marker ' . $marker . '.';
			} else {
				$messages[] = 'Replacement failed for marker ' . $marker . '.';
			}
		}

		if ( ! $changed ) {
			return [
				'summary'  => 'Release Notes screenshot blocks already present or anchors missing; nothing changed.',
				'messages' => $messages,
			];
		}

		$updated = wp_update_post(
			[
				'ID'           => (int) $release->ID,
				'post_content' => $content,
			],
			true
		);

		if ( is_wp_error( $updated ) ) {
			return [
				'summary'  => 'Release Notes screenshot backfill failed to save.',
				'messages' => array_merge(
					$messages,
					[ 'ERROR: ' . $updated->get_error_message() ]
				),
			];
		}

		return [
			'summary'  => 'Release Notes screenshot blocks embedded safely.',
			'messages' => $messages,
		];
	},
];
