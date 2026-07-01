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
$rub_usdt_default_payment_purpose = '';
$rub_usdt_preview = null;
$kanyon_live_last_rate = function_exists( 'rates_kanyon_get_last' ) ? rates_kanyon_get_last( $company_id ) : null;

if ( $company_id > 0 && function_exists( 'crm_fintech_get_pay2day_default_payment_purpose' ) ) {
	$rub_usdt_default_payment_purpose = crm_fintech_get_pay2day_default_payment_purpose( $company_id );
}

if ( $show_rub_usdt_web_form && function_exists( 'crm_fintech_company_web_rub_usdt_preview_context' ) ) {
	$rub_usdt_preview = crm_fintech_company_web_rub_usdt_preview_context( $company_id, 0.0, false );
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
add_action( 'wp_footer', function () use ( $nonce, $show_rub_usdt_web_form, $show_rub_usdt_live_web_form, $rub_usdt_preview, $rub_usdt_default_payment_purpose ) {
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
	var RUB_USDT_PREVIEW = <?php echo function_exists( 'crm_json_for_inline_js' ) ? crm_json_for_inline_js( $rub_usdt_preview ) : wp_json_encode( $rub_usdt_preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
	var RUB_USDT_DEFAULT_PURPOSE = <?php echo wp_json_encode( $rub_usdt_default_payment_purpose, JSON_UNESCAPED_UNICODE ); ?>;

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

		$('#create-order-card, #create-order-rub-usdt-card, #create-order-rub-usdt-live-card').addClass('d-none');
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
		$('#create-order-card, #create-order-rub-usdt-card, #create-order-rub-usdt-live-card').removeClass('d-none');
		$('#order-result').addClass('d-none');
		MalibuOrderCreate.reset();
		resetRubUsdtForm();
		resetRubUsdtLiveForm();
		$('#order-receipt-inline').empty();
	});

	$('#moc-rub-usdt-amount-value').on('input change', renderRubUsdtPreview);
	renderRubUsdtPreview();

}(jQuery));
</script>
<?php
}, 99 );
?>

<?php get_footer(); ?>
