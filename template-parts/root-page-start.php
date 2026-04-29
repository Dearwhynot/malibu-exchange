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
$vendor_img_uri = get_template_directory_uri() . '/vendor/pages/assets/img';

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
	<div class="header">
		<a href="#" class="btn-link toggle-sidebar d-lg-none pg-icon btn-icon-link" data-toggle="sidebar">menu</a>
		<div class="">
			<div class="brand inline">
				<img src="<?php echo esc_url( $vendor_img_uri . '/logo.png' ); ?>" alt="logo"
				     data-src="<?php echo esc_url( $vendor_img_uri . '/logo.png' ); ?>"
				     data-src-retina="<?php echo esc_url( $vendor_img_uri . '/logo_2x.png' ); ?>"
				     width="78" height="22">
			</div>
		</div>
		<div class="d-flex align-items-center">
			<div class="dropdown pull-right d-lg-block d-none">
				<button class="profile-dropdown-toggle" type="button" data-bs-toggle="dropdown"
				        aria-haspopup="true" aria-expanded="false" aria-label="profile dropdown">
					<span class="thumbnail-wrapper d32 circular inline">
						<img src="<?php echo esc_url( $vendor_img_uri . '/profiles/avatar.jpg' ); ?>"
						     alt="" width="32" height="32">
					</span>
				</button>
				<div class="dropdown-menu dropdown-menu-right profile-dropdown" role="menu">
					<a href="#" class="dropdown-item">
						<span>Вход как<br><b><?php echo esc_html( wp_get_current_user()->display_name ); ?></b></span>
					</a>
					<div class="dropdown-divider"></div>
					<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="dropdown-item">Выйти</a>
				</div>
			</div>
			<a href="#" class="header-icon m-l-5 sm-no-margin d-inline-block"
			   data-toggle="quickview" data-toggle-element="#quickview">
				<i class="pg-icon btn-icon-link">menu_add</i>
			</a>
		</div>
	</div>

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
