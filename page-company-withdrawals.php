<?php
/*
Template Name: Company Withdrawals Page
Slug: company-withdrawals
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

if ( ! crm_can_access( 'company_withdrawals.view' ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$_company_id = crm_require_company_page_context();
if ( $_company_id <= 0 ) {
	wp_die( 'Выводы компании доступны только внутри активной компании.', 'Недоступно', [ 'response' => 403 ] );
}

$_tz_label   = crm_get_timezone_label( $_company_id );
$_nonce      = wp_create_nonce( 'me_company_withdrawals' );
$_can_create = crm_can_access( 'company_withdrawals.create' );
$_stats      = function_exists( 'crm_company_withdrawals_get_stats' )
	? crm_company_withdrawals_get_stats( $_company_id )
	: [ 'platform_fee_total' => 0.0, 'withdrawals_total' => 0.0, 'available_balance' => 0.0 ];

function _company_withdrawals_fmt_usdt( float $value ): string {
	$formatted = number_format( $value, 8, '.', "\xc2\xa0" );
	$formatted = rtrim( rtrim( $formatted, '0' ), '.' );
	if ( $formatted === '' || $formatted === '-0' ) {
		$formatted = '0';
	}

	return $formatted . "\xc2\xa0USDT";
}

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
							<li class="breadcrumb-item active">Выводы компании</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">

				<div class="row m-b-20">
					<div class="col-xl-4 col-lg-4 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Накопленная прибыль</p>
								<h3 class="no-margin bold text-success"><?php echo esc_html( _company_withdrawals_fmt_usdt( (float) $_stats['platform_fee_total'] ) ); ?></h3>
								<p class="hint-text no-margin fs-12">merchant fee по paid-ордерам</p>
							</div>
						</div>
					</div>
					<div class="col-xl-4 col-lg-4 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Уже выведено</p>
								<h3 class="no-margin bold text-complete"><?php echo esc_html( _company_withdrawals_fmt_usdt( (float) $_stats['withdrawals_total'] ) ); ?></h3>
								<p class="hint-text no-margin fs-12">зафиксированные выводы компании</p>
							</div>
						</div>
					</div>
					<div class="col-xl-4 col-lg-4 col-md-12 col-sm-12 m-b-15">
						<div class="card no-margin <?php echo (float) $_stats['available_balance'] > 0 ? 'bg-success' : 'card-default'; ?>">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase <?php echo (float) $_stats['available_balance'] > 0 ? 'text-white' : ''; ?>">Доступно к выводу</p>
								<h3 class="no-margin bold <?php echo (float) $_stats['available_balance'] > 0 ? 'text-white' : 'text-master'; ?>">
									<?php echo esc_html( _company_withdrawals_fmt_usdt( (float) $_stats['available_balance'] ) ); ?>
								</h3>
								<p class="no-margin fs-11 <?php echo (float) $_stats['available_balance'] > 0 ? 'text-white' : 'hint-text'; ?>">profit минус выводы</p>
							</div>
						</div>
					</div>
				</div>

				<?php if ( $_can_create ) : ?>
				<div class="row m-b-20">
					<div class="col-12">
						<button class="btn btn-complete" id="btn-add-company-withdrawal">
							<i class="pg-icon m-r-5">add</i>Зафиксировать вывод
						</button>
					</div>
				</div>

				<div id="company-withdrawal-form-wrapper" style="display:none;" class="m-b-25">
					<div class="card card-default">
						<div class="card-header">
							<div class="card-title">Новый вывод средств компании</div>
						</div>
						<div class="card-body">
							<div class="alert bordered m-b-20 alert-info" id="company-withdrawal-balance-hint">
								Доступно к выводу: <strong id="company-withdrawal-current-balance"><?php echo esc_html( _company_withdrawals_fmt_usdt( (float) $_stats['available_balance'] ) ); ?></strong>.
								После вывода баланс составит: <strong id="company-withdrawal-remaining"><?php echo esc_html( _company_withdrawals_fmt_usdt( (float) $_stats['available_balance'] ) ); ?></strong>.
							</div>

							<form id="form-add-company-withdrawal" enctype="multipart/form-data" novalidate>
								<input type="hidden" name="_nonce" value="<?php echo esc_attr( $_nonce ); ?>">

								<div class="row">
									<div class="col-md-4 m-b-15">
										<label class="fs-12 text-uppercase hint-text">Сумма вывода (USDT) <span class="text-danger">*</span></label>
										<input type="number" name="amount" id="company-withdrawal-amount" class="form-control" min="0.00000001" step="0.00000001" placeholder="0.00" required>
									</div>

									<div class="col-md-4 m-b-15">
										<label class="fs-12 text-uppercase hint-text">Сеть</label>
										<select name="network" id="company-withdrawal-network" class="full-width" data-init-plugin="select2">
											<option value="TRC20" selected>TRC20</option>
											<option value="ERC20">ERC20</option>
											<option value="BEP20">BEP20</option>
										</select>
									</div>

									<div class="col-md-4 m-b-15">
										<label class="fs-12 text-uppercase hint-text">Кошелёк</label>
										<input type="text" name="wallet_address" class="form-control" maxlength="255" placeholder="Необязательно">
									</div>

									<div class="col-md-6 m-b-15">
										<label class="fs-12 text-uppercase hint-text">TX hash</label>
										<input type="text" name="tx_hash" class="form-control" maxlength="255" placeholder="Необязательно">
									</div>

									<div class="col-md-6 m-b-15">
										<label class="fs-12 text-uppercase hint-text">Комментарий</label>
										<textarea name="notes" class="form-control" rows="1" maxlength="1000" placeholder="Необязательно"></textarea>
									</div>

									<div class="col-md-12 m-b-15">
										<label class="fs-12 text-uppercase hint-text">Скриншот / подтверждение <span class="hint-text">(необязательно, JPG/PNG/GIF/WEBP, макс. 10 МБ)</span></label>
										<input type="file" name="receipt" id="company-withdrawal-receipt" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
										<div id="company-withdrawal-receipt-preview" class="m-t-10" style="display:none;">
											<img id="company-withdrawal-receipt-img" src="" alt="Предпросмотр" style="max-height:120px;max-width:240px;border-radius:4px;border:1px solid #dee2e6;object-fit:cover;">
											<button type="button" id="company-withdrawal-receipt-clear" class="btn btn-xs btn-default m-l-10">Убрать</button>
										</div>
									</div>
								</div>

								<div id="company-withdrawal-form-error" class="alert alert-danger" style="display:none;"></div>

								<div class="d-flex gap-10">
									<button type="submit" class="btn btn-complete" id="btn-company-withdrawal-submit">Сохранить вывод</button>
									<button type="button" class="btn btn-default" id="btn-company-withdrawal-cancel">Отмена</button>
								</div>
							</form>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<div class="card card-default">
					<div class="card-header">
						<div class="card-title">История выводов компании</div>
						<div class="card-controls">
							<span class="hint-text fs-12"><?php echo esc_html( $_tz_label ); ?></span>
						</div>
					</div>

					<div class="card-body no-padding">
						<div class="table-responsive">
							<table class="table table-hover no-margin" id="company-withdrawals-table">
								<thead>
									<tr>
										<th>#</th>
										<th>Дата</th>
										<th class="text-right">Сумма</th>
										<th>Сеть</th>
										<th>Кошелёк</th>
										<th>TX hash</th>
										<th>Скриншот</th>
										<th>Внёс</th>
										<th>Комментарий</th>
									</tr>
								</thead>
								<tbody id="company-withdrawals-tbody">
									<tr>
										<td colspan="9" class="text-center hint-text p-3">Загрузка...</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					<div class="card-body border-top p-2 d-flex justify-content-between align-items-center" id="company-withdrawals-pagination-row" style="display:none!important;">
						<span class="hint-text fs-12" id="company-withdrawals-count-label"></span>
						<div id="company-withdrawals-pagination"></div>
					</div>
				</div>

			</div>
		</div>

		<?php get_template_part( 'template-parts/footer-backoffice' ); ?>
	</div>

</div>

<?php get_template_part( 'template-parts/quickview' ); ?>
<?php get_template_part( 'template-parts/overlay' ); ?>

<?php
add_action( 'wp_footer', function () use ( $_nonce, $_can_create, $_stats ) {
?>
<script>
(function($) {
	var nonce = <?php echo crm_json_for_inline_js( $_nonce ); ?>;
	var ajaxUrl = <?php echo crm_json_for_inline_js( admin_url( 'admin-ajax.php' ) ); ?>;
	var canCreate = <?php echo $_can_create ? 'true' : 'false'; ?>;
	var currentBalance = <?php echo crm_json_for_inline_js( round( (float) $_stats['available_balance'], 8 ) ); ?>;
	var page = 1;
	var perPage = 25;

	function fmtUsdt(value) {
		return parseFloat(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 8 }) + '\u00a0USDT';
	}

	function updateRemaining(entered) {
		var remaining = currentBalance - (parseFloat(entered) || 0);
		$('#company-withdrawal-remaining').text(fmtUsdt(remaining));
		var $hint = $('#company-withdrawal-balance-hint');
		if (remaining < 0) {
			$hint.removeClass('alert-info').addClass('alert-danger');
		} else {
			$hint.removeClass('alert-danger').addClass('alert-info');
		}
	}

	function escHtml(value) {
		return $('<div>').text(String(value || '')).html();
	}

	function renderPagination(totalPages, current) {
		var $p = $('#company-withdrawals-pagination').empty();
		if (totalPages <= 1) return;
		var html = '<ul class="pagination pagination-sm no-margin">';
		html += '<li class="page-item' + (current <= 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (current - 1) + '">&laquo;</a></li>';
		for (var i = 1; i <= totalPages; i++) {
			html += '<li class="page-item' + (i === current ? ' active' : '') + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
		}
		html += '<li class="page-item' + (current >= totalPages ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (current + 1) + '">&raquo;</a></li>';
		html += '</ul>';
		$p.html(html);
	}

	function loadWithdrawals(targetPage) {
		page = targetPage || 1;
		$('#company-withdrawals-tbody').html('<tr><td colspan="9" class="text-center p-t-30 p-b-30 text-muted">Загрузка...</td></tr>');

		$.post(ajaxUrl, {
			action: 'me_company_withdrawals_list',
			_nonce: nonce,
			page: page,
			per_page: perPage
		}, function(res) {
			if (!res || !res.success) {
				$('#company-withdrawals-tbody').html('<tr><td colspan="9" class="text-center p-t-30 p-b-30 text-muted">Ошибка загрузки.</td></tr>');
				return;
			}

			var rows = res.data.rows || [];
			var $tbody = $('#company-withdrawals-tbody').empty();
			if (!rows.length) {
				$tbody.html('<tr><td colspan="9" class="text-center p-t-30 p-b-30 text-muted">Выводов пока нет.</td></tr>');
				$('#company-withdrawals-pagination-row').hide();
				return;
			}

			$.each(rows, function(_, row) {
				var receipt = '<span class="hint-text">—</span>';
				if (row.receipt_url) {
					receipt = '<a href="' + escHtml(row.receipt_url) + '" target="_blank" rel="noopener">' +
						'<img src="' + escHtml(row.receipt_url) + '" alt="скриншот" style="height:36px;width:auto;border-radius:3px;border:1px solid #dee2e6;object-fit:cover;cursor:pointer;">' +
						'</a>';
				}

				$tbody.append(
					'<tr>' +
					'<td class="hint-text">' + escHtml(row.id) + '</td>' +
					'<td>' + escHtml(row.created_at || '—') + '</td>' +
					'<td class="text-right font-montserrat fs-14 bold">' + escHtml(fmtUsdt(row.amount_usdt)) + '</td>' +
					'<td>' + escHtml(row.network || 'TRC20') + '</td>' +
					'<td>' + (row.wallet_address ? escHtml(row.wallet_address) : '<span class="hint-text">—</span>') + '</td>' +
					'<td>' + (row.tx_hash ? escHtml(row.tx_hash) : '<span class="hint-text">—</span>') + '</td>' +
					'<td>' + receipt + '</td>' +
					'<td>' + (row.recorder_name ? escHtml(row.recorder_name) : '<span class="hint-text">—</span>') + '</td>' +
					'<td>' + (row.notes ? escHtml(row.notes) : '<span class="hint-text">—</span>') + '</td>' +
					'</tr>'
				);
			});

			$('#company-withdrawals-count-label').text('Записей: ' + (res.data.total || 0));
			renderPagination(res.data.total_pages || 1, page);
			$('#company-withdrawals-pagination-row').show();
		}, 'json').fail(function() {
			$('#company-withdrawals-tbody').html('<tr><td colspan="9" class="text-center p-t-30 p-b-30 text-muted">Ошибка сервера.</td></tr>');
		});
	}

	if (canCreate) {
		$('#btn-add-company-withdrawal').on('click', function() {
			$('#company-withdrawal-form-wrapper').slideDown(200);
			$(this).hide();
		});

		$('#btn-company-withdrawal-cancel').on('click', function() {
			$('#company-withdrawal-form-wrapper').slideUp(200);
			$('#btn-add-company-withdrawal').show();
			$('#form-add-company-withdrawal')[0].reset();
			$('#company-withdrawal-network').val('TRC20').trigger('change');
			$('#company-withdrawal-receipt-preview').hide();
			$('#company-withdrawal-receipt-img').attr('src', '');
			$('#company-withdrawal-form-error').hide();
			updateRemaining(0);
		});

		$('#company-withdrawal-amount').on('input', function() {
			updateRemaining($(this).val());
		});

		$('#company-withdrawal-receipt').on('change', function() {
			var file = this.files[0];
			if (!file) return;
			var reader = new FileReader();
			reader.onload = function(e) {
				$('#company-withdrawal-receipt-img').attr('src', e.target.result);
				$('#company-withdrawal-receipt-preview').show();
			};
			reader.readAsDataURL(file);
		});

		$('#company-withdrawal-receipt-clear').on('click', function() {
			$('#company-withdrawal-receipt').val('');
			$('#company-withdrawal-receipt-preview').hide();
			$('#company-withdrawal-receipt-img').attr('src', '');
		});

		$('#form-add-company-withdrawal').on('submit', function(e) {
			e.preventDefault();
			$('#company-withdrawal-form-error').hide();
			var $btn = $('#btn-company-withdrawal-submit').prop('disabled', true).text('Сохранение...');
			var fd = new FormData(this);
			fd.append('action', 'me_company_withdrawals_create');

			$.ajax({
				url: ajaxUrl,
				type: 'POST',
				data: fd,
				processData: false,
				contentType: false,
				dataType: 'json',
				success: function(res) {
					if (!res || !res.success) {
						$('#company-withdrawal-form-error').text((res && res.data && res.data.message) || 'Ошибка сохранения.').show();
						return;
					}
					location.reload();
				},
				error: function() {
					$('#company-withdrawal-form-error').text('Ошибка сервера при сохранении вывода.').show();
				},
				complete: function() {
					$btn.prop('disabled', false).text('Сохранить вывод');
				}
			});
		});
	}

	$('#company-withdrawals-pagination').on('click', '.page-link', function(e) {
		e.preventDefault();
		var targetPage = parseInt($(this).data('page'), 10);
		if (targetPage >= 1) loadWithdrawals(targetPage);
	});

	if ($.fn.select2) {
		$('#company-withdrawal-network').select2({
			minimumResultsForSearch: Infinity
		});
	}

	loadWithdrawals(1);
})(jQuery);
</script>
<?php
}, 99 );
?>

<?php get_footer(); ?>
