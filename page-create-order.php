<?php
/*
Template Name: Create Order Page
Slug: create-order
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

if ( ! crm_can_access( 'orders.create' ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$nonce          = wp_create_nonce( 'me_orders_create' );
$current_user_id = get_current_user_id();
$company_id      = function_exists( 'crm_get_current_user_company_id' )
	? (int) crm_get_current_user_company_id( $current_user_id )
	: 0;
$web_modes       = function_exists( 'crm_fintech_company_web_create_order_supported_modes' )
	? crm_fintech_company_web_create_order_supported_modes( $company_id )
	: [ 'usdt' ];
$show_rub_usdt_web_form = in_array( 'rub_usdt', $web_modes, true );
$show_rub_usdt_live_web_form = in_array( 'rub_usdt_live', $web_modes, true );
$show_rub_thb_rub_form = in_array( 'rub_thb_rub_rapira', $web_modes, true ) || in_array( 'rub_thb_rub_live', $web_modes, true );
$show_rub_thb_thb_form = in_array( 'rub_thb_thb_rapira', $web_modes, true ) || in_array( 'rub_thb_thb_live', $web_modes, true );
$rub_usdt_default_payment_purpose = '';
$rub_usdt_preview = null;
$rub_thb_context = null;
$kanyon_live_last_rate = function_exists( 'rates_kanyon_get_last' ) ? rates_kanyon_get_last( $company_id ) : null;

if ( $company_id > 0 && function_exists( 'crm_fintech_get_pay2day_default_payment_purpose' ) ) {
	$rub_usdt_default_payment_purpose = crm_fintech_get_pay2day_default_payment_purpose( $company_id );
}

if ( $show_rub_usdt_web_form && function_exists( 'crm_fintech_company_web_rub_usdt_preview_context' ) ) {
	$rub_usdt_preview = crm_fintech_company_web_rub_usdt_preview_context( $company_id, 0.0, false );
}

if ( ( $show_rub_thb_rub_form || $show_rub_thb_thb_form ) && function_exists( 'crm_fintech_company_web_rub_thb_context' ) ) {
	$rub_thb_context = crm_fintech_company_web_rub_thb_context( $company_id );
}

get_header();
?>

<!-- BEGIN SIDEBAR-->
<?php get_template_part( 'template-parts/sidebar' ); ?>
<!-- END SIDEBAR -->

<div class="page-container">

	<?php get_template_part( 'template-parts/header-backoffice' ); ?>

	<div class="page-content-wrapper">
		<div class="content">

			<div class="jumbotron" data-pages="parallax">
				<div class="container-fluid container-fixed-lg sm-p-l-0 sm-p-r-0">
					<div class="inner">
						<ol class="breadcrumb">
							<li class="breadcrumb-item"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a></li>
							<li class="breadcrumb-item"><a href="<?php echo esc_url( home_url( '/orders/' ) ); ?>">Ордера</a></li>
							<li class="breadcrumb-item active">Создать ордер</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">
				<div class="row justify-content-center">
					<div class="col-12 col-xxl-6 col-xl-7 col-lg-8 col-md-10">

						<!-- ─── Форма создания ─────────────────────────────── -->
						<form id="create-order-form">
							<div class="card card-default" id="create-order-card">
								<div class="card-header">
									<div class="card-title">Новый платёжный ордер</div>
								</div>
								<div class="card-body">
									<?php get_template_part( 'template-parts/order-create-form' ); ?>
								</div>
								<div class="card-footer p-t-10 p-b-10 p-l-20 p-r-20">
									<div class="d-flex justify-content-end">
										<button type="submit" id="btn-create-order" class="btn btn-primary btn-cons">
											<i class="pg-icon m-r-5">add</i>Создать ордер
										</button>
									</div>
								</div>
							</div>
						</form>

						<?php if ( $show_rub_usdt_web_form ) : ?>
						<form id="create-order-rub-usdt-form" class="m-t-20">
							<div class="card card-default" id="create-order-rub-usdt-card">
								<div class="card-header">
									<div class="card-title">Новый платёжный ордер (RUB -&gt; USDT)</div>
								</div>
								<div class="card-body">
									<div id="moc-rub-usdt-alert" class="alert d-none m-b-15" role="alert"></div>

									<div class="form-group">
										<label for="moc-rub-usdt-amount-value">Сумма в RUB <span class="text-danger">*</span></label>
										<div class="input-group">
											<input type="number"
											       id="moc-rub-usdt-amount-value"
											       name="amount_value"
											       class="form-control"
											       min="0.01"
											       step="0.01"
											       placeholder="например 30000"
											       required
											       autocomplete="off">
											<span class="input-group-text">RUB</span>
										</div>
										<p class="hint-text m-t-5">
											Введите сумму в RUB. Мы рассчитаем USDT по Rapira Ask + 4% и под капотом выпустим legacy Kanyon USDT contour.
										</p>
									</div>

									<div class="card card-default m-b-15">
										<div class="card-body p-t-15 p-b-15">
											<div class="fs-11 all-caps hint-text m-b-10">RUB -> USDT расчёт</div>
											<div id="moc-rub-usdt-preview-ok" class="d-none">
												<div class="m-b-5">
													<span class="hint-text">Rapira Ask:</span>
													<strong id="moc-rub-usdt-ask">—</strong>
												</div>
												<div class="m-b-5">
													<span class="hint-text">Наценка:</span>
													<strong id="moc-rub-usdt-markup">—</strong>
												</div>
												<div class="m-b-5">
													<span class="hint-text">Расчётный курс:</span>
													<strong id="moc-rub-usdt-rate">—</strong>
												</div>
												<div class="m-b-5">
													<span class="hint-text" id="moc-rub-usdt-caption">Пример:</span>
													<strong id="moc-rub-usdt-estimate">—</strong>
												</div>
												<div class="hint-text fs-12">
													USDT-значение считается для ориентира. В момент создания ордера сервер заново берёт живой Rapira Ask.
												</div>
											</div>
											<div id="moc-rub-usdt-preview-error" class="alert alert-warning d-none no-margin"></div>
										</div>
									</div>

									<div class="form-group">
										<label for="moc-rub-usdt-description">Назначение платежа <span class="text-muted">(можно изменить)</span></label>
										<input type="text"
										       id="moc-rub-usdt-description"
										       name="description"
										       class="form-control"
										       maxlength="200"
										       value="<?php echo esc_attr( $rub_usdt_default_payment_purpose ); ?>"
										       placeholder="например, Одежда">
										<p class="hint-text m-t-5">
											<?php if ( $rub_usdt_default_payment_purpose !== '' ) : ?>
												Подставлено значение по умолчанию из настроек компании. При необходимости его можно заменить перед выпуском ордера.
											<?php else : ?>
												Если поле оставить пустым, будет использовано значение по умолчанию из настроек компании, если оно задано.
											<?php endif; ?>
										</p>
									</div>
								</div>
								<div class="card-footer p-t-10 p-b-10 p-l-20 p-r-20">
									<div class="d-flex justify-content-end">
										<button type="submit" id="btn-create-order-rub-usdt" class="btn btn-primary btn-cons">
											<i class="pg-icon m-r-5">add</i>Создать ордер
										</button>
									</div>
								</div>
							</div>
						</form>
						<?php endif; ?>

						<?php if ( $show_rub_usdt_live_web_form ) : ?>
						<form id="create-order-rub-usdt-live-form" class="m-t-20">
							<div class="card card-default" id="create-order-rub-usdt-live-card">
								<div class="card-header">
									<div class="card-title">Новый платёжный ордер (RUB -> USDT через live Kanyon)</div>
								</div>
								<div class="card-body">
									<div id="moc-rub-usdt-live-alert" class="alert d-none m-b-15" role="alert"></div>

									<div class="form-group">
										<label for="moc-rub-usdt-live-amount-value">Сумма в RUB <span class="text-danger">*</span></label>
										<div class="input-group">
											<input type="number"
											       id="moc-rub-usdt-live-amount-value"
											       name="amount_value"
											       class="form-control"
											       min="0.01"
											       step="0.01"
											       placeholder="например 50000"
											       required
											       autocomplete="off">
											<span class="input-group-text">RUB</span>
										</div>
										<p class="hint-text m-t-5">
											При создании мы сначала делаем test-order Kanyon на <strong>100 USDT</strong>, берём фактический текущий курс провайдера и по нему считаем финальный USDT для основного ордера.
										</p>
									</div>

									<div class="card card-default m-b-15">
										<div class="card-body p-t-15 p-b-15">
											<div class="fs-11 all-caps hint-text m-b-10">Live Kanyon quote</div>
											<?php if ( ! empty( $kanyon_live_last_rate ) ) : ?>
											<div class="m-b-5">
												<span class="hint-text">Последний сохранённый Kanyon:</span>
												<strong><?php echo number_format( (float) $kanyon_live_last_rate['kanyon_rate'], 4 ); ?> RUB за 1 USDT</strong>
											</div>
											<div class="hint-text fs-12">
												Снято: <?php echo esc_html( (string) $kanyon_live_last_rate['created_at'] ); ?>.
												Это только ориентир; при создании ордера будет запрошен новый live-курс через test-order.
											</div>
											<?php else : ?>
											<div class="hint-text fs-12">
												Сейчас сохранённого Kanyon-курса нет. При создании ордера live-курс всё равно будет запрошен автоматически через test-order.
											</div>
											<?php endif; ?>
										</div>
									</div>

									<div class="form-group">
										<label for="moc-rub-usdt-live-description">Назначение платежа <span class="text-muted">(можно изменить)</span></label>
										<input type="text"
										       id="moc-rub-usdt-live-description"
										       name="description"
										       class="form-control"
										       maxlength="200"
										       value="<?php echo esc_attr( $rub_usdt_default_payment_purpose ); ?>"
										       placeholder="например, Одежда">
										<p class="hint-text m-t-5">
											<?php if ( $rub_usdt_default_payment_purpose !== '' ) : ?>
												Подставлено значение по умолчанию из настроек компании. При необходимости его можно заменить перед выпуском ордера.
											<?php else : ?>
												Если поле оставить пустым, будет использовано значение по умолчанию из настроек компании, если оно задано.
											<?php endif; ?>
										</p>
									</div>
								</div>
								<div class="card-footer p-t-10 p-b-10 p-l-20 p-r-20">
									<div class="d-flex justify-content-end">
										<button type="submit" id="btn-create-order-rub-usdt-live" class="btn btn-primary btn-cons">
											<i class="pg-icon m-r-5">add</i>Создать ордер
										</button>
									</div>
								</div>
							</div>
						</form>
						<?php endif; ?>

						<?php if ( $show_rub_thb_rub_form ) : ?>
						<form id="create-order-rub-thb-rub-form" class="m-t-20">
							<div class="card card-default" id="create-order-rub-thb-rub-card">
								<div class="card-header">
									<div class="card-title">Новый платёжный ордер (THB contour / ввод RUB)</div>
								</div>
								<div class="card-body">
									<div id="moc-rub-thb-rub-alert" class="alert d-none m-b-15" role="alert"></div>

									<div class="form-group">
										<label for="moc-rub-thb-rub-amount-value">Сумма в RUB <span class="text-danger">*</span></label>
										<div class="input-group">
											<input type="number"
											       id="moc-rub-thb-rub-amount-value"
											       name="amount_value"
											       class="form-control"
											       min="0.01"
											       step="0.01"
											       placeholder="например 50000"
											       required
											       autocomplete="off">
											<span class="input-group-text">RUB</span>
										</div>
										<p class="hint-text m-t-5">
											Введите RUB. Мы возьмём сохранённый курс <strong>Наш Sberbank</strong>, посчитаем эквивалент THB и затем выпустим RUB-счёт через Kanyon USDT contour.
										</p>
									</div>

									<div class="form-group">
										<label for="moc-rub-thb-rub-mode">Стратегия USDT-ориентира</label>
										<select id="moc-rub-thb-rub-mode" name="amount_mode" class="full-width" data-init-plugin="select2" data-select2-hide-search="1">
											<option value="rub_thb_rub_rapira">Rapira Ask + 4%</option>
											<option value="rub_thb_rub_live">Live Kanyon (test-order 100 USDT)</option>
										</select>
										<p class="hint-text m-t-5">
											Rapira работает быстрее. Live Kanyon медленнее, но точнее подстраивается под текущий курс провайдера.
										</p>
									</div>

									<div class="card card-default m-b-15">
										<div class="card-body p-t-15 p-b-15">
											<div class="fs-11 all-caps hint-text m-b-10">RUB -> THB ориентир</div>
											<div id="moc-rub-thb-rub-preview-ok" class="d-none">
												<div class="m-b-5">
													<span class="hint-text">Наш Sberbank:</span>
													<strong id="moc-rub-thb-rub-rate">—</strong>
												</div>
												<div class="m-b-5">
													<span class="hint-text">Эквивалент THB:</span>
													<strong id="moc-rub-thb-rub-thb">—</strong>
												</div>
												<div class="m-b-5">
													<span class="hint-text">Целевой RUB-счёт:</span>
													<strong id="moc-rub-thb-rub-target-rub">—</strong>
												</div>
												<div class="m-b-5">
													<span class="hint-text" id="moc-rub-thb-rub-usdt-label">Ориентир USDT:</span>
													<strong id="moc-rub-thb-rub-usdt">—</strong>
												</div>
												<div class="hint-text fs-12" id="moc-rub-thb-rub-note">
													Финальный RUB-счёт фиксируется провайдером в момент создания ордера.
												</div>
											</div>
											<div id="moc-rub-thb-rub-preview-error" class="alert alert-warning d-none no-margin"></div>
										</div>
									</div>

									<div class="form-group">
										<label for="moc-rub-thb-rub-description">Назначение платежа <span class="text-muted">(можно изменить)</span></label>
										<input type="text"
										       id="moc-rub-thb-rub-description"
										       name="description"
										       class="form-control"
										       maxlength="200"
										       value="<?php echo esc_attr( $rub_usdt_default_payment_purpose ); ?>"
										       placeholder="например, Одежда">
									</div>
								</div>
								<div class="card-footer p-t-10 p-b-10 p-l-20 p-r-20">
									<div class="d-flex justify-content-end">
										<button type="submit" id="btn-create-order-rub-thb-rub" class="btn btn-primary btn-cons">
											<i class="pg-icon m-r-5">add</i>Создать ордер
										</button>
									</div>
								</div>
							</div>
						</form>
						<?php endif; ?>

						<?php if ( $show_rub_thb_thb_form ) : ?>
						<form id="create-order-rub-thb-thb-form" class="m-t-20">
							<div class="card card-default" id="create-order-rub-thb-thb-card">
								<div class="card-header">
									<div class="card-title">Новый платёжный ордер (THB contour / ввод THB)</div>
								</div>
								<div class="card-body">
									<div id="moc-rub-thb-thb-alert" class="alert d-none m-b-15" role="alert"></div>

									<div class="form-group">
										<label for="moc-rub-thb-thb-amount-value">Сумма в THB <span class="text-danger">*</span></label>
										<div class="input-group">
											<input type="number"
											       id="moc-rub-thb-thb-amount-value"
											       name="amount_value"
											       class="form-control"
											       min="0.01"
											       step="0.01"
											       placeholder="например 10000"
											       required
											       autocomplete="off">
											<span class="input-group-text">THB</span>
										</div>
										<p class="hint-text m-t-5">
											Введите THB. Мы возьмём сохранённый курс <strong>Наш Sberbank</strong>, рассчитаем целевой RUB-счёт и выпустим его через Kanyon USDT contour.
										</p>
									</div>

									<div class="form-group">
										<label for="moc-rub-thb-thb-mode">Стратегия USDT-ориентира</label>
										<select id="moc-rub-thb-thb-mode" name="amount_mode" class="full-width" data-init-plugin="select2" data-select2-hide-search="1">
											<option value="rub_thb_thb_rapira">Rapira Ask + 4%</option>
											<option value="rub_thb_thb_live">Live Kanyon (test-order 100 USDT)</option>
										</select>
										<p class="hint-text m-t-5">
											Rapira работает быстрее. Live Kanyon медленнее, но точнее подстраивается под текущий курс провайдера.
										</p>
									</div>

									<div class="card card-default m-b-15">
										<div class="card-body p-t-15 p-b-15">
											<div class="fs-11 all-caps hint-text m-b-10">THB -> RUB ориентир</div>
											<div id="moc-rub-thb-thb-preview-ok" class="d-none">
												<div class="m-b-5">
													<span class="hint-text">Наш Sberbank:</span>
													<strong id="moc-rub-thb-thb-rate">—</strong>
												</div>
												<div class="m-b-5">
													<span class="hint-text">Целевой RUB-счёт:</span>
													<strong id="moc-rub-thb-thb-target-rub">—</strong>
												</div>
												<div class="m-b-5">
													<span class="hint-text">Эквивалент THB:</span>
													<strong id="moc-rub-thb-thb-thb">—</strong>
												</div>
												<div class="m-b-5">
													<span class="hint-text" id="moc-rub-thb-thb-usdt-label">Ориентир USDT:</span>
													<strong id="moc-rub-thb-thb-usdt">—</strong>
												</div>
												<div class="hint-text fs-12" id="moc-rub-thb-thb-note">
													Финальный RUB-счёт фиксируется провайдером в момент создания ордера.
												</div>
											</div>
											<div id="moc-rub-thb-thb-preview-error" class="alert alert-warning d-none no-margin"></div>
										</div>
									</div>

									<div class="form-group">
										<label for="moc-rub-thb-thb-description">Назначение платежа <span class="text-muted">(можно изменить)</span></label>
										<input type="text"
										       id="moc-rub-thb-thb-description"
										       name="description"
										       class="form-control"
										       maxlength="200"
										       value="<?php echo esc_attr( $rub_usdt_default_payment_purpose ); ?>"
										       placeholder="например, Одежда">
									</div>
								</div>
								<div class="card-footer p-t-10 p-b-10 p-l-20 p-r-20">
									<div class="d-flex justify-content-end">
										<button type="submit" id="btn-create-order-rub-thb-thb" class="btn btn-primary btn-cons">
											<i class="pg-icon m-r-5">add</i>Создать ордер
										</button>
									</div>
								</div>
							</div>
						</form>
						<?php endif; ?>

					</div>
				</div>

				<div class="row">
					<div class="col-lg-6 col-md-8">

						<!-- ─── Результат: стилизованный чек ───────────────── -->
						<div id="order-result" class="d-none">
							<div class="receipt-inline-frame" id="order-receipt-inline"></div>

							<!-- Ссылка оплаты -->
							<div id="or-link-block" class="m-t-20" style="max-width:380px;margin-left:auto;margin-right:auto;">
								<label class="text-muted small d-block m-b-5">Ссылка для оплаты</label>
								<div class="input-group">
									<input type="text" id="or-payment-link" class="form-control form-control-sm" readonly>
									<button class="btn btn-sm btn-default" id="btn-copy-link" type="button" title="Копировать">
										<i class="pg-icon">copy</i>
									</button>
								</div>
							</div>

							<div class="d-flex gap-2 m-t-20 justify-content-center">
								<a href="<?php echo esc_url( home_url( '/orders/' ) ); ?>"
								   class="btn btn-default btn-sm">
									Все ордера
								</a>
								<button type="button" id="btn-new-order" class="btn btn-primary btn-sm">
									Новый ордер
								</button>
							</div>
						</div>
						<!-- /.order-result -->

					</div>
				</div>
			</div><!-- /.container-fluid -->
		</div>

		<?php get_template_part( 'template-parts/footer-backoffice' ); ?>
	</div>
</div>

<?php get_template_part( 'template-parts/toast-host' ); ?>
<?php get_template_part( 'template-parts/order-receipt' ); ?>
<?php get_template_part( 'template-parts/quickview' ); ?>
<?php get_template_part( 'template-parts/overlay' ); ?>

<?php
add_action( 'wp_footer', function () use ( $nonce, $show_rub_usdt_web_form, $show_rub_usdt_live_web_form, $show_rub_thb_rub_form, $show_rub_thb_thb_form, $rub_usdt_preview, $rub_thb_context, $rub_usdt_default_payment_purpose, $kanyon_live_last_rate ) {
?>
<script>
(function ($) {
	'use strict';

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	// Nonce для me_orders_check_status (того же семейства me_orders_list)
	var CHECK_NONCE = '<?php echo esc_js( wp_create_nonce( 'me_orders_list' ) ); ?>';
	var NONCE    = '<?php echo esc_js( $nonce ); ?>';
	var RUB_USDT_FORM_ENABLED = <?php echo $show_rub_usdt_web_form ? 'true' : 'false'; ?>;
	var RUB_USDT_LIVE_FORM_ENABLED = <?php echo $show_rub_usdt_live_web_form ? 'true' : 'false'; ?>;
	var RUB_THB_RUB_FORM_ENABLED = <?php echo $show_rub_thb_rub_form ? 'true' : 'false'; ?>;
	var RUB_THB_THB_FORM_ENABLED = <?php echo $show_rub_thb_thb_form ? 'true' : 'false'; ?>;
	var RUB_USDT_PREVIEW = <?php echo function_exists( 'crm_json_for_inline_js' ) ? crm_json_for_inline_js( $rub_usdt_preview ) : wp_json_encode( $rub_usdt_preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
	var RUB_THB_CONTEXT = <?php echo function_exists( 'crm_json_for_inline_js' ) ? crm_json_for_inline_js( $rub_thb_context ) : wp_json_encode( $rub_thb_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
	var RUB_USDT_DEFAULT_PURPOSE = <?php echo wp_json_encode( $rub_usdt_default_payment_purpose, JSON_UNESCAPED_UNICODE ); ?>;
	var LAST_KANYON_RATE = <?php echo function_exists( 'crm_json_for_inline_js' ) ? crm_json_for_inline_js( $kanyon_live_last_rate ) : wp_json_encode( $kanyon_live_last_rate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;

	// Конфигурируем общий чек под AJAX проверки статуса
	if (window.MalibuOrderReceipt && MalibuOrderReceipt.configure) {
		MalibuOrderReceipt.configure({ ajaxUrl: AJAX_URL, nonce: CHECK_NONCE });
	}

	function showCreatedOrder(d) {
		var receiptData = {
			id:                   d.order_db_id,
			status_code:          'created',
			merchant_order_id:    d.merchant_order_id,
			payment_amount_value: d.payment_amount_rub,
			qr_url:               d.qr_url,
			created_at:           d.created_at || new Date().toISOString().slice(0, 19).replace('T', ' '),
		};

		MalibuOrderReceipt.renderInto('#order-receipt-inline', receiptData, {
			onStatusChange: function () {
			},
		});

		if (d.payment_link) {
			$('#or-payment-link').val(d.payment_link);
			$('#or-link-block').show();
		} else {
			$('#or-link-block').hide();
		}

		$(getCreateOrderCardsSelector()).addClass('d-none');
		$('#order-result').removeClass('d-none');

		if (d.warning && window.MalibuToast && MalibuToast.show) {
			MalibuToast.show(d.warning, 'warning');
		}
	}

	function formatRuNumber(value, minDecimals, maxDecimals) {
		if (value === null || value === undefined || isNaN(value)) {
			return '—';
		}

		return Number(value).toLocaleString('ru-RU', {
			minimumFractionDigits: minDecimals,
			maximumFractionDigits: maxDecimals
		});
	}

	function getCreateOrderCardsSelector() {
		return '#create-order-card, #create-order-rub-usdt-card, #create-order-rub-usdt-live-card, #create-order-rub-thb-rub-card, #create-order-rub-thb-thb-card';
	}

	function getLastKanyonRateValue() {
		if (!LAST_KANYON_RATE || LAST_KANYON_RATE.kanyon_rate === undefined || LAST_KANYON_RATE.kanyon_rate === null) {
			return 0;
		}

		var rate = parseFloat(LAST_KANYON_RATE.kanyon_rate);
		return isNaN(rate) || rate <= 0 ? 0 : rate;
	}

	function estimateUsdtByMode(targetRub, mode) {
		var useLive = (mode || '').indexOf('_live') !== -1;
		var rapiraRate = RUB_USDT_PREVIEW && RUB_USDT_PREVIEW.success ? parseFloat(RUB_USDT_PREVIEW.effective_rate || 0) : 0;
		var liveRate = getLastKanyonRateValue();

		if (useLive) {
			if (liveRate > 0 && targetRub > 0) {
				return {
					label: 'Ориентир USDT по последнему Kanyon:',
					valueText: formatRuNumber(targetRub, 2, 2) + ' RUB ≈ ' + formatRuNumber(targetRub / liveRate, 2, 2) + ' USDT',
					note: 'Это ориентир по последнему сохранённому Kanyon rate. При создании ордера будет запрошен новый live quote через test-order 100 USDT.'
				};
			}

			return {
				label: 'Live Kanyon:',
				valueText: 'Ориентир USDT появится после live quote',
				note: 'Сейчас нет последнего Kanyon rate. При создании ордера live quote будет запрошен автоматически.'
			};
		}

		if (rapiraRate > 0 && targetRub > 0) {
			return {
				label: 'Ориентир USDT по Rapira:',
				valueText: formatRuNumber(targetRub, 2, 2) + ' RUB ≈ ' + formatRuNumber(targetRub / rapiraRate, 2, 2) + ' USDT',
				note: 'Ориентир считается по Rapira Ask + 4%. В момент создания ордера сервер заново возьмёт свежий Rapira Ask.'
			};
		}

		return {
			label: 'Rapira:',
			valueText: 'Сейчас не удалось получить ориентир USDT',
			note: 'Свежий Rapira Ask сейчас недоступен. Сервер всё равно повторно проверит курс в момент создания ордера.'
		};
	}

	function rubUsdtShowAlert(msg, type) {
		$('#moc-rub-usdt-alert')
			.removeClass('d-none alert-success alert-danger alert-warning alert-info')
			.addClass('alert-' + (type || 'danger'))
			.text(msg);
	}

	function rubUsdtHideAlert() {
		$('#moc-rub-usdt-alert').addClass('d-none');
	}

	function renderRubUsdtPreview() {
		if (!RUB_USDT_FORM_ENABLED) {
			return;
		}

		var preview = RUB_USDT_PREVIEW || null;
		var $ok = $('#moc-rub-usdt-preview-ok');
		var $error = $('#moc-rub-usdt-preview-error');

		if (!preview || !preview.success || !preview.effective_rate) {
			$ok.addClass('d-none');
			$error
				.removeClass('d-none')
				.text((preview && preview.error) ? preview.error : 'Сейчас не удалось получить Rapira Ask. Попробуйте создать ордер позже.');
			return;
		}

		var effectiveRate = parseFloat(preview.effective_rate || 0);
		var sampleRub = parseFloat(preview.sample_requested_rub || 30000);
		var inputRub = parseFloat($('#moc-rub-usdt-amount-value').val());
		var hasInput = !isNaN(inputRub) && inputRub > 0;
		var targetRub = hasInput ? inputRub : sampleRub;
		var estimatedUsdt = effectiveRate > 0 ? Math.round((targetRub / effectiveRate) * 100) / 100 : 0;

		$error.addClass('d-none').text('');
		$ok.removeClass('d-none');
		$('#moc-rub-usdt-ask').text(formatRuNumber(preview.rapira_ask, 4, 8) + ' RUB');
		$('#moc-rub-usdt-markup').text('+' + formatRuNumber(preview.markup_percent || 0, 2, 4) + '%');
		$('#moc-rub-usdt-rate').text(formatRuNumber(effectiveRate, 4, 4) + ' RUB за 1 USDT');
		$('#moc-rub-usdt-caption').text(hasInput ? 'Ориентир по введённой сумме:' : 'Пример:');
		$('#moc-rub-usdt-estimate').text(
			formatRuNumber(targetRub, 2, 2) + ' RUB ≈ ' + formatRuNumber(estimatedUsdt, 2, 2) + ' USDT'
		);
	}

	function resetRubUsdtForm() {
		if (!RUB_USDT_FORM_ENABLED) {
			return;
		}

		$('#moc-rub-usdt-amount-value').val('');
		$('#moc-rub-usdt-description').val(RUB_USDT_DEFAULT_PURPOSE);
		rubUsdtHideAlert();
		renderRubUsdtPreview();
	}

	function rubUsdtLiveShowAlert(msg, type) {
		$('#moc-rub-usdt-live-alert')
			.removeClass('d-none alert-success alert-danger alert-warning alert-info')
			.addClass('alert-' + (type || 'danger'))
			.text(msg);
	}

	function rubUsdtLiveHideAlert() {
		$('#moc-rub-usdt-live-alert').addClass('d-none');
	}

	function resetRubUsdtLiveForm() {
		if (!RUB_USDT_LIVE_FORM_ENABLED) {
			return;
		}

		$('#moc-rub-usdt-live-amount-value').val('');
		$('#moc-rub-usdt-live-description').val(RUB_USDT_DEFAULT_PURPOSE);
		rubUsdtLiveHideAlert();
	}

	function rubThbRubShowAlert(msg, type) {
		$('#moc-rub-thb-rub-alert')
			.removeClass('d-none alert-success alert-danger alert-warning alert-info')
			.addClass('alert-' + (type || 'danger'))
			.text(msg);
	}

	function rubThbRubHideAlert() {
		$('#moc-rub-thb-rub-alert').addClass('d-none');
	}

	function renderRubThbRubPreview() {
		if (!RUB_THB_RUB_FORM_ENABLED) {
			return;
		}

		var preview = RUB_THB_CONTEXT || null;
		var $ok = $('#moc-rub-thb-rub-preview-ok');
		var $error = $('#moc-rub-thb-rub-preview-error');

		if (!preview || !preview.success || !preview.rub_per_thb_rate) {
			$ok.addClass('d-none');
			$error
				.removeClass('d-none')
				.text((preview && preview.error) ? preview.error : 'Сейчас нет сохранённого курса "Наш Sberbank" для RUB/THB.');
			return;
		}

		var thbRate = parseFloat(preview.rub_per_thb_rate || 0);
		var inputRub = parseFloat($('#moc-rub-thb-rub-amount-value').val());
		var hasInput = !isNaN(inputRub) && inputRub > 0;
		var targetRub = hasInput ? inputRub : 50000;
		var targetThb = thbRate > 0 ? (targetRub / thbRate) : 0;
		var mode = $('#moc-rub-thb-rub-mode').val() || 'rub_thb_rub_rapira';
		var usdtPreview = estimateUsdtByMode(targetRub, mode);

		$error.addClass('d-none').text('');
		$ok.removeClass('d-none');
		$('#moc-rub-thb-rub-rate').text(formatRuNumber(thbRate, 4, 4) + ' RUB за 1 THB');
		$('#moc-rub-thb-rub-thb').text(formatRuNumber(targetThb, 2, 2) + ' THB');
		$('#moc-rub-thb-rub-target-rub').text(formatRuNumber(targetRub, 2, 2) + ' RUB');
		$('#moc-rub-thb-rub-usdt-label').text(usdtPreview.label);
		$('#moc-rub-thb-rub-usdt').text(usdtPreview.valueText);
		$('#moc-rub-thb-rub-note').text(usdtPreview.note);
	}

	function resetRubThbRubForm() {
		if (!RUB_THB_RUB_FORM_ENABLED) {
			return;
		}

		$('#moc-rub-thb-rub-amount-value').val('');
		$('#moc-rub-thb-rub-mode').val('rub_thb_rub_rapira').trigger('change.select2');
		$('#moc-rub-thb-rub-description').val(RUB_USDT_DEFAULT_PURPOSE);
		rubThbRubHideAlert();
		renderRubThbRubPreview();
	}

	function rubThbThbShowAlert(msg, type) {
		$('#moc-rub-thb-thb-alert')
			.removeClass('d-none alert-success alert-danger alert-warning alert-info')
			.addClass('alert-' + (type || 'danger'))
			.text(msg);
	}

	function rubThbThbHideAlert() {
		$('#moc-rub-thb-thb-alert').addClass('d-none');
	}

	function renderRubThbThbPreview() {
		if (!RUB_THB_THB_FORM_ENABLED) {
			return;
		}

		var preview = RUB_THB_CONTEXT || null;
		var $ok = $('#moc-rub-thb-thb-preview-ok');
		var $error = $('#moc-rub-thb-thb-preview-error');

		if (!preview || !preview.success || !preview.rub_per_thb_rate) {
			$ok.addClass('d-none');
			$error
				.removeClass('d-none')
				.text((preview && preview.error) ? preview.error : 'Сейчас нет сохранённого курса "Наш Sberbank" для RUB/THB.');
			return;
		}

		var thbRate = parseFloat(preview.rub_per_thb_rate || 0);
		var inputThb = parseFloat($('#moc-rub-thb-thb-amount-value').val());
		var hasInput = !isNaN(inputThb) && inputThb > 0;
		var targetThb = hasInput ? inputThb : 10000;
		var targetRub = thbRate > 0 ? (targetThb * thbRate) : 0;
		var mode = $('#moc-rub-thb-thb-mode').val() || 'rub_thb_thb_rapira';
		var usdtPreview = estimateUsdtByMode(targetRub, mode);

		$error.addClass('d-none').text('');
		$ok.removeClass('d-none');
		$('#moc-rub-thb-thb-rate').text(formatRuNumber(thbRate, 4, 4) + ' RUB за 1 THB');
		$('#moc-rub-thb-thb-target-rub').text(formatRuNumber(targetRub, 2, 2) + ' RUB');
		$('#moc-rub-thb-thb-thb').text(formatRuNumber(targetThb, 2, 2) + ' THB');
		$('#moc-rub-thb-thb-usdt-label').text(usdtPreview.label);
		$('#moc-rub-thb-thb-usdt').text(usdtPreview.valueText);
		$('#moc-rub-thb-thb-note').text(usdtPreview.note);
	}

	function resetRubThbThbForm() {
		if (!RUB_THB_THB_FORM_ENABLED) {
			return;
		}

		$('#moc-rub-thb-thb-amount-value').val('');
		$('#moc-rub-thb-thb-mode').val('rub_thb_thb_rapira').trigger('change.select2');
		$('#moc-rub-thb-thb-description').val(RUB_USDT_DEFAULT_PURPOSE);
		rubThbThbHideAlert();
		renderRubThbThbPreview();
	}

	$('#create-order-form').on('submit', function (e) {
		e.preventDefault();

		var $btn = $('#btn-create-order');
		$btn.prop('disabled', true).html('<i class="pg-icon m-r-5">refresh</i>Создаём…');

		MalibuOrderCreate.submitFromForm({
			ajaxUrl: AJAX_URL,
			nonce:   NONCE,
			onSuccess: function (d) {
				showCreatedOrder(d);
			},
			onEnd: function () {
				$btn.prop('disabled', false).html('<i class="pg-icon m-r-5">add</i>Создать ордер');
			},
		});
	});

	$('#create-order-rub-usdt-form').on('submit', function (e) {
		e.preventDefault();

		if (!RUB_USDT_FORM_ENABLED) {
			return;
		}

		var $btn = $('#btn-create-order-rub-usdt');
		var amount = parseFloat($('#moc-rub-usdt-amount-value').val());
		var description = $('#moc-rub-usdt-description').val();

		if (isNaN(amount) || amount <= 0) {
			rubUsdtShowAlert('Введите корректную сумму RUB.', 'danger');
			return;
		}

		rubUsdtHideAlert();
		$btn.prop('disabled', true).html('<i class="pg-icon m-r-5">refresh</i>Создаём…');

		$.post(AJAX_URL, {
			action: 'me_orders_create',
			_nonce: NONCE,
			amount_value: amount,
			amount_mode: 'rub_usdt',
			description: description
		})
		.done(function (res) {
			if (!res.success) {
				rubUsdtShowAlert((res.data && res.data.message) ? res.data.message : 'Ошибка создания ордера.', 'danger');
				return;
			}

			showCreatedOrder(res.data);
		})
		.fail(function (jqXHR) {
			var msg = 'Сетевая ошибка. Попробуйте ещё раз.';
			if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
				msg = jqXHR.responseJSON.data.message;
			}
			rubUsdtShowAlert(msg, 'danger');
		})
		.always(function () {
			$btn.prop('disabled', false).html('<i class="pg-icon m-r-5">add</i>Создать ордер');
		});
	});

	$('#create-order-rub-usdt-live-form').on('submit', function (e) {
		e.preventDefault();

		if (!RUB_USDT_LIVE_FORM_ENABLED) {
			return;
		}

		var $btn = $('#btn-create-order-rub-usdt-live');
		var amount = parseFloat($('#moc-rub-usdt-live-amount-value').val());
		var description = $('#moc-rub-usdt-live-description').val();

		if (isNaN(amount) || amount <= 0) {
			rubUsdtLiveShowAlert('Введите корректную сумму RUB.', 'danger');
			return;
		}

		rubUsdtLiveHideAlert();
		$btn.prop('disabled', true).html('<i class="pg-icon m-r-5">refresh</i>Делаем live quote…');

		$.post(AJAX_URL, {
			action: 'me_orders_create',
			_nonce: NONCE,
			amount_value: amount,
			amount_mode: 'rub_usdt_live',
			description: description
		})
		.done(function (res) {
			if (!res.success) {
				rubUsdtLiveShowAlert((res.data && res.data.message) ? res.data.message : 'Ошибка создания ордера.', 'danger');
				return;
			}

			showCreatedOrder(res.data);
		})
		.fail(function (jqXHR) {
			var msg = 'Сетевая ошибка. Попробуйте ещё раз.';
			if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
				msg = jqXHR.responseJSON.data.message;
			}
			rubUsdtLiveShowAlert(msg, 'danger');
		})
		.always(function () {
			$btn.prop('disabled', false).html('<i class="pg-icon m-r-5">add</i>Создать ордер');
		});
	});

	$('#create-order-rub-thb-rub-form').on('submit', function (e) {
		e.preventDefault();

		if (!RUB_THB_RUB_FORM_ENABLED) {
			return;
		}

		var $btn = $('#btn-create-order-rub-thb-rub');
		var amount = parseFloat($('#moc-rub-thb-rub-amount-value').val());
		var description = $('#moc-rub-thb-rub-description').val();
		var mode = $('#moc-rub-thb-rub-mode').val() || 'rub_thb_rub_rapira';
		var isLive = mode.indexOf('_live') !== -1;

		if (isNaN(amount) || amount <= 0) {
			rubThbRubShowAlert('Введите корректную сумму RUB.', 'danger');
			return;
		}

		rubThbRubHideAlert();
		$btn.prop('disabled', true).html(isLive
			? '<i class="pg-icon m-r-5">refresh</i>Делаем live quote…'
			: '<i class="pg-icon m-r-5">refresh</i>Создаём…');

		$.post(AJAX_URL, {
			action: 'me_orders_create',
			_nonce: NONCE,
			amount_value: amount,
			amount_mode: mode,
			description: description
		})
		.done(function (res) {
			if (!res.success) {
				rubThbRubShowAlert((res.data && res.data.message) ? res.data.message : 'Ошибка создания ордера.', 'danger');
				return;
			}

			showCreatedOrder(res.data);
		})
		.fail(function (jqXHR) {
			var msg = 'Сетевая ошибка. Попробуйте ещё раз.';
			if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
				msg = jqXHR.responseJSON.data.message;
			}
			rubThbRubShowAlert(msg, 'danger');
		})
		.always(function () {
			$btn.prop('disabled', false).html('<i class="pg-icon m-r-5">add</i>Создать ордер');
		});
	});

	$('#create-order-rub-thb-thb-form').on('submit', function (e) {
		e.preventDefault();

		if (!RUB_THB_THB_FORM_ENABLED) {
			return;
		}

		var $btn = $('#btn-create-order-rub-thb-thb');
		var amount = parseFloat($('#moc-rub-thb-thb-amount-value').val());
		var description = $('#moc-rub-thb-thb-description').val();
		var mode = $('#moc-rub-thb-thb-mode').val() || 'rub_thb_thb_rapira';
		var isLive = mode.indexOf('_live') !== -1;

		if (isNaN(amount) || amount <= 0) {
			rubThbThbShowAlert('Введите корректную сумму THB.', 'danger');
			return;
		}

		rubThbThbHideAlert();
		$btn.prop('disabled', true).html(isLive
			? '<i class="pg-icon m-r-5">refresh</i>Делаем live quote…'
			: '<i class="pg-icon m-r-5">refresh</i>Создаём…');

		$.post(AJAX_URL, {
			action: 'me_orders_create',
			_nonce: NONCE,
			amount_value: amount,
			amount_mode: mode,
			description: description
		})
		.done(function (res) {
			if (!res.success) {
				rubThbThbShowAlert((res.data && res.data.message) ? res.data.message : 'Ошибка создания ордера.', 'danger');
				return;
			}

			showCreatedOrder(res.data);
		})
		.fail(function (jqXHR) {
			var msg = 'Сетевая ошибка. Попробуйте ещё раз.';
			if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
				msg = jqXHR.responseJSON.data.message;
			}
			rubThbThbShowAlert(msg, 'danger');
		})
		.always(function () {
			$btn.prop('disabled', false).html('<i class="pg-icon m-r-5">add</i>Создать ордер');
		});
	});

	// Кнопка "Копировать ссылку"
	$('#btn-copy-link').on('click', function () {
		var val = $('#or-payment-link').val();
		if (!val) return;
		if (navigator.clipboard) {
			navigator.clipboard.writeText(val).then(function () {
				$('#btn-copy-link').html('<i class="pg-icon">tick</i>');
				setTimeout(function () { $('#btn-copy-link').html('<i class="pg-icon">copy</i>'); }, 1500);
			});
		} else {
			$('#or-payment-link').select();
			document.execCommand('copy');
		}
	});

	// Кнопка "Новый ордер"
	$('#btn-new-order').on('click', function () {
		$(getCreateOrderCardsSelector()).removeClass('d-none');
		$('#order-result').addClass('d-none');
		MalibuOrderCreate.reset();
		resetRubUsdtForm();
		resetRubUsdtLiveForm();
		resetRubThbRubForm();
		resetRubThbThbForm();
		$('#order-receipt-inline').empty();
	});

	$('#moc-rub-usdt-amount-value').on('input change', renderRubUsdtPreview);
	$('#moc-rub-thb-rub-amount-value, #moc-rub-thb-rub-mode').on('input change', renderRubThbRubPreview);
	$('#moc-rub-thb-thb-amount-value, #moc-rub-thb-thb-mode').on('input change', renderRubThbThbPreview);
	renderRubUsdtPreview();
	renderRubThbRubPreview();
	renderRubThbThbPreview();

}(jQuery));
</script>
<?php
}, 99 );
?>

<?php get_footer(); ?>
