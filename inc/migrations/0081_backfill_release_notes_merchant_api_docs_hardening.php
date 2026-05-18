<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0081_backfill_release_notes_merchant_api_docs_hardening',
	'title'    => 'Append retrospective Release Notes entry for Merchant API public docs and hardening',
	'callback' => function () {
		$release = get_page_by_path( 'release-notes', OBJECT, 'page' );

		if ( ! $release instanceof WP_Post ) {
			return [
				'summary'  => 'Release Notes page is missing; Merchant API docs/hardening retrospective backfill skipped.',
				'messages' => [ 'Release Notes page not found by slug `release-notes`.' ],
			];
		}

		$entry = [
			'marker'        => '<!-- crm_product_entry:release_notes:merchant_api_docs_hardening_2026_05 -->',
			'skip_patterns' => [
				'Merchant API public docs и hardening',
				'merchant-api-console',
				'RateLimitExceededError',
				'paymentAmount-ветка',
			],
			'content'       => implode( "\n\n", [
				'<!-- crm_product_entry:release_notes:merchant_api_docs_hardening_2026_05 -->',
				'<!-- wp:heading {"level":2} --><h2>Май 2026 — Merchant API public docs и hardening</h2><!-- /wp:heading -->',
				'<!-- wp:paragraph --><p>Следующим ретро-этапом после запуска Merchant API MVP в продукт вошли публичная документация API и production-hardening самого merchant surface. Этот блок тоже фиксируем задним числом по итогам рабочего цикла разработки.</p><!-- /wp:paragraph -->',
				'<!-- wp:heading {"level":3} --><h3>Что вошло в этап</h3><!-- /wp:heading -->',
				'<!-- wp:list --><ul><li>В репозитории зафиксирован единый OpenAPI source of truth: <code>docs/api/merchant/openapi.yaml</code>.</li><li>Подняты две публичные страницы: <code>/merchant-api/</code> на <strong>Redoc</strong> и <code>/merchant-api-console/</code> на <strong>Swagger UI</strong>.</li><li>Для docs pages были отдельно доработаны login guards, чтобы они действительно открывались без WordPress-логина и не редиректили на <code>/authorization</code>.</li><li>В Merchant API добавлен per-client rate limit с ответом <code>429 rate_limit_exceeded</code> и заголовками <code>Retry-After</code>, <code>X-RateLimit-Limit</code>, <code>X-RateLimit-Remaining</code>.</li><li>Auth-layer получил дополнительный hardening и deny-логирование для inactive company, pending / blocked / archived merchant, revoked token и scope violations.</li><li>Отдельно live-smoke подтверждена <code>paymentAmount</code>-ветка для компаний с <code>fintech_pay2day_order_currency = RUB</code>: create, replay, conflict и cross-scope negative checks прошли на живом контуре.</li></ul><!-- /wp:list -->',
				'<!-- wp:paragraph --><p><strong>Итог:</strong> Merchant API теперь уже не только умеет читать и создавать merchant invoices, но и имеет публичную reference-документацию, интерактивную консоль и базовый production-hardening для интеграционного контура. Скриншоты для этой ретро-записи нужно добавить отдельно.</p><!-- /wp:paragraph -->',
			] ),
		];

		$current_content = (string) get_post_field( 'post_content', $release->ID );
		$marker          = (string) $entry['marker'];

		if ( $marker !== '' && strpos( $current_content, $marker ) !== false ) {
			return [
				'summary'  => 'Release Notes Merchant API docs/hardening retrospective entry already present; nothing changed.',
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
					'summary'  => 'Release Notes Merchant API docs/hardening retrospective entry appears to exist already; nothing changed.',
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
				'summary'  => 'Release Notes Merchant API docs/hardening retrospective entry failed to save.',
				'messages' => [ 'ERROR: ' . $updated->get_error_message() ],
			];
		}

		return [
			'summary'  => 'Release Notes Merchant API docs/hardening retrospective entry appended safely.',
			'messages' => [ 'Appended Release Notes retrospective entry for marker ' . $marker . '.' ],
		];
	},
];
