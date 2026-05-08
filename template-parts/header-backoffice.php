<?php
/**
 * Standard backoffice header.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$vendor_img_uri = get_template_directory_uri() . '/vendor/pages/assets/img';
?>
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
		<a href="#" class="header-icon m-l-5 sm-no-margin d-none"
		   data-toggle="quickview" data-toggle-element="#quickview">
			<i class="pg-icon btn-icon-link">menu_add</i>
		</a>
	</div>
</div>
