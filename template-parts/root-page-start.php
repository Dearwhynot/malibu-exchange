<?php
/**
 * Root page shell start.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$root_page_args = is_array( $args ?? null ) ? $args : [];
$title          = isset( $root_page_args['title'] ) ? (string) $root_page_args['title'] : '';
$breadcrumbs    = isset( $root_page_args['breadcrumbs'] ) && is_array( $root_page_args['breadcrumbs'] )
	? $root_page_args['breadcrumbs']
	: [];
$description    = isset( $root_page_args['description'] ) ? (string) $root_page_args['description'] : '';

if ( empty( $breadcrumbs ) ) {
	$breadcrumbs[] = [
		'label'  => $title !== '' ? $title : 'Root',
		'url'    => '',
		'active' => true,
	];
}

get_header();
get_template_part( 'template-parts/sidebar' );
?>
<div class="page-container">
	<?php get_template_part( 'template-parts/header-backoffice' ); ?>

	<div class="page-content-wrapper">
		<div class="content">
			<div class="jumbotron" data-pages="parallax">
				<div class="container-fluid container-fixed-lg sm-p-l-0 sm-p-r-0">
					<div class="inner">
						<ol class="breadcrumb">
							<?php foreach ( $breadcrumbs as $crumb ) : ?>
								<?php
								$crumb_label  = isset( $crumb['label'] ) ? (string) $crumb['label'] : '';
								$crumb_url    = isset( $crumb['url'] ) ? (string) $crumb['url'] : '';
								$crumb_active = ! empty( $crumb['active'] );
								?>
								<li class="breadcrumb-item<?php echo $crumb_active ? ' active' : ''; ?>">
									<?php if ( ! $crumb_active && $crumb_url !== '' ) : ?>
										<a href="<?php echo esc_url( $crumb_url ); ?>"><?php echo esc_html( $crumb_label ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $crumb_label ); ?>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">
				<?php if ( $title !== '' || $description !== '' ) : ?>
				<div class="m-b-20">
					<?php if ( $title !== '' ) : ?>
					<h3 class="m-b-5"><?php echo esc_html( $title ); ?></h3>
					<?php endif; ?>
					<?php if ( $description !== '' ) : ?>
					<p class="hint-text m-b-0"><?php echo esc_html( $description ); ?></p>
					<?php endif; ?>
				</div>
				<?php endif; ?>
