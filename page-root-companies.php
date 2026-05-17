<?php
/*
Template Name: Root Companies Page
Slug: root-companies
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

if ( ! crm_can_manage_users() ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$all_companies_full          = crm_get_all_companies_full();
$company_fintech_access_map  = [];
$company_exchange_pairs_map  = [];
$company_rub_usdt_fixation_map = [];
foreach ( $all_companies_full as $company ) {
	$company_id = (int) $company->id;
	$company_fintech_access_map[ $company_id ] = crm_company_get_enabled_fintech_providers( $company_id );
	$company_exchange_pairs_map[ $company_id ] = crm_company_get_enabled_exchange_pairs( $company_id );
	$company_rub_usdt_fixation_map[ $company_id ] = function_exists( 'crm_company_get_rub_usdt_fixation_mode' )
		? crm_company_get_rub_usdt_fixation_mode( $company_id )
		: 'rapira_manual';
}

$nonce_create_company   = wp_create_nonce( 'me_create_company' );
$nonce_company_settings = wp_create_nonce( 'me_company_contours_save' );
$nonce_company_status   = wp_create_nonce( 'me_company_status' );

get_template_part(
	'template-parts/root-page-start',
	null,
	[
		'title'       => 'Компании',
		'description' => 'Root-only контур компаний: создание новых компаний и управление направлениями обмена и платёжными контурами.',
		'breadcrumbs' => [
			[
				'label'  => 'Компании',
				'url'    => '',
				'active' => true,
			],
		],
	]
);
?>

<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 m-b-10">
	<p class="hint-text m-b-0">Всего компаний: <strong><?php echo (int) count( $all_companies_full ); ?></strong></p>
	<button type="button" class="btn btn-primary btn-cons"
	        data-bs-toggle="modal" data-bs-target="#modal-create-company">
		<i class="pg-icon">add</i>&nbsp; Создать компанию
	</button>
</div>

<div class="card card-default">
	<div class="card-header">
		<div class="card-title">Компании</div>
	</div>
	<div class="card-body no-padding" id="companies-list-body">
		<?php if ( empty( $all_companies_full ) ) : ?>
			<div class="p-30 text-center hint-text" id="companies-empty-state">
				<p>Компании не найдены</p>
			</div>
			<div class="table-responsive d-none" id="companies-table-wrap">
				<table class="table table-hover m-b-0" id="companies-table">
					<thead>
						<tr>
							<th style="width:50px">ID</th>
							<th>Название</th>
							<th style="width:130px">Статус</th>
							<th style="width:120px">Пользователей</th>
							<th>Телефон</th>
							<th>Адрес / заметка</th>
							<th style="width:60px"></th>
						</tr>
					</thead>
					<tbody id="companies-tbody"></tbody>
				</table>
			</div>
		<?php else : ?>
			<div class="table-responsive" id="companies-table-wrap">
				<table class="table table-hover m-b-0" id="companies-table">
					<thead>
						<tr>
							<th style="width:50px">ID</th>
							<th>Название</th>
							<th style="width:130px">Статус</th>
							<th style="width:120px">Пользователей</th>
							<th>Телефон</th>
							<th>Адрес / заметка</th>
							<th style="width:60px"></th>
						</tr>
					</thead>
					<tbody id="companies-tbody">
						<?php foreach ( $all_companies_full as $company ) : ?>
							<?php
							$company_id        = (int) $company->id;
							$enabled_pairs     = $company_exchange_pairs_map[ $company_id ] ?? [];
							$allowed_providers = $company_fintech_access_map[ $company_id ] ?? crm_company_get_enabled_fintech_providers( $company_id );
							$rub_usdt_fixation_mode = $company_rub_usdt_fixation_map[ $company_id ] ?? 'rapira_manual';
							$company_status    = (string) $company->status;
							$status_label      = crm_company_status_label( $company_status );
							$status_badge      = crm_company_status_badge_class( $company_status );
							$block_reason      = trim( (string) ( $company->block_reason ?? '' ) );
							?>
							<tr id="corow-<?php echo $company_id; ?>">
								<td class="v-align-middle">
									<span class="hint-text fs-12">#<?php echo $company_id; ?></span>
								</td>
								<td class="v-align-middle">
									<div>
										<span class="semi-bold"><?php echo esc_html( $company->name ); ?></span>
										<small class="hint-text m-l-5 fs-11"><?php echo esc_html( $company->code ); ?></small>
									</div>
									<div class="m-t-5" id="company-exchange-pairs-<?php echo $company_id; ?>">
										<span class="hint-text fs-11 m-r-5">Обмен:</span>
										<?php echo crm_company_render_exchange_pair_badges_html( $enabled_pairs ); ?>
									</div>
									<div class="m-t-5" id="company-providers-<?php echo $company_id; ?>">
										<span class="hint-text fs-11 m-r-5">Платежи:</span>
										<?php echo crm_company_render_fintech_provider_badges_html( $allowed_providers ); ?>
									</div>
								</td>
								<td class="v-align-middle" id="company-status-<?php echo $company_id; ?>">
									<span class="badge badge-<?php echo esc_attr( $status_badge ); ?>">
										<?php echo esc_html( $status_label ); ?>
									</span>
									<?php if ( $company_status === 'blocked' && $block_reason !== '' ) : ?>
										<div class="hint-text fs-11 m-t-5"><?php echo esc_html( $block_reason ); ?></div>
									<?php endif; ?>
								</td>
								<td class="v-align-middle hint-text fs-12">
									<?php echo (int) $company->user_count; ?>
								</td>
								<td class="v-align-middle hint-text fs-12">
									<?php echo esc_html( $company->phone ?? '—' ); ?>
								</td>
								<td class="v-align-middle hint-text fs-12">
									<?php echo esc_html( $company->address ?? ( $company->note ?: '—' ) ); ?>
								</td>
								<td class="v-align-middle text-right">
									<div class="dropdown">
										<button type="button"
										        class="btn btn-default btn-xs dropdown-toggle"
										        data-bs-toggle="dropdown"
										        aria-expanded="false"
										        aria-label="Действия компании">
											<i class="pg-icon">more_vertical</i>
										</button>
										<ul class="dropdown-menu dropdown-menu-end">
											<li>
												<a class="dropdown-item js-company-settings" href="#"
												   data-company-id="<?php echo $company_id; ?>"
												   data-company-name="<?php echo esc_attr( $company->name ); ?>"
												   data-company-code="<?php echo esc_attr( $company->code ); ?>"
												   data-company-status="<?php echo esc_attr( $company_status ); ?>"
												   data-enabled-exchange-pairs="<?php echo esc_attr( implode( ',', $enabled_pairs ) ); ?>"
												   data-allowed-providers="<?php echo esc_attr( implode( ',', $allowed_providers ) ); ?>"
												   data-rub-usdt-fixation-mode="<?php echo esc_attr( $rub_usdt_fixation_mode ); ?>"
												   data-bs-toggle="modal"
												   data-bs-target="#modal-company-settings">
													<i class="pg-icon m-r-5">settings</i> Настройки
												</a>
											</li>
											<?php if ( $company_status === 'active' ) : ?>
												<li>
													<a class="dropdown-item text-danger js-company-status" href="#"
													   data-company-id="<?php echo $company_id; ?>"
													   data-company-name="<?php echo esc_attr( $company->name ); ?>"
													   data-status="blocked">
														<i class="pg-icon m-r-5">lock</i> Заблокировать
													</a>
												</li>
											<?php elseif ( $company_status === 'blocked' ) : ?>
												<li>
													<a class="dropdown-item text-success js-company-status" href="#"
													   data-company-id="<?php echo $company_id; ?>"
													   data-company-name="<?php echo esc_attr( $company->name ); ?>"
													   data-status="active">
														<i class="pg-icon m-r-5">unlock</i> Разблокировать
													</a>
												</li>
											<?php endif; ?>
										</ul>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="p-30 text-center hint-text d-none" id="companies-empty-state">
				<p>Компании не найдены</p>
			</div>
		<?php endif; ?>
	</div>
</div>

<?php get_template_part( 'template-parts/toast-host' ); ?>

<div class="modal fade" id="modal-create-company" tabindex="-1"
     aria-labelledby="modal-create-company-title" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header clearfix text-left">
				<button aria-label="Закрыть" type="button" class="close" data-bs-dismiss="modal" aria-hidden="true">
					<i class="pg-icon">close</i>
				</button>
				<h5 class="modal-title" id="modal-create-company-title">Создать компанию</h5>
			</div>
			<div class="modal-body">
				<form id="form-company" novalidate>
					<div class="row">
						<div class="col-12">
							<div class="form-group form-group-default required">
								<label>Название <span class="text-danger">*</span></label>
								<input type="text" class="form-control" id="cc-name" required placeholder="Название компании">
							</div>
						</div>
						<div class="col-12">
							<div class="form-group form-group-default">
								<label>Телефон</label>
								<input type="text" class="form-control" id="cc-phone" placeholder="+7 ...">
							</div>
						</div>
						<div class="col-12">
							<div class="form-group form-group-default">
								<label>Адрес</label>
								<input type="text" class="form-control" id="cc-address">
							</div>
						</div>
					</div>
					<div class="alert alert-danger d-none m-t-10" id="cc-error"></div>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-bs-dismiss="modal">Отмена</button>
				<button type="button" class="btn btn-primary" id="btn-create-company">
					<span class="btn-label">Создать</span>
					<i class="pg-icon spin d-none" id="btn-create-company-spinner">refresh</i>
				</button>
			</div>
		</div>
	</div>
</div>

<?php get_template_part( 'template-parts/root-company-settings-modal' ); ?>

<div class="modal fade" id="modal-company-status" tabindex="-1"
     aria-labelledby="modal-company-status-title" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header clearfix text-left">
				<button aria-label="Закрыть" type="button" class="close" data-bs-dismiss="modal" aria-hidden="true">
					<i class="pg-icon">close</i>
				</button>
				<h5 class="modal-title" id="modal-company-status-title">Изменить статус компании</h5>
				<p class="p-b-10 m-b-0" id="company-status-message"></p>
			</div>
			<div class="modal-body">
				<form id="form-company-status" novalidate>
					<input type="hidden" id="cst-company-id" value="0">
					<input type="hidden" id="cst-status" value="">

					<div class="form-group form-group-default">
						<label>Компания</label>
						<input type="text" class="form-control" id="cst-company-name" value="" readonly>
					</div>

					<div class="form-group form-group-default" id="cst-reason-wrap">
						<label>Причина блокировки</label>
						<textarea class="form-control" id="cst-reason" rows="3" placeholder="Можно оставить пустой"></textarea>
					</div>

					<div class="hint-text fs-12 m-t-5" id="company-status-impact"></div>
					<div class="alert alert-danger d-none m-t-10" id="cst-error"></div>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-bs-dismiss="modal">Отмена</button>
				<button type="button" class="btn btn-danger" id="btn-company-status-confirm">
					<span class="btn-label">Подтвердить</span>
					<i class="pg-icon spin d-none" id="btn-company-status-spinner">refresh</i>
				</button>
			</div>
		</div>
	</div>
</div>

<style>
.badge { font-size:11px; font-weight:600; padding:3px 8px; border-radius:20px; }
.badge-success  { background:rgba(29,211,176,.18); color:#0d9e82; border:1px solid rgba(29,211,176,.3); }
.badge-info     { background:rgba(76,201,240,.18); color:#1579a8; border:1px solid rgba(76,201,240,.3); }
.badge-primary  { background:rgba(90,128,255,.18); color:#3f5fcc; border:1px solid rgba(90,128,255,.3); }
.badge-danger   { background:rgba(240,83,83,.16); color:#c93b3b; border:1px solid rgba(240,83,83,.28); }
.badge-secondary{ background:rgba(120,120,140,.15); color:#888; border:1px solid rgba(120,120,140,.2); }
.m-r-2 { margin-right:2px; }
.table th { font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
.table td { font-size:13px; }
.btn-xs { padding:3px 8px; font-size:12px; }
.pg-icon.spin { animation:spin 1s linear infinite; display:inline-block; }
.dropdown-menu .dropdown-item { display:flex; align-items:center; }
.dropdown-menu .dropdown-item .pg-icon { flex-shrink:0; line-height:1; }
@keyframes spin { to { transform:rotate(360deg); } }
</style>

<?php
add_action(
	'wp_footer',
	function () use ( $nonce_create_company, $nonce_company_settings, $nonce_company_status ) {
		?>
<script>
(function ($) {
	'use strict';

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCES = {
		createCo: '<?php echo esc_js( $nonce_create_company ); ?>',
		companySettings: '<?php echo esc_js( $nonce_company_settings ); ?>',
		companyStatus: '<?php echo esc_js( $nonce_company_status ); ?>'
	};
	var FINTECH_PROVIDER_LABELS = <?php echo crm_json_for_inline_js( crm_fintech_provider_labels() ); ?>;
	var EXCHANGE_PAIR_TITLES = <?php echo crm_json_for_inline_js( array_column( crm_company_exchange_pair_definitions(), 'title', 'code' ) ); ?>;
	var $companySettingsModal = $('#modal-company-settings');
	var $createCompanyModal = $('#modal-create-company');
	var $companyStatusModal = $('#modal-company-status');

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

	function normalizeProviderCodes(providers) {
		var seen = {};
		var normalized = [];

		$.each(providers || [], function (_, provider) {
			var code = $.trim(String(provider || '')).toLowerCase();
			if (!code || seen[code] || !FINTECH_PROVIDER_LABELS[code]) {
				return;
			}
			seen[code] = true;
			normalized.push(code);
		});

		return normalized;
	}

	function normalizePairCodes(pairs) {
		var seen = {};
		var normalized = [];

		$.each(pairs || [], function (_, pair) {
			var code = $.trim(String(pair || '')).toUpperCase();
			if (code === 'THB_RUB') {
				code = 'RUB_THB';
			}
			if (!code || seen[code] || !EXCHANGE_PAIR_TITLES[code]) {
				return;
			}
			seen[code] = true;
			normalized.push(code);
		});

		return normalized;
	}

	function renderCompanyExchangePairBadges(pairs) {
		var normalized = normalizePairCodes(pairs);
		var html = '';

		if (!normalized.length) {
			return '<span class="badge badge-secondary m-r-2">Направления выключены</span>';
		}

		$.each(normalized, function (_, code) {
			html += '<span class="badge badge-success m-r-2">' + escapeHtml(EXCHANGE_PAIR_TITLES[code] || code) + '</span>';
		});

		return html;
	}

	function renderCompanyProviderBadges(providers) {
		var normalized = normalizeProviderCodes(providers);
		var html = '';

		if (!normalized.length) {
			return '<span class="badge badge-secondary m-r-2">Платёжные контуры отключены</span>';
		}

		$.each(normalized, function (_, provider) {
			var badgeClass = provider === 'doverka' ? 'badge-info' : 'badge-primary';
			html += '<span class="badge ' + badgeClass + ' m-r-2">' + escapeHtml(FINTECH_PROVIDER_LABELS[provider] || provider) + '</span>';
		});

		return html;
	}

	function companyStatusLabel(status) {
		var labels = {
			active: 'Активна',
			blocked: 'Заблокирована',
			archived: 'Архив'
		};

		return labels[status] || status || '—';
	}

	function companyStatusBadgeClass(status) {
		if (status === 'blocked') {
			return 'danger';
		}
		if (status === 'archived') {
			return 'secondary';
		}

		return 'success';
	}

	function renderCompanyStatusCell(company) {
		var status = company.status || 'active';
		var html = '<span class="badge badge-' + companyStatusBadgeClass(status) + '">' + escapeHtml(companyStatusLabel(status)) + '</span>';
		if (status === 'blocked' && company.block_reason) {
			html += '<div class="hint-text fs-11 m-t-5">' + escapeHtml(company.block_reason) + '</div>';
		}

		return html;
	}

	function buildCompanyActionsDropdown(company) {
		var status = company.status || 'active';
		var statusAction = '';

		if (status === 'active') {
			statusAction = '<li><a class="dropdown-item text-danger js-company-status" href="#"'
				+ ' data-company-id="' + escapeHtml(company.id) + '"'
				+ ' data-company-name="' + escapeHtml(company.name) + '"'
				+ ' data-status="blocked">'
				+ '<i class="pg-icon m-r-5">lock</i> Заблокировать'
				+ '</a></li>';
		} else if (status === 'blocked') {
			statusAction = '<li><a class="dropdown-item text-success js-company-status" href="#"'
				+ ' data-company-id="' + escapeHtml(company.id) + '"'
				+ ' data-company-name="' + escapeHtml(company.name) + '"'
				+ ' data-status="active">'
				+ '<i class="pg-icon m-r-5">unlock</i> Разблокировать'
				+ '</a></li>';
		}

		return ''
			+ '<div class="dropdown">'
			+ '<button type="button" class="btn btn-default btn-xs dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Действия компании">'
			+ '<i class="pg-icon">more_vertical</i>'
			+ '</button>'
			+ '<ul class="dropdown-menu dropdown-menu-end">'
			+ '<li><a class="dropdown-item js-company-settings" href="#"'
			+ ' data-company-id="' + escapeHtml(company.id) + '"'
			+ ' data-company-name="' + escapeHtml(company.name) + '"'
			+ ' data-company-code="' + escapeHtml(company.code) + '"'
			+ ' data-company-status="' + escapeHtml(status) + '"'
			+ ' data-enabled-exchange-pairs="' + escapeHtml((company.enabled_exchange_pairs || []).join(',')) + '"'
			+ ' data-allowed-providers="' + escapeHtml((company.allowed_providers || []).join(',')) + '"'
			+ ' data-rub-usdt-fixation-mode="' + escapeHtml(company.rub_usdt_fixation_mode || 'rapira_manual') + '"'
			+ ' data-bs-toggle="modal"'
			+ ' data-bs-target="#modal-company-settings">'
			+ '<i class="pg-icon m-r-5">settings</i> Настройки'
			+ '</a></li>'
			+ statusAction
			+ '</ul>'
			+ '</div>';
	}

	function initActionDropdowns($scope) {
		($scope || $(document)).find('.dropdown-toggle[data-bs-toggle="dropdown"]').each(function () {
			bootstrap.Dropdown.getOrCreateInstance(this, {
				popperConfig: function (config) {
					return $.extend(true, config, { strategy: 'fixed' });
				}
			});
		});
	}

	function parseCompanyProvidersAttr(rawValue) {
		if (!rawValue) {
			return [];
		}
		return normalizeProviderCodes(String(rawValue).split(','));
	}

	function parseCompanyPairsAttr(rawValue) {
		if (!rawValue) {
			return [];
		}
		return normalizePairCodes(String(rawValue).split(','));
	}

	function getCompanyFromRow(companyId) {
		var $settings = $('#corow-' + companyId).find('.js-company-settings');

		return {
			id: companyId,
			name: $settings.attr('data-company-name') || '',
			code: $settings.attr('data-company-code') || '',
			status: $settings.attr('data-company-status') || 'active',
			enabled_exchange_pairs: parseCompanyPairsAttr($settings.attr('data-enabled-exchange-pairs')),
			allowed_providers: parseCompanyProvidersAttr($settings.attr('data-allowed-providers')),
			rub_usdt_fixation_mode: $settings.attr('data-rub-usdt-fixation-mode') || 'rapira_manual',
			block_reason: ''
		};
	}

	function updateCompanyStatusUi(data) {
		var companyId = parseInt(data.company_id, 10) || 0;
		var company = getCompanyFromRow(companyId);
		var $row = $('#corow-' + companyId);

		company.status = data.status || 'active';
		company.block_reason = data.block_reason || '';

		$row.find('.js-company-settings').attr('data-company-status', company.status);
		$('#company-status-' + companyId).html(renderCompanyStatusCell(company));
		$row.find('td:last').html(buildCompanyActionsDropdown(company));
		initActionDropdowns($row);
	}

	initActionDropdowns($(document));

	$createCompanyModal.on('hidden.bs.modal', function () {
		$('#form-company')[0].reset();
		$('#cc-error').addClass('d-none').text('');
		$('#btn-create-company').prop('disabled', false)
			.find('.btn-label').show()
			.end().find('#btn-create-company-spinner').addClass('d-none');
	});

	$companySettingsModal.on('hidden.bs.modal', function () {
		$('#cfs-company-id').val(0);
		$('#modal-company-settings-title').text('Настройки компании');
		$('#cfs-company-name').val('');
		$('#cfs-company-code').val('');
		$('#cfs-error').addClass('d-none').text('');
		$('.js-company-pair').prop('checked', false);
		$('.js-company-provider').prop('checked', false);
		$('#cfs-rub-usdt-fixation-mode').val('rapira_manual');
		$('#btn-save-company-settings').prop('disabled', false)
			.find('.btn-label').show()
			.end().find('#btn-company-settings-spinner').addClass('d-none');
	});

	$companyStatusModal.on('hidden.bs.modal', function () {
		$('#cst-company-id').val(0);
		$('#cst-status').val('');
		$('#cst-company-name').val('');
		$('#cst-reason').val('');
		$('#company-status-message').text('');
		$('#company-status-impact').text('');
		$('#cst-error').addClass('d-none').text('');
		$('#cst-reason-wrap').removeClass('d-none');
		$('#btn-company-status-confirm')
			.removeClass('btn-success btn-danger')
			.addClass('btn-danger')
			.prop('disabled', false)
			.find('.btn-label').text('Подтвердить').show()
			.end().find('#btn-company-status-spinner').addClass('d-none');
	});

	$(document).on('click', '.js-company-settings', function () {
		var $trigger = $(this);
		var companyId = parseInt($trigger.attr('data-company-id'), 10) || 0;
		var companyName = $trigger.attr('data-company-name') || '—';
		var companyCode = $trigger.attr('data-company-code') || '—';
		var pairs = parseCompanyPairsAttr($trigger.attr('data-enabled-exchange-pairs'));
		var providers = parseCompanyProvidersAttr($trigger.attr('data-allowed-providers'));
		var rubUsdtFixationMode = $trigger.attr('data-rub-usdt-fixation-mode') || 'rapira_manual';

		$('#cfs-company-id').val(companyId);
		$('#modal-company-settings-title').text('Настройки: ' + companyName);
		$('#cfs-company-name').val(companyName);
		$('#cfs-company-code').val('#' + companyId + ' · ' + companyCode);
		$('.js-company-pair').each(function () {
			$(this).prop('checked', pairs.indexOf($(this).val()) !== -1);
		});
		$('.js-company-provider').each(function () {
			$(this).prop('checked', providers.indexOf($(this).val()) !== -1);
		});
		$('#cfs-rub-usdt-fixation-mode').val(rubUsdtFixationMode);
		$('#cfs-error').addClass('d-none').text('');
	});

	$('#btn-save-company-settings').on('click', function () {
		var $btn = $(this);
		var companyId = parseInt($('#cfs-company-id').val(), 10) || 0;
		var pairs = [];
		var providers = [];
		var rubUsdtFixationMode = $('#cfs-rub-usdt-fixation-mode').val() || 'rapira_manual';

		$('.js-company-pair:checked').each(function () {
			pairs.push($(this).val());
		});

		$('.js-company-provider:checked').each(function () {
			providers.push($(this).val());
		});

		$('#cfs-error').addClass('d-none').text('');
		$btn.prop('disabled', true)
			.find('.btn-label').hide()
			.end().find('#btn-company-settings-spinner').removeClass('d-none');

		$.post(AJAX_URL, {
			action: 'me_company_contours_save',
			nonce: NONCES.companySettings,
			company_id: companyId,
			exchange_pairs: pairs,
			providers: providers,
			rub_usdt_fixation_mode: rubUsdtFixationMode
		})
		.done(function (res) {
			if (!res || !res.success) {
				$('#cfs-error').removeClass('d-none').text((res && res.data && res.data.message) || 'Ошибка сохранения.');
				$btn.prop('disabled', false)
					.find('.btn-label').show()
					.end().find('#btn-company-settings-spinner').addClass('d-none');
				return;
			}

			var pairList = normalizePairCodes(res.data.enabled_exchange_pairs || []);
			var providerList = normalizeProviderCodes(res.data.allowed_providers || []);
			var pairAttr = pairList.join(',');
			var providerAttr = providerList.join(',');
			var $row = $('#corow-' + companyId);
			$row.find('.js-company-settings').attr('data-enabled-exchange-pairs', pairAttr);
			$row.find('.js-company-settings').attr('data-allowed-providers', providerAttr);
			$row.find('.js-company-settings').attr('data-rub-usdt-fixation-mode', res.data.rub_usdt_fixation_mode || 'rapira_manual');
			$('#company-exchange-pairs-' + companyId).html('<span class="hint-text fs-11 m-r-5">Обмен:</span>' + renderCompanyExchangePairBadges(pairList));
			$('#company-providers-' + companyId).html('<span class="hint-text fs-11 m-r-5">Платежи:</span>' + renderCompanyProviderBadges(providerList));
			showToast(res.data.message || 'Настройки компании сохранены.', 'success');
			bootstrap.Modal.getInstance($companySettingsModal[0]).hide();
		})
		.fail(function (xhr) {
			var message = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
				? xhr.responseJSON.data.message
				: 'Ошибка сервера.';
			$('#cfs-error').removeClass('d-none').text(message);
			$btn.prop('disabled', false)
				.find('.btn-label').show()
				.end().find('#btn-company-settings-spinner').addClass('d-none');
		});
	});

	function openCompanyStatusModal(companyId, status, companyName) {
		if (!companyId || (status !== 'blocked' && status !== 'active')) {
			return;
		}

		$('#cst-company-id').val(companyId);
		$('#cst-status').val(status);
		$('#cst-company-name').val(companyName || '—');
		$('#cst-reason').val('');
		$('#cst-error').addClass('d-none').text('');

		if (status === 'blocked') {
			$('#modal-company-status-title').text('Заблокировать компанию');
			$('#company-status-message').text('Компания «' + companyName + '» будет заблокирована.');
			$('#company-status-impact').text('Все пользователи компании сразу потеряют доступ к системе. Индивидуальные статусы пользователей не изменятся.');
			$('#cst-reason-wrap').removeClass('d-none');
			$('#btn-company-status-confirm')
				.removeClass('btn-success')
				.addClass('btn-danger')
				.find('.btn-label').text('Заблокировать');
		} else {
			$('#modal-company-status-title').text('Разблокировать компанию');
			$('#company-status-message').text('Компания «' + companyName + '» будет разблокирована.');
			$('#company-status-impact').text('Доступ вернётся только пользователям этой компании с активным индивидуальным статусом.');
			$('#cst-reason-wrap').addClass('d-none');
			$('#btn-company-status-confirm')
				.removeClass('btn-danger')
				.addClass('btn-success')
				.find('.btn-label').text('Разблокировать');
		}

		bootstrap.Modal.getOrCreateInstance($companyStatusModal[0]).show();
	}

	function submitCompanyStatus() {
		var $btn = $('#btn-company-status-confirm');
		var companyId = parseInt($('#cst-company-id').val(), 10) || 0;
		var status = String($('#cst-status').val() || '');
		var reason = $.trim($('#cst-reason').val());

		if (!companyId || (status !== 'blocked' && status !== 'active')) {
			return;
		}

		$('#cst-error').addClass('d-none').text('');
		$btn.prop('disabled', true)
			.find('.btn-label').hide()
			.end().find('#btn-company-status-spinner').removeClass('d-none');

		$.post(AJAX_URL, {
			action: 'me_set_company_status',
			_nonce: NONCES.companyStatus,
			company_id: companyId,
			status: status,
			reason: reason
		})
		.done(function (res) {
			if (!res || !res.success) {
				$('#cst-error').removeClass('d-none').text((res && res.data && res.data.message) || 'Ошибка изменения статуса компании.');
				$btn.prop('disabled', false)
					.find('.btn-label').show()
					.end().find('#btn-company-status-spinner').addClass('d-none');
				return;
			}

			updateCompanyStatusUi(res.data || {});
			showToast(res.data.message || 'Статус компании изменён.', 'success');
			bootstrap.Modal.getInstance($companyStatusModal[0]).hide();
		})
		.fail(function (xhr) {
			var message = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
				? xhr.responseJSON.data.message
				: 'Ошибка сервера.';
			$('#cst-error').removeClass('d-none').text(message);
			$btn.prop('disabled', false)
				.find('.btn-label').show()
				.end().find('#btn-company-status-spinner').addClass('d-none');
		});
	}

	$('#btn-create-company').on('click', function () {
		var $btn = $(this);
		var name = $.trim($('#cc-name').val());
		var phone = $.trim($('#cc-phone').val());
		var address = $.trim($('#cc-address').val());

		$('#cc-error').addClass('d-none').text('');

		if (!name) {
			$('#cc-error').removeClass('d-none').text('Введите название компании.');
			return;
		}

		$btn.prop('disabled', true)
			.find('.btn-label').hide()
			.end().find('#btn-create-company-spinner').removeClass('d-none');

		$.post(AJAX_URL, {
			action: 'me_create_company',
			name: name,
			phone: phone,
			address: address,
			_nonce: NONCES.createCo
		})
		.done(function (res) {
			if (!res || !res.success) {
				$('#cc-error').removeClass('d-none').text((res && res.data && res.data.message) || 'Ошибка создания компании.');
				$btn.prop('disabled', false)
					.find('.btn-label').show()
					.end().find('#btn-create-company-spinner').addClass('d-none');
				return;
			}

			var company = res.data.company || {};
			var $tbody = $('#companies-tbody');
			var exchangeBadges = renderCompanyExchangePairBadges(company.enabled_exchange_pairs || []);
			var providerBadges = renderCompanyProviderBadges(company.allowed_providers || []);
			var rowHtml = ''
				+ '<tr id="corow-' + company.id + '">'
				+ '<td class="v-align-middle"><span class="hint-text fs-12">#' + company.id + '</span></td>'
				+ '<td class="v-align-middle"><div><span class="semi-bold">' + escapeHtml(company.name) + '</span> <small class="hint-text m-l-5 fs-11">' + escapeHtml(company.code) + '</small></div><div class="m-t-5" id="company-exchange-pairs-' + company.id + '"><span class="hint-text fs-11 m-r-5">Обмен:</span>' + exchangeBadges + '</div><div class="m-t-5" id="company-providers-' + company.id + '"><span class="hint-text fs-11 m-r-5">Платежи:</span>' + providerBadges + '</div></td>'
				+ '<td class="v-align-middle" id="company-status-' + company.id + '">' + renderCompanyStatusCell(company) + '</td>'
				+ '<td class="v-align-middle hint-text fs-12">0</td>'
				+ '<td class="v-align-middle hint-text fs-12">' + escapeHtml(company.phone || '—') + '</td>'
				+ '<td class="v-align-middle hint-text fs-12">' + escapeHtml(company.address || '—') + '</td>'
				+ '<td class="v-align-middle text-right">' + buildCompanyActionsDropdown(company) + '</td>'
				+ '</tr>';
			var $row = $(rowHtml);

			$('#companies-empty-state').addClass('d-none');
			$('#companies-table-wrap').removeClass('d-none');
			$tbody.append($row);
			initActionDropdowns($row);

			showToast(res.data.message || 'Компания создана.', 'success');
			bootstrap.Modal.getInstance($createCompanyModal[0]).hide();
		})
		.fail(function (xhr) {
			var message = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
				? xhr.responseJSON.data.message
				: 'Ошибка сервера.';
			$('#cc-error').removeClass('d-none').text(message);
			$btn.prop('disabled', false)
				.find('.btn-label').show()
				.end().find('#btn-create-company-spinner').addClass('d-none');
		});
	});

	$(document).on('click', '.js-company-status', function (e) {
		e.preventDefault();

		var $trigger = $(this);
		openCompanyStatusModal(
			parseInt($trigger.attr('data-company-id'), 10) || 0,
			String($trigger.attr('data-status') || ''),
			$trigger.attr('data-company-name') || 'компания'
		);
	});

	$('#btn-company-status-confirm').on('click', function () {
		submitCompanyStatus();
	});

}(jQuery));
</script>
		<?php
	},
	99
);

get_template_part( 'template-parts/root-page-end' );
