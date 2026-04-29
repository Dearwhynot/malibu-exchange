<?php
/*
Template Name: Root Roles Page
Slug: root-roles
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

if ( ! crm_user_has_permission( $current_uid, 'roles.view' ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

global $wpdb;

$can_edit_roles = crm_user_has_permission( $current_uid, 'roles.edit' );
$nonce_roles    = wp_create_nonce( 'me_roles_save' );

$all_perms_raw          = $wpdb->get_results( 'SELECT * FROM crm_permissions ORDER BY module, action' ) ?: [];
$all_permissions_grouped = [];
foreach ( $all_perms_raw as $perm ) {
	$all_permissions_grouped[ $perm->module ][] = $perm;
}

$rp_rows = $wpdb->get_results( 'SELECT role_id, permission_id FROM crm_role_permissions' ) ?: [];
$role_permissions_map = [];
foreach ( $rp_rows as $rp_row ) {
	$role_permissions_map[ (int) $rp_row->role_id ][] = (int) $rp_row->permission_id;
}

$uc_rows = $wpdb->get_results( 'SELECT role_id, COUNT(*) as cnt FROM crm_user_roles GROUP BY role_id' ) ?: [];
$roles_user_counts = [];
foreach ( $uc_rows as $uc_row ) {
	$roles_user_counts[ (int) $uc_row->role_id ] = (int) $uc_row->cnt;
}

$all_crm_roles_full = $wpdb->get_results( 'SELECT * FROM crm_roles ORDER BY id ASC' ) ?: [];

get_template_part(
	'template-parts/root-page-start',
	null,
	[
		'title'       => 'Роли и права',
		'description' => 'Root-only матрица ролей и permission-контур без company-tab бара.',
		'breadcrumbs' => [
			[
				'label'  => 'Роли и права',
				'url'    => '',
				'active' => true,
			],
		],
	]
);
?>

<div class="card card-default">
	<div class="card-header">
		<div class="card-title">Роли и права доступа</div>
	</div>
	<div class="card-body no-padding">
		<div class="table-responsive">
			<table class="table table-hover m-b-0" id="roles-table">
				<thead>
					<tr>
						<th style="width:50px">ID</th>
						<th>Название</th>
						<th>Описание</th>
						<th style="width:80px">Прав</th>
						<th style="width:120px">Пользователей</th>
						<th style="width:60px"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $all_crm_roles_full as $crm_role ) : ?>
						<?php
						$role_id      = (int) $crm_role->id;
						$perm_count   = isset( $role_permissions_map[ $role_id ] ) ? count( $role_permissions_map[ $role_id ] ) : 0;
						$user_count   = $roles_user_counts[ $role_id ] ?? 0;
						$is_owner     = $crm_role->code === 'owner';
						$role_perm_ids = $role_permissions_map[ $role_id ] ?? [];
						$role_json    = esc_attr(
							wp_json_encode(
								[
									'id'       => $role_id,
									'name'     => $crm_role->name,
									'perm_ids' => $role_perm_ids,
								]
							)
						);
						?>
						<tr id="rrow-<?php echo $role_id; ?>">
							<td class="v-align-middle">
								<span class="hint-text fs-12">#<?php echo $role_id; ?></span>
							</td>
							<td class="v-align-middle">
								<span class="semi-bold"><?php echo esc_html( $crm_role->name ); ?></span>
								<?php if ( $crm_role->is_system ) : ?>
									<span class="badge badge-secondary m-l-5">System</span>
								<?php endif; ?>
							</td>
							<td class="v-align-middle hint-text fs-12">
								<?php echo esc_html( $crm_role->description ?: '—' ); ?>
							</td>
							<td class="v-align-middle">
								<?php if ( $is_owner ) : ?>
									<span class="hint-text fs-12">Все</span>
								<?php else : ?>
									<span id="rperm-count-<?php echo $role_id; ?>"><?php echo (int) $perm_count; ?></span>
								<?php endif; ?>
							</td>
							<td class="v-align-middle">
								<span class="hint-text fs-12"><?php echo (int) $user_count; ?></span>
							</td>
							<td class="v-align-middle">
								<?php if ( ! $is_owner && $can_edit_roles ) : ?>
									<button type="button"
									        class="btn btn-default btn-xs js-edit-role"
									        data-role="<?php echo $role_json; ?>"
									        data-bs-toggle="modal"
									        data-bs-target="#modal-role-perms">
										<i class="pg-icon">edit</i>
									</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<?php get_template_part( 'template-parts/toast-host' ); ?>

<div class="modal fade" id="modal-role-perms" tabindex="-1"
     aria-labelledby="modal-role-perms-title" aria-hidden="true">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header clearfix text-left">
				<button aria-label="Закрыть" type="button" class="close" data-bs-dismiss="modal" aria-hidden="true">
					<i class="pg-icon">close</i>
				</button>
				<h5 class="modal-title" id="modal-role-perms-title">Права роли</h5>
			</div>

			<div class="modal-body" id="modal-role-perms-body"></div>

			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-bs-dismiss="modal">Отмена</button>
				<button type="button" class="btn btn-primary" id="btn-save-role-perms">
					<span class="btn-label">Сохранить</span>
					<i class="pg-icon spin d-none" id="btn-role-perms-spinner">refresh</i>
				</button>
			</div>
		</div>
	</div>
</div>

<style>
.badge { font-size:11px; font-weight:600; padding:3px 8px; border-radius:20px; }
.badge-secondary { background:rgba(120,120,140,.15); color:#888; border:1px solid rgba(120,120,140,.2); }
.table th { font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
.table td { font-size:13px; }
.btn-xs { padding:3px 8px; font-size:12px; }
.pg-icon.spin { animation:spin 1s linear infinite; display:inline-block; }
.perm-module-title { font-size:10px; text-transform:uppercase; letter-spacing:.08em; color:#aaa; margin:14px 0 6px; padding-bottom:4px; border-bottom:1px solid rgba(0,0,0,.06); }
.perm-check-label { font-size:13px; cursor:pointer; display:flex; align-items:center; gap:6px; margin-bottom:4px; }
.perm-check-label input { flex-shrink:0; }
@keyframes spin { to { transform:rotate(360deg); } }
</style>

<?php
add_action(
	'wp_footer',
	function () use ( $nonce_roles, $all_permissions_grouped ) {
		?>
<script>
(function ($) {
	'use strict';

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCE_ROLES = '<?php echo esc_js( $nonce_roles ); ?>';
	var PERMS_GROUPED = <?php echo crm_json_for_inline_js( $all_permissions_grouped ); ?>;
	var editRoleId = 0;
	var $roleModal = $('#modal-role-perms');

	function showToast(message, type) {
		if (window.MalibuToast && typeof window.MalibuToast.show === 'function') {
			window.MalibuToast.show(message, type || 'info');
			return;
		}
		alert(message);
	}

	$(document).on('click', '.js-edit-role', function () {
		var role = $(this).data('role');
		editRoleId = parseInt(role.id, 10) || 0;
		$('#modal-role-perms-title').text('Права роли: ' + (role.name || '—'));

		var activeIds = (role.perm_ids || []).map(Number);
		var html = '';

		$.each(PERMS_GROUPED, function (module, perms) {
			html += '<div class="perm-module-title">' + module + '</div><div class="row">';
			$.each(perms, function (_, perm) {
				var pid = parseInt(perm.id, 10);
				var checked = activeIds.indexOf(pid) !== -1 ? ' checked' : '';
				html += '<div class="col-md-6">'
					+ '<label class="perm-check-label">'
					+ '<input type="checkbox" class="perm-checkbox" value="' + pid + '"' + checked + '>'
					+ '<span>' + perm.name + ' <small class="hint-text">(' + perm.code + ')</small></span>'
					+ '</label></div>';
			});
			html += '</div>';
		});

		$('#modal-role-perms-body').html(html);
		$('#btn-save-role-perms').prop('disabled', false)
			.find('.btn-label').show()
			.end().find('#btn-role-perms-spinner').addClass('d-none');
	});

	$('#btn-save-role-perms').on('click', function () {
		if (!editRoleId) {
			return;
		}

		var permIds = [];
		$('#modal-role-perms-body .perm-checkbox:checked').each(function () {
			permIds.push(parseInt($(this).val(), 10));
		});

		var $btn = $(this);
		$btn.prop('disabled', true)
			.find('.btn-label').hide()
			.end().find('#btn-role-perms-spinner').removeClass('d-none');

		$.post(AJAX_URL, {
			action: 'me_save_role_permissions',
			role_id: editRoleId,
			permission_ids: permIds,
			_nonce: NONCE_ROLES
		})
		.done(function (res) {
			if (!res || !res.success) {
				showToast((res && res.data && res.data.message) || 'Ошибка сохранения прав.', 'error');
				$btn.prop('disabled', false)
					.find('.btn-label').show()
					.end().find('#btn-role-perms-spinner').addClass('d-none');
				return;
			}

			showToast(res.data.message || 'Права роли сохранены.', 'success');
			$('#rperm-count-' + editRoleId).text(res.data.count || 0);
			bootstrap.Modal.getInstance($roleModal[0]).hide();
		})
		.fail(function () {
			showToast('Ошибка сервера.', 'error');
			$btn.prop('disabled', false)
				.find('.btn-label').show()
				.end().find('#btn-role-perms-spinner').addClass('d-none');
		});
	});

}(jQuery));
</script>
		<?php
	},
	99
);

get_template_part( 'template-parts/root-page-end' );
