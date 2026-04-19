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

// Root (uid=1) имеет org_id = 0 — системный контекст, отдельный от всех компаний.
// Обычный пользователь — org_id его компании.
$org_id   = crm_is_root( get_current_user_id() ) ? 0 : crm_get_current_user_company_id( get_current_user_id() );
$settings = crm_get_all_settings( $org_id );
$telegram_token = $settings['telegram_bot_token'] ?? '';

// Fintech settings
$fintech = [
	'company_name'              => $settings['fintech_company_name']              ?? '',
	'merchant_order_prefix'     => $settings['fintech_merchant_order_prefix']     ?? 'MALIBU',
	'active_provider'           => $settings['fintech_active_provider']           ?? 'kanyon',
	'debug'                     => ( $settings['fintech_debug'] ?? '0' ) === '1',
	'pay2day_login'             => $settings['fintech_pay2day_login']             ?? '',
	'pay2day_password'          => $settings['fintech_pay2day_password']          ?? '',
	'pay2day_tsp_id'            => $settings['fintech_pay2day_tsp_id']            ?? '',
	'pay2day_order_currency'    => $settings['fintech_pay2day_order_currency']    ?? 'USDT',
	'doverka_api_key'           => $settings['fintech_doverka_api_key']           ?? '',
	'doverka_currency_id'       => $settings['fintech_doverka_currency_id']       ?? '',
	'doverka_approve_url'       => $settings['fintech_doverka_approve_url']       ?? '',
	'doverka_kyc_redirect_url'  => $settings['fintech_doverka_kyc_redirect_url']  ?? '',
	'kanyon_verify_signature'   => ( $settings['fintech_kanyon_verify_signature'] ?? '0' ) === '1',
	'kanyon_public_key_pem'     => $settings['fintech_kanyon_public_key_pem']     ?? '',
];

$current_tz = $settings['timezone'] ?? 'UTC';

$pair       = rates_get_pair( RATES_PAIR_CODE, $org_id );
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

				<!-- ─── Система / Таймзона ─────────────────────────────────────────── -->
				<div class="card card-default m-b-30">
					<div class="card-header">
						<div class="card-title">Система — Общие</div>
					</div>
					<div class="card-body">
						<form id="system-settings-form">
							<div class="row">
								<div class="col-md-5 col-lg-4">
									<div class="form-group">
										<label for="timezone">Часовой пояс (отображение дат)</label>
										<select class="full-width" id="timezone" name="timezone" data-init-plugin="select2">
											<?php
											$tz_groups = [
												'Универсальный' => [ 'UTC' ],
												'Россия' => [
													'Europe/Kaliningrad',
													'Europe/Moscow',
													'Europe/Samara',
													'Asia/Yekaterinburg',
													'Asia/Omsk',
													'Asia/Krasnoyarsk',
													'Asia/Irkutsk',
													'Asia/Yakutsk',
													'Asia/Vladivostok',
													'Asia/Magadan',
													'Asia/Kamchatka',
												],
												'Азия' => [
													'Asia/Dubai',
													'Asia/Bangkok',
													'Asia/Singapore',
													'Asia/Tokyo',
												],
												'Европа' => [
													'Europe/London',
													'Europe/Berlin',
													'Europe/Kiev',
												],
											];
											foreach ( $tz_groups as $group => $tzs ) {
												echo '<optgroup label="' . esc_attr( $group ) . '">';
												foreach ( $tzs as $tz ) {
													$label = $tz;
													try {
														$dtz    = new DateTimeZone( $tz );
														$offset = $dtz->getOffset( new DateTime( 'now', $dtz ) );
														$sign   = $offset >= 0 ? '+' : '-';
														$abs    = abs( $offset );
														$h      = (int) floor( $abs / 3600 );
														$m_m    = (int) floor( ( $abs % 3600 ) / 60 );
														$label  = 'UTC' . $sign . str_pad( $h, 2, '0', STR_PAD_LEFT ) . ':' . str_pad( $m_m, 2, '0', STR_PAD_LEFT ) . ' — ' . $tz;
													} catch ( \Exception $e ) {}
													echo '<option value="' . esc_attr( $tz ) . '"' . selected( $current_tz, $tz, false ) . '>' . esc_html( $label ) . '</option>';
												}
												echo '</optgroup>';
											}
											?>
										</select>
										<p class="hint-text m-t-5">
											Даты в таблицах логов и ордеров конвертируются из UTC в выбранный пояс.<br>
											Убедитесь, что MySQL-сервер хранит даты в UTC.
										</p>
									</div>
								</div>
							</div>
							<button type="submit" class="btn btn-primary btn-cons">
								Сохранить
							</button>
						</form>
					</div>
				</div>

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
											Пример: 2.70 − 0.05 = 2.65
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

				<!-- ─── Fintech: Общие ──────────────────────────────────────── -->
				<div class="card card-default m-b-20">
					<div class="card-header">
						<div class="card-title">Платёжный шлюз — Общие настройки</div>
					</div>
					<div class="card-body">
						<form id="fintech-settings-form">
							<div class="row">
								<div class="col-md-4 col-lg-3">
									<div class="form-group">
										<label for="fintech_company_name">Название компании</label>
										<input type="text" class="form-control" id="fintech_company_name" name="fintech_company_name"
										       value="<?php echo esc_attr( $fintech['company_name'] ); ?>" placeholder="Malibu Exchange">
									</div>
								</div>
								<div class="col-md-3 col-lg-2">
									<div class="form-group">
										<label for="fintech_merchant_order_prefix">Префикс ордера</label>
										<input type="text" class="form-control" id="fintech_merchant_order_prefix" name="fintech_merchant_order_prefix"
										       value="<?php echo esc_attr( $fintech['merchant_order_prefix'] ); ?>" placeholder="MALIBU" maxlength="16">
									</div>
								</div>
								<div class="col-md-3 col-lg-2">
									<div class="form-group">
										<label for="fintech_active_provider">Активный провайдер</label>
										<select class="full-width" id="fintech_active_provider" name="fintech_active_provider" data-init-plugin="select2">
											<option value="kanyon" <?php selected( $fintech['active_provider'], 'kanyon' ); ?>>Kanyon (Pay2Day)</option>
											<option value="doverka" <?php selected( $fintech['active_provider'], 'doverka' ); ?>>Doverka</option>
										</select>
									</div>
								</div>
								<div class="col-md-2">
									<div class="form-group">
										<label>&nbsp;</label>
										<div class="checkbox check-success" style="margin-top:7px">
											<input type="checkbox" id="fintech_debug" name="fintech_debug" value="1" <?php checked( $fintech['debug'] ); ?>>
											<label for="fintech_debug">Debug-лог</label>
										</div>
									</div>
								</div>
							</div>
							<button type="submit" class="btn btn-primary btn-cons">
								Сохранить
							</button>
						</form>
					</div>
				</div>

				<!-- ─── Fintech: Kanyon / Pay2Day ────────────────────────────── -->
				<div class="card card-default m-b-20">
					<div class="card-header">
						<div class="card-title">Kanyon / Pay2Day — Учётные данные</div>
					</div>
					<div class="card-body">
						<form id="fintech-kanyon-form">
							<div class="row">
								<div class="col-md-4">
									<div class="form-group">
										<label for="fintech_pay2day_login">Логин (Login)</label>
										<input type="text" class="form-control" id="fintech_pay2day_login" name="fintech_pay2day_login"
										       value="<?php echo esc_attr( $fintech['pay2day_login'] ); ?>"
										       placeholder="your@login" autocomplete="off">
									</div>
								</div>
								<div class="col-md-4">
									<div class="form-group">
										<label for="fintech_pay2day_password">Пароль (Password)</label>
										<input type="password" class="form-control" id="fintech_pay2day_password" name="fintech_pay2day_password"
										       value="<?php echo esc_attr( $fintech['pay2day_password'] ); ?>"
										       placeholder="••••••••" autocomplete="new-password">
									</div>
								</div>
								<div class="col-md-2">
									<div class="form-group">
										<label for="fintech_pay2day_tsp_id">TSP ID</label>
										<input type="number" class="form-control" id="fintech_pay2day_tsp_id" name="fintech_pay2day_tsp_id"
										       value="<?php echo esc_attr( $fintech['pay2day_tsp_id'] ); ?>" min="0" placeholder="0">
									</div>
								</div>
								<div class="col-md-2">
									<div class="form-group">
										<label for="fintech_pay2day_order_currency">Код валюты</label>
										<input type="text" class="form-control" id="fintech_pay2day_order_currency" name="fintech_pay2day_order_currency"
										       value="<?php echo esc_attr( $fintech['pay2day_order_currency'] ); ?>"
										       placeholder="USDT" maxlength="8">
										<p class="hint-text m-t-3">Например: USDT</p>
									</div>
								</div>
							</div>
							<div class="row m-t-10 m-b-10">
								<div class="col-md-8">
									<div class="checkbox check-success">
										<input type="checkbox" id="fintech_kanyon_verify_signature" name="fintech_kanyon_verify_signature" value="1"
										       <?php checked( $fintech['kanyon_verify_signature'] ); ?>>
										<label for="fintech_kanyon_verify_signature">Проверять подпись callback (HMAC)</label>
									</div>
								</div>
							</div>
							<div class="row" id="kanyon-pubkey-row"<?php echo $fintech['kanyon_verify_signature'] ? '' : ' style="display:none"'; ?>>
								<div class="col-md-8">
									<div class="form-group">
										<label for="fintech_kanyon_public_key_pem">Публичный ключ провайдера (PEM)</label>
										<textarea class="form-control" id="fintech_kanyon_public_key_pem" name="fintech_kanyon_public_key_pem"
										          rows="5" style="font-family:monospace;font-size:12px"><?php echo esc_textarea( $fintech['kanyon_public_key_pem'] ); ?></textarea>
									</div>
								</div>
							</div>
							<button type="submit" class="btn btn-primary btn-cons m-t-10">
								Сохранить Kanyon
							</button>
						</form>
					</div>
				</div>

				<!-- ─── Fintech: Doverka ──────────────────────────────────────── -->
				<div class="card card-default m-b-30">
					<div class="card-header">
						<div class="card-title">Doverka — Учётные данные</div>
					</div>
					<div class="card-body">
						<form id="fintech-doverka-form">
							<div class="row">
								<div class="col-md-5">
									<div class="form-group">
										<label for="fintech_doverka_api_key">API Key</label>
										<input type="password" class="form-control" id="fintech_doverka_api_key" name="fintech_doverka_api_key"
										       value="<?php echo esc_attr( $fintech['doverka_api_key'] ); ?>"
										       placeholder="••••••••••••••••" autocomplete="new-password">
									</div>
								</div>
								<div class="col-md-2">
									<div class="form-group">
										<label for="fintech_doverka_currency_id">Currency ID</label>
										<input type="number" class="form-control" id="fintech_doverka_currency_id" name="fintech_doverka_currency_id"
										       value="<?php echo esc_attr( $fintech['doverka_currency_id'] ); ?>" min="0" placeholder="0">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-5">
									<div class="form-group">
										<label for="fintech_doverka_approve_url">Approve URL</label>
										<input type="url" class="form-control" id="fintech_doverka_approve_url" name="fintech_doverka_approve_url"
										       value="<?php echo esc_attr( $fintech['doverka_approve_url'] ); ?>" placeholder="https://...">
									</div>
								</div>
								<div class="col-md-5">
									<div class="form-group">
										<label for="fintech_doverka_kyc_redirect_url">KYC Redirect URL</label>
										<input type="url" class="form-control" id="fintech_doverka_kyc_redirect_url" name="fintech_doverka_kyc_redirect_url"
										       value="<?php echo esc_attr( $fintech['doverka_kyc_redirect_url'] ); ?>" placeholder="https://...">
									</div>
								</div>
							</div>
							<button type="submit" class="btn btn-primary btn-cons">
								Сохранить Doverka
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
		$('#system-settings-form'),
		$('#settings-alert'),
		function () { return { section: 'system', timezone: $('#timezone').val() }; },
		'Сохранить'
	);

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

	handleSettingsForm(
		$('#fintech-settings-form'),
		$('#settings-alert'),
		function () {
			return {
				section:                       'fintech_general',
				fintech_company_name:          $('#fintech_company_name').val(),
				fintech_merchant_order_prefix: $('#fintech_merchant_order_prefix').val(),
				fintech_active_provider:       $('#fintech_active_provider').val(),
				fintech_debug:                 $('#fintech_debug').is(':checked') ? '1' : '0',
			};
		},
		'Сохранить'
	);

	handleSettingsForm(
		$('#fintech-kanyon-form'),
		$('#settings-alert'),
		function () {
			return {
				section:                         'fintech_kanyon',
				fintech_pay2day_login:           $('#fintech_pay2day_login').val(),
				fintech_pay2day_password:        $('#fintech_pay2day_password').val(),
				fintech_pay2day_tsp_id:          $('#fintech_pay2day_tsp_id').val(),
				fintech_pay2day_order_currency:  $('#fintech_pay2day_order_currency').val(),
				fintech_kanyon_verify_signature: $('#fintech_kanyon_verify_signature').is(':checked') ? '1' : '0',
				fintech_kanyon_public_key_pem:   $('#fintech_kanyon_public_key_pem').val(),
			};
		},
		'Сохранить Kanyon'
	);

	handleSettingsForm(
		$('#fintech-doverka-form'),
		$('#settings-alert'),
		function () {
			return {
				section:                         'fintech_doverka',
				fintech_doverka_api_key:         $('#fintech_doverka_api_key').val(),
				fintech_doverka_currency_id:     $('#fintech_doverka_currency_id').val(),
				fintech_doverka_approve_url:     $('#fintech_doverka_approve_url').val(),
				fintech_doverka_kyc_redirect_url: $('#fintech_doverka_kyc_redirect_url').val(),
			};
		},
		'Сохранить Doverka'
	);

	// Toggle PEM key field visibility
	$('#fintech_kanyon_verify_signature').on('change', function () {
		$('#kanyon-pubkey-row').toggle(this.checked);
	});

}(jQuery));
</script>
<?php
}, 99 );
?>

<?php get_footer(); ?>
