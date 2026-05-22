<?php
/*
Template Name: Merchant Payouts Page
Slug: merchant-payouts
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

if ( ! crm_can_access( 'merchant_payouts.view' ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$_company_id = crm_require_company_page_context();
if ( $_company_id <= 0 ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$_nonce      = wp_create_nonce( 'me_merchant_payouts' );
$_can_create = crm_can_access( 'merchant_payouts.create' );
$_tz_label   = crm_get_timezone_label( $_company_id );
$_cancel_reasons = function_exists( 'crm_merchant_payout_cancellation_reasons' )
	? crm_merchant_payout_cancellation_reasons()
	: [
		'duplicate_entry' => 'Случайно внесена повторная выплата',
		'wrong_wallet'    => 'Указан неверный адрес кошелька',
		'tx_not_sent'     => 'Транзакция фактически не была отправлена',
		'test_entry'      => 'Тестовая или ошибочная запись',
		'other'           => 'Другая причина',
	];
$_networks   = [
	'TRC20' => 'TRC20',
];

get_header();
?>

<?php get_template_part( 'template-parts/sidebar' ); ?>

<div class="page-container">
	<?php get_template_part( 'template-parts/header-backoffice' ); ?>

	<div class="page-content-wrapper">
		<div class="content">
			<div class="jumbotron" data-pages="parallax">
				<div class="container-fluid container-fixed-lg sm-p-l-0 sm-p-r-0">
					<div class="inner">
						<ol class="breadcrumb">
							<li class="breadcrumb-item"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a></li>
							<li class="breadcrumb-item active">Выплаты мерчантам</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">
				<?php get_template_part( 'template-parts/toast-host' ); ?>

				<div class="row">
					<div class="col-md-8">
						<h3 class="m-t-0">Выплаты мерчантам</h3>
						<p class="hint-text">
							Рабочий экран для закрытия основного долга компании перед мерчантами.
							Блок «Баланс» в карточке мерчанта остается справочным.
						</p>
					</div>
					<div class="col-md-4 text-md-right">
						<span class="hint-text fs-12"><?php echo esc_html( $_tz_label ); ?></span>
					</div>
				</div>

				<div class="card card-default m-b-20">
					<div class="card-body p-t-20 p-b-15">
						<form id="merchant-payouts-filter-form" class="row align-items-center">
							<div class="col-md-6 col-sm-12 m-b-10">
								<div class="input-group">
									<span class="input-group-text"><i class="pg-icon">search</i></span>
									<input type="text" id="merchant-payouts-search" class="form-control" placeholder="merchant, chat_id, username">
								</div>
							</div>
							<div class="col-md-2 col-sm-4 m-b-10">
								<select id="merchant-payouts-per-page" class="full-width" data-init-plugin="select2">
									<option value="25">25</option>
									<option value="50">50</option>
									<option value="100">100</option>
								</select>
							</div>
							<div class="col-md-4 col-sm-8 m-b-10">
								<button type="submit" class="btn btn-primary">
									<i class="pg-icon m-r-5">search</i>Найти
								</button>
								<button type="button" id="merchant-payouts-reset" class="btn btn-default">Сброс</button>
							</div>
						</form>
					</div>
				</div>

				<div class="card card-default">
					<div class="card-header">
						<div class="card-title">Мерчанты и суммы к выплате</div>
						<div class="card-controls">
							<span class="hint-text fs-12" id="merchant-payouts-stats">—</span>
						</div>
					</div>
					<div class="card-body p-0">
						<div class="table-responsive">
							<table class="table table-hover m-b-0" id="merchant-payouts-table">
								<thead>
									<tr>
										<th style="width:60px">#</th>
										<th style="width:260px">Мерчант</th>
										<th style="width:120px">Статус мерчанта</th>
										<th style="width:130px">К выплате</th>
										<th style="width:120px">Бонус</th>
										<th style="width:120px">Рефка</th>
										<th style="width:130px">Итого</th>
										<th style="width:130px">Выплачено</th>
										<th style="width:170px">Статус выплаты</th>
										<th style="width:160px">Последнее движение</th>
										<th class="me-actions-col"></th>
									</tr>
								</thead>
								<tbody id="merchant-payouts-tbody">
									<tr>
										<td colspan="11" class="text-center p-t-30 p-b-30 text-muted">Загрузка...</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
					<div class="card-body border-top p-2 d-flex justify-content-between align-items-center" id="merchant-payouts-pagination-row" style="display:none!important;">
						<span class="hint-text fs-12" id="merchant-payouts-count-label"></span>
						<div id="merchant-payouts-pagination"></div>
					</div>
				</div>
			</div>
		</div>

		<?php get_template_part( 'template-parts/footer-backoffice' ); ?>
	</div>
</div>

<div class="modal fade" id="merchant-payout-modal" tabindex="-1" role="dialog" aria-labelledby="merchant-payout-modal-title" aria-hidden="true">
	<div class="modal-dialog modal-lg" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title" id="merchant-payout-modal-title">Произвести выплату</h4>
				<button type="button" class="close" data-bs-dismiss="modal" aria-label="Закрыть">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<form id="merchant-payout-form" enctype="multipart/form-data" novalidate>
				<div class="modal-body">
					<input type="hidden" name="_nonce" value="<?php echo esc_attr( $_nonce ); ?>">
					<input type="hidden" name="merchant_id" id="merchant-payout-merchant-id" value="">

					<div class="alert alert-info" id="merchant-payout-balance-hint">
						К выплате: <strong id="merchant-payout-current-balance">0 USDT</strong>
					</div>

					<div class="row">
						<div class="col-md-4 m-b-15">
							<label class="fs-12 text-uppercase hint-text">Сумма USDT <span class="text-danger">*</span></label>
							<input type="number" name="amount" id="merchant-payout-amount" class="form-control" min="0.00000001" step="0.00000001" placeholder="0.00" required>
						</div>
						<div class="col-md-4 m-b-15">
							<label class="fs-12 text-uppercase hint-text">Сеть</label>
							<select name="network" id="merchant-payout-network" class="full-width" data-init-plugin="select2">
								<?php foreach ( $_networks as $_network_code => $_network_label ) : ?>
									<option value="<?php echo esc_attr( $_network_code ); ?>"><?php echo esc_html( $_network_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="col-md-4 m-b-15">
							<label class="fs-12 text-uppercase hint-text">TX hash</label>
							<input type="text" name="tx_hash" class="form-control" placeholder="Необязательно" maxlength="255">
						</div>
						<div class="col-md-12 m-b-15">
							<label class="fs-12 text-uppercase hint-text">Кошелёк</label>
							<input type="text" name="wallet_address" class="form-control" placeholder="Необязательно" maxlength="255">
						</div>
						<div class="col-md-12 m-b-15">
							<label class="fs-12 text-uppercase hint-text">Скриншот / квитанция <span class="hint-text">(необязательно)</span></label>
							<input type="file" name="receipt" id="merchant-payout-receipt" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
							<div id="merchant-payout-receipt-preview" class="m-t-10" style="display:none;">
								<img id="merchant-payout-receipt-img" src="" alt="Предпросмотр" style="max-height:120px;max-width:240px;border-radius:4px;border:1px solid #dee2e6;object-fit:cover;">
								<button type="button" id="merchant-payout-receipt-clear" class="btn btn-xs btn-default m-l-10">Убрать</button>
							</div>
						</div>
						<div class="col-md-12 m-b-15">
							<label class="fs-12 text-uppercase hint-text">Комментарий</label>
							<textarea name="notes" class="form-control" rows="2" placeholder="Необязательно" maxlength="500"></textarea>
						</div>
					</div>

					<div id="merchant-payout-form-error" class="alert alert-danger" style="display:none;"></div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-default" data-bs-dismiss="modal">Отмена</button>
					<button type="submit" class="btn btn-complete" id="merchant-payout-submit">Сохранить выплату</button>
				</div>
			</form>
		</div>
	</div>
</div>

<div class="modal fade" id="merchant-payout-history-modal" tabindex="-1" role="dialog" aria-labelledby="merchant-payout-history-title" aria-hidden="true">
	<div class="modal-dialog modal-xl" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title" id="merchant-payout-history-title">История выплат мерчанта</h4>
				<button type="button" class="close" data-bs-dismiss="modal" aria-label="Закрыть">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="modal-body p-0">
				<div class="p-3 border-bottom d-flex justify-content-between align-items-center">
					<div>
						<div class="semi-bold" id="merchant-payout-history-merchant-label">—</div>
						<div class="hint-text fs-12">Статусы и действия по конкретным выплатам мерчанта.</div>
					</div>
					<div class="hint-text fs-12" id="merchant-payout-history-count-label">—</div>
				</div>

				<div class="table-responsive">
					<table class="table table-hover m-b-0">
						<thead>
							<tr>
								<th style="width:70px">#</th>
								<th style="width:150px">Дата</th>
								<th style="width:130px">Сумма</th>
								<th style="width:170px">Статус</th>
								<th style="width:110px">Сеть</th>
								<th style="width:250px">TX / кошелёк</th>
								<th>Комментарий</th>
								<th style="width:120px" class="text-right">Действия</th>
							</tr>
						</thead>
						<tbody id="merchant-payout-history-tbody">
							<tr>
								<td colspan="8" class="text-center p-t-30 p-b-30 text-muted">Загрузка...</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
			<div class="modal-footer d-flex justify-content-between align-items-center">
				<div id="merchant-payout-history-pagination"></div>
				<button type="button" class="btn btn-default" data-bs-dismiss="modal">Закрыть</button>
			</div>
		</div>
	</div>
</div>

<div class="modal fade" id="merchant-payout-cancel-modal" tabindex="-1" role="dialog" aria-labelledby="merchant-payout-cancel-title" aria-hidden="true">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title" id="merchant-payout-cancel-title">Отменить выплату</h4>
				<button type="button" class="close" data-bs-dismiss="modal" aria-label="Закрыть">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<form id="merchant-payout-cancel-form" novalidate>
				<div class="modal-body">
					<input type="hidden" name="_nonce" value="<?php echo esc_attr( $_nonce ); ?>">
					<input type="hidden" name="payout_id" id="merchant-payout-cancel-id" value="">

					<div class="alert alert-warning">
						<div class="semi-bold">Отмена вернёт сумму выплаты обратно на баланс мерчанта.</div>
						<div class="m-t-5" id="merchant-payout-cancel-summary">—</div>
					</div>

					<div class="form-group">
						<label class="fs-12 text-uppercase hint-text">Причина отмены <span class="text-danger">*</span></label>
						<select name="reason_code" id="merchant-payout-cancel-reason" class="full-width">
							<?php foreach ( $_cancel_reasons as $_reason_code => $_reason_label ) : ?>
								<option value="<?php echo esc_attr( $_reason_code ); ?>"><?php echo esc_html( $_reason_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="form-group">
						<label class="fs-12 text-uppercase hint-text">Комментарий <span class="text-danger">*</span></label>
						<textarea name="comment" id="merchant-payout-cancel-comment" class="form-control" rows="3" maxlength="500" placeholder="Обязательно опишите, что произошло"></textarea>
					</div>

					<div id="merchant-payout-cancel-error" class="alert alert-danger" style="display:none;"></div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-default" data-bs-dismiss="modal">Закрыть</button>
					<button type="submit" class="btn btn-danger" id="merchant-payout-cancel-submit">Подтвердить отмену</button>
				</div>
			</form>
		</div>
	</div>
</div>

<?php get_template_part( 'template-parts/quickview' ); ?>
<?php get_template_part( 'template-parts/overlay' ); ?>

<?php
add_action(
	'wp_footer',
	function () use ( $_nonce, $_can_create ) {
		?>
<script>
(function($) {
	'use strict';

	var AJAX_URL = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	var NONCE = <?php echo wp_json_encode( $_nonce ); ?>;
	var CAN_CREATE = <?php echo $_can_create ? 'true' : 'false'; ?>;
	var page = 1;
	var perPage = 25;
	var rowsById = {};
	var payoutHistoryMerchantId = 0;
	var payoutHistoryPage = 1;
	var payoutHistoryPerPage = 10;
	var payoutHistoryRowsById = {};

	function escHtml(value) {
		return $('<div>').text(String(value == null ? '' : value)).html();
	}

	function showToast(message, type) {
		if (window.MalibuToast && typeof window.MalibuToast.show === 'function') {
			window.MalibuToast.show(message, type || 'info');
		}
	}

	function merchantAvatarInitials(label) {
		return (label || 'TG').replace(/\s+/g, ' ').trim().substring(0, 2).toUpperCase() || 'TG';
	}

	function merchantAvatarHtml(row) {
		var label = row.name || row.telegram_username || row.chat_id || 'TG';
		if (row.telegram_avatar_url) {
			return '<span class="thumbnail-wrapper circular inline m-r-10 merchant-avatar-inline">'
				+ '<img class="merchant-avatar-inline__image" src="' + escHtml(row.telegram_avatar_url) + '" alt="' + escHtml(label) + '">'
				+ '</span>';
		}
		return '<span class="thumbnail-wrapper circular inline m-r-10 bg-complete text-white merchant-avatar-inline merchant-avatar-inline--fallback"><span>'
			+ escHtml(merchantAvatarInitials(label))
			+ '</span></span>';
	}

	function payoutStatusHtml(row) {
		if (!row.last_payout_status) {
			return '<span class="hint-text">—</span>';
		}

		var html = '<span class="badge badge-' + escHtml(row.last_payout_status_badge || 'default') + '">'
			+ escHtml(row.last_payout_status_label || row.last_payout_status)
			+ '</span>';

		if (row.last_payout_at) {
			html += '<div class="hint-text fs-11 m-t-5">' + escHtml(row.last_payout_at) + '</div>';
		}

		return html;
	}

	function merchantPayoutZeroBalanceMessage() {
		return '🚨 Выплата недоступна: у мерчанта сейчас 0 USDT к выплате. Сначала должны появиться начисления на основной баланс.';
	}

	function merchantCanCreatePayout(row) {
		return parseFloat(row && row.main_balance ? row.main_balance : 0) > 0;
	}

	function syncPayoutModalAvailability(row) {
		var canCreate = merchantCanCreatePayout(row);
		var $hint = $('#merchant-payout-balance-hint');
		var $submit = $('#merchant-payout-submit');
		var $inputs = $('#merchant-payout-form')
			.find('input[type="number"], input[type="text"], input[type="file"], textarea, select')
			.not('[name="_nonce"], [name="merchant_id"]');

		if (canCreate) {
			$hint
				.removeClass('alert-danger')
				.addClass('alert-info')
				.html('К выплате: <strong>' + escHtml(row.main_balance_label || '0 USDT') + '</strong>');
			$submit.prop('disabled', false);
			$inputs.prop('disabled', false);
			$('#merchant-payout-form').data('payoutAllowed', '1');
			return;
		}

		$hint
			.removeClass('alert-info')
			.addClass('alert-danger')
			.html('<strong>' + escHtml(merchantPayoutZeroBalanceMessage()) + '</strong>');
		$submit.prop('disabled', true);
		$inputs.prop('disabled', true);
		$('#merchant-payout-form').data('payoutAllowed', '0');
	}

	function renderActionMenu(row) {
		var items = [];

		if (CAN_CREATE) {
			items.push('<a href="#" class="dropdown-item js-merchant-payout-create" data-id="' + row.id + '">Произвести выплату</a>');
		}
		items.push('<a href="#" class="dropdown-item js-merchant-payout-history" data-id="' + row.id + '">История выплат</a>');

		if (!items.length) {
			return '<span class="hint-text">—</span>';
		}

		return '<div class="btn-group btn-group-sm row-action-menu">'
			+ '<button type="button" class="btn btn-default btn-xs dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Действия мерчанта">'
			+ '<i class="pg-icon">more_vertical</i>'
			+ '</button>'
			+ '<div class="dropdown-menu dropdown-menu-right">'
			+ items.join('')
			+ '</div>'
			+ '</div>';
	}

	function loadRows(targetPage) {
		page = targetPage || 1;
		perPage = parseInt($('#merchant-payouts-per-page').val(), 10) || 25;

		$('#merchant-payouts-tbody').html('<tr><td colspan="11" class="text-center p-t-30 p-b-30 text-muted">Загрузка...</td></tr>');

		$.post(AJAX_URL, {
			action: 'me_merchant_payouts_list',
			_nonce: NONCE,
			page: page,
			per_page: perPage,
			search: $('#merchant-payouts-search').val() || ''
		}, function(res) {
			if (!res || !res.success) {
				showToast((res && res.data && res.data.message) || 'Не удалось загрузить выплаты мерчантам.', 'danger');
				$('#merchant-payouts-tbody').html('<tr><td colspan="11" class="text-center p-t-30 p-b-30 text-muted">Ошибка загрузки.</td></tr>');
				return;
			}

			renderRows(res.data.rows || []);
			renderPagination(res.data.total_pages || 1, res.data.page || 1, res.data.total || 0);
			$('#merchant-payouts-stats').text('Найдено: ' + (res.data.total || 0));
		}, 'json').fail(function() {
			showToast('Ошибка сервера при загрузке выплат мерчантам.', 'danger');
			$('#merchant-payouts-tbody').html('<tr><td colspan="11" class="text-center p-t-30 p-b-30 text-muted">Ошибка загрузки.</td></tr>');
		});
	}

	function renderRows(rows) {
		var $tbody = $('#merchant-payouts-tbody').empty();
		rowsById = {};

		if (!rows.length) {
			$tbody.html('<tr><td colspan="11" class="text-center p-t-30 p-b-30 text-muted">Ничего не найдено.</td></tr>');
			$('#merchant-payouts-pagination-row').hide();
			return;
		}

		$.each(rows, function(_, row) {
			rowsById[row.id] = row;
			var nameHtml = '<div class="d-flex align-items-center">'
				+ merchantAvatarHtml(row)
				+ '<div><div class="semi-bold">' + escHtml(row.name || 'Без имени') + '</div>'
				+ '<div class="hint-text fs-12">ID #' + row.id + ' · ' + escHtml(row.telegram_username ? '@' + row.telegram_username : row.chat_id) + '</div></div></div>';
			var statusHtml = '<span class="badge badge-' + escHtml(row.status_badge || 'default') + '">' + escHtml(row.status_label || row.status || '—') + '</span>';
			var lastMovement = row.last_movement_at || row.last_paid_at || '—';
			var payoutStatus = payoutStatusHtml(row);

			$tbody.append(
				'<tr>'
				+ '<td class="v-align-middle"><span class="hint-text fs-12">#' + row.id + '</span></td>'
				+ '<td class="v-align-middle">' + nameHtml + '</td>'
				+ '<td class="v-align-middle">' + statusHtml + '</td>'
				+ '<td class="v-align-middle font-montserrat fs-12 semi-bold">' + escHtml(row.main_balance_label) + '</td>'
				+ '<td class="v-align-middle font-montserrat fs-12">' + escHtml(row.bonus_balance_label) + '</td>'
				+ '<td class="v-align-middle font-montserrat fs-12">' + escHtml(row.referral_balance_label) + '</td>'
				+ '<td class="v-align-middle font-montserrat fs-12">' + escHtml(row.total_balance_label) + '</td>'
				+ '<td class="v-align-middle font-montserrat fs-12">' + escHtml(row.paid_total_label) + '</td>'
				+ '<td class="v-align-middle">' + payoutStatus + '</td>'
				+ '<td class="v-align-middle hint-text fs-12">' + escHtml(lastMovement) + '</td>'
				+ '<td class="v-align-middle me-actions-col">' + renderActionMenu(row) + '</td>'
				+ '</tr>'
			);
		});

		$('#merchant-payouts-tbody [data-bs-toggle="dropdown"]').each(function() {
			bootstrap.Dropdown.getOrCreateInstance(this, {
				popperConfig: function(config) {
					return $.extend(true, config, { strategy: 'fixed' });
				}
			});
		});
	}

	function renderPagination(totalPages, current, total) {
		$('#merchant-payouts-count-label').text('Записей: ' + total);
		var $p = $('#merchant-payouts-pagination').empty();
		if (totalPages <= 1) {
			$('#merchant-payouts-pagination-row').hide();
			return;
		}

		var html = '<ul class="pagination pagination-sm no-margin">';
		html += '<li class="page-item' + (current <= 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (current - 1) + '">&laquo;</a></li>';
		for (var i = 1; i <= totalPages; i++) {
			html += '<li class="page-item' + (i === current ? ' active' : '') + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
		}
		html += '<li class="page-item' + (current >= totalPages ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (current + 1) + '">&raquo;</a></li>';
		html += '</ul>';
		$p.html(html);
		$('#merchant-payouts-pagination-row').show();
	}

	function openPayoutModal(merchantId) {
		var row = rowsById[merchantId];
		if (!row) {
			showToast('Не удалось определить мерчанта.', 'warning');
			return;
		}

		$('#merchant-payout-form')[0].reset();
		$('#merchant-payout-form-error').hide();
		$('#merchant-payout-receipt-preview').hide();
		$('#merchant-payout-receipt-img').attr('src', '');
		$('#merchant-payout-merchant-id').val(row.id);
		$('#merchant-payout-modal-title').text('Произвести выплату: ' + (row.name || ('Merchant #' + row.id)));
		$('#merchant-payout-network').val('TRC20').trigger('change');
		syncPayoutModalAvailability(row);
		$('#merchant-payout-modal').modal('show');
	}

	function renderHistoryPagination(totalPages, current, total) {
		$('#merchant-payout-history-count-label').text('Записей: ' + total);
		var $p = $('#merchant-payout-history-pagination').empty();
		if (totalPages <= 1) {
			return;
		}

		var html = '<ul class="pagination pagination-sm no-margin">';
		html += '<li class="page-item' + (current <= 1 ? ' disabled' : '') + '"><a class="page-link js-merchant-payout-history-page" href="#" data-page="' + (current - 1) + '">&laquo;</a></li>';
		for (var i = 1; i <= totalPages; i++) {
			html += '<li class="page-item' + (i === current ? ' active' : '') + '"><a class="page-link js-merchant-payout-history-page" href="#" data-page="' + i + '">' + i + '</a></li>';
		}
		html += '<li class="page-item' + (current >= totalPages ? ' disabled' : '') + '"><a class="page-link js-merchant-payout-history-page" href="#" data-page="' + (current + 1) + '">&raquo;</a></li>';
		html += '</ul>';
		$p.html(html);
	}

	function payoutHistoryStatusCell(row) {
		var html = '<span class="badge badge-' + escHtml(row.status_badge || 'default') + '">' + escHtml(row.status_label || row.status_code || '—') + '</span>';

		if (row.confirmed_at) {
			html += '<div class="hint-text fs-11 m-t-5">Подтв.: ' + escHtml(row.confirmed_at) + '</div>';
		}
		if (row.cancelled_at) {
			html += '<div class="hint-text fs-11 m-t-5">Отмена: ' + escHtml(row.cancelled_at) + '</div>';
		}
		if (row.cancellation_reason_label) {
			html += '<div class="hint-text fs-11 m-t-5">' + escHtml(row.cancellation_reason_label) + '</div>';
		}

		return html;
	}

	function payoutHistoryDetailsCell(row) {
		var parts = [];

		if (row.tx_hash) {
			parts.push('<div><span class="semi-bold">TX:</span> <code>' + escHtml(row.tx_hash) + '</code></div>');
		}
		if (row.wallet_address) {
			parts.push('<div class="m-t-5"><span class="semi-bold">Wallet:</span> <code>' + escHtml(row.wallet_address) + '</code></div>');
		}
		if (row.receipt_url) {
			parts.push('<div class="m-t-5"><a href="' + escHtml(row.receipt_url) + '" target="_blank" rel="noopener">Открыть скриншот</a></div>');
		}

		return parts.length ? parts.join('') : '<span class="hint-text">—</span>';
	}

	function payoutHistoryCommentCell(row) {
		var html = row.notes ? escHtml(row.notes) : '<span class="hint-text">—</span>';

		if (row.cancellation_comment) {
			html += '<div class="m-t-5 text-danger fs-12">' + escHtml(row.cancellation_comment) + '</div>';
		}

		return html;
	}

	function renderPayoutHistoryRows(rows) {
		var $tbody = $('#merchant-payout-history-tbody').empty();
		payoutHistoryRowsById = {};

		if (!rows.length) {
			$tbody.html('<tr><td colspan="8" class="text-center p-t-30 p-b-30 text-muted">Выплат пока нет.</td></tr>');
			return;
		}

		$.each(rows, function(_, row) {
			payoutHistoryRowsById[row.id] = row;

			var actions = '<span class="hint-text">—</span>';
			if (row.can_cancel) {
				actions = '<button type="button" class="btn btn-xs btn-danger js-merchant-payout-cancel-open" data-id="' + row.id + '">Отменить</button>';
			}

			$tbody.append(
				'<tr>'
				+ '<td class="v-align-middle hint-text fs-12">#' + row.id + '</td>'
				+ '<td class="v-align-middle">' + escHtml(row.paid_at || '—') + '</td>'
				+ '<td class="v-align-middle font-montserrat fs-12 semi-bold">' + escHtml(row.amount_label || '—') + '</td>'
				+ '<td class="v-align-middle">' + payoutHistoryStatusCell(row) + '</td>'
				+ '<td class="v-align-middle">' + escHtml(row.network || '—') + '</td>'
				+ '<td class="v-align-middle">' + payoutHistoryDetailsCell(row) + '</td>'
				+ '<td class="v-align-middle">' + payoutHistoryCommentCell(row) + '</td>'
				+ '<td class="v-align-middle text-right">' + actions + '</td>'
				+ '</tr>'
			);
		});
	}

	function loadPayoutHistory(targetPage) {
		if (!payoutHistoryMerchantId) {
			return;
		}

		payoutHistoryPage = targetPage || 1;
		$('#merchant-payout-history-tbody').html('<tr><td colspan="8" class="text-center p-t-30 p-b-30 text-muted">Загрузка...</td></tr>');

		$.post(AJAX_URL, {
			action: 'me_merchant_payouts_history',
			_nonce: NONCE,
			merchant_id: payoutHistoryMerchantId,
			page: payoutHistoryPage,
			per_page: payoutHistoryPerPage
		}, function(res) {
			if (!res || !res.success) {
				showToast((res && res.data && res.data.message) || 'Не удалось загрузить историю выплат.', 'danger');
				$('#merchant-payout-history-tbody').html('<tr><td colspan="8" class="text-center p-t-30 p-b-30 text-muted">Ошибка загрузки.</td></tr>');
				return;
			}

			$('#merchant-payout-history-merchant-label').text((res.data.merchant && res.data.merchant.name) || ('Merchant #' + payoutHistoryMerchantId));
			renderPayoutHistoryRows(res.data.rows || []);
			renderHistoryPagination(res.data.total_pages || 1, res.data.page || 1, res.data.total || 0);
		}, 'json').fail(function() {
			showToast('Ошибка сервера при загрузке истории выплат.', 'danger');
			$('#merchant-payout-history-tbody').html('<tr><td colspan="8" class="text-center p-t-30 p-b-30 text-muted">Ошибка сервера.</td></tr>');
		});
	}

	function openPayoutHistoryModal(merchantId) {
		var row = rowsById[merchantId];
		if (!row) {
			showToast('Не удалось определить мерчанта.', 'warning');
			return;
		}

		payoutHistoryMerchantId = merchantId;
		payoutHistoryPage = 1;
		$('#merchant-payout-history-title').text('История выплат: ' + (row.name || ('Merchant #' + row.id)));
		$('#merchant-payout-history-merchant-label').text(row.name || ('Merchant #' + row.id));
		$('#merchant-payout-history-pagination').empty();
		$('#merchant-payout-history-count-label').text('—');
		$('#merchant-payout-history-modal').modal('show');
		loadPayoutHistory(1);
	}

	function openPayoutCancelModal(payoutId) {
		var row = payoutHistoryRowsById[payoutId];
		if (!row) {
			showToast('Не удалось определить выплату.', 'warning');
			return;
		}

		$('#merchant-payout-cancel-form')[0].reset();
		$('#merchant-payout-cancel-error').hide();
		$('#merchant-payout-cancel-id').val(row.id);
		$('#merchant-payout-cancel-reason').val('duplicate_entry').trigger('change');
		$('#merchant-payout-cancel-summary').html(
			'<strong>#' + escHtml(row.id) + '</strong> · '
			+ escHtml(row.amount_label || '—')
			+ (row.network ? ' · ' + escHtml(row.network) : '')
			+ (row.paid_at ? ' · ' + escHtml(row.paid_at) : '')
		);
		$('#merchant-payout-cancel-modal').modal('show');
	}

	$('#merchant-payouts-filter-form').on('submit', function(e) {
		e.preventDefault();
		loadRows(1);
	});

	$('#merchant-payouts-reset').on('click', function() {
		$('#merchant-payouts-search').val('');
		$('#merchant-payouts-per-page').val('25').trigger('change');
		loadRows(1);
	});

	$('#merchant-payouts-pagination').on('click', '.page-link', function(e) {
		e.preventDefault();
		var target = parseInt($(this).data('page'), 10);
		if (target >= 1) {
			loadRows(target);
		}
	});

	$(document).on('click', '.js-merchant-payout-create', function(e) {
		e.preventDefault();
		openPayoutModal(parseInt($(this).data('id'), 10));
	});

	$(document).on('click', '.js-merchant-payout-history', function(e) {
		e.preventDefault();
		openPayoutHistoryModal(parseInt($(this).data('id'), 10));
	});

	$('#merchant-payout-history-pagination').on('click', '.js-merchant-payout-history-page', function(e) {
		e.preventDefault();
		var target = parseInt($(this).data('page'), 10);
		if (target >= 1) {
			loadPayoutHistory(target);
		}
	});

	$(document).on('click', '.js-merchant-payout-cancel-open', function() {
		openPayoutCancelModal(parseInt($(this).data('id'), 10));
	});

	$('#merchant-payout-receipt').on('change', function() {
		var file = this.files && this.files[0] ? this.files[0] : null;
		if (!file) {
			return;
		}
		var reader = new FileReader();
		reader.onload = function(e) {
			$('#merchant-payout-receipt-img').attr('src', e.target.result);
			$('#merchant-payout-receipt-preview').show();
		};
		reader.readAsDataURL(file);
	});

	$('#merchant-payout-receipt-clear').on('click', function() {
		$('#merchant-payout-receipt').val('');
		$('#merchant-payout-receipt-preview').hide();
		$('#merchant-payout-receipt-img').attr('src', '');
	});

	$('#merchant-payout-form').on('submit', function(e) {
		e.preventDefault();
		$('#merchant-payout-form-error').hide();

		if ($(this).data('payoutAllowed') !== '1') {
			$('#merchant-payout-form-error').text(merchantPayoutZeroBalanceMessage()).show();
			return;
		}

		var $btn = $('#merchant-payout-submit').prop('disabled', true).text('Сохранение...');
		var fd = new FormData(this);
		fd.append('action', 'me_merchant_payouts_create');

		$.ajax({
			url: AJAX_URL,
			type: 'POST',
			data: fd,
			processData: false,
			contentType: false,
			dataType: 'json',
			success: function(res) {
				if (!res || !res.success) {
					$('#merchant-payout-form-error').text((res && res.data && res.data.message) || 'Ошибка сохранения.').show();
					return;
				}

				showToast(res.data.message || 'Выплата внесена.', res.data.toast_type || 'success');
				$('#merchant-payout-modal').modal('hide');
				loadRows(page);
				if (payoutHistoryMerchantId && parseInt($('#merchant-payout-merchant-id').val(), 10) === payoutHistoryMerchantId) {
					loadPayoutHistory(1);
				}
			},
			error: function() {
				$('#merchant-payout-form-error').text('Ошибка сервера при сохранении выплаты.').show();
			},
			complete: function() {
				$btn.prop('disabled', false).text('Сохранить выплату');
			}
		});
	});

	$('#merchant-payout-cancel-form').on('submit', function(e) {
		e.preventDefault();
		$('#merchant-payout-cancel-error').hide();

		var comment = $.trim($('#merchant-payout-cancel-comment').val());
		if (!comment) {
			$('#merchant-payout-cancel-error').text('Комментарий обязателен для отмены выплаты.').show();
			return;
		}

		var $btn = $('#merchant-payout-cancel-submit').prop('disabled', true).text('Сохраняем...');

		$.post(AJAX_URL, {
			action: 'me_merchant_payouts_cancel',
			_nonce: NONCE,
			payout_id: $('#merchant-payout-cancel-id').val(),
			reason_code: $('#merchant-payout-cancel-reason').val() || 'other',
			comment: comment
		}, function(res) {
			if (!res || !res.success) {
				$('#merchant-payout-cancel-error').text((res && res.data && res.data.message) || 'Не удалось отменить выплату.').show();
				return;
			}

			showToast(res.data.message || 'Выплата отменена.', res.data.toast_type || 'warning');
			$('#merchant-payout-cancel-modal').modal('hide');
			loadRows(page);
			loadPayoutHistory(payoutHistoryPage);
		}, 'json').fail(function() {
			$('#merchant-payout-cancel-error').text('Ошибка сервера при отмене выплаты.').show();
		}).always(function() {
			$btn.prop('disabled', false).text('Подтвердить отмену');
		});
	});

	$(function() {
		$('#merchant-payouts-per-page, #merchant-payout-network').select2({
			minimumResultsForSearch: Infinity
		});

		$('#merchant-payout-cancel-modal').on('shown.bs.modal', function() {
			var $reason = $('#merchant-payout-cancel-reason');
			if ($reason.hasClass('select2-hidden-accessible')) {
				$reason.select2('destroy');
			}
			$reason.select2({
				dropdownParent: $('#merchant-payout-cancel-modal'),
				minimumResultsForSearch: Infinity,
				width: '100%'
			});
		});

		$('#merchant-payout-cancel-modal').on('hidden.bs.modal', function() {
			var $reason = $('#merchant-payout-cancel-reason');
			if ($reason.hasClass('select2-hidden-accessible')) {
				$reason.select2('destroy');
			}
		});

		loadRows(1);
	});
})(jQuery);
</script>
		<?php
	},
	99
);
?>

<?php get_footer(); ?>
