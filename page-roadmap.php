<?php
/*
Template Name: Roadmap Page
Slug: roadmap
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

if ( ! crm_can_access( 'roadmap.view' ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$page_id         = get_queried_object_id();
$version_label   = crm_get_product_release_version();
$release_notes_url = crm_get_product_release_notes_url();
$edit_url        = crm_get_product_page_edit_url( get_post( $page_id ) );
$modified_label  = crm_product_get_modified_label( $page_id );
$can_edit_page   = $page_id > 0 && current_user_can( 'edit_post', $page_id );

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
							<li class="breadcrumb-item active">Roadmap</li>
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
								<h1 class="m-t-5 m-b-10">Roadmap</h1>
								<p class="m-b-0 text-muted">
									Планы по развитию Malibu Exchange CRM. Здесь фиксируются направления, этапы и изменения статусов без смешивания с operational logs.
								</p>
							</div>
							<div class="product-page-hero-meta">
								<div class="product-version-chip">
									<span class="hint-text">Текущая версия</span>
									<a href="<?php echo esc_url( $release_notes_url ); ?>" class="product-version-chip__value">
										v<?php echo esc_html( $version_label ); ?>
									</a>
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

				<div class="row">
					<div class="col-lg-8">
						<div class="card card-default m-b-30">
							<div class="card-header">
								<div class="card-title">План разработки</div>
							</div>
							<div class="card-body product-rich-content">
								<?php if ( have_posts() ) : ?>
									<?php while ( have_posts() ) : the_post(); ?>
										<?php the_content(); ?>
									<?php endwhile; ?>
								<?php endif; ?>
							</div>
						</div>
					</div>
					<div class="col-lg-4">
						<div class="card card-default m-b-20">
							<div class="card-header">
								<div class="card-title">Правило раздела</div>
							</div>
							<div class="card-body">
								<ul class="product-rule-list m-b-0">
									<li>Здесь публикуются планы, а не фактически выпущенные изменения.</li>
									<li>Скриншоты для Roadmap допустимы, но не обязательны по умолчанию.</li>
									<li>Для уже сделанных изменений используйте `Release Notes`.</li>
								</ul>
							</div>
						</div>

						<div class="card card-default m-b-30">
							<div class="card-header">
								<div class="card-title">Связанный раздел</div>
							</div>
							<div class="card-body">
								<p class="text-muted">
									Текущая версия и уже выпущенные изменения ведутся отдельно, чтобы планы и фактические релизы не смешивались.
								</p>
								<a href="<?php echo esc_url( $release_notes_url ); ?>" class="btn btn-default btn-block">
									Открыть Release Notes
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
