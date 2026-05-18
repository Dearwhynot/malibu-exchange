<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'key'      => '0075_backfill_product_communication_initial_content',
	'title'    => 'Backfill initial Roadmap and Release Notes entries from 2026-05-18 product communication rollout',
	'callback' => function () {
		$messages = [];

		$roadmap = get_page_by_path( 'roadmap', OBJECT, 'page' );
		$release = get_page_by_path( 'release-notes', OBJECT, 'page' );

		$roadmap_content = implode( "\n\n", [
			'<!-- wp:heading {"level":2} --><h2>Запись от 18.05.2026</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Этот блок зафиксирован задним числом по итогам рабочего чата, в котором было принято решение добавить в продукт отдельные страницы <strong>Roadmap</strong> и <strong>Release Notes</strong>, а также вывести версию проекта в футер.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading {"level":3} --><h3>Что уже выполнено</h3><!-- /wp:heading -->',
			'<!-- wp:list --><ul><li>Добавлен продуктовый раздел в sidebar для company-аккаунтов.</li><li>Добавлены отдельные страницы <strong>Roadmap</strong> и <strong>Release Notes</strong>.</li><li>В футере появилась версия проекта с переходом на Release Notes.</li><li>В Settings для root добавлен блок управления версией и быстрые ссылки на редактирование обеих страниц.</li><li>Для Release Notes зафиксировано правило: для заметных функциональных изменений нужны скриншоты изменённых узлов.</li></ul><!-- /wp:list -->',
			'<!-- wp:heading {"level":3} --><h3>Ближайшие шаги</h3><!-- /wp:heading -->',
			'<!-- wp:list --><ul><li>Начать вести реальные продуктовые планы уже не в чате, а прямо на этой странице.</li><li>Наполнять Release Notes после каждого заметного релиза или изменения flow.</li><li>При необходимости позже добавить более удобный внутренний CRUD для редакции этих разделов.</li></ul><!-- /wp:list -->',
			'<!-- wp:paragraph --><p>Это не новый пересобранный roadmap, а первая зафиксированная запись на основе уже принятого решения.</p><!-- /wp:paragraph -->',
		] );

		$release_content = implode( "\n\n", [
			'<!-- wp:heading {"level":2} --><h2>v0.1.0 — 18.05.2026</h2><!-- /wp:heading -->',
			'<!-- wp:paragraph --><p>Первая продуктовая запись, оформленная задним числом по итогам рабочего чата.</p><!-- /wp:paragraph -->',
			'<!-- wp:heading {"level":3} --><h3>Что изменилось</h3><!-- /wp:heading -->',
			'<!-- wp:list --><ul><li>В продукт добавлены отдельные страницы <strong>Roadmap</strong> и <strong>Release Notes</strong>.</li><li>В sidebar для company-аккаунтов добавлен новый блок <strong>Продукт</strong>.</li><li>В футере появилась управляемая версия проекта с переходом на Release Notes.</li><li>В root Settings добавлен отдельный блок для управления версией и быстрых переходов к редактированию этих страниц.</li><li>Для Release Notes зафиксировано правило обязательных скриншотов для заметных функциональных изменений.</li></ul><!-- /wp:list -->',
			'<!-- wp:heading {"level":3} --><h3>Что важно</h3><!-- /wp:heading -->',
			'<!-- wp:list --><ul><li>Roadmap используется для будущих планов и этапов.</li><li>Release Notes используется только для уже выпущенных изменений.</li><li>Скриншоты для этой первой записи нужно добавить отдельно после визуальной проверки на живом интерфейсе.</li></ul><!-- /wp:list -->',
			'<!-- wp:paragraph --><p><strong>Статус:</strong> базовая инфраструктура продуктовой коммуникации введена в проект.</p><!-- /wp:paragraph -->',
		] );

		$placeholder_markers = [
			'После первого наполнения этот текст можно заменить рабочим содержимым roadmap.',
			'После первого опубликованного релиза этот текст можно заменить реальными release notes.',
		];

		$should_replace = static function ( ?WP_Post $page, string $marker ): bool {
			if ( ! $page instanceof WP_Post ) {
				return false;
			}

			$content = (string) $page->post_content;
			if ( trim( wp_strip_all_tags( $content ) ) === '' ) {
				return true;
			}

			return strpos( $content, $marker ) !== false;
		};

		if ( $should_replace( $roadmap, $placeholder_markers[0] ) ) {
			wp_update_post( [
				'ID'           => (int) $roadmap->ID,
				'post_content' => $roadmap_content,
			] );
			$messages[] = 'Roadmap page initial content backfilled from 2026-05-18 chat.';
		} else {
			$messages[] = 'Roadmap page already has custom content — skipped.';
		}

		if ( $should_replace( $release, $placeholder_markers[1] ) ) {
			wp_update_post( [
				'ID'           => (int) $release->ID,
				'post_content' => $release_content,
			] );
			$messages[] = 'Release Notes page initial content backfilled from 2026-05-18 chat.';
		} else {
			$messages[] = 'Release Notes page already has custom content — skipped.';
		}

		return [
			'summary'  => 'Initial product communication content ensured for Roadmap and Release Notes.',
			'messages' => $messages,
		];
	},
];
