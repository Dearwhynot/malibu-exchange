<?php
/*
Template Name: Create Order Page
Slug: create-order
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

if ( ! crm_can_access( 'orders.view' ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$vendor_img_uri = get_template_directory_uri() . '/vendor/pages/assets/img';
$nonce          = wp_create_nonce( 'me_orders_create' );

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
							<li class="breadcrumb-item"><a href="<?php echo esc_url( home_url( '/orders/' ) ); ?>">Ордера</a></li>
							<li class="breadcrumb-item active">Создать ордер</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">
				<div class="row">
					<div class="col-lg-6 col-md-8">

						<!-- ─── Форма создания ─────────────────────────────── -->
						<div class="card card-default" id="create-order-card">
							<div class="card-header">
								<div class="card-title">Новый платёжный ордер</div>
							</div>
							<div class="card-body">

								<div id="co-alert" class="alert d-none m-b-20" role="alert"></div>

								<form id="create-order-form">
									<div class="form-group">
										<label for="co-amount-usdt">Сумма в USDT <span class="text-danger">*</span></label>
										<div class="input-group">
											<input type="number"
											       id="co-amount-usdt"
											       name="amount_usdt"
											       class="form-control"
											       min="0.01"
											       step="0.01"
											       placeholder="например 100.00"
											       required
											       autocomplete="off">
											<span class="input-group-text">USDT</span>
										</div>
										<p class="hint-text m-t-5">Введите сумму в USDT. Конвертация в RUB производится провайдером.</p>
									</div>

									<div class="form-group">
										<label for="co-description">Комментарий <span class="text-muted">(необязательно)</span></label>
										<input type="text"
										       id="co-description"
										       name="description"
										       class="form-control"
										       maxlength="200"
										       placeholder="Назначение платежа или заметка">
									</div>

									<button type="submit" id="btn-create-order" class="btn btn-primary btn-cons">
										<i class="pg-icon m-r-5">add</i>Создать ордер
									</button>
								</form>

							</div>
						</div>

						<!-- ─── Результат ─────────────────────────────────── -->
						<div id="order-result" class="d-none">

							<div class="card card-default">
								<div class="card-header">
									<div class="card-title">
										Ордер создан
										<span id="or-status-badge" class="m-l-10"></span>
									</div>
								</div>
								<div class="card-body">

									<div class="order-detail-grid m-b-20">
										<div class="order-detail-label">Merchant ID</div>
										<div class="order-detail-value"><code id="or-merchant-id"></code></div>

										<div class="order-detail-label">Провайдер</div>
										<div class="order-detail-value"><span id="or-provider"></span></div>

										<div class="order-detail-label">Сумма USDT</div>
										<div class="order-detail-value"><strong id="or-amount-usdt"></strong></div>

										<div class="order-detail-label">Сумма RUB</div>
										<div class="order-detail-value"><strong id="or-amount-rub"></strong></div>
									</div>

									<!-- QR-код -->
									<div id="or-qr-block" class="d-none text-center m-b-20">
										<p class="text-muted small m-b-10">Отправьте клиенту QR-код для оплаты через СБП</p>
										<img id="or-qr-img" src="" alt="QR код" style="max-width:220px;border:1px solid #dee2e6;border-radius:6px;padding:8px;background:#fff">
									</div>

									<!-- Ссылка оплаты -->
									<div id="or-link-block" class="m-b-20">
										<label class="text-muted small d-block m-b-5">Ссылка для оплаты</label>
										<div class="input-group">
											<input type="text" id="or-payment-link" class="form-control form-control-sm" readonly>
											<button class="btn btn-sm btn-default" id="btn-copy-link" type="button" title="Копировать">
												<i class="pg-icon">copy</i>
											</button>
										</div>
									</div>

									<div class="d-flex gap-2 m-t-10">
										<a href="<?php echo esc_url( home_url( '/orders/' ) ); ?>"
										   class="btn btn-default btn-sm">
											Все ордера
										</a>
										<button type="button" id="btn-new-order" class="btn btn-primary btn-sm">
											Новый ордер
										</button>
									</div>

								</div>
							</div>

						</div>
						<!-- /.order-result -->

					</div>
				</div>
			</div><!-- /.container-fluid -->
		</div>

		<!-- COPYRIGHT -->
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
add_action( 'wp_footer', function () use ( $nonce ) {
?>
<style>
.order-detail-grid { display: grid; grid-template-columns: 140px 1fr; gap: 8px 12px; }
.order-detail-label { font-weight: 600; color: #6c757d; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; align-self: center; }
.order-detail-value { word-break: break-word; }
.order-provider-badge { display: inline-block; padding: 2px 7px; border-radius: 3px; font-size: 11px; background: #e3f2fd; color: #1565c0; font-weight: 600; }
.order-status-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; letter-spacing: .03em; }
.order-status-created { background: #f1f3f4; color: #495057; }
.order-status-pending { background: #fff8e1; color: #FF8F00; }
</style>

<script>
(function ($) {
	'use strict';

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCE    = '<?php echo esc_js( $nonce ); ?>';

	function escHtml(str) {
		if (str === null || str === undefined) return '';
		return String(str)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function showAlert(msg, type) {
		$('#co-alert')
			.removeClass('d-none alert-success alert-danger alert-warning')
			.addClass('alert-' + (type || 'danger'))
			.text(msg);
	}

	function hideAlert() {
		$('#co-alert').addClass('d-none');
	}

	$('#create-order-form').on('submit', function (e) {
		e.preventDefault();
		hideAlert();

		var $btn    = $('#btn-create-order');
		var amount  = parseFloat($('#co-amount-usdt').val());
		var desc    = $('#co-description').val();

		if (isNaN(amount) || amount <= 0) {
			showAlert('Введите корректную сумму USDT.', 'danger');
			return;
		}

		$btn.prop('disabled', true).html('<i class="pg-icon m-r-5">refresh</i>Создаём…');

		$.post(AJAX_URL, {
			action:      'me_orders_create',
			_nonce:      NONCE,
			amount_usdt: amount,
			description: desc,
		})
		.done(function (res) {
			if (!res.success) {
				showAlert(res.data ? res.data.message : 'Ошибка создания ордера.', 'danger');
				return;
			}

			var d = res.data;

			// Заполняем поля результата
			$('#or-merchant-id').text(d.merchant_order_id || '—');
			$('#or-provider').html('<span class="order-provider-badge">' + escHtml(d.provider) + '</span>');
			$('#or-amount-usdt').text(parseFloat(amount).toFixed(2) + ' USDT');
			$('#or-amount-rub').text(d.payment_amount_rub ? parseFloat(d.payment_amount_rub).toFixed(2) + ' RUB' : '—');
			$('#or-status-badge').html('<span class="order-status-badge order-status-created">created</span>');

			// QR
			if (d.qr_url) {
				$('#or-qr-img').attr('src', d.qr_url);
				$('#or-qr-block').removeClass('d-none');
			} else {
				$('#or-qr-block').addClass('d-none');
			}

			// Ссылка
			if (d.payment_link) {
				$('#or-payment-link').val(d.payment_link);
				$('#or-link-block').show();
			} else {
				$('#or-link-block').hide();
			}

			// Показываем результат, скрываем форму
			$('#create-order-card').addClass('d-none');
			$('#order-result').removeClass('d-none');
		})
		.fail(function () {
			showAlert('Сетевая ошибка. Попробуйте ещё раз.', 'danger');
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
		$('#create-order-card').removeClass('d-none');
		$('#order-result').addClass('d-none');
		$('#create-order-form')[0].reset();
		$('#or-qr-block').addClass('d-none');
		hideAlert();
	});

}(jQuery));
</script>
<?php
}, 99 );
?>

<?php get_footer(); ?>
