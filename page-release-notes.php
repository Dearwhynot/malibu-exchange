<?php
/*
Template Name: Release Notes Page
Slug: release-notes
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

if ( ! crm_can_access( 'release_notes.view' ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$page_id          = get_queried_object_id();
$roadmap_url      = crm_get_product_roadmap_url();
$edit_url         = crm_get_product_page_edit_url( get_post( $page_id ) );
$version_label    = crm_get_product_release_version();
$modified_label   = crm_product_get_modified_label( $page_id );
$can_edit_page    = $page_id > 0 && current_user_can( 'edit_post', $page_id );
$raw_content      = (string) get_post_field( 'post_content', $page_id );
$has_visual_media = crm_product_content_has_visual_media( $raw_content );
$attachments      = crm_product_get_page_screenshot_attachments( $page_id );
$show_gallery     = ! $has_visual_media && ! empty( $attachments );
$show_warning     = $can_edit_page && ! $has_visual_media && empty( $attachments );

get_header();
?>

<?php get_template_part( 'template-parts/sidebar' ); ?>

<div class="page-container">

	<?php get_template_part( 'template-parts/header-backoffice' ); ?>

	<div class="page-content-wrapper">
		<div class="content">

			<div class="jumbotron" data-pages="parallax">
				<div class="container-fluid container-fixed-lg sm-p-l-0 sm-p-r-0">
					<div class="inner">
						<ol class="breadcrumb">
							<li class="breadcrumb-item"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a></li>
							<li class="breadcrumb-item active">Release Notes</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">
				<div class="card card-default card-product-hero m-b-30">
					<div class="card-body">
						<div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
							<div class="product-page-hero-copy">
								<div class="product-page-eyebrow">Product</div>
								<h1 class="m-t-5 m-b-10">Release Notes</h1>
								<p class="m-b-0 text-muted">
									Журнал уже выпущенных изменений. Для заметных функциональных правок здесь обязателен хотя бы один скриншот изменённого узла.
								</p>
							</div>
							<div class="product-page-hero-meta">
								<div class="product-version-chip">
									<span class="hint-text">Текущая версия</span>
									<span class="product-version-chip__value">v<?php echo esc_html( $version_label ); ?></span>
								</div>
								<?php if ( $modified_label !== '' ) : ?>
									<div class="product-hero-note">
										Обновлено: <?php echo esc_html( $modified_label ); ?>
									</div>
								<?php endif; ?>
								<?php if ( $can_edit_page && $edit_url !== '' ) : ?>
									<div class="product-admin-actions">
										<a href="<?php echo esc_url( $edit_url ); ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener">
											Редактировать страницу
										</a>
									</div>
								<?php endif; ?>
							</div>
						</div>
					</div>
				</div>

				<?php if ( $show_warning ) : ?>
					<div class="alert alert-warning bordered m-b-20">
						<strong>Для Release Notes пока не добавлены скриншоты.</strong><br>
						Для заметных функциональных изменений загрузите хотя бы один screenshot через media library этой страницы или вставьте изображения прямо в контент.
					</div>
				<?php endif; ?>

				<div class="row">
					<div class="col-lg-8">
						<div class="card card-default m-b-30">
							<div class="card-header">
								<div class="card-title">Журнал изменений</div>
							</div>
							<div class="card-body product-rich-content">
								<?php if ( have_posts() ) : ?>
									<?php while ( have_posts() ) : the_post(); ?>
										<?php the_content(); ?>
									<?php endwhile; ?>
								<?php endif; ?>
							</div>
						</div>

						<?php if ( $show_gallery ) : ?>
							<div class="card card-default m-b-30">
								<div class="card-header">
									<div class="card-title">Скриншоты изменений</div>
								</div>
								<div class="card-body">
									<div class="row">
										<?php foreach ( $attachments as $attachment ) : ?>
											<?php
											$full_url = wp_get_attachment_image_url( $attachment->ID, 'full' );
											$thumb    = wp_get_attachment_image( $attachment->ID, 'large', false, [
												'class' => 'img-fluid product-shot-card__image',
												'alt'   => trim( (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) ) ?: get_the_title( $attachment->ID ),
											] );
											if ( ! $full_url || ! $thumb ) {
												continue;
											}
											?>
											<div class="col-md-6 m-b-20">
												<div class="card card-default product-shot-card m-b-0">
													<a href="<?php echo esc_url( $full_url ); ?>" target="_blank" rel="noopener" class="product-shot-card__media">
														<?php echo $thumb; ?>
													</a>
													<div class="card-body p-t-15 p-b-15">
														<div class="small text-muted">
															<?php echo esc_html( get_the_title( $attachment->ID ) ?: 'Screenshot' ); ?>
														</div>
													</div>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
								</div>
							</div>
						<?php endif; ?>
					</div>
					<div class="col-lg-4">
						<div class="card card-default m-b-20">
							<div class="card-header">
								<div class="card-title">Правило раздела</div>
							</div>
							<div class="card-body">
								<ul class="product-rule-list m-b-0">
									<li>Здесь публикуются только уже выпущенные изменения.</li>
									<li>Для заметных UI или flow-изменений обязателен хотя бы один screenshot.</li>
									<li>Планы и будущие этапы ведутся отдельно в `Roadmap`.</li>
								</ul>
							</div>
						</div>

						<div class="card card-default m-b-30">
							<div class="card-header">
								<div class="card-title">Связанный раздел</div>
							</div>
							<div class="card-body">
								<p class="text-muted">
									Если изменение ещё не вышло в рабочий контур и пока находится в планах, его место в Roadmap, а не в Release Notes.
								</p>
								<a href="<?php echo esc_url( $roadmap_url ); ?>" class="btn btn-default btn-block">
									Открыть Roadmap
								</a>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<?php get_template_part( 'template-parts/footer-backoffice' ); ?>
	</div>
</div>

<?php get_template_part( 'template-parts/quickview' ); ?>
<?php get_template_part( 'template-parts/overlay' ); ?>
<?php get_footer(); ?>
