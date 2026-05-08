<?php
/*
Template Name: Root Rate Pairs Page
Slug: root-rate-pairs
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

$matrix          = crm_root_get_company_pairs_matrix();
$available_pairs = crm_root_available_rate_pairs();
$nonce_save      = wp_create_nonce( 'me_root_rate_pair_save' );

get_template_part(
	'template-parts/root-page-start',
	null,
	[
		'title'       => 'Курсы и пары',
		'description' => 'Активация валютных пар и установка коэффициентов для каждой компании. Без активной пары компания не может сохранять курсы.',
		'breadcrumbs' => [
			[
				'label'  => 'Курсы и пары',
				'url'    => '',
				'active' => true,
			],
		],
	]
);
?>

<div class="card card-default">
	<div class="card-header">
		<div class="card-title">Активация пар по компаниям</div>
		<p class="hint-text small m-b-0 m-t-5">
			Включи нужную пару у компании и задай коэффициент. Наш курс = курс конкурента − коэффициент.
		</p>
	</div>
	<div class="card-body no-padding">
		<?php if ( empty( $matrix ) ) : ?>
			<div class="p-30 text-center hint-text">
				<p>Компании не найдены. Сначала создай компании на странице «Компании».</p>
			</div>
		<?php else : ?>
			<div class="table-responsive">
				<table class="table table-hover m-b-0" id="rate-pairs-table">
					<thead>
						<tr>
							<th style="width:60px">ID</th>
							<th>Компания</th>
							<?php foreach ( $available_pairs as $pair ) : ?>
								<th><?php echo esc_html( $pair['title'] ); ?> <small class="hint-text fs-11">(<?php echo esc_html( $pair['code'] ); ?>)</small></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $matrix as $row ) :
							$company    = $row['company'];
							$company_id = (int) $company->id;
							?>
							<tr id="rprow-<?php echo $company_id; ?>" data-company-id="<?php echo $company_id; ?>" data-company-name="<?php echo esc_attr( $company->name ); ?>">
								<td class="v-align-middle">
									<span class="hint-text fs-12">#<?php echo $company_id; ?></span>
								</td>
								<td class="v-align-middle">
									<div class="semi-bold"><?php echo esc_html( $company->name ); ?></div>
									<small class="hint-text fs-11"><?php echo esc_html( $company->code ); ?></small>
								</td>
								<?php foreach ( $row['pairs'] as $pair_view ) : ?>
									<td class="v-align-middle js-edit-rate-pair-cell"
									    id="rpcell-<?php echo $company_id; ?>-<?php echo esc_attr( $pair_view['code'] ); ?>"
									    data-company-id="<?php echo $company_id; ?>"
									    data-company-name="<?php echo esc_attr( $company->name ); ?>"
									    data-pair-code="<?php echo esc_attr( $pair_view['code'] ); ?>"
									    data-pair-title="<?php echo esc_attr( $pair_view['title'] ); ?>"
									    data-is-active="<?php echo $pair_view['is_active'] ? '1' : '0'; ?>"
									    role="button"
									    title="Кликните, чтобы переключить активность пары <?php echo esc_attr( $pair_view['title'] ); ?> для компании <?php echo esc_attr( $company->name ); ?>">
										<?php if ( $pair_view['is_active'] ) : ?>
											<span class="badge badge-success">активна</span>
										<?php elseif ( $pair_view['pair_id'] !== null ) : ?>
											<span class="badge badge-secondary">отключена</span>
										<?php else : ?>
											<span class="badge badge-secondary">не настроена</span>
										<?php endif; ?>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>

<?php get_template_part( 'template-parts/toast-host' ); ?>

<div class="modal fade" id="modal-edit-rate-pair" tabindex="-1"
     aria-labelledby="modal-edit-rate-pair-title" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header clearfix text-left">
				<button aria-label="Закрыть" type="button" class="close" data-bs-dismiss="modal" aria-hidden="true">
					<i class="pg-icon">close</i>
				</button>
				<h5 class="modal-title" id="modal-edit-rate-pair-title">Активация пары</h5>
				<p class="p-b-10 m-b-0 hint-text small">
					Включи или отключи пару для компании. Сдвиг и процент настраиваются на странице
					<strong>«Настройки» → раздел «Курсы»</strong> внутри компании.
				</p>
			</div>
			<div class="modal-body">
				<form id="form-rate-pair" novalidate>
					<input type="hidden" id="rp-company-id" value="0">
					<input type="hidden" id="rp-pair-code" value="">

					<div class="form-group form-group-default">
						<label>Компания</label>
						<input type="text" class="form-control" id="rp-company-name" readonly>
					</div>

					<div class="form-group form-group-default">
						<label>Пара</label>
						<input type="text" class="form-control" id="rp-pair-title" readonly>
					</div>

					<div class="form-group form-group-default">
						<label>Статус</label>
						<div class="form-check complete m-t-5">
							<input type="checkbox" id="rp-is-active" value="1">
							<label for="rp-is-active">Активна (компания сможет сохранять курсы и настраивать наценку)</label>
						</div>
					</div>

					<div class="alert alert-danger d-none m-t-10" id="rp-error"></div>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-bs-dismiss="modal">Отмена</button>
				<button type="button" class="btn btn-primary" id="btn-save-rate-pair">
					<span class="btn-label">Сохранить</span>
					<i class="pg-icon spin d-none" id="btn-rate-pair-spinner">refresh</i>
				</button>
			</div>
		</div>
	</div>
</div>

<style>
.badge { font-size:11px; font-weight:600; padding:3px 8px; border-radius:20px; }
.badge-success  { background:rgba(29,211,176,.18); color:#0d9e82; border:1px solid rgba(29,211,176,.3); }
.badge-secondary{ background:rgba(120,120,140,.15); color:#888;  border:1px solid rgba(120,120,140,.2); }
.table th { font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
.table td { font-size:13px; }
.btn-xs { padding:3px 8px; font-size:12px; }
.pg-icon.spin { animation:spin 1s linear infinite; display:inline-block; }
@keyframes spin { to { transform:rotate(360deg); } }
.js-edit-rate-pair-cell { cursor:pointer; transition:background-color .12s ease; }
.js-edit-rate-pair-cell:hover { background-color:rgba(0,123,255,.06); }
</style>

<?php
add_action(
	'wp_footer',
	function () use ( $nonce_save, $available_pairs ) {
		?>
<script>
(function ($) {
	'use strict';

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCE    = '<?php echo esc_js( $nonce_save ); ?>';
	var PAIRS    = <?php echo wp_json_encode( $available_pairs ); ?>;

	function showToast(message, type) {
		if (window.MalibuToast && typeof window.MalibuToast.show === 'function') {
			window.MalibuToast.show(message, type || 'info');
			return;
		}
		alert(message);
	}

	function escapeHtml(value) {
		return $('<div>').text(value == null ? '' : String(value)).html();
	}

	function renderCellContent(isActive, hasRow) {
		if (isActive) {
			return '<span class="badge badge-success">активна</span>';
		}
		if (hasRow) {
			return '<span class="badge badge-secondary">отключена</span>';
		}
		return '<span class="badge badge-secondary">не настроена</span>';
	}

	function findPairDef(code) {
		for (var i = 0; i < PAIRS.length; i++) {
			if (PAIRS[i].code === code) {
				return PAIRS[i];
			}
		}
		return null;
	}

	$(document).on('click', '.js-edit-rate-pair-cell', function () {
		var $cell = $(this);
		var companyId = parseInt($cell.attr('data-company-id'), 10) || 0;
		var companyName = $cell.attr('data-company-name') || '';
		var pairCode = $cell.attr('data-pair-code') || '';
		var pair = findPairDef(pairCode);
		if (!pair) { return; }

		var isActive = $cell.attr('data-is-active') === '1';

		$('#rp-company-id').val(companyId);
		$('#rp-pair-code').val(pair.code);
		$('#rp-company-name').val(companyName);
		$('#rp-pair-title').val(pair.title + ' (' + pair.code + ')');
		$('#rp-is-active').prop('checked', isActive);
		$('#rp-error').addClass('d-none').text('');

		bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-edit-rate-pair')).show();
	});

	$('#btn-save-rate-pair').on('click', function () {
		var $btn = $(this);
		var companyId = parseInt($('#rp-company-id').val(), 10) || 0;
		var code = $('#rp-pair-code').val();
		var isActive = $('#rp-is-active').is(':checked') ? 1 : 0;

		$('#rp-error').addClass('d-none').text('');

		$btn.prop('disabled', true)
			.find('.btn-label').hide()
			.end().find('#btn-rate-pair-spinner').removeClass('d-none');

		$.post(AJAX_URL, {
			action: 'me_root_rate_pair_save',
			_nonce: NONCE,
			company_id: companyId,
			code: code,
			is_active: isActive
		})
		.done(function (res) {
			if (!res || !res.success) {
				$('#rp-error').removeClass('d-none').text((res && res.data && res.data.message) || 'Ошибка сохранения.');
				$btn.prop('disabled', false)
					.find('.btn-label').show()
					.end().find('#btn-rate-pair-spinner').addClass('d-none');
				return;
			}

			var d = res.data;
			var $cell = $('#rpcell-' + d.company_id + '-' + d.code);
			$cell.attr('data-is-active', d.is_active ? '1' : '0');
			$cell.html(renderCellContent(!!d.is_active, true));

			showToast(d.message || 'Пара сохранена.', 'success');
			bootstrap.Modal.getInstance(document.getElementById('modal-edit-rate-pair')).hide();
			$btn.prop('disabled', false)
				.find('.btn-label').show()
				.end().find('#btn-rate-pair-spinner').addClass('d-none');
		})
		.fail(function (xhr) {
			var msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
				? xhr.responseJSON.data.message
				: ('Ошибка сервера (HTTP ' + (xhr ? xhr.status : '?') + ').');
			console.error('[me_root_rate_pair_save] failed:', xhr && xhr.status, xhr && xhr.responseText);
			$('#rp-error').removeClass('d-none').text(msg);
			$btn.prop('disabled', false)
				.find('.btn-label').show()
				.end().find('#btn-rate-pair-spinner').addClass('d-none');
		});
	});

}(jQuery));
</script>
		<?php
	},
	99
);

get_template_part( 'template-parts/root-page-end' );
