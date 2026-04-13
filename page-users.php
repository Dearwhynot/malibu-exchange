<?php
/*
Template Name: Users Page
Slug: users
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

if ( ! crm_can_manage_users() ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

global $wpdb;

// ─── Параметры запроса ───────────────────────────────────────────────────────
$per_page = 20;
$paged    = max( 1, (int) sanitize_text_field( $_GET['paged']     ?? '1' ) );
$s        = sanitize_text_field( $_GET['me_s']      ?? '' );
$f_role   = sanitize_key( $_GET['me_role']   ?? '' );
$f_status = sanitize_key( $_GET['me_status'] ?? '' );

// ─── WP_User_Query ───────────────────────────────────────────────────────────
$query_args = [
	'number'      => $per_page,
	'offset'      => ( $paged - 1 ) * $per_page,
	'count_total' => true,
	'orderby'     => 'registered',
	'order'       => 'DESC',
];

// Полнотекстовый поиск
if ( $s !== '' ) {
	$query_args['search']         = '*' . $s . '*';
	$query_args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
}

// Фильтр по CRM-роли — получаем user_id через crm_user_roles
if ( $f_role !== '' ) {
	$role_user_ids = $wpdb->get_col( $wpdb->prepare(
		'SELECT DISTINCT ur.user_id FROM crm_user_roles ur
		 JOIN crm_roles r ON r.id = ur.role_id WHERE r.code = %s',
		$f_role
	) );
	$query_args['include'] = ! empty( $role_user_ids ) ? array_map( 'intval', $role_user_ids ) : [ -1 ];
}

// Фильтр по CRM-статусу через crm_user_accounts
if ( $f_status === '' ) {
	// По умолчанию: скрываем archived
	$excl = $wpdb->get_col( "SELECT user_id FROM crm_user_accounts WHERE status = 'archived'" );
	if ( ! empty( $excl ) ) {
		$query_args['exclude'] = array_map( 'intval', $excl );
	}
} elseif ( $f_status === 'active' ) {
	// Только активные — исключить всех у кого статус не active
	$excl = $wpdb->get_col( "SELECT user_id FROM crm_user_accounts WHERE status != 'active'" );
	if ( ! empty( $excl ) ) {
		$query_args['exclude'] = array_map( 'intval', $excl );
	}
} elseif ( $f_status !== 'all' ) {
	$incl = $wpdb->get_col( $wpdb->prepare(
		'SELECT user_id FROM crm_user_accounts WHERE status = %s',
		$f_status
	) );
	$query_args['include'] = ! empty( $incl ) ? array_map( 'intval', $incl ) : [ -1 ];
}

$uq          = new WP_User_Query( $query_args );
$users       = $uq->get_results();
$total       = (int) $uq->get_total();
$total_pages = (int) ceil( $total / $per_page );

// ─── Batch-загрузка CRM-данных для всех пользователей в выборке ─────────────
$user_ids        = array_map( function ( $u ) { return (int) $u->ID; }, $users );
$crm_accounts    = crm_get_accounts_for_users( $user_ids );
$crm_roles_map   = crm_get_roles_for_users( $user_ids );
$all_crm_roles   = crm_get_all_roles();

// ─── Вспомогательные данные ──────────────────────────────────────────────────
$can_assign_roles = crm_user_has_permission( get_current_user_id(), 'users.assign_roles' );
$can_hard_delete  = me_users_can_hard_delete();
$current_uid      = get_current_user_id();
$page_url         = get_permalink();
$vendor_img_uri   = get_template_directory_uri() . '/vendor/pages/assets/img';

// Nonces
$nonce_save   = wp_create_nonce( 'me_users_save' );
$nonce_status = wp_create_nonce( 'me_users_status' );
$nonce_delete = wp_create_nonce( 'me_users_delete' );
$nonce_roles  = wp_create_nonce( 'me_roles_save' );

// ─── Данные для вкладки "Роли" ───────────────────────────────────────────────
$can_edit_roles = crm_user_has_permission( get_current_user_id(), 'roles.edit' );

$all_perms_raw = $wpdb->get_results( 'SELECT * FROM crm_permissions ORDER BY module, action' ) ?: [];
$all_permissions_grouped = [];
foreach ( $all_perms_raw as $_p ) {
	$all_permissions_grouped[ $_p->module ][] = $_p;
}

$rp_rows = $wpdb->get_results( 'SELECT role_id, permission_id FROM crm_role_permissions' ) ?: [];
$role_permissions_map = [];
foreach ( $rp_rows as $_rp ) {
	$role_permissions_map[ (int) $_rp->role_id ][] = (int) $_rp->permission_id;
}

$uc_rows = $wpdb->get_results( 'SELECT role_id, COUNT(*) as cnt FROM crm_user_roles GROUP BY role_id' ) ?: [];
$roles_user_counts = [];
foreach ( $uc_rows as $_uc ) {
	$roles_user_counts[ (int) $_uc->role_id ] = (int) $_uc->cnt;
}

$all_crm_roles_full = $wpdb->get_results( 'SELECT * FROM crm_roles ORDER BY id ASC' ) ?: [];

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
							<li class="breadcrumb-item active">Пользователи</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">

				<!-- ─── TAB NAV ───────────────────────────────────────────────── -->
				<ul class="nav nav-tabs nav-tabs-simple m-b-0" role="tablist" data-init-reponsive-tabs="dropdownfx">
					<li class="nav-item">
						<a class="active" data-bs-toggle="tab" role="tab" data-bs-target="#tab-users" href="#">
							Пользователи
						</a>
					</li>
					<li class="nav-item">
						<a data-bs-toggle="tab" role="tab" data-bs-target="#tab-roles" href="#">
							Роли и права
						</a>
					</li>
				</ul>

				<div class="tab-content" style="padding:0;overflow:visible">

				<!-- ─── TAB: USERS ────────────────────────────────────────────── -->
				<div class="tab-pane active" id="tab-users">

				<!-- Page heading -->
				<div class="d-flex align-items-center justify-content-between m-b-20">
					<p class="hint-text m-b-0">Всего в выборке: <strong><?php echo (int) $total; ?></strong></p>
					<button type="button" class="btn btn-primary btn-cons"
					        data-bs-toggle="modal" data-bs-target="#modal-user-form">
						<i class="pg-icon">user_add</i>&nbsp; Добавить пользователя
					</button>
				</div>

				<!-- ─── FILTERS ────────────────────────────────────────────────── -->
				<div class="card card-default m-b-20 align-items-center">
					<div class="card-body p-t-15 p-b-15">
						<form method="get" action="<?php echo esc_url( $page_url ); ?>" id="users-filter-form">
							<div style="display:grid; grid-template-columns:1fr auto 1fr; gap:12px; align-items:center;">
								<!-- Поиск — левый край -->
								<div class="input-group">
									<span class="input-group-text"><i class="pg-icon">search</i></span>
									<input type="text" class="form-control"
									       name="me_s" value="<?php echo esc_attr( $s ); ?>"
									       placeholder="Поиск по логину, email, имени…">
								</div>
								<!-- Фильтры — по центру -->
								<div class="d-flex gap-3">
									<div style="min-width:180px;">
										<select class="full-width" name="me_role" data-init-plugin="select2">
											<option value="">Все роли</option>
											<?php foreach ( $all_crm_roles as $crm_role ) : ?>
												<option value="<?php echo esc_attr( $crm_role->code ); ?>"
												        <?php selected( $f_role, $crm_role->code ); ?>>
													<?php echo esc_html( $crm_role->name ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>
									<div style="min-width:210px;">
										<select class="full-width" name="me_status" data-init-plugin="select2">
											<option value=""        <?php selected( $f_status, '' ); ?>>Активные (без архивных)</option>
											<option value="active"   <?php selected( $f_status, 'active' ); ?>>Только активные</option>
											<option value="blocked"  <?php selected( $f_status, 'blocked' ); ?>>Заблокированные</option>
											<option value="pending"  <?php selected( $f_status, 'pending' ); ?>>Ожидающие</option>
											<option value="archived" <?php selected( $f_status, 'archived' ); ?>>Архивные</option>
											<option value="all"      <?php selected( $f_status, 'all' ); ?>>Все</option>
										</select>
									</div>
								</div>
								<!-- Кнопки — правый край -->
								<div class="d-flex gap-2 justify-content-end">
									<button type="submit" class="btn btn-primary btn-sm">
										<i class="pg-icon">search</i>
									</button>
									<a href="<?php echo esc_url( $page_url ); ?>"
									   class="btn btn-default btn-sm">
										<i class="pg-icon">close</i>
									</a>
								</div>
							</div>
						</form>
					</div>
				</div>

				<!-- ─── USERS TABLE ────────────────────────────────────────────── -->
				<div class="card card-default">
					<div class="card-header">
						<div class="card-title">Список пользователей</div>
					</div>
					<div class="card-body no-padding">
						<?php if ( empty( $users ) ) : ?>
							<div class="p-30 text-center hint-text">
								<i class="pg-icon" style="font-size:48px;display:block;margin-bottom:12px">users</i>
								<p>Пользователи не найдены</p>
							</div>
						<?php else : ?>
							<div class="table-responsive">
								<table class="table table-hover m-b-0" id="users-table">
									<thead>
										<tr>
											<th style="width:50px">ID</th>
											<th>Пользователь</th>
											<th>Email</th>
											<th>Display Name</th>
											<th>Роли</th>
											<th style="width:95px">Статус</th>
											<th style="width:120px">Телефон</th>
											<th style="width:130px">Telegram</th>
											<th style="width:100px">Зарегистрирован</th>
											<th style="width:110px">Последний вход</th>
											<th style="width:60px"></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $users as $user ) :
											$acc           = $crm_accounts[ $user->ID ] ?? null;
											$user_roles    = $crm_roles_map[ $user->ID ] ?? [];
											$crm_status    = $acc ? $acc->status : CRM_STATUS_ACTIVE;
											$status_class  = crm_status_badge_class( $crm_status );
											$last_login    = me_users_get_last_login( $user->ID );
											$is_super      = $user->ID === 1;
											$avatar_letter = me_users_avatar_letter( $user );

											// JSON для модалки редактирования
											$user_json = esc_attr( wp_json_encode( [
												'id'               => $user->ID,
												'user_login'       => $user->user_login,
												'user_email'       => $user->user_email,
												'first_name'       => $user->first_name,
												'last_name'        => $user->last_name,
												'display_name'     => $user->display_name,
												'crm_status'       => $crm_status,
												'phone'            => $acc ? ( $acc->phone ?? '' ) : '',
												'telegram_username' => $acc ? ( $acc->telegram_username ?? '' ) : '',
												'telegram_id'      => $acc ? ( $acc->telegram_id ?? '' ) : '',
												'department'       => $acc ? ( $acc->department ?? '' ) : '',
												'position_title'   => $acc ? ( $acc->position_title ?? '' ) : '',
												'note'             => $acc ? ( $acc->note ?? '' ) : '',
												'role_ids'         => array_map( function ( $r ) { return (int) $r->id; }, $user_roles ),
											] ) );
										?>
										<tr id="urow-<?php echo (int) $user->ID; ?>">

											<td class="v-align-middle">
												<span class="hint-text fs-12">#<?php echo (int) $user->ID; ?></span>
											</td>

											<td class="v-align-middle">
												<div class="d-flex align-items-center">
													<div class="me-avatar m-r-10" aria-hidden="true">
														<?php echo esc_html( $avatar_letter ); ?>
													</div>
													<div>
														<span class="semi-bold"><?php echo esc_html( $user->user_login ); ?></span>
														<?php if ( $is_super ) : ?>
															<span class="badge badge-danger m-l-5" title="Super Admin">SA</span>
														<?php endif; ?>
													</div>
												</div>
											</td>

											<td class="v-align-middle">
												<a href="mailto:<?php echo esc_attr( $user->user_email ); ?>">
													<?php echo esc_html( $user->user_email ); ?>
												</a>
											</td>

											<td class="v-align-middle">
												<?php echo esc_html( $user->display_name ?: '—' ); ?>
											</td>

											<td class="v-align-middle">
												<?php if ( empty( $user_roles ) ) : ?>
													<span class="hint-text">—</span>
												<?php else : ?>
													<?php foreach ( $user_roles as $role ) : ?>
														<span class="badge badge-<?php echo esc_attr( crm_role_badge_class( $role->code ) ); ?> m-r-2">
															<?php echo esc_html( $role->name ); ?>
														</span>
													<?php endforeach; ?>
												<?php endif; ?>
											</td>

											<td class="v-align-middle">
												<span class="badge badge-<?php echo esc_attr( $status_class ); ?>"
												      id="status-badge-<?php echo (int) $user->ID; ?>">
													<?php echo esc_html( crm_status_label( $crm_status ) ); ?>
												</span>
											</td>

											<td class="v-align-middle">
												<span class="hint-text fs-12">
													<?php echo $acc && $acc->phone ? esc_html( $acc->phone ) : '—'; ?>
												</span>
											</td>

											<td class="v-align-middle">
												<span class="hint-text fs-12">
													<?php if ( $acc && $acc->telegram_username ) : ?>
														@<?php echo esc_html( $acc->telegram_username ); ?>
													<?php elseif ( $acc && $acc->telegram_id ) : ?>
														<span title="Telegram ID"><?php echo esc_html( $acc->telegram_id ); ?></span>
													<?php else : ?>
														—
													<?php endif; ?>
												</span>
											</td>

											<td class="v-align-middle">
												<span class="hint-text fs-12">
													<?php echo esc_html( wp_date( 'd.m.Y', strtotime( $user->user_registered ) ) ); ?>
												</span>
											</td>

											<td class="v-align-middle">
												<span class="hint-text fs-12">
													<?php echo $last_login
														? esc_html( wp_date( 'd.m.Y H:i', strtotime( $last_login ) ) )
														: '—'; ?>
												</span>
											</td>

											<td class="v-align-middle">
												<div class="dropdown">
													<button type="button"
													        class="btn btn-default btn-xs dropdown-toggle"
													        data-bs-toggle="dropdown"
													        aria-expanded="false"
													        aria-label="Действия">
														<i class="pg-icon">more_vertical</i>
													</button>
													<ul class="dropdown-menu dropdown-menu-end">

														<!-- View — uid=1 only -->
														<?php if ( $current_uid === 1 ) : ?>
														<li>
															<a class="dropdown-item"
															   href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"
															   target="_blank" rel="noopener">
																<i class="pg-icon m-r-5">see</i> Просмотр (WP)
															</a>
														</li>
														<?php endif; ?>

														<!-- Edit -->
														<li>
															<a class="dropdown-item js-edit-user" href="#"
															   data-user="<?php echo $user_json; ?>"
															   data-bs-toggle="modal"
															   data-bs-target="#modal-user-form">
																<i class="pg-icon m-r-5">edit</i> Редактировать
															</a>
														</li>

														<?php if ( ! $is_super && $user->ID !== $current_uid ) : ?>
														<li><hr class="dropdown-divider"></li>

														<!-- Block / Unblock / Restore -->
														<?php if ( $crm_status === CRM_STATUS_BLOCKED ) : ?>
															<li>
																<a class="dropdown-item js-set-status text-success" href="#"
																   data-user-id="<?php echo (int) $user->ID; ?>"
																   data-status="active">
																	<i class="pg-icon m-r-5">tick_circle</i> Разблокировать
																</a>
															</li>
														<?php elseif ( $crm_status === CRM_STATUS_ARCHIVED ) : ?>
															<li>
																<a class="dropdown-item js-set-status text-success" href="#"
																   data-user-id="<?php echo (int) $user->ID; ?>"
																   data-status="active">
																	<i class="pg-icon m-r-5">undo</i> Восстановить
																</a>
															</li>
														<?php elseif ( $crm_status === CRM_STATUS_PENDING ) : ?>
															<li>
																<a class="dropdown-item js-set-status text-success" href="#"
																   data-user-id="<?php echo (int) $user->ID; ?>"
																   data-status="active">
																	<i class="pg-icon m-r-5">tick_circle</i> Активировать
																</a>
															</li>
														<?php else : ?>
															<li>
																<a class="dropdown-item js-set-status text-warning" href="#"
																   data-user-id="<?php echo (int) $user->ID; ?>"
																   data-status="blocked">
																	<i class="pg-icon m-r-5">disable</i> Заблокировать
																</a>
															</li>
														<?php endif; ?>

														<!-- Archive -->
														<?php if ( $crm_status !== CRM_STATUS_ARCHIVED ) : ?>
															<li>
																<a class="dropdown-item js-set-status" href="#"
																   data-user-id="<?php echo (int) $user->ID; ?>"
																   data-status="archived"
																   data-confirm="Архивировать «<?php echo esc_attr( $user->user_login ); ?>»? Вход будет запрещён.">
																	<i class="pg-icon m-r-5">download</i> Архивировать
																</a>
															</li>
														<?php endif; ?>

														<li><hr class="dropdown-divider"></li>

														<!-- Delete -->
														<li>
															<a class="dropdown-item js-delete-user text-danger" href="#"
															   data-user-id="<?php echo (int) $user->ID; ?>"
															   data-username="<?php echo esc_attr( $user->user_login ); ?>"
															   data-hard="0">
																<i class="pg-icon m-r-5">trash</i> Удалить (в архив)
															</a>
														</li>
														<?php if ( $can_hard_delete ) : ?>
															<li>
																<a class="dropdown-item js-delete-user text-danger" href="#"
																   data-user-id="<?php echo (int) $user->ID; ?>"
																   data-username="<?php echo esc_attr( $user->user_login ); ?>"
																   data-hard="1">
																	<i class="pg-icon m-r-5">trash_alt</i> Удалить физически
																</a>
															</li>
														<?php endif; ?>

														<?php endif; // !$is_super && != current ?>

													</ul>
												</div>
											</td>

										</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div><!-- /.table-responsive -->
						<?php endif; ?>
					</div><!-- /.card-body -->

					<!-- Pagination -->
					<?php if ( $total_pages > 1 ) : ?>
						<div class="card-footer">
							<nav aria-label="Навигация по страницам">
								<ul class="pagination pagination-sm no-margin justify-content-center">
									<?php if ( $paged > 1 ) : ?>
										<li class="page-item">
											<a class="page-link" href="<?php echo esc_url( add_query_arg( array_merge( $_GET, [ 'paged' => $paged - 1 ] ), $page_url ) ); ?>">&laquo;</a>
										</li>
									<?php endif; ?>
									<?php
									$range = 2;
									for ( $i = max( 1, $paged - $range ); $i <= min( $total_pages, $paged + $range ); $i++ ) :
									?>
										<li class="page-item <?php echo $i === $paged ? 'active' : ''; ?>">
											<a class="page-link" href="<?php echo esc_url( add_query_arg( array_merge( $_GET, [ 'paged' => $i ] ), $page_url ) ); ?>"><?php echo (int) $i; ?></a>
										</li>
									<?php endfor; ?>
									<?php if ( $paged < $total_pages ) : ?>
										<li class="page-item">
											<a class="page-link" href="<?php echo esc_url( add_query_arg( array_merge( $_GET, [ 'paged' => $paged + 1 ] ), $page_url ) ); ?>">&raquo;</a>
										</li>
									<?php endif; ?>
								</ul>
							</nav>
						</div>
					<?php endif; ?>

				</div><!-- /.card -->

				</div><!-- /#tab-users -->

				<!-- ─── TAB: ROLES ───────────────────────────────────────────── -->
				<div class="tab-pane" id="tab-roles">

					<div class="card card-default m-t-20">
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
										<?php foreach ( $all_crm_roles_full as $crm_r ) :
											$r_perm_count = isset( $role_permissions_map[ (int) $crm_r->id ] )
												? count( $role_permissions_map[ (int) $crm_r->id ] )
												: 0;
											$r_user_count = $roles_user_counts[ (int) $crm_r->id ] ?? 0;
											$r_is_owner   = $crm_r->code === 'owner';
											$r_perm_ids   = $role_permissions_map[ (int) $crm_r->id ] ?? [];
											$r_json       = esc_attr( wp_json_encode( [
												'id'       => (int) $crm_r->id,
												'name'     => $crm_r->name,
												'perm_ids' => $r_perm_ids,
											] ) );
										?>
										<tr id="rrow-<?php echo (int) $crm_r->id; ?>">
											<td class="v-align-middle">
												<span class="hint-text fs-12">#<?php echo (int) $crm_r->id; ?></span>
											</td>
											<td class="v-align-middle">
												<span class="semi-bold"><?php echo esc_html( $crm_r->name ); ?></span>
												<?php if ( $crm_r->is_system ) : ?>
													<span class="badge badge-secondary m-l-5">System</span>
												<?php endif; ?>
											</td>
											<td class="v-align-middle hint-text fs-12">
												<?php echo esc_html( $crm_r->description ?: '—' ); ?>
											</td>
											<td class="v-align-middle">
												<?php if ( $r_is_owner ) : ?>
													<span class="hint-text fs-12">Все</span>
												<?php else : ?>
													<span id="rperm-count-<?php echo (int) $crm_r->id; ?>"><?php echo (int) $r_perm_count; ?></span>
												<?php endif; ?>
											</td>
											<td class="v-align-middle">
												<span class="hint-text fs-12"><?php echo (int) $r_user_count; ?></span>
											</td>
											<td class="v-align-middle">
												<?php if ( ! $r_is_owner && $can_edit_roles ) : ?>
													<button type="button"
													        class="btn btn-default btn-xs js-edit-role"
													        data-role="<?php echo $r_json; ?>"
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

				</div><!-- /#tab-roles -->

				</div><!-- /.tab-content -->

			</div><!-- /.container-fluid -->
		</div><!-- /.content -->

		<div class="container-fluid container-fixed-lg footer">
			<div class="copyright sm-text-center">
				<p class="small-text no-margin pull-left sm-pull-reset">
					&copy;<?php echo esc_html( gmdate( 'Y' ) ); ?> Malibu Exchange. All Rights Reserved.
				</p>
				<div class="clearfix"></div>
			</div>
		</div>
	</div><!-- /.page-content-wrapper -->

</div><!-- /.page-container -->


<!-- ════════════════════════════════════════════════════════════════════════
     TOAST
     ════════════════════════════════════════════════════════════════════ -->
<div aria-live="polite" aria-atomic="true"
     style="position:fixed;bottom:24px;right:24px;z-index:99999;min-width:280px">
	<div id="me-toast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
		<div class="d-flex">
			<div class="toast-body" id="me-toast-body">…</div>
			<button type="button" class="btn-close btn-close-white me-2 m-auto"
			        data-bs-dismiss="toast" aria-label="Закрыть"></button>
		</div>
	</div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════
     MODAL: ADD / EDIT USER
     ════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modal-user-form" tabindex="-1"
     aria-labelledby="modal-user-form-title" aria-hidden="true">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">

			<div class="modal-header clearfix text-left">
				<button aria-label="Закрыть" type="button" class="close" data-bs-dismiss="modal" aria-hidden="true">
					<i class="pg-icon">close</i>
				</button>
				<h5 class="modal-title" id="modal-user-form-title">Добавить пользователя</h5>
			</div>

			<div class="modal-body">
				<form id="form-user" autocomplete="off" novalidate>
					<input type="hidden" name="user_id" id="uf-user-id" value="0">

					<div class="row">

						<!-- Username (только для новых) -->
						<div class="col-md-6" id="uf-login-row">
							<div class="form-group form-group-default required">
								<label>Username <span class="text-danger">*</span></label>
								<input type="text" class="form-control" name="user_login" id="uf-user-login" autocomplete="off">
							</div>
						</div>

						<!-- Email -->
						<div class="col-md-6">
							<div class="form-group form-group-default required">
								<label>Email <span class="text-danger">*</span></label>
								<input type="email" class="form-control" name="user_email" id="uf-user-email">
							</div>
						</div>

						<!-- Password -->
						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Пароль
									<span class="text-danger" id="uf-pass-req">*</span>
									<small class="hint-text" id="uf-pass-hint" style="display:none">(пустое поле сохраняет текущий пароль)</small>
								</label>
								<input type="password" class="form-control" name="user_pass" id="uf-user-pass" autocomplete="new-password">
							</div>
						</div>

						<!-- First name -->
						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Имя</label>
								<input type="text" class="form-control" name="first_name" id="uf-first-name">
							</div>
						</div>

						<!-- Last name -->
						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Фамилия</label>
								<input type="text" class="form-control" name="last_name" id="uf-last-name">
							</div>
						</div>

						<!-- Display name -->
						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Display Name</label>
								<input type="text" class="form-control" name="display_name" id="uf-display-name">
							</div>
						</div>

						<!-- Phone -->
						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Телефон</label>
								<input type="text" class="form-control" name="phone" id="uf-phone" placeholder="+7 ...">
							</div>
						</div>

						<!-- Telegram username -->
						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Telegram Username</label>
								<input type="text" class="form-control" name="telegram_username" id="uf-telegram-username" placeholder="@username">
							</div>
						</div>

						<!-- Telegram ID -->
						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Telegram ID</label>
								<input type="text" class="form-control" name="telegram_id" id="uf-telegram-id" placeholder="123456789">
							</div>
						</div>

						<!-- Department -->
						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Отдел</label>
								<input type="text" class="form-control" name="department" id="uf-department">
							</div>
						</div>

						<!-- Position -->
						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Должность</label>
								<input type="text" class="form-control" name="position_title" id="uf-position-title">
							</div>
						</div>

						<!-- Separator -->
						<div class="w-100"></div>
						<div class="col-12 m-t-5 m-b-10"><div class="b-b b-grey"></div></div>

						<!-- CRM Roles -->
						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>CRM Роли</label>
								<select class="full-width" name="crm_role_ids[]" id="uf-crm-roles" multiple>
									<?php foreach ( $all_crm_roles as $crm_role ) : ?>
										<option value="<?php echo (int) $crm_role->id; ?>">
											<?php echo esc_html( $crm_role->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>

						<!-- Status -->
						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Статус</label>
								<select class="full-width" name="crm_status" id="uf-status">
									<option value="active">Active</option>
									<option value="blocked">Blocked</option>
									<option value="pending">Pending</option>
									<option value="archived">Archived</option>
								</select>
							</div>
						</div>

						<!-- Separator -->
						<div class="w-100"></div>
						<div class="col-12 m-t-5 m-b-10"><div class="b-b b-grey"></div></div>

						<!-- Note -->
						<div class="col-md-12">
							<div class="form-group form-group-default">
								<label>Заметка</label>
								<textarea class="form-control" name="note" id="uf-note" rows="2" style="resize:vertical"></textarea>
							</div>
						</div>

					</div>

					<div class="alert alert-danger d-none m-t-10" id="uf-error"></div>
				</form>
			</div>

			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-bs-dismiss="modal">Отмена</button>
				<button type="button" class="btn btn-primary" id="btn-save-user">
					<span class="btn-label">Создать</span>
					<i class="pg-icon spin d-none" id="btn-save-spinner">refresh</i>
				</button>
			</div>

		</div>
	</div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════
     MODAL: CONFIRM
     ════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modal-confirm" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header clearfix text-left">
				<button aria-label="Закрыть" type="button" class="close" data-bs-dismiss="modal" aria-hidden="true">
					<i class="pg-icon">close</i>
				</button>
				<h5 class="modal-title">Подтвердите действие</h5>
			</div>
			<div class="modal-body">
				<p id="confirm-modal-message"></p>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-bs-dismiss="modal">Отмена</button>
				<button type="button" class="btn btn-primary" id="btn-confirm-ok">Подтвердить</button>
			</div>
		</div>
	</div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════
     MODAL: ROLE PERMISSIONS
     ════════════════════════════════════════════════════════════════════ -->
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

			<div class="modal-body" id="modal-role-perms-body">
				<!-- заполняется через JS -->
			</div>

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

<!-- ════════════════════════════════════════════════════════════════════════
     INLINE STYLES
     ════════════════════════════════════════════════════════════════════ -->
<style>
.me-avatar {
	width:32px; height:32px; border-radius:50%;
	display:grid; place-items:center;
	background:linear-gradient(135deg,#1dd3b0,#4cc9f0);
	color:#032b3a; font-weight:700; font-size:13px; flex-shrink:0;
}
.badge { font-size:11px; font-weight:600; padding:3px 8px; border-radius:20px; }
.badge-success  { background:rgba(29,211,176,.18);  color:#0d9e82; border:1px solid rgba(29,211,176,.3); }
.badge-danger   { background:rgba(255,107,107,.18); color:#e0302e; border:1px solid rgba(255,107,107,.3); }
.badge-warning  { background:rgba(255,183,3,.18);   color:#b8830a; border:1px solid rgba(255,183,3,.3); }
.badge-info     { background:rgba(76,201,240,.18);  color:#1579a8; border:1px solid rgba(76,201,240,.3); }
.badge-primary  { background:rgba(90,128,255,.18);  color:#3f5fcc; border:1px solid rgba(90,128,255,.3); }
.badge-secondary{ background:rgba(120,120,140,.15); color:#888;    border:1px solid rgba(120,120,140,.2); }
.m-r-2 { margin-right:2px; }
.table th { font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
.table td { font-size:13px; }
.btn-xs { padding:3px 8px; font-size:12px; }
.toast { background:#1c2b3a; color:#f7fbff; }
.toast.toast-success { border-left:3px solid #1dd3b0; }
.toast.toast-error   { border-left:3px solid #ff6b6b; }
.pg-icon.spin { animation:spin 1s linear infinite; display:inline-block; }
@keyframes spin { to { transform:rotate(360deg); } }
.dropdown-menu .dropdown-item { display:flex; align-items:center; }
.dropdown-menu .dropdown-item .pg-icon { flex-shrink:0; line-height:1; }
/* ── Tabs ── */
#tab-users, #tab-roles { padding: 0; }
.nav-tabs ~ .tab-content { overflow: visible; }
.perm-module-title { font-size:10px; text-transform:uppercase; letter-spacing:.08em; color:#aaa; margin:14px 0 6px; padding-bottom:4px; border-bottom:1px solid rgba(0,0,0,.06); }
.perm-check-label { font-size:13px; cursor:pointer; display:flex; align-items:center; gap:6px; margin-bottom:4px; }
.perm-check-label input { flex-shrink:0; }
</style>

<?php
add_action( 'wp_footer', function () use ( $nonce_save, $nonce_status, $nonce_delete, $nonce_roles, $f_status, $can_assign_roles, $can_edit_roles, $all_permissions_grouped, $role_permissions_map ) {
?>
<script>
(function ($) {
	'use strict';

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCES   = {
		save:   '<?php echo esc_js( $nonce_save ); ?>',
		status: '<?php echo esc_js( $nonce_status ); ?>',
		delete: '<?php echo esc_js( $nonce_delete ); ?>'
	};
	var CAN_ASSIGN_ROLES = <?php echo $can_assign_roles ? 'true' : 'false'; ?>;

	// ── Дропдауны Actions: strategy fixed (fix overflow in .table-responsive) ──
	$('#users-table .dropdown-toggle').each(function () {
		new bootstrap.Dropdown(this, { popperConfig: { strategy: 'fixed' } });
	});

	// ── Select2 в модалке ─────────────────────────────────────────────────────
	var $modal = $('#modal-user-form');
	$modal.on('shown.bs.modal', function () {
		$('#uf-crm-roles, #uf-status').each(function () {
			if ( !$(this).hasClass('select2-hidden-accessible') ) {
				$(this).select2({ dropdownParent: $modal });
			}
		});
	});
	$modal.on('hidden.bs.modal', function () {
		$('#uf-crm-roles, #uf-status').each(function () {
			if ( $(this).hasClass('select2-hidden-accessible') ) {
				$(this).select2('destroy');
			}
		});
	});

	// ── Toast ─────────────────────────────────────────────────────────────────
	function showToast(message, type) {
		var $el = $('#me-toast');
		$el.removeClass('toast-success toast-error').addClass(type === 'error' ? 'toast-error' : 'toast-success');
		$('#me-toast-body').text(message);
		new bootstrap.Toast($el[0], { delay: 4000 }).show();
	}

	// ── Confirm modal ─────────────────────────────────────────────────────────
	var _confirmCb    = null;
	var _confirmModal = new bootstrap.Modal($('#modal-confirm')[0]);

	function showConfirm(message, callback, opts) {
		opts = opts || {};
		$('#confirm-modal-message').text(message);
		$('#btn-confirm-ok')
			.removeClass('btn-primary btn-danger btn-warning')
			.addClass(opts.btnClass || 'btn-primary')
			.text(opts.btnText || 'Подтвердить');
		_confirmCb = callback;
		_confirmModal.show();
	}

	$('#btn-confirm-ok').on('click', function () {
		_confirmModal.hide();
		if (_confirmCb) { _confirmCb(); _confirmCb = null; }
	});

	// ── Spinner ───────────────────────────────────────────────────────────────
	function setLoading($btn, loading) {
		$btn.prop('disabled', loading);
		$btn.find('.btn-label').toggle(!loading);
		$btn.find('#btn-save-spinner').toggleClass('d-none', !loading);
	}

	// ── Modal open: Add (reset) ───────────────────────────────────────────────
	$('#modal-user-form').on('show.bs.modal', function (e) {
		if (!e.relatedTarget || !$(e.relatedTarget).hasClass('js-edit-user')) {
			resetUserForm();
			$('#modal-user-form-title').text('Добавить пользователя');
			$('#btn-save-user .btn-label').text('Создать');
			$('#uf-login-row').show();
			$('#uf-pass-req').show();
			$('#uf-pass-hint').hide();
		}
	});

	// ── Modal: Edit ───────────────────────────────────────────────────────────
	$(document).on('click', '.js-edit-user', function () {
		var data = $(this).data('user');

		$('#modal-user-form-title').text('Редактировать: ' + data.user_login);
		$('#btn-save-user .btn-label').text('Сохранить');

		$('#uf-user-id').val(data.id);
		$('#uf-user-login').val(data.user_login);
		$('#uf-user-email').val(data.user_email);
		$('#uf-first-name').val(data.first_name);
		$('#uf-last-name').val(data.last_name);
		$('#uf-display-name').val(data.display_name);
		$('#uf-phone').val(data.phone || '');
		$('#uf-telegram-username').val(data.telegram_username || '');
		$('#uf-telegram-id').val(data.telegram_id || '');
		$('#uf-department').val(data.department || '');
		$('#uf-position-title').val(data.position_title || '');
		$('#uf-note').val(data.note || '');
		$('#uf-user-pass').val('');

		// Статус через Select2 (trigger change после init)
		$('#uf-status').val(data.crm_status || 'active');

		// CRM роли (multiple)
		if (CAN_ASSIGN_ROLES) {
			var roleIds = (data.role_ids || []).map(String);
			$('#uf-crm-roles').val(roleIds);
		}

		$('#uf-login-row').hide();
		$('#uf-pass-req').hide();
		$('#uf-pass-hint').show();
		$('#uf-error').addClass('d-none').text('');
	});

	function resetUserForm() {
		$('#form-user')[0].reset();
		$('#uf-user-id').val(0);
		$('#uf-error').addClass('d-none').text('');
		setLoading($('#btn-save-user'), false);
	}

	// ── Save User ─────────────────────────────────────────────────────────────
	$('#btn-save-user').on('click', function () {
		var $btn  = $(this);
		var $form = $('#form-user');
		var $err  = $('#uf-error');

		$err.addClass('d-none').text('');

		if ($form[0].checkValidity && !$form[0].checkValidity()) {
			$form[0].reportValidity();
			return;
		}

		var data = $form.serialize() + '&action=me_save_user&_nonce=' + NONCES.save;
		setLoading($btn, true);

		$.post(AJAX_URL, data)
			.done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					bootstrap.Modal.getInstance($('#modal-user-form')[0]).hide();
					setTimeout(function () { window.location.reload(); }, 800);
				} else {
					$err.removeClass('d-none').text(res.data.message || 'Ошибка.');
					setLoading($btn, false);
				}
			})
			.fail(function (xhr) {
				var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
					? xhr.responseJSON.data.message : 'Ошибка сервера.';
				$err.removeClass('d-none').text(msg);
				setLoading($btn, false);
			});
	});

	// ── Set Status ────────────────────────────────────────────────────────────
	$(document).on('click', '.js-set-status', function (e) {
		e.preventDefault();
		var $el         = $(this);
		var uid         = $el.data('user-id');
		var status      = $el.data('status');
		var confirm_msg = $el.data('confirm');

		function doSetStatus() {
			$.post(AJAX_URL, { action: 'me_set_user_status', user_id: uid, status: status, _nonce: NONCES.status })
			.done(function (res) {
				if (res.success) {
					var d = res.data;
					$('#status-badge-' + uid)
						.removeClass('badge-success badge-danger badge-secondary badge-warning badge-info badge-primary')
						.addClass('badge-' + d.badge_class)
						.text(d.label);
					showToast(d.message, 'success');
					var cur = '<?php echo esc_js( $f_status ); ?>';
					if (!cur || cur === 'active' || cur === 'blocked' || cur === 'pending') {
						if (d.status === 'archived') {
							setTimeout(function () {
								$('#urow-' + uid).fadeOut(300, function () { $(this).remove(); });
							}, 600);
						}
					}
				} else {
					showToast(res.data.message || 'Ошибка.', 'error');
				}
			})
			.fail(function () { showToast('Ошибка сервера.', 'error'); });
		}

		if (confirm_msg) {
			showConfirm(confirm_msg, doSetStatus);
		} else {
			doSetStatus();
		}
	});

	// ── Delete ────────────────────────────────────────────────────────────────
	$(document).on('click', '.js-delete-user', function (e) {
		e.preventDefault();
		var uid      = $(this).data('user-id');
		var username = $(this).data('username');
		var hard     = parseInt($(this).data('hard'), 10) === 1;

		if (hard) {
			showConfirm(
				'Физически удалить «' + username + '»? Это действие необратимо.',
				function () { doDelete(uid, true); },
				{ btnClass: 'btn-danger', btnText: 'Удалить навсегда' }
			);
		} else {
			showConfirm('Переместить «' + username + '» в архив?', function () { doDelete(uid, false); });
		}
	});

	function doDelete(uid, hard) {
		$.post(AJAX_URL, { action: 'me_delete_user', user_id: uid, hard: hard ? 1 : 0, _nonce: NONCES.delete })
		.done(function (res) {
			if (res.success) {
				showToast(res.data.message, 'success');
				$('#urow-' + uid).fadeOut(300, function () { $(this).remove(); });
			} else {
				showToast(res.data.message || 'Ошибка.', 'error');
			}
		})
		.fail(function () { showToast('Ошибка сервера.', 'error'); });
	}


	// ── Roles: permissions modal ──────────────────────────────────────────────
	var PERMS_GROUPED  = <?php echo wp_json_encode( $all_permissions_grouped ); ?>;
	var NONCE_ROLES    = '<?php echo esc_js( $nonce_roles ); ?>';
	var _editRoleId    = 0;
	var $roleModal     = $('#modal-role-perms');

	$(document).on('click', '.js-edit-role', function () {
		var role = $(this).data('role');
		_editRoleId = role.id;

		$('#modal-role-perms-title').text('Права роли: ' + role.name);

		var activeIds = (role.perm_ids || []).map(Number);
		var html = '';

		$.each(PERMS_GROUPED, function (module, perms) {
			html += '<div class="perm-module-title">' + module + '</div><div class="row">';
			$.each(perms, function (i, perm) {
				var pid     = parseInt(perm.id, 10);
				var checked = activeIds.indexOf(pid) !== -1 ? ' checked' : '';
				html += '<div class="col-md-6">' +
					'<label class="perm-check-label">' +
					'<input type="checkbox" class="perm-checkbox" value="' + pid + '"' + checked + '>' +
					'<span>' + perm.name + ' <small class="hint-text">(' + perm.code + ')</small></span>' +
					'</label></div>';
			});
			html += '</div>';
		});

		$('#modal-role-perms-body').html(html);
		$('#btn-save-role-perms').prop('disabled', false)
			.find('.btn-label').show()
			.end().find('#btn-role-perms-spinner').addClass('d-none');
	});

	$('#btn-save-role-perms').on('click', function () {
		if (!_editRoleId) return;

		var permIds = [];
		$('#modal-role-perms-body .perm-checkbox:checked').each(function () {
			permIds.push(parseInt($(this).val(), 10));
		});

		var $btn = $(this);
		$btn.prop('disabled', true).find('.btn-label').hide()
			.end().find('#btn-role-perms-spinner').removeClass('d-none');

		$.post(AJAX_URL, {
			action:         'me_save_role_permissions',
			role_id:        _editRoleId,
			permission_ids: permIds,
			_nonce:         NONCE_ROLES
		})
		.done(function (res) {
			if (res.success) {
				showToast(res.data.message, 'success');
				$('#rperm-count-' + _editRoleId).text(res.data.count);
				bootstrap.Modal.getInstance($roleModal[0]).hide();
			} else {
				showToast(res.data.message || 'Ошибка.', 'error');
				$btn.prop('disabled', false).find('.btn-label').show()
					.end().find('#btn-role-perms-spinner').addClass('d-none');
			}
		})
		.fail(function () {
			showToast('Ошибка сервера.', 'error');
			$btn.prop('disabled', false).find('.btn-label').show()
				.end().find('#btn-role-perms-spinner').addClass('d-none');
		});
	});

}(jQuery));
</script>
<?php
}, 99 );
?>

<?php
get_template_part( 'template-parts/quickview' );
get_template_part( 'template-parts/overlay' );
get_footer();
