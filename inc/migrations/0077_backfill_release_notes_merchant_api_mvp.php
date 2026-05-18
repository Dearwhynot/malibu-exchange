<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0077_backfill_release_notes_merchant_api_mvp',
	'title'    => 'Append retrospective Release Notes entry for Merchant API MVP',
	'callback' => function () {
		$release = get_page_by_path( 'release-notes', OBJECT, 'page' );

		if ( ! $release instanceof WP_Post ) {
			return [
				'summary'  => 'Release Notes page is missing; Merchant API retrospective backfill skipped.',
				'messages' => [ 'Release Notes page not found by slug `release-notes`.' ],
			];
		}

		$entry = [
			'marker'        => '<!-- crm_product_entry:release_notes:merchant_api_mvp_2026_05 -->',
			'skip_patterns' => [
				'Merchant API MVP для мерчантов',
				'crm_merchant_api_clients',
				'/wp-json/malibu/v1/merchant/invoices',
				'company-scoped <strong>Merchant API</strong> для интеграций мерчантов',
				'идемпотентность по <code>external_order_id</code>',
			],
			'content'       => implode( "\n\n", [
				'<!-- crm_product_entry:release_notes:merchant_api_mvp_2026_05 -->',
				'<!-- wp:heading {"level":2} --><h2>Май 2026 — Merchant API MVP для мерчантов</h2><!-- /wp:heading -->',
				'<!-- wp:paragraph --><p>Задним числом фиксируем отдельную продуктовую веху: в Malibu Exchange появился company-scoped <strong>Merchant API</strong> для интеграций мерчантов. Контур построен на Bearer token auth, жёсткой company isolation и отдельном admin UI для выпуска и отзыва ключей.</p><!-- /wp:paragraph -->',
				'<!-- wp:heading {"level":3} --><h3>Что вошло в веху</h3><!-- /wp:heading -->',
				'<!-- wp:list --><ul><li>В карточке мерчанта появилась отдельная вкладка <strong>Merchant API</strong> с выпуском и отзывом API keys; данные клиентов хранятся в <code>crm_merchant_api_clients</code>.</li><li>Поднят Merchant API read-layer: <code>GET /merchant/me</code>, <code>/balances</code>, <code>/rates</code>, <code>/orders</code>, <code>/orders/{id}</code>, <code>/payouts</code>, <code>/payouts/{id}</code>.</li><li>Поднят write endpoint <code>POST /wp-json/malibu/v1/merchant/invoices</code> для выставления счёта из merchant contour.</li><li>Для business direction <code>RUB_USDT</code> API поддерживает оба Kanyon-режима из company settings: <code>orderAmount</code> для компаний с <code>fintech_pay2day_order_currency = USDT</code> и <code>paymentAmount</code> для компаний с <code>fintech_pay2day_order_currency = RUB</code>.</li><li>В create-flow добавлена идемпотентность по <code>external_order_id</code>: повтор того же запроса возвращает уже созданный invoice, а конфликтный replay получает <code>409 conflict</code>.</li><li>Во время self-test исправлена rates-семантика: invoice-поля и <code>invoice_create_supported</code> теперь выставляются только для поддержанного invoice contour, без ложной проекции на другие направления.</li></ul><!-- /wp:list -->',
				'<!-- wp:paragraph --><p><strong>Важно:</strong> в этой вехе уже работает создание merchant invoices и чтение merchant data, но webhooks и публичная API documentation page вынесены в следующий этап. Скриншоты для этой ретро-записи нужно добавить вручную позже.</p><!-- /wp:paragraph -->',
			] ),
		];

		$current_content = (string) get_post_field( 'post_content', $release->ID );
		$marker          = (string) $entry['marker'];

		if ( $marker !== '' && strpos( $current_content, $marker ) !== false ) {
			return [
				'summary'  => 'Release Notes Merchant API retrospective entry already present; nothing changed.',
				'messages' => [ 'Release Notes entry already present for marker ' . $marker . ' — skipped.' ],
			];
		}

		$patterns = isset( $entry['skip_patterns'] ) && is_array( $entry['skip_patterns'] )
			? $entry['skip_patterns']
			: [];

		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( $pattern !== '' && strpos( $current_content, $pattern ) !== false ) {
				return [
					'summary'  => 'Release Notes Merchant API retrospective entry appears to exist already; nothing changed.',
					'messages' => [ 'Release Notes entry matched existing pattern `' . $pattern . '` — skipped.' ],
				];
			}
		}

		$current_content = trim( $current_content );
		$current_content = $current_content === ''
			? (string) $entry['content']
			: $current_content . "\n\n" . (string) $entry['content'];

		$updated = wp_update_post(
			[
				'ID'           => (int) $release->ID,
				'post_content' => $current_content,
			],
			true
		);

		if ( is_wp_error( $updated ) ) {
			return [
				'summary'  => 'Release Notes Merchant API retrospective entry failed to save.',
				'messages' => [ 'ERROR: ' . $updated->get_error_message() ],
			];
		}

		return [
			'summary'  => 'Release Notes Merchant API retrospective entry appended safely.',
			'messages' => [ 'Appended Release Notes retrospective entry for marker ' . $marker . '.' ],
		];
	},
];
