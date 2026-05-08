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
add_action( 'wp_footer', function () use ( $nonce ) {
?>
<script>
(function ($) {
	'use strict';

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	// Nonce для me_orders_check_status (того же семейства me_orders_list)
	var CHECK_NONCE = '<?php echo esc_js( wp_create_nonce( 'me_orders_list' ) ); ?>';
	var NONCE    = '<?php echo esc_js( $nonce ); ?>';

	// Конфигурируем общий чек под AJAX проверки статуса
	if (window.MalibuOrderReceipt && MalibuOrderReceipt.configure) {
		MalibuOrderReceipt.configure({ ajaxUrl: AJAX_URL, nonce: CHECK_NONCE });
	}

	$('#create-order-form').on('submit', function (e) {
		e.preventDefault();

		var $btn = $('#btn-create-order');
		$btn.prop('disabled', true).html('<i class="pg-icon m-r-5">refresh</i>Создаём…');

		MalibuOrderCreate.submitFromForm({
			ajaxUrl: AJAX_URL,
			nonce:   NONCE,
			onSuccess: function (d, input) {
				var receiptData = {
					id:                   d.order_db_id,
					status_code:          'created',
					merchant_order_id:    d.merchant_order_id,
					payment_amount_value: d.payment_amount_rub,
					qr_url:               d.qr_url,
					created_at:           d.created_at || new Date().toISOString().slice(0, 19).replace('T', ' '),
				};
				// Рендерим через renderInto, чтобы контекст для кнопки «Проверить» сохранился
				MalibuOrderReceipt.renderInto('#order-receipt-inline', receiptData, {
					onStatusChange: function () {
						// Чек уже перерисован с новым статусом самим receipt'ом.
						// На этой странице специальных действий не требуется.
					},
				});

				if (d.payment_link) {
					$('#or-payment-link').val(d.payment_link);
					$('#or-link-block').show();
				} else {
					$('#or-link-block').hide();
				}

				$('#create-order-card').addClass('d-none');
				$('#order-result').removeClass('d-none');

				if (d.warning) { MalibuOrderCreate.showAlert(d.warning, 'warning'); }
			},
			onEnd: function () {
				$btn.prop('disabled', false).html('<i class="pg-icon m-r-5">add</i>Создать ордер');
			},
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
		MalibuOrderCreate.reset();
		$('#order-receipt-inline').empty();
	});

}(jQuery));
</script>
<?php
}, 99 );
?>

<?php get_footer(); ?>
