<?php
/*
Template Name: Settings Page
Slug: settings
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

if ( ! crm_user_has_permission( get_current_user_id(), 'settings.view' ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$settings       = crm_get_all_settings( CRM_DEFAULT_ORG_ID );
$telegram_token = $settings['telegram_bot_token'] ?? '';

$pair       = rates_get_pair( RATES_PAIR_CODE, CRM_DEFAULT_ORG_ID );
$coeff      = $pair ? rates_get_coefficient( (int) $pair->id, RATES_PROVIDER_EX24, RATES_PROVIDER_SOURCE ) : 0.05;

$vendor_img_uri = get_template_directory_uri() . '/vendor/pages/assets/img';
$nonce_save     = wp_create_nonce( 'me_settings_save' );

get_header();
?>

<!-- BEGIN SIDEBAR-->
<?php get_template_part( 'template-parts/sidebar' ); ?>
<!-- END SIDEBAR -->

<div class="page-container">

	<!-- HEADER -->
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
	<!-- END HEADER -->

	<div class="page-content-wrapper">
		<div class="content">

			<div class="jumbotron" data-pages="parallax">
				<div class="container-fluid container-fixed-lg sm-p-l-0 sm-p-r-0">
					<div class="inner">
						<ol class="breadcrumb">
							<li class="breadcrumb-item"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a></li>
							<li class="breadcrumb-item active">Настройки</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">

				<!-- Алерт результата сохранения -->
				<div id="settings-alert" class="alert d-none m-b-20" role="alert"></div>

				<!-- ─── Telegram ───────────────────────────────────────────────────── -->
				<div class="card card-default m-b-30">
					<div class="card-header">
						<div class="card-title">Telegram-бот</div>
					</div>
					<div class="card-body">
						<form id="settings-form">
							<div class="row">
								<div class="col-md-8 col-lg-6">
									<div class="form-group">
										<label for="telegram_bot_token">Токен бота (Bot Token)</label>
										<input type="text"
										       class="form-control"
										       id="telegram_bot_token"
										       name="telegram_bot_token"
										       value="<?php echo esc_attr( $telegram_token ); ?>"
										       placeholder="1234567890:AAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
										       autocomplete="off">
										<p class="hint-text m-t-5">
											Получить токен можно у
											<a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>
											в Telegram.
										</p>
									</div>
								</div>
							</div>
							<button type="submit" class="btn btn-primary btn-cons">
								Сохранить настройки
							</button>
						</form>
					</div>
				</div>

				<!-- ─── Курсы ──────────────────────────────────────────────────── -->
				<div class="card card-default m-b-30">
					<div class="card-header">
						<div class="card-title">Курсы — RUB/THB</div>
					</div>
					<div class="card-body">
						<form id="rates-settings-form">
							<div class="row">
								<div class="col-md-4 col-lg-3">
									<div class="form-group">
										<label for="rates_coefficient">Коэффициент вычитания (Ex24 / <?php echo esc_html( RATES_PROVIDER_SOURCE ); ?>)</label>
										<input type="number"
										       class="form-control"
										       id="rates_coefficient"
										       name="rates_coefficient"
										       value="<?php echo esc_attr( number_format( $coeff, 4, '.', '' ) ); ?>"
										       step="0.0001"
										       min="0"
										       placeholder="0.0500">
										<p class="hint-text m-t-5">
											Наш курс = курс конкурента − этот коэффициент.<br>
											Пример: 27.70 − 0.05 = 27.65
										</p>
									</div>
								</div>
							</div>
							<button type="submit" class="btn btn-primary btn-cons">
								Сохранить коэффициент
							</button>
						</form>
					</div>
				</div>

			</div>
		</div>
		<!-- START COPYRIGHT -->
		<div class="container-fluid container-fixed-lg footer">
			<div class="copyright sm-text-center">
				<p class="small-text no-margin pull-left sm-pull-reset">
					©2014-2020 All Rights Reserved. Pages® and/or its subsidiaries or affiliates are registered trademark of Revox Ltd.
				</p>
				<div class="clearfix"></div>
			</div>
		</div>
		<!-- END COPYRIGHT -->
	</div>
</div>

<?php get_template_part( 'template-parts/quickview' ); ?>
<?php get_template_part( 'template-parts/overlay' ); ?>

<?php
add_action( 'wp_footer', function () use ( $nonce_save ) {
?>
<script>
(function ($) {
	'use strict';

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCE    = '<?php echo esc_js( $nonce_save ); ?>';

	function handleSettingsForm($form, $alert, extraData, resetLabel) {
		$form.on('submit', function (e) {
			e.preventDefault();
			var $btn = $(this).find('[type=submit]');
			$btn.prop('disabled', true).text('Сохраняем…');
			$alert.addClass('d-none').removeClass('alert-success alert-danger');

			$.post(AJAX_URL, $.extend({ action: 'me_settings_save', nonce: NONCE }, extraData()))
			.done(function (res) {
				if (res.success) {
					$alert.removeClass('d-none alert-danger').addClass('alert-success').text(res.data.message || 'Сохранено');
				} else {
					$alert.removeClass('d-none alert-success').addClass('alert-danger').text(res.data.message || 'Ошибка сохранения');
				}
			})
			.fail(function () {
				$alert.removeClass('d-none alert-success').addClass('alert-danger').text('Сетевая ошибка. Попробуйте ещё раз.');
			})
			.always(function () {
				$btn.prop('disabled', false).text(resetLabel);
			});
		});
	}

	handleSettingsForm(
		$('#settings-form'),
		$('#settings-alert'),
		function () { return { section: 'telegram', telegram_bot_token: $('#telegram_bot_token').val() }; },
		'Сохранить настройки'
	);

	handleSettingsForm(
		$('#rates-settings-form'),
		$('#settings-alert'),
		function () { return { section: 'rates_coefficient', rates_coefficient: $('#rates_coefficient').val() }; },
		'Сохранить коэффициент'
	);

}(jQuery));
</script>
<?php
}, 99 );
?>

<?php get_footer(); ?>
