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

// Fintech settings.
// Читаем ТОЛЬКО настройки данной компании. Никакого fallback на другие компании.
// Если настройки не заданы — $_fintech_not_configured = true, форма показывает предупреждение.
$fintech_status          = crm_fintech_get_configuration_status( $org_id );
$_fintech_not_configured = ! empty( $fintech_status['is_configured'] ) ? false : true;
$fintech                 = $fintech_status['settings'];
$fintech_missing_ids     = array_values( array_filter( array_map(
	static fn( $item ) => isset( $item['id'] ) ? (string) $item['id'] : '',
	$fintech_status['missing_fields'] ?? []
) ) );
$fintech_provider_labels       = crm_fintech_provider_labels();
$fintech_allowed_providers     = array_values( $fintech['allowed_providers'] ?? crm_fintech_default_allowed_providers() );
$fintech_active_provider_allowed = in_array( $fintech['active_provider'], $fintech_allowed_providers, true );
$_root_company_context         = null;

if ( ! function_exists( 'me_settings_render_fintech_status_html' ) ) {
	function me_settings_render_fintech_status_html( array $status ): string {
		$provider_label          = (string) ( $status['provider_label'] ?? 'Не выбран' );
		$blocked_reason          = trim( (string) ( $status['blocked_reason'] ?? '' ) );
		$allowed_provider_labels = array_values( array_filter( array_map(
			static fn( $item ) => (string) $item,
			$status['allowed_provider_labels'] ?? []
		) ) );
		$missing_general         = array_values( array_filter( array_map(
			static fn( $item ) => (string) ( $item['label'] ?? '' ),
			$status['missing_general'] ?? []
		) ) );
		$missing_provider        = array_values( array_filter( array_map(
			static fn( $item ) => (string) ( $item['label'] ?? '' ),
			$status['missing_provider'] ?? []
		) ) );

		ob_start();
		if ( ! empty( $status['is_configured'] ) ) :
			?>
			<div class="alert alert-success bordered m-b-15">
				<strong>Платёжный шлюз готов к работе.</strong><br>
				Активный провайдер: <?php echo esc_html( $provider_label ); ?>.
			</div>
			<?php
			else :
				?>
				<div class="alert alert-danger bordered m-b-15">
					<strong>Платёжные ордера сейчас заблокированы.</strong><br>
					<?php if ( $blocked_reason !== '' ) : ?>
						<?php echo esc_html( $blocked_reason ); ?>
					<?php elseif ( ! empty( $status['provider'] ) ) : ?>
						Сейчас выбран провайдер: <?php echo esc_html( $provider_label ); ?>.
					<?php else : ?>
						Сначала выберите активный провайдер в блоке общих настроек.
					<?php endif; ?>
					<?php if ( ! empty( $allowed_provider_labels ) ) : ?>
						<div class="m-t-10">
							<strong>Доступные контуры:</strong> <?php echo esc_html( implode( ', ', $allowed_provider_labels ) ); ?>.
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $missing_general ) || ! empty( $missing_provider ) ) : ?>
						<div class="m-t-10">
							<div class="bold fs-12">Что именно нужно заполнить:</div>
						<ul class="m-b-0 p-l-20">
							<?php if ( ! empty( $missing_general ) ) : ?>
								<li>В блоке «<?php echo esc_html( $status['general_section_label'] ?? 'Общие настройки' ); ?>»: <?php echo esc_html( implode( ', ', $missing_general ) ); ?>.</li>
							<?php endif; ?>
							<?php if ( ! empty( $missing_provider ) ) : ?>
								<li>В блоке «<?php echo esc_html( $status['provider_section_label'] ?? 'Настройки провайдера' ); ?>»: <?php echo esc_html( implode( ', ', $missing_provider ) ); ?>.</li>
							<?php endif; ?>
						</ul>
					</div>
					<div class="m-t-10">После заполнения нажмите «Сохранить» в общем блоке и в блоке активного провайдера.</div>
				<?php endif; ?>
			</div>
			<?php
		endif;

		return (string) ob_get_clean();
	}
}

$current_tz = $settings['timezone'] ?? 'UTC';

$pair       = rates_get_pair( RATES_PAIR_CODE, $org_id );
$coeff      = $pair ? rates_get_coefficient( (int) $pair->id, RATES_PROVIDER_EX24, RATES_PROVIDER_SOURCE ) : 0.05;

$vendor_img_uri = get_template_directory_uri() . '/vendor/pages/assets/img';
$nonce_save      = wp_create_nonce( 'me_settings_save' );

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

				<?php if ( $_root_company_context ) : ?>
				<div class="alert alert-info bordered m-b-20">
					<div class="d-flex align-items-center justify-content-between">
						<div>
							<strong>Настройки компании:</strong>
							<?php echo esc_html( $_root_company_context->name ); ?>
							<span class="hint-text m-l-5 fs-12">(#<?php echo (int) $_root_company_context->id; ?> · <?php echo esc_html( $_root_company_context->code ); ?>)</span>
						</div>
						<a href="<?php echo esc_url( home_url( '/users/#tab-companies' ) ); ?>" class="btn btn-sm btn-default">
							← Компании
						</a>
					</div>
				</div>
				<?php endif; ?>

				<!-- Алерт результата сохранения -->
				<div id="settings-alert" class="alert d-none m-b-20" role="alert"></div>
				<style>
					.crm-fintech-missing {
						border-color: #f55753 !important;
						background: #fff8f8 !important;
					}
					.crm-fintech-status-line {
						font-size: 12px;
						color: #626c75;
						margin-bottom: 14px;
					}
					.crm-fintech-status-line strong {
						color: #1f2d3d;
					}
				</style>

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
				<div id="fintech-config-alert">
					<?php echo me_settings_render_fintech_status_html( $fintech_status ); ?>
				</div>
				<div class="card card-default m-b-20">
					<div class="card-header">
						<div class="card-title">Платёжный шлюз — Общие настройки</div>
					</div>
					<div class="card-body">
						<div id="fintech-general-status" class="crm-fintech-status-line"></div>
						<form id="fintech-settings-form">
							<div class="row">
								<div class="col-md-4 col-lg-3">
									<div class="form-group">
										<label for="fintech_company_name">Название компании</label>
										<input type="text" class="form-control<?php echo in_array( 'fintech_company_name', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_company_name" name="fintech_company_name" data-fintech-field="fintech_company_name"
										       value="<?php echo esc_attr( $fintech['company_name'] ); ?>" placeholder="Malibu Exchange">
									</div>
								</div>
								<div class="col-md-3 col-lg-2">
									<div class="form-group">
										<label for="fintech_merchant_order_prefix">Префикс ордера</label>
										<input type="text" class="form-control<?php echo in_array( 'fintech_merchant_order_prefix', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_merchant_order_prefix" name="fintech_merchant_order_prefix" data-fintech-field="fintech_merchant_order_prefix"
										       value="<?php echo esc_attr( $fintech['merchant_order_prefix'] ); ?>" placeholder="MALIBU" maxlength="16">
									</div>
								</div>
									<div class="col-md-3 col-lg-2">
										<div class="form-group">
											<label for="fintech_active_provider">Активный провайдер</label>
											<select class="full-width<?php echo in_array( 'fintech_active_provider', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_active_provider" name="fintech_active_provider" data-init-plugin="select2" data-fintech-field="fintech_active_provider">
												<option value="" <?php selected( $fintech_active_provider_allowed ? $fintech['active_provider'] : '', '' ); ?>>
													<?php echo empty( $fintech_allowed_providers ) ? 'Нет доступных контуров' : 'Выберите доступный контур'; ?>
												</option>
												<?php foreach ( $fintech_allowed_providers as $provider_code ) : ?>
													<option value="<?php echo esc_attr( $provider_code ); ?>" <?php selected( $fintech_active_provider_allowed ? $fintech['active_provider'] : '', $provider_code ); ?>>
														<?php echo esc_html( $fintech_provider_labels[ $provider_code ] ?? $provider_code ); ?>
													</option>
												<?php endforeach; ?>
											</select>
											<p class="hint-text m-t-5">Список доступных контуров задаётся root на странице компаний.</p>
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
					<?php if ( in_array( 'kanyon', $fintech_allowed_providers, true ) ) : ?>
					<div class="card card-default m-b-20">
						<div class="card-header">
							<div class="card-title">Kanyon / Pay2Day — Учётные данные</div>
					</div>
					<div class="card-body">
						<div id="fintech-kanyon-status" class="crm-fintech-status-line"></div>
						<form id="fintech-kanyon-form">
							<div class="row">
								<div class="col-md-4">
									<div class="form-group">
										<label for="fintech_pay2day_login">Логин (Login)</label>
										<input type="text" class="form-control<?php echo in_array( 'fintech_pay2day_login', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_pay2day_login" name="fintech_pay2day_login" data-fintech-field="fintech_pay2day_login"
										       value="<?php echo esc_attr( $fintech['pay2day_login'] ); ?>"
										       placeholder="your@login" autocomplete="off">
									</div>
								</div>
								<div class="col-md-4">
									<div class="form-group">
										<label for="fintech_pay2day_password">Пароль (Password)</label>
										<input type="password" class="form-control<?php echo in_array( 'fintech_pay2day_password', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_pay2day_password" name="fintech_pay2day_password" data-fintech-field="fintech_pay2day_password"
										       value="<?php echo esc_attr( $fintech['pay2day_password'] ); ?>"
										       placeholder="••••••••" autocomplete="new-password">
									</div>
								</div>
								<div class="col-md-2">
									<div class="form-group">
										<label for="fintech_pay2day_tsp_id">TSP ID</label>
										<input type="number" class="form-control<?php echo in_array( 'fintech_pay2day_tsp_id', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_pay2day_tsp_id" name="fintech_pay2day_tsp_id" data-fintech-field="fintech_pay2day_tsp_id"
										       value="<?php echo esc_attr( $fintech['pay2day_tsp_id'] ); ?>" min="0" placeholder="0">
									</div>
								</div>
								<div class="col-md-2">
									<div class="form-group">
										<label for="fintech_pay2day_order_currency">Код валюты</label>
										<input type="text" class="form-control<?php echo in_array( 'fintech_pay2day_order_currency', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_pay2day_order_currency" name="fintech_pay2day_order_currency" data-fintech-field="fintech_pay2day_order_currency"
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
										<textarea class="form-control<?php echo in_array( 'fintech_kanyon_public_key_pem', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_kanyon_public_key_pem" name="fintech_kanyon_public_key_pem" data-fintech-field="fintech_kanyon_public_key_pem"
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
					<?php endif; ?>

					<!-- ─── Fintech: Doverka ──────────────────────────────────────── -->
					<?php if ( in_array( 'doverka', $fintech_allowed_providers, true ) ) : ?>
					<div class="card card-default m-b-30">
						<div class="card-header">
							<div class="card-title">Doverka — Учётные данные</div>
					</div>
					<div class="card-body">
						<div id="fintech-doverka-status" class="crm-fintech-status-line"></div>
						<form id="fintech-doverka-form">
							<div class="row">
								<div class="col-md-5">
									<div class="form-group">
										<label for="fintech_doverka_api_key">API Key</label>
										<input type="password" class="form-control<?php echo in_array( 'fintech_doverka_api_key', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_doverka_api_key" name="fintech_doverka_api_key" data-fintech-field="fintech_doverka_api_key"
										       value="<?php echo esc_attr( $fintech['doverka_api_key'] ); ?>"
										       placeholder="••••••••••••••••" autocomplete="new-password">
									</div>
								</div>
								<div class="col-md-2">
									<div class="form-group">
										<label for="fintech_doverka_currency_id">Currency ID</label>
										<input type="number" class="form-control<?php echo in_array( 'fintech_doverka_currency_id', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_doverka_currency_id" name="fintech_doverka_currency_id" data-fintech-field="fintech_doverka_currency_id"
										       value="<?php echo esc_attr( $fintech['doverka_currency_id'] ); ?>" min="0" placeholder="0">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-5">
									<div class="form-group">
										<label for="fintech_doverka_approve_url">Approve URL</label>
										<input type="url" class="form-control<?php echo in_array( 'fintech_doverka_approve_url', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_doverka_approve_url" name="fintech_doverka_approve_url" data-fintech-field="fintech_doverka_approve_url"
										       value="<?php echo esc_attr( $fintech['doverka_approve_url'] ); ?>" placeholder="https://...">
									</div>
								</div>
								<div class="col-md-5">
									<div class="form-group">
										<label for="fintech_doverka_kyc_redirect_url">KYC Redirect URL</label>
										<input type="url" class="form-control<?php echo in_array( 'fintech_doverka_kyc_redirect_url', $fintech_missing_ids, true ) ? ' crm-fintech-missing' : ''; ?>" id="fintech_doverka_kyc_redirect_url" name="fintech_doverka_kyc_redirect_url" data-fintech-field="fintech_doverka_kyc_redirect_url"
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
					<?php endif; ?>

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
add_action( 'wp_footer', function () use ( $nonce_save, $fintech_allowed_providers, $fintech_provider_labels ) {
?>
<script>
	(function ($) {
		'use strict';

		var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
		var NONCE    = '<?php echo esc_js( $nonce_save ); ?>';
		var FINTECH_ALLOWED_PROVIDERS = <?php echo wp_json_encode( array_values( $fintech_allowed_providers ) ); ?>;
		var FINTECH_PROVIDER_LABELS   = <?php echo wp_json_encode( $fintech_provider_labels ); ?>;
		var FINTECH_FORM_SELECTOR = '#fintech-settings-form, #fintech-kanyon-form, #fintech-doverka-form';

	function escapeHtml(value) {
		return $('<div>').text(value == null ? '' : String(value)).html();
	}

	function getTrimmedValue(selector) {
		return $.trim($(selector).val() || '');
	}

		function collectFintechStatus() {
			var provider = getTrimmedValue('#fintech_active_provider').toLowerCase();
			var providerLabels = FINTECH_PROVIDER_LABELS || {};
			var allowedProviders = (FINTECH_ALLOWED_PROVIDERS || []).slice();
			var missingGeneral = [];
			var missingProvider = [];
			var providerSectionLabel = '';
			var blockedReason = '';
			var providerUnavailable = false;
			var allowedProviderLabels = $.map(allowedProviders, function (providerCode) {
				return providerLabels[providerCode] || providerCode;
			});

			if (!getTrimmedValue('#fintech_company_name')) {
				missingGeneral.push({ id: 'fintech_company_name', label: 'Название компании' });
		}
			if (!getTrimmedValue('#fintech_merchant_order_prefix')) {
				missingGeneral.push({ id: 'fintech_merchant_order_prefix', label: 'Префикс ордера' });
			}
			if (!allowedProviders.length) {
				missingGeneral.push({ id: 'fintech_active_provider', label: 'Активный провайдер' });
				blockedReason = 'Для этой компании сейчас отключены все платёжные контуры.';
				provider = '';
			} else if (provider && allowedProviders.indexOf(provider) === -1) {
				missingGeneral.push({ id: 'fintech_active_provider', label: 'Активный провайдер' });
				blockedReason = 'Контур ' + (providerLabels[provider] || provider) + ' отключён в настройках компании. Выберите другой доступный контур.';
				providerUnavailable = true;
				provider = '';
			} else if (!providerLabels[provider]) {
				missingGeneral.push({ id: 'fintech_active_provider', label: 'Активный провайдер' });
				provider = '';
			}

			if (provider === 'kanyon' && $('#fintech-kanyon-form').length) {
				providerSectionLabel = 'Kanyon / Pay2Day — Учётные данные';
				if (!getTrimmedValue('#fintech_pay2day_login')) {
					missingProvider.push({ id: 'fintech_pay2day_login', label: 'Логин (Login)' });
			}
			if (!getTrimmedValue('#fintech_pay2day_password')) {
				missingProvider.push({ id: 'fintech_pay2day_password', label: 'Пароль (Password)' });
			}
			if ((parseInt($('#fintech_pay2day_tsp_id').val(), 10) || 0) <= 0) {
				missingProvider.push({ id: 'fintech_pay2day_tsp_id', label: 'TSP ID' });
			}
			if (!getTrimmedValue('#fintech_pay2day_order_currency')) {
				missingProvider.push({ id: 'fintech_pay2day_order_currency', label: 'Код валюты' });
			}
			if ($('#fintech_kanyon_verify_signature').is(':checked') && !getTrimmedValue('#fintech_kanyon_public_key_pem')) {
				missingProvider.push({ id: 'fintech_kanyon_public_key_pem', label: 'Публичный ключ провайдера (PEM)' });
			}
			} else if (provider === 'doverka' && $('#fintech-doverka-form').length) {
				providerSectionLabel = 'Doverka — Учётные данные';
				if (!getTrimmedValue('#fintech_doverka_api_key')) {
					missingProvider.push({ id: 'fintech_doverka_api_key', label: 'API Key' });
			}
			if ((parseInt($('#fintech_doverka_currency_id').val(), 10) || 0) <= 0) {
				missingProvider.push({ id: 'fintech_doverka_currency_id', label: 'Currency ID' });
			}
			if (!getTrimmedValue('#fintech_doverka_approve_url')) {
				missingProvider.push({ id: 'fintech_doverka_approve_url', label: 'Approve URL' });
			}
			if (!getTrimmedValue('#fintech_doverka_kyc_redirect_url')) {
				missingProvider.push({ id: 'fintech_doverka_kyc_redirect_url', label: 'KYC Redirect URL' });
			}
		}

		return {
			is_configured: provider !== '' && missingGeneral.length === 0 && missingProvider.length === 0,
			provider: provider,
				provider_label: provider ? providerLabels[provider] : 'Не выбран',
				general_section_label: 'Платёжный шлюз — Общие настройки',
				provider_section_label: providerSectionLabel,
				missing_general: missingGeneral,
				missing_provider: missingProvider,
				missing_fields: missingGeneral.concat(missingProvider),
				allowed_providers: allowedProviders,
				allowed_provider_labels: allowedProviderLabels,
				provider_unavailable: providerUnavailable,
				blocked_reason: blockedReason
			};
		}

	function labelsFromItems(items) {
		return $.map(items || [], function (item) {
			return item && item.label ? item.label : null;
		});
	}

		function buildFintechAlertHtml(status) {
			var missingGeneral = labelsFromItems(status.missing_general);
			var missingProvider = labelsFromItems(status.missing_provider);
			var allowedProviderLabels = status.allowed_provider_labels || [];

			if (status.is_configured) {
				return '' +
				'<div class="alert alert-success bordered m-b-15">' +
					'<strong>Платёжный шлюз готов к работе.</strong><br>' +
					'Активный провайдер: ' + escapeHtml(status.provider_label) + '.' +
				'</div>';
		}

			var html = '' +
				'<div class="alert alert-danger bordered m-b-15">' +
					'<strong>Платёжные ордера сейчас заблокированы.</strong><br>';

			if (status.blocked_reason) {
				html += escapeHtml(status.blocked_reason);
			} else if (status.provider) {
				html += 'Сейчас выбран провайдер: ' + escapeHtml(status.provider_label) + '.';
			} else {
				html += 'Сначала выберите активный провайдер в блоке общих настроек.';
			}

			if (allowedProviderLabels.length) {
				html += '<div class="m-t-10"><strong>Доступные контуры:</strong> ' + escapeHtml(allowedProviderLabels.join(', ')) + '.</div>';
			}

			if (missingGeneral.length || missingProvider.length) {
				html += '<div class="m-t-10"><div class="bold fs-12">Что именно нужно заполнить:</div><ul class="m-b-0 p-l-20">';
				if (missingGeneral.length) {
				html += '<li>В блоке «' + escapeHtml(status.general_section_label) + '»: ' + escapeHtml(missingGeneral.join(', ')) + '.</li>';
			}
			if (missingProvider.length) {
				html += '<li>В блоке «' + escapeHtml(status.provider_section_label || 'Настройки провайдера') + '»: ' + escapeHtml(missingProvider.join(', ')) + '.</li>';
			}
			html += '</ul></div><div class="m-t-10">После заполнения нажмите «Сохранить» в общем блоке и в блоке активного провайдера.</div>';
		}

		html += '</div>';

		return html;
	}

		function renderFintechBlockStatuses(status) {
			var generalText = (status.missing_general || []).length
				? '<strong>Не хватает в этом блоке:</strong> ' + escapeHtml(labelsFromItems(status.missing_general).join(', ')) + '.'
				: '<strong>Общий блок заполнен.</strong> Здесь должны быть название компании, префикс ордера и активный провайдер.';
			$('#fintech-general-status').html(generalText);

			if (status.provider === 'kanyon' && $('#fintech-kanyon-status').length) {
				$('#fintech-kanyon-status').html(
					(status.missing_provider || []).length
						? '<strong>Это активный провайдер.</strong> Не хватает: ' + escapeHtml(labelsFromItems(status.missing_provider).join(', ')) + '.'
						: '<strong>Это активный провайдер.</strong> Блок Kanyon заполнен.'
				);
				if ($('#fintech-doverka-status').length) {
					$('#fintech-doverka-status').html('Этот блок сейчас не обязателен. Он нужен только если активный провайдер = Doverka.');
				}
			} else if (status.provider === 'doverka' && $('#fintech-doverka-status').length) {
				$('#fintech-doverka-status').html(
					(status.missing_provider || []).length
						? '<strong>Это активный провайдер.</strong> Не хватает: ' + escapeHtml(labelsFromItems(status.missing_provider).join(', ')) + '.'
						: '<strong>Это активный провайдер.</strong> Блок Doverka заполнен.'
				);
				if ($('#fintech-kanyon-status').length) {
					$('#fintech-kanyon-status').html('Этот блок сейчас не обязателен. Он нужен только если активный провайдер = Kanyon.');
				}
			} else {
				if ($('#fintech-kanyon-status').length) {
					$('#fintech-kanyon-status').html('Сначала выберите активный провайдер в общем блоке.');
				}
				if ($('#fintech-doverka-status').length) {
					$('#fintech-doverka-status').html('Сначала выберите активный провайдер в общем блоке.');
				}
			}
		}

	function highlightFintechField(fieldId, isMissing) {
		var $field = $('#' + fieldId);
		$field.toggleClass('crm-fintech-missing', !!isMissing);
		if ($field.hasClass('select2-hidden-accessible')) {
			$field.next('.select2').find('.select2-selection').toggleClass('crm-fintech-missing', !!isMissing);
		}
	}

	function renderFintechStatus(status) {
		var missingIds = {};
		$.each(status.missing_fields || [], function (_, item) {
			if (item && item.id) {
				missingIds[item.id] = true;
			}
		});

		$('#fintech-config-alert').html(buildFintechAlertHtml(status));
		renderFintechBlockStatuses(status);

		$('[data-fintech-field]').each(function () {
			highlightFintechField($(this).attr('id'), !!missingIds[$(this).attr('id')]);
		});
	}

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
					if (res.data.fintech_status) {
						renderFintechStatus(res.data.fintech_status);
					} else if ($form.is(FINTECH_FORM_SELECTOR)) {
						renderFintechStatus(collectFintechStatus());
					}
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
		renderFintechStatus(collectFintechStatus());
	});

	$(document).on('input change', '[data-fintech-field], #fintech_active_provider, #fintech_kanyon_verify_signature', function () {
		renderFintechStatus(collectFintechStatus());
	});

	renderFintechStatus(collectFintechStatus());

}(jQuery));
</script>
<?php
}, 99 );
?>

<?php get_footer(); ?>
