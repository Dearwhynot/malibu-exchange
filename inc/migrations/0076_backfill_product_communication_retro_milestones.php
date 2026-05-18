<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0076_backfill_product_communication_retro_milestones',
	'title'    => 'Append retrospective Release Notes entries for major service bot ACL and merchant contour milestones',
	'callback' => function () {
		$messages = [];
		$release  = get_page_by_path( 'release-notes', OBJECT, 'page' );

		if ( ! $release instanceof WP_Post ) {
			return [
				'summary'  => 'Release Notes page is missing; retrospective milestone backfill skipped.',
				'messages' => [ 'Release Notes page not found by slug `release-notes`.' ],
			];
		}

		$entries = [
			[
				'marker'        => '<!-- crm_product_entry:release_notes:service_bot_acl_2026_05 -->',
				'skip_patterns' => [
					'ACL-контур service bot компании',
					'service Telegram contour',
					'crm_service_telegram_invites',
					'crm_service_telegram_access',
				],
				'content'       => implode( "\n\n", [
					'<!-- crm_product_entry:release_notes:service_bot_acl_2026_05 -->',
					'<!-- wp:heading {"level":2} --><h2>Май 2026 — ACL-контур service bot компании</h2><!-- /wp:heading -->',
					'<!-- wp:paragraph --><p>Задним числом фиксируем крупную продуктовую веху: в Malibu Exchange появился отдельный company-scoped <strong>service Telegram contour</strong> с явным ACL-слоем и invite-based привязкой пользователя.</p><!-- /wp:paragraph -->',
					'<!-- wp:heading {"level":3} --><h3>Что вошло в веху</h3><!-- /wp:heading -->',
					'<!-- wp:list --><ul><li>Добавлена отдельная группа CRM-permissions <code>service.telegram.*</code> для просмотра ACL, invite и рабочих разделов service bot.</li><li>Добавлены таблицы <code>crm_service_telegram_invites</code> и <code>crm_service_telegram_access</code> для invite-history и активного доступа.</li><li>На странице <strong>Users</strong> появился UI для выдачи invite, просмотра истории и отзыва service access.</li><li>Service bot открывается через <code>/start svc_...</code> и не пускает пользователя без явного invite и active access.</li><li>Главное меню service bot показывает только разрешённые разделы: <code>Merchant payouts</code>, <code>Acquirer payouts</code>, <code>Orders</code>, <code>Rates</code>.</li></ul><!-- /wp:list -->',
					'<!-- wp:paragraph --><p><strong>Важно:</strong> root в этом контуре не участвует, а company isolation остаётся жёсткой. Скриншоты для этой ретро-записи нужно добавить вручную позже.</p><!-- /wp:paragraph -->',
				] ),
			],
			[
				'marker'        => '<!-- crm_product_entry:release_notes:merchant_rub_usdt_2026_05 -->',
				'skip_patterns' => [
					'merchant contour RUB → USDT через Rapira + Kanyon',
					'merchant contour RUB -> USDT через Rapira + Kanyon',
					'fintech_kanyon_rapira_markup_percent',
					'Merchant RUB -> USDT invoice created via Rapira + Kanyon paymentAmount flow.',
				],
				'content'       => implode( "\n\n", [
					'<!-- crm_product_entry:release_notes:merchant_rub_usdt_2026_05 -->',
					'<!-- wp:heading {"level":2} --><h2>Май 2026 — merchant contour RUB → USDT через Rapira + Kanyon</h2><!-- /wp:heading -->',
					'<!-- wp:paragraph --><p>Задним числом фиксируем ещё одну крупную веху: merchant contour получил отдельный RUB-input flow, в котором merchant bot создаёт счёт через Kanyon, а расчёт экономики опирается на живой Rapira ask и company-scoped merchant settings.</p><!-- /wp:paragraph -->',
					'<!-- wp:heading {"level":3} --><h3>Что вошло в веху</h3><!-- /wp:heading -->',
					'<!-- wp:list --><ul><li>Merchant bot получил оформленный набор slash-команд и menu-entrypoints: <code>/invoice</code>, <code>/orders</code>, <code>/balance</code>, <code>/rates</code>, <code>/profile</code>, <code>/help</code>.</li><li>Для компаний с <code>fintech_pay2day_order_currency = RUB</code> новый flow использует Kanyon <code>paymentAmount</code>; legacy USDT-contour через <code>orderAmount</code> сохранён отдельно.</li><li>В расчёт добавлены Rapira ask, company setting <code>fintech_kanyon_rapira_markup_percent</code> и merchant markup modes.</li><li>Order сохраняет economics snapshot, включая <code>merchant_requested_rub_value</code>, <code>merchant_payable_value</code> и <code>merchant_meta_json</code>.</li><li>Новый merchant-flow не подменяет старый contour молча: RUB и USDT paths разделены явно.</li></ul><!-- /wp:list -->',
					'<!-- wp:paragraph --><p><strong>Важно:</strong> это отдельная product-веха merchant contour, а не перенос старого USDT-сценария один в один. Скриншоты для этой ретро-записи нужно добавить вручную позже.</p><!-- /wp:paragraph -->',
				] ),
			],
		];

		$current_content = (string) get_post_field( 'post_content', $release->ID );
		$did_append      = false;
		$entry_exists    = static function ( string $content, array $entry ): bool {
			$marker = (string) ( $entry['marker'] ?? '' );
			if ( $marker !== '' && strpos( $content, $marker ) !== false ) {
				return true;
			}

			$patterns = isset( $entry['skip_patterns'] ) && is_array( $entry['skip_patterns'] )
				? $entry['skip_patterns']
				: [];

			foreach ( $patterns as $pattern ) {
				$pattern = trim( (string) $pattern );
				if ( $pattern !== '' && strpos( $content, $pattern ) !== false ) {
					return true;
				}
			}

			return false;
		};

		foreach ( $entries as $entry ) {
			$marker = (string) $entry['marker'];

			if ( $entry_exists( $current_content, $entry ) ) {
				$messages[] = 'Release Notes entry already present for marker ' . $marker . ' — skipped.';
				continue;
			}

			$current_content = trim( $current_content );
			$current_content = $current_content === ''
				? (string) $entry['content']
				: $current_content . "\n\n" . (string) $entry['content'];
			$did_append      = true;

			$messages[] = 'Appended Release Notes retrospective entry for marker ' . $marker . '.';
		}

		if ( ! $did_append ) {
			return [
				'summary'  => 'Release Notes retrospective milestone entries already present; nothing changed.',
				'messages' => $messages,
			];
		}

		$updated = wp_update_post(
			[
				'ID'           => (int) $release->ID,
				'post_content' => $current_content,
			],
			true
		);

		if ( is_wp_error( $updated ) ) {
			return [
				'summary'  => 'Release Notes retrospective entries failed to save.',
				'messages' => array_merge(
					$messages,
					[ 'ERROR: ' . $updated->get_error_message() ]
				),
			];
		}

		return [
			'summary'  => 'Release Notes retrospective milestone entries appended safely.',
			'messages' => $messages,
		];
	},
];
