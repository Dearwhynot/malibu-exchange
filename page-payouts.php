<?php
/*
Template Name: Payouts Page
Slug: payouts
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

if ( ! crm_can_access( 'payouts.view' ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

// ─── Скоп компании ────────────────────────────────────────────────────────────
$_uid        = get_current_user_id();
$_company_id = crm_is_root( $_uid )
	? (int) CRM_DEFAULT_ORG_ID
	: crm_get_current_user_company_id( $_uid );

$_org_id   = ( $_company_id > 0 ) ? $_company_id : (int) CRM_DEFAULT_ORG_ID;
$_tz       = crm_get_timezone( $_org_id );
$_tz_label = crm_get_timezone_label( $_org_id );

// ─── Агрегаты (server-side, без AJAX) ────────────────────────────────────────
global $wpdb;

$_co_cond_orders  = $_company_id > 0 ? $wpdb->prepare( ' AND company_id = %d', $_company_id ) : '';
$_co_cond_payouts = $_company_id > 0 ? $wpdb->prepare( ' AND company_id = %d', $_company_id ) : '';

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// Накопленный USDT по paid-ордерам (amount_asset_value = USDT)
$_total_paid_all = (float) $wpdb->get_var(
	"SELECT COALESCE(SUM(amount_asset_value), 0)
	 FROM `crm_fintech_payment_orders`
	 WHERE status_code = 'paid'" . $_co_cond_orders
);
$_total_payout_all = (float) $wpdb->get_var(
	"SELECT COALESCE(SUM(amount), 0)
	 FROM `crm_acquirer_payouts`
	 WHERE 1=1" . $_co_cond_payouts
);
$_payout_count_all = (int) $wpdb->get_var(
	"SELECT COUNT(*)
	 FROM `crm_acquirer_payouts`
	 WHERE 1=1" . $_co_cond_payouts
);
// phpcs:enable

$_ep_debt = max( 0.0, $_total_paid_all - $_total_payout_all );

// ─── Форматирование чисел ─────────────────────────────────────────────────────
function _fmt_usdt( float $v ): string {
	return number_format( $v, 8, '.', "\xc2\xa0" ) . "\xc2\xa0USDT";
}

$_nonce         = wp_create_nonce( 'me_payouts' );
$_can_create    = crm_can_access( 'payouts.create' );
$_vendor_img    = get_template_directory_uri() . '/vendor/pages/assets/img';

get_header();
?>

<!-- BEGIN SIDEBAR -->
<?php get_template_part( 'template-parts/sidebar' ); ?>
<!-- END SIDEBAR -->

<div class="page-container">

	<!-- HEADER -->
	<div class="header">
		<a href="#" class="btn-link toggle-sidebar d-lg-none pg-icon btn-icon-link" data-toggle="sidebar">menu</a>
		<div class="">
			<div class="brand inline">
				<img src="<?php echo esc_url( $_vendor_img . '/logo.png' ); ?>" alt="logo"
				     data-src="<?php echo esc_url( $_vendor_img . '/logo.png' ); ?>"
				     data-src-retina="<?php echo esc_url( $_vendor_img . '/logo_2x.png' ); ?>"
				     width="78" height="22">
			</div>
		</div>
		<div class="d-flex align-items-center">
			<div class="dropdown pull-right d-lg-block d-none">
				<button class="profile-dropdown-toggle" type="button" data-bs-toggle="dropdown"
				        aria-haspopup="true" aria-expanded="false" aria-label="profile dropdown">
					<span class="thumbnail-wrapper d32 circular inline">
						<img src="<?php echo esc_url( $_vendor_img . '/profiles/avatar.jpg' ); ?>"
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
							<li class="breadcrumb-item active">Выплаты ЭП</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">

				<!-- ── Сводные карточки ─────────────────────────────────────── -->
				<div class="row m-b-20">

					<div class="col-xl-4 col-lg-4 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase" style="letter-spacing:.05em;">Всего paid-ордеров (накоплено)</p>
								<h3 class="no-margin bold text-complete"><?php echo esc_html( _fmt_usdt( $_total_paid_all ) ); ?></h3>
							</div>
						</div>
					</div>

					<div class="col-xl-4 col-lg-4 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase" style="letter-spacing:.05em;">Выплачено ЭП (<?php echo esc_html( $_payout_count_all ); ?> зап.)</p>
								<h3 class="no-margin bold text-success"><?php echo esc_html( _fmt_usdt( $_total_payout_all ) ); ?></h3>
							</div>
						</div>
					</div>

					<div class="col-xl-4 col-lg-4 col-md-12 col-sm-12 m-b-15">
						<div class="card no-margin <?php echo $_ep_debt > 0 ? 'bg-warning' : 'card-default'; ?>">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase <?php echo $_ep_debt > 0 ? 'text-white' : ''; ?>" style="letter-spacing:.05em;">Долг ЭП (не выплачено)</p>
								<h3 class="no-margin bold <?php echo $_ep_debt > 0 ? 'text-white' : 'text-danger'; ?>"><?php echo esc_html( _fmt_usdt( $_ep_debt ) ); ?></h3>
							</div>
						</div>
					</div>

				</div>
				<!-- /карточки -->

				<!-- ── Кнопка + форма внесения ─────────────────────────────── -->
				<?php if ( $_can_create ) : ?>
				<div class="row m-b-20">
					<div class="col-12">
						<button class="btn btn-complete" id="btn-add-payout">
							<i class="pg-icon m-r-5">add</i>Внести выплату от ЭП
						</button>
					</div>
				</div>

				<!-- Форма (скрыта по умолчанию) -->
				<div id="payout-form-wrapper" style="display:none;" class="m-b-25">
					<div class="card card-default">
						<div class="card-header">
							<div class="card-title">Новая выплата от эквайринг-партнёра</div>
						</div>
						<div class="card-body">

							<!-- Подсказка: текущий остаток -->
							<div class="alert alert-info bordered m-b-20" id="payout-debt-hint">
								Текущий долг ЭП: <strong id="payout-current-debt"><?php echo esc_html( _fmt_usdt( $_ep_debt ) ); ?></strong>.
								После внесения выплаты остаток будет: <strong id="payout-remaining"><?php echo esc_html( _fmt_usdt( $_ep_debt ) ); ?></strong>.
							</div>

							<form id="form-add-payout" novalidate>
								<input type="hidden" name="_nonce" value="<?php echo esc_attr( $_nonce ); ?>">

								<div class="row">

									<div class="col-md-4 m-b-15">
										<label class="fs-12 text-uppercase hint-text">Сумма выплаты (USDT) <span class="text-danger">*</span></label>
										<input type="number" name="amount" id="payout-amount"
										       class="form-control"
										       placeholder="0.00" min="0.01" step="0.01" required>
									</div>

									<div class="col-md-4 m-b-15">
										<label class="fs-12 text-uppercase hint-text">Период с</label>
										<input type="date" name="period_from" class="form-control" placeholder="ГГГГ-ММ-ДД">
									</div>

									<div class="col-md-4 m-b-15">
										<label class="fs-12 text-uppercase hint-text">Период по</label>
										<input type="date" name="period_to" class="form-control" placeholder="ГГГГ-ММ-ДД">
									</div>

									<div class="col-md-6 m-b-15">
										<label class="fs-12 text-uppercase hint-text">Платёжное поручение / референс ЭП</label>
										<input type="text" name="reference" class="form-control" placeholder="№ или ID из кабинета ЭП" maxlength="255">
									</div>

									<div class="col-md-6 m-b-15">
										<label class="fs-12 text-uppercase hint-text">Примечание</label>
										<textarea name="notes" class="form-control" rows="1" placeholder="Необязательно" maxlength="500"></textarea>
									</div>

									<div class="col-md-12 m-b-15">
										<label class="fs-12 text-uppercase hint-text">Квитанция / скриншот <span class="hint-text">(необязательно, JPG/PNG/GIF/WEBP, макс. 10 МБ)</span></label>
										<input type="file" name="receipt" id="payout-receipt" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
										<div id="payout-receipt-preview" class="m-t-10" style="display:none;">
											<img id="payout-receipt-img" src="" alt="Предпросмотр" style="max-height:120px; max-width:240px; border-radius:4px; border:1px solid #dee2e6; object-fit:cover;">
											<button type="button" id="btn-receipt-clear" class="btn btn-xs btn-default m-l-10">Убрать</button>
										</div>
									</div>

								</div><!-- /row -->

								<div id="payout-form-error" class="alert alert-danger" style="display:none;"></div>

								<div class="d-flex gap-10">
									<button type="submit" class="btn btn-complete" id="btn-payout-submit">Сохранить</button>
									<button type="button" class="btn btn-default" id="btn-payout-cancel">Отмена</button>
								</div>

							</form>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<!-- ── Таблица выплат ───────────────────────────────────────── -->
				<div class="card card-default">
					<div class="card-header">
						<div class="card-title">История выплат от ЭП</div>
						<div class="card-controls">
							<span class="hint-text fs-12" id="payouts-tz-label"><?php echo esc_html( $_tz_label ); ?></span>
						</div>
					</div>

					<div class="card-body no-padding">
						<div class="table-responsive">
							<table class="table table-hover no-margin" id="payouts-table">
								<thead>
									<tr>
										<th>#</th>
										<th>Дата внесения</th>
										<th>Период</th>
										<th class="text-right">Сумма</th>
										<th>Поручение / Референс</th>
										<th>Примечание</th>
										<th>Квитанция</th>
										<th>Внёс</th>
									</tr>
								</thead>
								<tbody id="payouts-tbody">
									<tr>
										<td colspan="7" class="text-center hint-text p-3">Загрузка...</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					<!-- Пагинация -->
					<div class="card-body border-top p-2 d-flex justify-content-between align-items-center" id="payouts-pagination-row" style="display:none!important;">
						<span class="hint-text fs-12" id="payouts-count-label"></span>
						<div id="payouts-pagination"></div>
					</div>
				</div>
				<!-- /таблица -->

			</div>
			<!-- /container -->

		</div>
	</div>

</div>
<!-- END PAGE CONTAINER -->

<?php get_template_part( 'template-parts/quickview' ); ?>
<?php get_template_part( 'template-parts/overlay' ); ?>

<?php
add_action( 'wp_footer', function () use ( $_nonce, $_can_create, $_ep_debt ) {
?>
<script>
(function($) {
	var nonce      = <?php echo json_encode( $_nonce ); ?>;
	var ajaxUrl    = <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	var canCreate  = <?php echo $_can_create ? 'true' : 'false'; ?>;
	var currentDebt= <?php echo json_encode( round( $_ep_debt, 2 ) ); ?>;

	var page     = 1;
	var perPage  = 25;

	// ── Отображение/скрытие формы ─────────────────────────────────────────────
	$('#btn-add-payout').on('click', function() {
		$('#payout-form-wrapper').slideDown(200);
		$(this).hide();
	});
	$('#btn-payout-cancel').on('click', function() {
		$('#payout-form-wrapper').slideUp(200);
		$('#btn-add-payout').show();
		$('#form-add-payout')[0].reset();
		updateRemaining(0);
		$('#payout-form-error').hide();
	});

	// ── Пересчёт остатка в реальном времени ──────────────────────────────────
	function fmtRub(v) {
		return parseFloat(v).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:8}) + '\u00a0USDT';
	}
	function updateRemaining(entered) {
		var remaining = Math.max(0, currentDebt - entered);
		$('#payout-remaining').text(fmtRub(remaining));
	}
	$('#payout-amount').on('input', function() {
		var v = parseFloat($(this).val()) || 0;
		updateRemaining(v);
	});

	// ── Предпросмотр квитанции ───────────────────────────────────────────────
	$('#payout-receipt').on('change', function() {
		var file = this.files[0];
		if (!file) return;
		var reader = new FileReader();
		reader.onload = function(e) {
			$('#payout-receipt-img').attr('src', e.target.result);
			$('#payout-receipt-preview').show();
		};
		reader.readAsDataURL(file);
	});
	$('#btn-receipt-clear').on('click', function() {
		$('#payout-receipt').val('');
		$('#payout-receipt-preview').hide();
		$('#payout-receipt-img').attr('src', '');
	});

	// ── Сабмит формы (FormData для поддержки файла) ───────────────────────────
	$('#form-add-payout').on('submit', function(e) {
		e.preventDefault();
		$('#payout-form-error').hide();

		var $btn = $('#btn-payout-submit').prop('disabled', true).text('Сохранение...');

		var fd = new FormData(this);
		fd.append('action', 'me_payouts_create');

		$.ajax({
			url:         ajaxUrl,
			type:        'POST',
			data:        fd,
			processData: false,
			contentType: false,
			dataType:    'json',
			success: function(res) {
				if (res.success) {
					location.reload();
				} else {
					$('#payout-form-error').text(res.data.message || 'Ошибка').show();
				}
			},
			error: function() {
				$('#payout-form-error').text('Ошибка сети, повторите попытку.').show();
			},
			complete: function() {
				$btn.prop('disabled', false).text('Сохранить');
			}
		});
	});

	// ── Загрузка таблицы ─────────────────────────────────────────────────────
	function loadPayouts(p) {
		page = p || 1;
		$.post(ajaxUrl, {
			action:   'me_payouts_list',
			_nonce:   nonce,
			page:     page,
			per_page: perPage
		}, function(res) {
			if (!res.success) return;
			var rows = res.data.rows;
			var $tbody = $('#payouts-tbody').empty();

			if (!rows || rows.length === 0) {
				$tbody.html('<tr><td colspan="7" class="text-center hint-text p-3">Выплат пока нет.</td></tr>');
				$('#payouts-pagination-row').hide();
				return;
			}

			$.each(rows, function(_, r) {
				var period = r.period_from
					? (r.period_from + (r.period_to ? ' — ' + r.period_to : ''))
					: '—';
				var ref   = r.reference ? escHtml(r.reference) : '<span class="hint-text">—</span>';
				var notes = r.notes     ? escHtml(r.notes)     : '<span class="hint-text">—</span>';
				var who   = r.recorder_name ? escHtml(r.recorder_name) : '<span class="hint-text">—</span>';
				var amt   = parseFloat(r.amount).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:8}) + '\u00a0' + r.currency_code;

				var receipt = '<span class="hint-text">—</span>';
				if (r.receipt_url) {
					receipt = '<a href="' + escHtml(r.receipt_url) + '" target="_blank" rel="noopener" title="Открыть квитанцию">' +
						'<img src="' + escHtml(r.receipt_url) + '" alt="квитанция" ' +
						'style="height:36px;width:auto;border-radius:3px;border:1px solid #dee2e6;object-fit:cover;cursor:pointer;" ' +
						'onerror="this.parentNode.innerHTML=\'<span class=hint-text>ошибка</span>\'">' +
						'</a>';
				}

				$tbody.append(
					'<tr>' +
					'<td class="hint-text">' + r.id + '</td>' +
					'<td>' + escHtml(r.created_at || '—') + '</td>' +
					'<td>' + escHtml(period) + '</td>' +
					'<td class="text-right font-montserrat fs-14 bold">' + escHtml(amt) + '</td>' +
					'<td>' + ref + '</td>' +
					'<td>' + notes + '</td>' +
					'<td>' + receipt + '</td>' +
					'<td>' + who + '</td>' +
					'</tr>'
				);
			});

			// Пагинация
			var total = res.data.total;
			var totalPages = res.data.total_pages;
			$('#payouts-count-label').text('Записей: ' + total);
			renderPagination(totalPages, page);
			$('#payouts-pagination-row').show();

		}, 'json');
	}

	function renderPagination(totalPages, current) {
		var $p = $('#payouts-pagination').empty();
		if (totalPages <= 1) return;
		var html = '<ul class="pagination pagination-sm no-margin">';
		html += '<li class="page-item' + (current <= 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (current-1) + '">&laquo;</a></li>';
		for (var i = 1; i <= totalPages; i++) {
			html += '<li class="page-item' + (i === current ? ' active' : '') + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
		}
		html += '<li class="page-item' + (current >= totalPages ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (current+1) + '">&raquo;</a></li>';
		html += '</ul>';
		$p.html(html);
	}

	$('#payouts-pagination').on('click', '.page-link', function(e) {
		e.preventDefault();
		var p = parseInt($(this).data('page'));
		if (p >= 1) loadPayouts(p);
	});

	function escHtml(s) {
		return $('<div>').text(String(s)).html();
	}

	// Инициализация
	loadPayouts(1);

})(jQuery);
</script>
<?php
}, 99 );
?>

<?php get_footer(); ?>
