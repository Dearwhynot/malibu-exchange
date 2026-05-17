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
										<th style="width:110px">Статус</th>
										<th style="width:130px">К выплате</th>
										<th style="width:120px">Бонус</th>
										<th style="width:120px">Рефка</th>
										<th style="width:130px">Итого</th>
										<th style="width:130px">Выплачено</th>
										<th style="width:160px">Последнее движение</th>
										<th style="width:80px" class="text-right"><i class="pg-icon">more_vertical</i></th>
									</tr>
								</thead>
								<tbody id="merchant-payouts-tbody">
									<tr>
										<td colspan="10" class="text-center p-t-30 p-b-30 text-muted">Загрузка...</td>
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

	function renderActionMenu(row) {
		if (!CAN_CREATE) {
			return '<span class="hint-text">—</span>';
		}

		return '<div class="btn-group btn-group-sm row-action-menu">'
			+ '<button type="button" class="btn btn-default btn-xs dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Действия мерчанта">'
			+ '<i class="pg-icon">more_vertical</i>'
			+ '</button>'
			+ '<div class="dropdown-menu dropdown-menu-right">'
			+ '<a href="#" class="dropdown-item js-merchant-payout-create" data-id="' + row.id + '">Произвести выплату</a>'
			+ '</div>'
			+ '</div>';
	}

	function loadRows(targetPage) {
		page = targetPage || 1;
		perPage = parseInt($('#merchant-payouts-per-page').val(), 10) || 25;

		$('#merchant-payouts-tbody').html('<tr><td colspan="10" class="text-center p-t-30 p-b-30 text-muted">Загрузка...</td></tr>');

		$.post(AJAX_URL, {
			action: 'me_merchant_payouts_list',
			_nonce: NONCE,
			page: page,
			per_page: perPage,
			search: $('#merchant-payouts-search').val() || ''
		}, function(res) {
			if (!res || !res.success) {
				showToast((res && res.data && res.data.message) || 'Не удалось загрузить выплаты мерчантам.', 'danger');
				$('#merchant-payouts-tbody').html('<tr><td colspan="10" class="text-center p-t-30 p-b-30 text-muted">Ошибка загрузки.</td></tr>');
				return;
			}

			renderRows(res.data.rows || []);
			renderPagination(res.data.total_pages || 1, res.data.page || 1, res.data.total || 0);
			$('#merchant-payouts-stats').text('Найдено: ' + (res.data.total || 0));
		}, 'json').fail(function() {
			showToast('Ошибка сервера при загрузке выплат мерчантам.', 'danger');
			$('#merchant-payouts-tbody').html('<tr><td colspan="10" class="text-center p-t-30 p-b-30 text-muted">Ошибка загрузки.</td></tr>');
		});
	}

	function renderRows(rows) {
		var $tbody = $('#merchant-payouts-tbody').empty();
		rowsById = {};

		if (!rows.length) {
			$tbody.html('<tr><td colspan="10" class="text-center p-t-30 p-b-30 text-muted">Ничего не найдено.</td></tr>');
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
				+ '<td class="v-align-middle hint-text fs-12">' + escHtml(lastMovement) + '</td>'
				+ '<td class="v-align-middle text-right">' + renderActionMenu(row) + '</td>'
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
		$('#merchant-payout-current-balance').text(row.main_balance_label || '0 USDT');
		$('#merchant-payout-modal-title').text('Произвести выплату: ' + (row.name || ('Merchant #' + row.id)));
		$('#merchant-payout-network').val('TRC20').trigger('change');
		$('#merchant-payout-modal').modal('show');
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

				showToast(res.data.message || 'Выплата внесена.', 'success');
				$('#merchant-payout-modal').modal('hide');
				loadRows(page);
			},
			error: function() {
				$('#merchant-payout-form-error').text('Ошибка сервера при сохранении выплаты.').show();
			},
			complete: function() {
				$btn.prop('disabled', false).text('Сохранить выплату');
			}
		});
	});

	$(function() {
		$('#merchant-payouts-per-page, #merchant-payout-network').select2({
			minimumResultsForSearch: Infinity
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
