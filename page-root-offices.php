<?php
/*
Template Name: Root Offices Page
Slug: root-offices
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

$current_uid = get_current_user_id();

if ( ! crm_is_root( $current_uid ) ) {
	wp_safe_redirect( malibu_exchange_get_company_dashboard_url() );
	exit;
}

if ( ! crm_can_create_company_offices( $current_uid ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$all_companies       = crm_get_all_companies_list();
$all_companies_full  = crm_get_all_companies_full();
$company_ids         = array_map( static fn( $company ) => (int) $company->id, $all_companies_full );
$company_offices     = crm_get_company_offices_full_by_company_ids( $company_ids );
$nonce_create_office = wp_create_nonce( 'me_create_company_office' );

get_template_part(
	'template-parts/root-page-start',
	null,
	[
		'title'       => 'Офисы',
		'description' => 'Root-only список офисов компаний и создание новых офисов внутри выбранной компании.',
		'breadcrumbs' => [
			[
				'label'  => 'Офисы',
				'url'    => '',
				'active' => true,
			],
		],
	]
);
?>

<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 m-b-10">
	<div>
		<p class="hint-text m-b-0">Всего офисов в контуре: <strong id="offices-total-count"><?php echo (int) count( $company_offices ); ?></strong></p>
	</div>
	<button type="button" class="btn btn-primary btn-cons"
	        data-bs-toggle="modal" data-bs-target="#modal-create-office"
	        <?php echo empty( $all_companies ) ? 'disabled' : ''; ?>>
		<i class="pg-icon">add</i>&nbsp; Добавить офис
	</button>
</div>

<div class="card card-default">
	<div class="card-header">
		<div class="card-title">Офисы компаний</div>
	</div>
	<div class="card-body no-padding">
		<div class="table-responsive">
			<table class="table table-hover m-b-0" id="company-offices-table">
				<thead>
					<tr>
						<th style="width:60px">ID</th>
						<th style="width:180px">Компания</th>
						<th>Офис</th>
						<th style="width:140px">Город</th>
						<th>Адрес</th>
						<th style="width:90px">Статус</th>
					</tr>
				</thead>
				<tbody id="company-offices-tbody">
					<?php if ( empty( $company_offices ) ) : ?>
					<tr id="company-offices-empty-row">
						<td colspan="6" class="text-center hint-text p-t-20 p-b-20">Офисы пока не созданы.</td>
					</tr>
					<?php else : ?>
						<?php foreach ( $company_offices as $office ) : ?>
						<tr id="office-row-<?php echo (int) $office['id']; ?>">
							<td class="v-align-middle">
								<span class="hint-text fs-12">#<?php echo (int) $office['id']; ?></span>
							</td>
							<td class="v-align-middle">
								<div>
									<span class="semi-bold"><?php echo esc_html( $office['company_name'] ); ?></span>
									<small class="hint-text m-l-5 fs-11"><?php echo esc_html( $office['company_code'] ); ?></small>
								</div>
							</td>
							<td class="v-align-middle">
								<div>
									<span class="semi-bold"><?php echo esc_html( $office['name'] ); ?></span>
									<?php if ( ! empty( $office['is_default'] ) ) : ?>
									<span class="badge badge-info m-l-5">Default</span>
									<?php endif; ?>
								</div>
								<small class="hint-text fs-11"><?php echo esc_html( $office['code'] ); ?></small>
							</td>
							<td class="v-align-middle hint-text fs-12">
								<?php echo esc_html( $office['city'] !== '' ? $office['city'] : '—' ); ?>
							</td>
							<td class="v-align-middle hint-text fs-12">
								<?php echo esc_html( $office['address_line'] !== '' ? $office['address_line'] : '—' ); ?>
							</td>
							<td class="v-align-middle">
								<span class="badge badge-<?php echo $office['status'] === 'active' ? 'success' : 'secondary'; ?>">
									<?php echo esc_html( $office['status'] ); ?>
								</span>
							</td>
						</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<?php get_template_part( 'template-parts/toast-host' ); ?>

<div class="modal fade" id="modal-create-office" tabindex="-1"
     aria-labelledby="modal-create-office-title" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header clearfix text-left">
				<button aria-label="Закрыть" type="button" class="close" data-bs-dismiss="modal" aria-hidden="true">
					<i class="pg-icon">close</i>
				</button>
				<h5 class="modal-title" id="modal-create-office-title">Добавить офис</h5>
			</div>
			<div class="modal-body">
				<form id="form-company-office" novalidate>
					<div class="row">
						<div class="col-12">
							<div class="form-group form-group-default required">
								<label>Компания <span class="text-danger">*</span></label>
								<select class="full-width" id="cof-company-id">
									<?php foreach ( $all_companies as $company_option ) : ?>
									<option value="<?php echo (int) $company_option->id; ?>">
										<?php echo esc_html( $company_option->name ); ?>
									</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group form-group-default required">
								<label>Название офиса <span class="text-danger">*</span></label>
								<input type="text" class="form-control" id="cof-name" required placeholder="Пхукет / Паттайя / Москва">
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Код</label>
								<input type="text" class="form-control" id="cof-code" placeholder="Автоматически из названия">
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Город</label>
								<input type="text" class="form-control" id="cof-city" placeholder="Phuket">
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Адрес</label>
								<input type="text" class="form-control" id="cof-address-line" placeholder="Rawai, Soi 12">
							</div>
						</div>
					</div>

					<div class="alert alert-danger d-none m-t-10" id="cof-error"></div>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-bs-dismiss="modal">Отмена</button>
				<button type="button" class="btn btn-primary" id="btn-create-office">
					<span class="btn-label">Создать</span>
					<i class="pg-icon spin d-none" id="btn-create-office-spinner">refresh</i>
				</button>
			</div>
		</div>
	</div>
</div>

<style>
.badge { font-size:11px; font-weight:600; padding:3px 8px; border-radius:20px; }
.badge-success  { background:rgba(29,211,176,.18); color:#0d9e82; border:1px solid rgba(29,211,176,.3); }
.badge-info     { background:rgba(76,201,240,.18); color:#1579a8; border:1px solid rgba(76,201,240,.3); }
.badge-secondary{ background:rgba(120,120,140,.15); color:#888; border:1px solid rgba(120,120,140,.2); }
.table th { font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
.table td { font-size:13px; }
.pg-icon.spin { animation:spin 1s linear infinite; display:inline-block; }
@keyframes spin { to { transform:rotate(360deg); } }
</style>

<?php
add_action(
	'wp_footer',
	function () use ( $nonce_create_office ) {
		?>
<script>
(function ($) {
	'use strict';

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCE_CREATE_OFFICE = '<?php echo esc_js( $nonce_create_office ); ?>';
	var $createOfficeModal = $('#modal-create-office');

	function showToast(message, type) {
		if (window.MalibuToast && typeof window.MalibuToast.show === 'function') {
			window.MalibuToast.show(message, type || 'info');
			return;
		}
		if (window.console && console.warn) {
			console.warn(message);
		}
	}

	function escapeHtml(value) {
		return $('<div>').text(value == null ? '' : String(value)).html();
	}

	function renderOfficeStatusBadge(status) {
		var normalized = $.trim(String(status || '')).toLowerCase();
		var badgeClass = normalized === 'active' ? 'success' : 'secondary';
		return '<span class="badge badge-' + badgeClass + '">' + escapeHtml(normalized || '—') + '</span>';
	}

	function buildOfficeRow(office) {
		var html = ''
			+ '<tr id="office-row-' + escapeHtml(office.id) + '">'
			+ '<td class="v-align-middle"><span class="hint-text fs-12">#' + escapeHtml(office.id) + '</span></td>'
			+ '<td class="v-align-middle"><div><span class="semi-bold">' + escapeHtml(office.company_name || '—') + '</span><small class="hint-text m-l-5 fs-11">' + escapeHtml(office.company_code || '') + '</small></div></td>'
			+ '<td class="v-align-middle"><div><span class="semi-bold">' + escapeHtml(office.name || '—') + '</span>';

		if (office.is_default) {
			html += '<span class="badge badge-info m-l-5">Default</span>';
		}

		html += '</div><small class="hint-text fs-11">' + escapeHtml(office.code || '') + '</small></td>'
			+ '<td class="v-align-middle hint-text fs-12">' + escapeHtml(office.city || '—') + '</td>'
			+ '<td class="v-align-middle hint-text fs-12">' + escapeHtml(office.address_line || '—') + '</td>'
			+ '<td class="v-align-middle">' + renderOfficeStatusBadge(office.status || '') + '</td>'
			+ '</tr>';

		return html;
	}

	function resetOfficeForm() {
		$('#form-company-office')[0].reset();
		$('#cof-error').addClass('d-none').text('');
		$('#btn-create-office').prop('disabled', false)
			.find('.btn-label').show()
			.end().find('#btn-create-office-spinner').addClass('d-none');
		if ($('#cof-company-id').hasClass('select2-hidden-accessible')) {
			$('#cof-company-id').trigger('change.select2');
		}
	}

	$createOfficeModal.on('shown.bs.modal', function () {
		if (!$('#cof-company-id').hasClass('select2-hidden-accessible')) {
			$('#cof-company-id').select2({ dropdownParent: $createOfficeModal });
		}
	});

	$createOfficeModal.on('hidden.bs.modal', function () {
		if ($('#cof-company-id').hasClass('select2-hidden-accessible')) {
			$('#cof-company-id').select2('destroy');
		}
		resetOfficeForm();
	});

	$('#btn-create-office').on('click', function () {
		var $btn = $(this);
		var companyId = parseInt($('#cof-company-id').val(), 10) || 0;
		var name = $.trim($('#cof-name').val());
		var code = $.trim($('#cof-code').val());
		var city = $.trim($('#cof-city').val());
		var addressLine = $.trim($('#cof-address-line').val());

		$('#cof-error').addClass('d-none').text('');

		if (companyId <= 0) {
			$('#cof-error').removeClass('d-none').text('Выберите компанию.');
			return;
		}

		if (!name) {
			$('#cof-error').removeClass('d-none').text('Введите название офиса.');
			return;
		}

		$btn.prop('disabled', true)
			.find('.btn-label').hide()
			.end().find('#btn-create-office-spinner').removeClass('d-none');

		$.post(AJAX_URL, {
			action: 'me_create_company_office',
			company_id: companyId,
			name: name,
			code: code,
			city: city,
			address_line: addressLine,
			_nonce: NONCE_CREATE_OFFICE
		})
		.done(function (res) {
			if (!res || !res.success) {
				$('#cof-error').removeClass('d-none').text((res && res.data && res.data.message) || 'Ошибка создания офиса.');
				$btn.prop('disabled', false)
					.find('.btn-label').show()
					.end().find('#btn-create-office-spinner').addClass('d-none');
				return;
			}

			var office = res.data.office || {};
			var $tbody = $('#company-offices-tbody');
			$('#company-offices-empty-row').remove();
			$tbody.append($(buildOfficeRow(office)));
			$('#offices-total-count').text((parseInt($('#offices-total-count').text(), 10) || 0) + 1);
			showToast(res.data.message || 'Офис создан.', 'success');
			bootstrap.Modal.getInstance($createOfficeModal[0]).hide();
		})
		.fail(function (xhr) {
			var message = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
				? xhr.responseJSON.data.message
				: 'Ошибка сервера.';
			$('#cof-error').removeClass('d-none').text(message);
			$btn.prop('disabled', false)
				.find('.btn-label').show()
				.end().find('#btn-create-office-spinner').addClass('d-none');
		});
	});

}(jQuery));
</script>
		<?php
	},
	99
);

get_template_part( 'template-parts/root-page-end' );
