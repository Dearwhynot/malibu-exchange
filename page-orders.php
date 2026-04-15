<?php
/*
Template Name: Orders Page
Slug: orders
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
$nonce          = wp_create_nonce( 'me_orders_list' );

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
							<li class="breadcrumb-item active">Платёжные ордера</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">

				<!-- ─── Фильтры ─────────────────────────────────────────────────── -->
				<div class="card card-default m-b-20">
					<div class="card-body p-t-20 p-b-15">

						<div class="row g-2 align-items-center m-b-10">
							<div class="col-12 col-md-4 col-lg-3">
								<div class="input-group">
									<span class="input-group-text"><i class="pg-icon">search</i></span>
									<input type="search" id="of-search" class="form-control"
									       placeholder="Merchant ID, Provider ID, реф…">
								</div>
							</div>
							<div class="col-6 col-md-2">
								<select id="of-status" class="full-width" data-init-plugin="select2">
									<option value="">Все статусы</option>
									<option value="created">created</option>
									<option value="pending">pending</option>
									<option value="paid">paid</option>
									<option value="declined">declined</option>
									<option value="cancelled">cancelled</option>
									<option value="expired">expired</option>
									<option value="error">error</option>
								</select>
							</div>
							<div class="col-6 col-md-2">
								<select id="of-provider" class="full-width" data-init-plugin="select2">
									<option value="">Все провайдеры</option>
									<option value="kanyon">kanyon</option>
									<option value="doverka">doverka</option>
								</select>
							</div>
							<div class="col-6 col-md-2">
								<input type="date" id="of-date-from" class="form-control" placeholder="Дата с">
							</div>
							<div class="col-6 col-md-2">
								<input type="date" id="of-date-to" class="form-control" placeholder="Дата по">
							</div>
						</div>

						<div class="row g-2 align-items-center">
							<div class="col-4 col-md-1">
								<select id="of-per-page" class="full-width" data-init-plugin="select2">
									<option value="25">25</option>
									<option value="50">50</option>
									<option value="100">100</option>
								</select>
							</div>
							<div class="col-8 col-md-3 d-flex gap-2">
								<button type="button" id="btn-orders-search" class="btn btn-primary">
									<i class="pg-icon">search</i> Найти
								</button>
								<button type="button" id="btn-orders-reset" class="btn btn-default">
									Сброс
								</button>
							</div>
						</div>

					</div>
				</div>

				<!-- ─── Счётчик ──────────────────────────────────────────────────── -->
				<div class="d-flex justify-content-between align-items-center m-b-10">
					<div id="orders-stats" class="text-muted small"></div>
					<div id="orders-loading" class="text-muted small d-none">
						<span class="pg-icon" style="animation:spin 1s linear infinite;display:inline-block;">refresh</span>
						Загрузка…
					</div>
				</div>

				<!-- ─── Таблица ───────────────────────────────────────────────────── -->
				<div class="card card-default">
					<div class="card-body p-0">
						<div class="table-responsive">
							<table class="table table-hover m-b-0" id="orders-table">
								<thead>
									<tr>
										<th style="width:60px">#</th>
										<th style="width:140px">Дата</th>
										<th style="width:90px">Провайдер</th>
										<th style="width:200px">Merchant ID</th>
										<th style="width:90px">Статус</th>
										<th style="width:110px">Сумма USDT</th>
										<th style="width:110px">Сумма RUB</th>
										<th style="width:90px">Источник</th>
										<th style="width:130px">Оплачен</th>
										<th style="width:70px"></th>
									</tr>
								</thead>
								<tbody id="orders-tbody">
									<tr>
										<td colspan="10" class="text-center p-t-30 p-b-30 text-muted">
											Нажмите «Найти» для загрузки данных.
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<!-- ─── Пагинация ────────────────────────────────────────────────── -->
				<div id="orders-pagination" class="d-flex justify-content-between align-items-center m-t-15 m-b-30"></div>

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

<!-- ─── Модальное окно QR-чека ───────────────────────────────────────────────── -->
<div class="modal fade" id="order-receipt-modal" tabindex="-1" role="dialog"
     aria-labelledby="order-receipt-title" aria-hidden="true">
	<div class="modal-dialog" style="max-width:380px;margin:1.75rem auto">
		<div class="modal-content" style="border-radius:14px;overflow:hidden;border:none;box-shadow:0 8px 40px rgba(0,0,0,.22)">
			<div id="order-receipt-body">
				<div class="text-center p-t-30 p-b-30 text-muted">Загрузка…</div>
			</div>
			<div class="border-0 p-b-20 p-t-5 d-flex justify-content-center">
				<button type="button" class="btn btn-default btn-sm" data-bs-dismiss="modal">Закрыть</button>
			</div>
		</div>
	</div>
</div>

<!-- ─── Модальное окно деталей ──────────────────────────────────────────────── -->
<div class="modal fade" id="order-detail-modal" tabindex="-1" role="dialog"
     aria-labelledby="order-detail-title" aria-hidden="true">
	<div class="modal-dialog modal-lg" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title" id="order-detail-title">Детали ордера</h4>
				<button type="button" class="close" data-bs-dismiss="modal" aria-label="Закрыть">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="modal-body">
				<div id="order-detail-body">
					<div class="text-center p-t-20 p-b-20">
						<span class="text-muted">Загрузка…</span>
					</div>
				</div>
				<div id="order-action-result" class="alert d-none m-t-15" role="alert"></div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-bs-dismiss="modal">Закрыть</button>
			</div>
		</div>
	</div>
</div>

<?php get_template_part( 'template-parts/quickview' ); ?>
<?php get_template_part( 'template-parts/overlay' ); ?>

<?php
add_action( 'wp_footer', function () use ( $nonce ) {
?>
<style>
@keyframes spin { to { transform: rotate(360deg); } }
#orders-table th { white-space: nowrap; font-size: 12px; color: #6c757d; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; border-top: none; }
#orders-table td { vertical-align: middle; font-size: 13px; }
.order-status-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; letter-spacing: .03em; }
.order-status-created   { background: #f1f3f4; color: #495057; }
.order-status-pending   { background: #fff8e1; color: #FF8F00; }
.order-status-paid      { background: #e8f5e9; color: #2e7d32; }
.order-status-declined  { background: #fde8e8; color: #c62828; }
.order-status-cancelled { background: #fce4ec; color: #880e4f; }
.order-status-expired   { background: #ede7f6; color: #4527a0; }
.order-status-error     { background: #3d0a0a; color: #ff6b6b; }
.order-provider-badge { display: inline-block; padding: 2px 7px; border-radius: 3px; font-size: 11px; background: #e3f2fd; color: #1565c0; }
.order-amount { font-family: monospace; font-size: 13px; }
.order-detail-grid { display: grid; grid-template-columns: 160px 1fr; gap: 6px 12px; }
.order-detail-label { font-weight: 600; color: #6c757d; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; align-self: start; padding-top: 2px; }
.order-detail-value { word-break: break-word; }
.order-json-pre { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 10px 14px; font-size: 12px; font-family: monospace; max-height: 200px; overflow-y: auto; white-space: pre; }
.btn-order-details { padding: 2px 8px; font-size: 11px; }
/* ── Receipt ──────────────────────────────────────────────────────────────── */
.receipt-wrap { background:#fff; font-family:'Helvetica Neue',Arial,sans-serif; }
.receipt-head { background:#0f1b35; color:#fff; text-align:center; padding:30px 24px 22px; }
.receipt-logo { font-size:20px; font-weight:800; letter-spacing:.14em; line-height:1.2; }
.receipt-sub  { font-size:10px; opacity:.55; letter-spacing:.18em; text-transform:uppercase; margin-top:5px; }
.receipt-amount-block { text-align:center; padding:28px 20px 22px; background:#f7f8fa; border-bottom:2px dashed #e0e4ec; }
.receipt-amount-label { font-size:10px; font-weight:700; letter-spacing:.16em; text-transform:uppercase; color:#aaa; margin-bottom:8px; }
.receipt-amount-value { font-size:44px; font-weight:900; color:#0f1b35; line-height:1; letter-spacing:-.01em; }
.receipt-qr-block { text-align:center; padding:26px 20px 10px; }
.receipt-qr-block img { width:210px; height:210px; display:block; margin:0 auto; image-rendering:pixelated; border:1px solid #eee; border-radius:4px; padding:6px; background:#fff; }
.receipt-qr-no { text-align:center; padding:20px; color:#aaa; font-size:12px; }
.receipt-hint { text-align:center; font-size:12px; color:#777; padding:12px 30px 22px; line-height:1.65; }
.receipt-divider { border:none; border-top:2px dashed #e0e4ec; margin:0 20px; }
.receipt-meta { padding:16px 24px 6px; }
.receipt-meta-row { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
.receipt-meta-row .rm-label { font-size:10px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; color:#aaa; }
.receipt-meta-row .rm-value { font-size:12px; font-family:monospace; color:#333; text-align:right; word-break:break-all; max-width:220px; }
.receipt-bottom { text-align:center; padding:10px 20px 22px; }
.receipt-tagline { display:inline-block; font-size:10px; color:#bbb; letter-spacing:.08em; }
/* ── Action dropdown (matches users page) ─────────────────────────────────── */
.orders-act .dropdown-menu { min-width:160px; font-size:13px; }
.orders-act .btn-xs { line-height:1.4; }
.orders-act .pg-icon { font-size:16px; vertical-align:middle; }
</style>

<script>
(function ($) {
	'use strict';

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCE    = '<?php echo esc_js( $nonce ); ?>';

	var currentPage = 1;
	var totalPages  = 1;

	// ── Утилиты ───────────────────────────────────────────────────────────────

	function escHtml(str) {
		if (str === null || str === undefined) return '';
		return String(str)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function statusBadge(s) {
		var cls = 'order-status-' + escHtml(s || 'created');
		return '<span class="order-status-badge ' + cls + '">' + escHtml(s || '—') + '</span>';
	}

	function providerBadge(p) {
		return '<span class="order-provider-badge">' + escHtml(p) + '</span>';
	}

	function formatDate(dt) {
		if (!dt) return '—';
		var parts = dt.split(' ');
		return '<span class="text-muted" style="font-size:11px">' + escHtml(parts[0]) + '</span><br>'
			+ '<strong>' + escHtml(parts[1] || '') + '</strong>';
	}

	function getFilters() {
		return {
			search:     $('#of-search').val(),
			status:     $('#of-status').val(),
			provider:   $('#of-provider').val(),
			date_from:  $('#of-date-from').val(),
			date_to:    $('#of-date-to').val(),
			per_page:   $('#of-per-page').val(),
		};
	}

	// ── Загрузка ──────────────────────────────────────────────────────────────

	function fetchOrders(page) {
		$('#orders-loading').removeClass('d-none');
		$('#orders-tbody').html('<tr><td colspan="10" class="text-center p-t-20 p-b-20 text-muted">Загрузка…</td></tr>');
		$('#orders-pagination').html('');
		$('#orders-stats').html('');

		var filters = getFilters();
		currentPage = page || 1;

		$.post(AJAX_URL, $.extend({
			action:   'me_orders_list',
			_nonce:   NONCE,
			page:     currentPage,
			per_page: filters.per_page,
		}, filters))
		.done(function (res) {
			if (res.success) {
				totalPages = res.data.total_pages;
				renderTable(res.data.rows);
				renderStats(res.data.total, res.data.page, res.data.per_page);
				renderPagination(res.data.total_pages, res.data.page);
			} else {
				$('#orders-tbody').html('<tr><td colspan="10" class="text-center text-danger p-t-20 p-b-20">' + escHtml(res.data ? res.data.message : 'Ошибка') + '</td></tr>');
			}
		})
		.fail(function () {
			$('#orders-tbody').html('<tr><td colspan="10" class="text-center text-danger p-t-20 p-b-20">Сетевая ошибка.</td></tr>');
		})
		.always(function () { $('#orders-loading').addClass('d-none'); });
	}

	// ── Рендер ───────────────────────────────────────────────────────────────

	function renderTable(rows) {
		if (!rows || rows.length === 0) {
			$('#orders-tbody').html('<tr><td colspan="10" class="text-center p-t-30 p-b-30 text-muted">Записей не найдено.</td></tr>');
			return;
		}

		var html = '';
		$.each(rows, function (i, r) {
			var amountUsdt = r.amount_asset_value
				? '<span class="order-amount">' + parseFloat(r.amount_asset_value).toFixed(2) + ' ' + escHtml(r.amount_asset_code) + '</span>'
				: '—';
			var amountRub = r.payment_amount_value !== null
				? '<span class="order-amount">' + parseFloat(r.payment_amount_value).toFixed(2) + ' ' + escHtml(r.payment_currency_code) + '</span>'
				: '<span class="text-muted">—</span>';
			var paidAt = r.paid_at ? formatDate(r.paid_at) : '<span class="text-muted">—</span>';
			var source = r.source_channel ? '<span class="badge badge-light">' + escHtml(r.source_channel) + '</span>' : '<span class="text-muted">—</span>';

			html += '<tr>'
				+ '<td class="text-muted" style="font-size:11px">#' + escHtml(r.id) + '</td>'
				+ '<td>' + formatDate(r.created_at) + '</td>'
				+ '<td>' + providerBadge(r.provider_code) + '</td>'
				+ '<td style="font-size:11px;word-break:break-all">' + escHtml(r.merchant_order_id) + '</td>'
				+ '<td>' + statusBadge(r.status_code) + '</td>'
				+ '<td>' + amountUsdt + '</td>'
				+ '<td>' + amountRub + '</td>'
				+ '<td>' + source + '</td>'
				+ '<td>' + paidAt + '</td>'
				+ '<td class="orders-act">'
			+ '<div class="dropdown">'
			+ '<button class="btn btn-default btn-xs dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Действия">'
			+ '<i class="pg-icon">more_vertical</i>'
			+ '</button>'
			+ '<ul class="dropdown-menu dropdown-menu-end">'
			+ '<li><a class="dropdown-item btn-order-details" href="#" data-id="' + escHtml(r.id) + '"><i class="pg-icon m-r-5">see</i> Детали</a></li>'
			+ '<li><a class="dropdown-item btn-order-qr" href="#" data-id="' + escHtml(r.id) + '"><i class="pg-icon m-r-5">picture</i> QR-чек</a></li>'
			+ '</ul>'
			+ '</div>'
			+ '</td>'
				+ '</tr>';
		});

		$('#orders-tbody').html(html);
	}

	function renderStats(total, page, perPage) {
		var from = (page - 1) * perPage + 1;
		var to   = Math.min(page * perPage, total);
		$('#orders-stats').html(total === 0 ? 'Ничего не найдено' : 'Показано ' + from + '–' + to + ' из ' + total + ' записей');
	}

	function renderPagination(pages, current) {
		if (pages <= 1) { $('#orders-pagination').html(''); return; }

		var prev = current > 1
			? '<button class="btn btn-sm btn-default orders-page-btn" data-page="' + (current - 1) + '">‹ Назад</button>'
			: '<button class="btn btn-sm btn-default" disabled>‹ Назад</button>';
		var next = current < pages
			? '<button class="btn btn-sm btn-default orders-page-btn m-l-5" data-page="' + (current + 1) + '">Вперёд ›</button>'
			: '<button class="btn btn-sm btn-default m-l-5" disabled>Вперёд ›</button>';

		var nums = '';
		var start = Math.max(1, current - 3);
		var end   = Math.min(pages, current + 3);
		if (start > 1) nums += '<button class="btn btn-sm btn-default orders-page-btn m-l-2" data-page="1">1</button>';
		if (start > 2) nums += '<span class="m-l-5 m-r-5 text-muted">…</span>';
		for (var p = start; p <= end; p++) {
			nums += '<button class="btn btn-sm m-l-2 orders-page-btn ' + (p === current ? 'btn-primary' : 'btn-default') + '" data-page="' + p + '">' + p + '</button>';
		}
		if (end < pages - 1) nums += '<span class="m-l-5 m-r-5 text-muted">…</span>';
		if (end < pages) nums += '<button class="btn btn-sm btn-default orders-page-btn m-l-2" data-page="' + pages + '">' + pages + '</button>';

		$('#orders-pagination').html('<div>' + prev + nums + next + '</div><div class="text-muted small">Страница ' + current + ' из ' + pages + '</div>');
	}

	// ── Детали ────────────────────────────────────────────────────────────────

	var _modal = null;
	function getModal() {
		if (!_modal) { _modal = new bootstrap.Modal(document.getElementById('order-detail-modal')); }
		return _modal;
	}

	function showDetails(id) {
		$('#order-detail-body').html('<div class="text-center p-t-20 p-b-20 text-muted">Загрузка…</div>');
		getModal().show();

		$.get(AJAX_URL, { action: 'me_orders_get', _nonce: NONCE, id: id })
		.done(function (res) {
			if (res.success) { renderDetails(res.data); }
			else { $('#order-detail-body').html('<div class="text-center text-danger p-t-20 p-b-20">' + escHtml(res.data ? res.data.message : 'Ошибка') + '</div>'); }
		})
		.fail(function () { $('#order-detail-body').html('<div class="text-center text-danger p-t-20 p-b-20">Сетевая ошибка.</div>'); });
	}

	function renderDetails(d) {
		function row(label, value) {
			return '<div class="order-detail-label">' + escHtml(label) + '</div><div class="order-detail-value">' + value + '</div>';
		}

		var amountUsdt = parseFloat(d.amount_asset_value || 0).toFixed(8) + ' ' + escHtml(d.amount_asset_code);
		var amountRub  = d.payment_amount_value !== null ? parseFloat(d.payment_amount_value).toFixed(2) + ' ' + escHtml(d.payment_currency_code) : '—';
		var payLink    = d.payment_link ? '<a href="' + escHtml(d.payment_link) + '" target="_blank" style="word-break:break-all;font-size:11px">' + escHtml(d.payment_link.substring(0, 80)) + (d.payment_link.length > 80 ? '…' : '') + '</a>' : '—';

		var html = '<div class="order-detail-grid">'
			+ row('ID',              '<code>#' + escHtml(d.id) + '</code>')
			+ row('Дата',            escHtml(d.created_at))
			+ row('Провайдер',       providerBadge(d.provider_code))
			+ row('Статус',          statusBadge(d.status_code))
			+ row('Merchant ID',     '<code style="word-break:break-all">' + escHtml(d.merchant_order_id) + '</code>')
			+ row('Provider ID',     d.provider_order_id ? '<code>' + escHtml(d.provider_order_id) + '</code>' : '—')
			+ row('Ext Order ID',    d.provider_external_order_id ? '<code>' + escHtml(d.provider_external_order_id) + '</code>' : '—')
			+ row('Сумма USDT',      '<strong>' + escHtml(amountUsdt) + '</strong>')
			+ row('Сумма RUB',       '<strong>' + escHtml(amountRub) + '</strong>')
			+ row('Источник',        escHtml(d.source_channel || '—'))
			+ row('Ссылка оплаты',   payLink)
			+ row('QRC ID',          d.qrc_id ? '<code>' + escHtml(d.qrc_id) + '</code>' : '—')
			+ row('Статус провайдера', escHtml(d.provider_status_code || '—'))
			+ row('Причина',         escHtml(d.status_reason || '—'))
			+ row('Оплачен',         escHtml(d.paid_at || '—'))
			+ row('Истекает',        escHtml(d.expires_at || '—'))
			+ row('Callback',        escHtml(d.last_callback_at || '—'))
			+ row('Проверен',        escHtml(d.last_checked_at || '—'))
			+ row('Реф проекта',     escHtml(d.local_order_ref || '—'))
			+ row('Заметки',         escHtml(d.notes || '—'))
			+ '</div>';

		var openStatuses = ['created', 'pending'];
		if (openStatuses.indexOf(d.status_code) !== -1) {
			html += '<div class="m-t-20 p-t-15" style="border-top:1px solid #eee">'
				+ '<div class="dropdown">'
				+ '<button class="btn btn-default btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">'
				+ '<i class="pg-icon m-r-5">settings</i> Действия'
				+ '</button>'
				+ '<ul class="dropdown-menu">'
				+ '<li><a class="dropdown-item btn-check-paid" href="#" data-id="' + escHtml(d.id) + '"><i class="pg-icon m-r-5">tick_circle</i> Проверить оплату</a></li>'
				+ '<li><hr class="dropdown-divider"></li>'
				+ '<li><a class="dropdown-item btn-order-cancel-web" href="#" data-id="' + escHtml(d.id) + '" style="color:#c62828"><i class="pg-icon m-r-5">disable</i> Отменить ордер</a></li>'
				+ '</ul>'
				+ '</div>'
				+ '</div>';
		}

		$('#order-action-result').addClass('d-none').text('').removeClass('alert-success alert-info alert-warning alert-danger');
		$('#order-detail-body').html(html);
	}

	// ── Проверка / отмена статуса ─────────────────────────────────────────────

	$(document).on('click', '.btn-check-paid, .btn-order-cancel-web', function (e) {
		e.preventDefault();
		var $btn    = $(this);
		var id      = $btn.data('id');
		var intent  = $btn.hasClass('btn-order-cancel-web') ? 'cancel' : 'check';
		var label   = $btn.html();
		var $result = $('#order-action-result');

		$btn.addClass('disabled');
		$result.addClass('d-none').removeClass('alert-success alert-info alert-warning alert-danger');

		$.post(AJAX_URL, { action: 'me_orders_check_status', _nonce: NONCE, id: id, intent: intent })
		.done(function (res) {
			var msg, cls;
			if (res.success) {
				msg = res.data.message;
				cls = res.data.new_status === 'paid' ? 'alert-success'
				    : (['declined','cancelled','expired'].indexOf(res.data.new_status) !== -1) ? 'alert-warning'
				    : 'alert-info';
			} else {
				msg = res.data ? res.data.message : 'Ошибка';
				cls = 'alert-danger';
			}
			$result.removeClass('d-none').addClass('alert ' + cls).text(msg);
			if (res.success && res.data.changed) {
				setTimeout(function () { showDetails(id); }, 1500);
			}
		})
		.fail(function () {
			$result.removeClass('d-none').addClass('alert alert-danger').text('Сетевая ошибка.');
		})
		.always(function () { $btn.removeClass('disabled'); });
	});

	// ── QR-чек ───────────────────────────────────────────────────────────────

	var _receiptModal = null;
	function getReceiptModal() {
		if (!_receiptModal) { _receiptModal = new bootstrap.Modal(document.getElementById('order-receipt-modal')); }
		return _receiptModal;
	}

	function formatRub(val) {
		if (val === null || val === undefined) return '—';
		var n = parseFloat(val);
		// format with space thousands separator and comma decimal (Russian locale)
		var parts = n.toFixed(2).split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
		return parts[0] + ',' + parts[1] + '\u00a0₽';
	}

	function formatDateRu(dt) {
		if (!dt) return '—';
		var d = dt.split(' ')[0]; // "2026-04-14"
		var p = d.split('-');
		return p[2] + '.' + p[1] + '.' + p[0];
	}

	function showQR(id) {
		$('#order-receipt-body').html('<div class="text-center p-t-30 p-b-30 text-muted">Загрузка…</div>');
		getReceiptModal().show();

		$.get(AJAX_URL, { action: 'me_orders_get_qr', _nonce: NONCE, id: id })
		.done(function (res) {
			if (res.success) { renderReceipt(res.data); }
			else { $('#order-receipt-body').html('<div class="text-center text-danger p-t-20 p-b-20">' + escHtml(res.data ? res.data.message : 'Ошибка') + '</div>'); }
		})
		.fail(function () { $('#order-receipt-body').html('<div class="text-center text-danger p-t-20 p-b-20">Сетевая ошибка.</div>'); });
	}

	function renderReceipt(d) {
		var amountStr = d.payment_amount_value !== null ? formatRub(d.payment_amount_value) : '—';
		var dateStr   = formatDateRu(d.created_at);

		var qrBlock = d.qr_url
			? '<img src="' + escHtml(d.qr_url) + '" alt="QR-код для оплаты">'
			: '<div class="receipt-qr-no">QR-код недоступен</div>';

		var html = '<div class="receipt-wrap">'

			// Header
			+ '<div class="receipt-head">'
			+ '<div class="receipt-logo">MALIBU EXCHANGE</div>'
			+ '<div class="receipt-sub">Платёжный счёт</div>'
			+ '</div>'

			// Amount
			+ '<div class="receipt-amount-block">'
			+ '<div class="receipt-amount-label">К оплате</div>'
			+ '<div class="receipt-amount-value">' + escHtml(amountStr) + '</div>'
			+ '</div>'

			// QR
			+ '<div class="receipt-qr-block">' + qrBlock + '</div>'

			// Hint
			+ '<div class="receipt-hint">'
			+ 'Отсканируйте QR-код камерой телефона<br>'
			+ 'и переведите <strong>точную сумму</strong> по реквизитам'
			+ '</div>'

			// Meta
			+ '<hr class="receipt-divider">'
			+ '<div class="receipt-meta">'
			+ '<div class="receipt-meta-row"><span class="rm-label">Заказ</span><span class="rm-value">' + escHtml(d.merchant_order_id) + '</span></div>'
			+ '<div class="receipt-meta-row"><span class="rm-label">Дата</span><span class="rm-value">' + escHtml(dateStr) + '</span></div>'
			+ '</div>'

			// Bottom
			+ '<div class="receipt-bottom">'
			+ '<span class="receipt-tagline">malibu.exchange</span>'
			+ '</div>'

			+ '</div>';

		$('#order-receipt-body').html(html);
	}

	// ── Обработчики ───────────────────────────────────────────────────────────

	$('#btn-orders-search').on('click', function () { currentPage = 1; fetchOrders(1); });

	$('#btn-orders-reset').on('click', function () {
		$('#of-search').val('');
		$('#of-date-from, #of-date-to').val('');
		$('#of-status, #of-provider').val('').trigger('change');
		$('#of-per-page').val('25').trigger('change');
		currentPage = 1;
		fetchOrders(1);
	});

	$('#of-search').on('keypress', function (e) { if (e.which === 13) { currentPage = 1; fetchOrders(1); } });

	$(document).on('click', '.orders-page-btn', function () {
		fetchOrders(parseInt($(this).data('page'), 10));
		$('html, body').animate({ scrollTop: $('#orders-table').offset().top - 20 }, 200);
	});

	$(document).on('click', '.btn-order-details', function (e) { e.preventDefault(); showDetails($(this).data('id')); });
	$(document).on('click', '.btn-order-qr',      function (e) { e.preventDefault(); showQR($(this).data('id')); });

	fetchOrders(1);

}(jQuery));
</script>
<?php
}, 99 );
?>

<?php get_footer(); ?>
