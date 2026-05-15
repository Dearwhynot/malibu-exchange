<?php
/*
Template Name: Root Fintech Providers Page
Slug: root-fintech-providers
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

$companies          = crm_get_all_companies_full();
$provider_labels    = crm_fintech_provider_labels();
$default_providers  = crm_fintech_default_allowed_providers();
$nonce_save         = wp_create_nonce( 'me_company_fintech_access_save' );

$company_access_map = [];
foreach ( $companies as $company ) {
	$company_id                          = (int) $company->id;
	$company_access_map[ $company_id ]   = crm_fintech_get_allowed_providers( $company_id );
}

get_template_part(
	'template-parts/root-page-start',
	null,
	[
		'title'       => 'Платёжные системы',
		'description' => 'Управление доступом компаний к платёжным провайдерам. Без активного провайдера компания не сможет принимать платежи.',
		'breadcrumbs' => [
			[
				'label'  => 'Платёжные системы',
				'url'    => '',
				'active' => true,
			],
		],
	]
);
?>

<div class="card card-default">
	<div class="card-header">
		<div class="card-title">Доступ к платёжным провайдерам по компаниям</div>
		<p class="hint-text small m-b-0 m-t-5">
			Кликни ячейку, чтобы переключить доступ компании к провайдеру. Активный провайдер на стороне компании настраивается в её /settings/.
		</p>
	</div>
	<div class="card-body no-padding">
		<?php if ( empty( $companies ) ) : ?>
			<div class="p-30 text-center hint-text">
				<p>Компании не найдены. Сначала создай компании на странице «Компании».</p>
			</div>
		<?php else : ?>
			<div class="table-responsive">
				<table class="table table-hover m-b-0" id="fintech-providers-table">
					<thead>
						<tr>
							<th style="width:60px">ID</th>
							<th>Компания</th>
							<?php foreach ( $provider_labels as $code => $label ) : ?>
								<th><?php echo esc_html( $label ); ?> <small class="hint-text fs-11">(<?php echo esc_html( $code ); ?>)</small></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $companies as $company ) :
							$company_id = (int) $company->id;
							$allowed    = $company_access_map[ $company_id ];
							?>
							<tr id="fprow-<?php echo $company_id; ?>"
							    data-company-id="<?php echo $company_id; ?>"
							    data-company-name="<?php echo esc_attr( $company->name ); ?>"
							    data-allowed='<?php echo esc_attr( wp_json_encode( array_values( $allowed ) ) ); ?>'>
								<td class="v-align-middle">
									<span class="hint-text fs-12">#<?php echo $company_id; ?></span>
								</td>
								<td class="v-align-middle">
									<div class="semi-bold"><?php echo esc_html( $company->name ); ?></div>
									<small class="hint-text fs-11"><?php echo esc_html( $company->code ); ?></small>
								</td>
								<?php foreach ( $provider_labels as $code => $label ) :
									$is_allowed = in_array( $code, $allowed, true );
									?>
									<td class="v-align-middle js-toggle-fintech-cell"
									    id="fpcell-<?php echo $company_id; ?>-<?php echo esc_attr( $code ); ?>"
									    data-company-id="<?php echo $company_id; ?>"
									    data-provider-code="<?php echo esc_attr( $code ); ?>"
									    data-is-allowed="<?php echo $is_allowed ? '1' : '0'; ?>"
									    role="button"
									    title="Кликните, чтобы переключить доступ к <?php echo esc_attr( $label ); ?> для компании <?php echo esc_attr( $company->name ); ?>">
										<?php if ( $is_allowed ) : ?>
											<span class="badge badge-success">доступен</span>
										<?php else : ?>
											<span class="badge badge-secondary">отключён</span>
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

<style>
.badge { font-size:11px; font-weight:600; padding:3px 8px; border-radius:20px; }
.badge-success  { background:rgba(29,211,176,.18); color:#0d9e82; border:1px solid rgba(29,211,176,.3); }
.badge-secondary{ background:rgba(120,120,140,.15); color:#888;  border:1px solid rgba(120,120,140,.2); }
.table th { font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
.table td { font-size:13px; }
.js-toggle-fintech-cell { cursor:pointer; transition:background-color .12s ease; }
.js-toggle-fintech-cell:hover { background-color:rgba(0,123,255,.06); }
.js-toggle-fintech-cell.is-saving { opacity:.55; pointer-events:none; }
</style>

<?php
add_action(
	'wp_footer',
	function () use ( $nonce_save ) {
		?>
<script>
(function ($) {
	'use strict';

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCE    = '<?php echo esc_js( $nonce_save ); ?>';

	function showToast(message, type) {
		if (window.MalibuToast && typeof window.MalibuToast.show === 'function') {
			window.MalibuToast.show(message, type || 'info');
			return;
		}
		if (window.console && console.warn) {
			console.warn(message);
		}
	}

	function renderCellContent(isAllowed) {
		if (isAllowed) {
			return '<span class="badge badge-success">доступен</span>';
		}
		return '<span class="badge badge-secondary">отключён</span>';
	}

	$(document).on('click', '.js-toggle-fintech-cell', function () {
		var $cell = $(this);
		if ($cell.hasClass('is-saving')) { return; }

		var $row = $cell.closest('tr');
		var companyId = parseInt($cell.attr('data-company-id'), 10) || 0;
		var providerCode = $cell.attr('data-provider-code') || '';
		var currentlyAllowed = $cell.attr('data-is-allowed') === '1';

		var rowAllowed;
		try {
			rowAllowed = JSON.parse($row.attr('data-allowed') || '[]');
		} catch (e) {
			rowAllowed = [];
		}

		var nextAllowed;
		if (currentlyAllowed) {
			nextAllowed = rowAllowed.filter(function (code) { return code !== providerCode; });
		} else {
			nextAllowed = rowAllowed.slice();
			if (nextAllowed.indexOf(providerCode) === -1) { nextAllowed.push(providerCode); }
		}

		$cell.addClass('is-saving');

		$.post(AJAX_URL, {
			action: 'me_company_fintech_access_save',
			nonce: NONCE,
			company_id: companyId,
			providers: nextAllowed
		})
		.done(function (res) {
			if (!res || !res.success) {
				showToast((res && res.data && res.data.message) || 'Ошибка сохранения.', 'danger');
				$cell.removeClass('is-saving');
				return;
			}

			var savedAllowed = (res.data && res.data.allowed_providers) || nextAllowed;
			$row.attr('data-allowed', JSON.stringify(savedAllowed));

			var nowAllowed = savedAllowed.indexOf(providerCode) !== -1;
			$cell.attr('data-is-allowed', nowAllowed ? '1' : '0');
			$cell.html(renderCellContent(nowAllowed));

			showToast((res.data && res.data.message) || 'Доступ обновлён.', 'success');
			$cell.removeClass('is-saving');
		})
		.fail(function (xhr) {
			var msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
				? xhr.responseJSON.data.message
				: ('Ошибка сервера (HTTP ' + (xhr ? xhr.status : '?') + ').');
			console.error('[me_company_fintech_access_save] failed:', xhr && xhr.status, xhr && xhr.responseText);
			showToast(msg, 'danger');
			$cell.removeClass('is-saving');
		});
	});

}(jQuery));
</script>
		<?php
	},
	99
);

get_template_part( 'template-parts/root-page-end' );
