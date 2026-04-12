<?php
/*
Template Name: Users Page
Slug: users
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

if ( ! function_exists( 'me_users_can_manage' ) || ! me_users_can_manage() ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

// ─── Параметры запроса ───────────────────────────────────────────────────────
// Все параметры используют префикс me_ чтобы не конфликтовать
// с зарезервированными WP query vars (s, role, status и др.)
$per_page    = 20;
$paged       = max( 1, (int) sanitize_text_field( $_GET['paged']   ?? '1' ) );
$s           = sanitize_text_field( $_GET['me_s']      ?? '' );
$f_role      = sanitize_key( $_GET['me_role']   ?? '' );
$f_status    = sanitize_key( $_GET['me_status'] ?? '' );

// ─── WP_User_Query ───────────────────────────────────────────────────────────
$query_args = [
	'number'      => $per_page,
	'offset'      => ( $paged - 1 ) * $per_page,
	'count_total' => true,
	'orderby'     => 'registered',
	'order'       => 'DESC',
];

if ( $s !== '' ) {
	$query_args['search']         = '*' . $s . '*';
	$query_args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
}

if ( $f_role !== '' ) {
	$query_args['role'] = $f_role;
}

// Фильтр по статусу через meta_query
if ( $f_status === 'active' ) {
	$query_args['meta_query'] = [
		'relation' => 'OR',
		[ 'key' => 'account_status', 'value' => 'active',  'compare' => '=' ],
		[ 'key' => 'account_status', 'compare' => 'NOT EXISTS' ],
	];
} elseif ( $f_status === 'blocked' ) {
	$query_args['meta_query'] = [
		[ 'key' => 'account_status', 'value' => 'blocked', 'compare' => '=' ],
	];
} elseif ( $f_status === 'archived' ) {
	$query_args['meta_query'] = [
		[ 'key' => 'account_status', 'value' => 'archived', 'compare' => '=' ],
	];
} elseif ( $f_status !== 'all' ) {
	// По умолчанию скрываем архивных
	$query_args['meta_query'] = [
		'relation' => 'OR',
		[ 'key' => 'account_status', 'value' => 'archived', 'compare' => '!=' ],
		[ 'key' => 'account_status', 'compare' => 'NOT EXISTS' ],
	];
}


$uq          = new WP_User_Query( $query_args );
$users       = $uq->get_results();
$total       = (int) $uq->get_total();
$total_pages = (int) ceil( $total / $per_page );

// ─── Данные для шаблона ──────────────────────────────────────────────────────
global $wp_roles;
$all_roles        = $wp_roles->roles;
$can_hard_delete  = me_users_can_hard_delete();
$current_uid      = get_current_user_id();
$page_url         = get_permalink();
$vendor_img_uri   = get_template_directory_uri() . '/vendor/pages/assets/img';

// Nonces — передаём в JS
$nonce_save   = wp_create_nonce( 'me_users_save' );
$nonce_status = wp_create_nonce( 'me_users_status' );
$nonce_delete = wp_create_nonce( 'me_users_delete' );
$nonce_admin  = wp_create_nonce( 'me_users_admin' );

get_header();
?>

<!-- BEGIN SIDEBAR-->
<?php get_template_part( 'template-parts/sidebar' ); ?>
<!-- END SIDEBAR -->

<!-- START PAGE-CONTAINER -->
<div class="page-container">

	<!-- START HEADER -->
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

	<!-- START PAGE CONTENT WRAPPER -->
	<div class="page-content-wrapper">
		<div class="content">

			<!-- JUMBOTRON / BREADCRUMB -->
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

			<!-- MAIN CONTENT -->
			<div class="container-fluid container-fixed-lg mt-4">

				<!-- Page heading -->
				<div class="d-flex align-items-center justify-content-between m-b-20">
					<div>
						<p class="hint-text m-b-0">
							Всего в выборке: <strong><?php echo (int) $total; ?></strong>
						</p>
					</div>
					<button type="button" class="btn btn-primary btn-cons"
					        data-bs-toggle="modal" data-bs-target="#modal-user-form">
						<i class="pg-icon">user_add</i>&nbsp; Добавить пользователя
					</button>
				</div>

				<!-- ─── FILTERS ──────────────────────────────────────────────── -->
				<div class="card card-default m-b-20">
					<div class="card-body">
						<form method="get" action="<?php echo esc_url( $page_url ); ?>" id="users-filter-form">
							<div class="row">
								<div class="col-md-4 m-b-10">
									<div class="input-group">
										<span class="input-group-text"><i class="pg-icon">search</i></span>
										<input type="text" class="form-control"
										       name="me_s" value="<?php echo esc_attr( $s ); ?>"
										       placeholder="Поиск по логину, email, имени…">
									</div>
								</div>
								<div class="col-md-2 m-b-10">
									<select class="full-width" name="me_role" data-init-plugin="select2">
										<option value="">Все роли</option>
										<?php foreach ( $all_roles as $slug => $role_data ) : ?>
											<option value="<?php echo esc_attr( $slug ); ?>"
											        <?php selected( $f_role, $slug ); ?>>
												<?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-md-2 m-b-10">
									<select class="full-width" name="me_status" data-init-plugin="select2">
										<option value=""   <?php selected( $f_status, '' ); ?>>Активные (без архивных)</option>
										<option value="active"   <?php selected( $f_status, 'active' ); ?>>Только активные</option>
										<option value="blocked"  <?php selected( $f_status, 'blocked' ); ?>>Заблокированные</option>
										<option value="archived" <?php selected( $f_status, 'archived' ); ?>>Архивные</option>
										<option value="all"      <?php selected( $f_status, 'all' ); ?>>Все пользователи</option>
									</select>
								</div>
							</div>
							<div class="row">
								<div class="col-12">
									<button type="submit" class="btn btn-primary btn-sm">
										<i class="pg-icon">search</i>&nbsp; Найти
									</button>
									<a href="<?php echo esc_url( $page_url ); ?>"
									   class="btn btn-default btn-sm m-l-5">
										<i class="pg-icon">close</i>&nbsp; Сбросить
									</a>
								</div>
							</div>
						</form>
					</div>
				</div>

				<!-- ─── USERS TABLE ──────────────────────────────────────────── -->
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
											<th style="width:55px">ID</th>
											<th>Пользователь</th>
											<th>Email</th>
											<th>Display Name</th>
											<th style="width:120px">Роль</th>
											<th style="width:100px">Статус</th>
											<th style="width:110px">Зарегистрирован</th>
											<th style="width:120px">Последний вход</th>
											<th style="width:70px"></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $users as $user ) :
											$status        = me_users_get_status( $user->ID );
											$last_login    = me_users_get_last_login( $user->ID );
											$primary_role  = me_users_get_primary_role( $user );
											$is_admin      = in_array( 'administrator', (array) $user->roles, true );
											$is_manager    = (bool) get_user_meta( $user->ID, 'me_user_manager', true );
											$is_super      = $user->ID === 1;
											$notes         = (string) get_user_meta( $user->ID, 'me_notes', true );
											$status_class  = me_users_status_badge_class( $status );
											$role_class    = me_users_role_badge_class( $primary_role );
											$avatar_letter = me_users_avatar_letter( $user );
											$role_label    = $all_roles[ $primary_role ]['name'] ?? $primary_role;

											// JSON для модалки редактирования
											$user_json = esc_attr( wp_json_encode( [
												'id'             => $user->ID,
												'user_login'     => $user->user_login,
												'user_email'     => $user->user_email,
												'first_name'     => $user->first_name,
												'last_name'      => $user->last_name,
												'display_name'   => $user->display_name,
												'role'           => $primary_role,
												'account_status' => $status,
												'me_notes'       => $notes,
											] ) );
										?>
										<tr id="urow-<?php echo (int) $user->ID; ?>"
										    data-user-id="<?php echo (int) $user->ID; ?>">

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
														<?php elseif ( $is_manager ) : ?>
															<span class="badge badge-info m-l-5" title="Менеджер">M</span>
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
												<span class="badge badge-<?php echo esc_attr( $role_class ); ?>">
													<?php echo esc_html( translate_user_role( $role_label ) ?: '—' ); ?>
												</span>
											</td>

											<td class="v-align-middle">
												<span class="badge badge-<?php echo esc_attr( $status_class ); ?>"
												      id="status-badge-<?php echo (int) $user->ID; ?>">
													<?php echo esc_html( ucfirst( $status ) ); ?>
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

														<li><hr class="dropdown-divider"></li>

														<!-- Block / Unblock / Restore -->
														<?php if ( $status === ME_USER_STATUS_BLOCKED ) : ?>
															<li>
																<a class="dropdown-item js-set-status text-success" href="#"
																   data-user-id="<?php echo (int) $user->ID; ?>"
																   data-status="active">
																	<i class="pg-icon m-r-5">tick_circle</i> Разблокировать
																</a>
															</li>
														<?php elseif ( $status === ME_USER_STATUS_ARCHIVED ) : ?>
															<li>
																<a class="dropdown-item js-set-status text-success" href="#"
																   data-user-id="<?php echo (int) $user->ID; ?>"
																   data-status="active">
																	<i class="pg-icon m-r-5">undo</i> Восстановить
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
														<?php if ( $status !== ME_USER_STATUS_ARCHIVED ) : ?>
															<li>
																<a class="dropdown-item js-set-status" href="#"
																   data-user-id="<?php echo (int) $user->ID; ?>"
																   data-status="archived"
																   data-confirm="Архивировать пользователя «<?php echo esc_attr( $user->user_login ); ?>»? Вход будет запрещён.">
																	<i class="pg-icon m-r-5">download</i> Архивировать
																</a>
															</li>
														<?php endif; ?>

														<?php if ( ! $is_super ) : ?>
														<li><hr class="dropdown-divider"></li>
														<?php endif; ?>

														<!-- Make / Remove Admin -->
														<?php if ( ! $is_admin ) : ?>
															<li>
																<a class="dropdown-item js-toggle-admin" href="#"
																   data-user-id="<?php echo (int) $user->ID; ?>"
																   data-action-type="make">
																	<i class="pg-icon m-r-5">shield_lock</i> Сделать Admin
																</a>
															</li>
														<?php elseif ( ! $is_super ) : ?>
															<li>
																<a class="dropdown-item js-toggle-admin text-warning" href="#"
																   data-user-id="<?php echo (int) $user->ID; ?>"
																   data-action-type="remove">
																	<i class="pg-icon m-r-5">shield_lock</i> Убрать Admin
																</a>
															</li>
														<?php endif; ?>

														<!-- Grant / Revoke Manager (only super admin sees this) -->
														<?php if ( $can_hard_delete && ! $is_super ) : ?>
															<?php if ( $is_manager ) : ?>
																<li>
																	<a class="dropdown-item js-toggle-manager" href="#"
																	   data-user-id="<?php echo (int) $user->ID; ?>"
																	   data-action-type="revoke">
																		<i class="pg-icon m-r-5">lock</i> Отозвать права менеджера
																	</a>
																</li>
															<?php else : ?>
																<li>
																	<a class="dropdown-item js-toggle-manager" href="#"
																	   data-user-id="<?php echo (int) $user->ID; ?>"
																	   data-action-type="grant">
																		<i class="pg-icon m-r-5">unlock</i> Дать права менеджера
																	</a>
																</li>
															<?php endif; ?>
														<?php endif; ?>

														<?php if ( $user->ID !== $current_uid && ! $is_super ) : ?>
														<li><hr class="dropdown-divider"></li>
														<?php endif; ?>

														<!-- Delete (soft = archive if not already) -->
														<?php if ( $user->ID !== $current_uid && ! $is_super ) : ?>
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
														<?php endif; ?>

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
											<a class="page-link"
											   href="<?php echo esc_url( add_query_arg( array_merge( $_GET, [ 'paged' => $paged - 1 ] ), $page_url ) ); ?>">
												&laquo;
											</a>
										</li>
									<?php endif; ?>

									<?php
									$range = 2;
									for ( $i = max( 1, $paged - $range ); $i <= min( $total_pages, $paged + $range ); $i++ ) :
										$pg_url = add_query_arg( array_merge( $_GET, [ 'paged' => $i ] ), $page_url );
									?>
										<li class="page-item <?php echo $i === $paged ? 'active' : ''; ?>">
											<a class="page-link" href="<?php echo esc_url( $pg_url ); ?>"><?php echo (int) $i; ?></a>
										</li>
									<?php endfor; ?>

									<?php if ( $paged < $total_pages ) : ?>
										<li class="page-item">
											<a class="page-link"
											   href="<?php echo esc_url( add_query_arg( array_merge( $_GET, [ 'paged' => $paged + 1 ] ), $page_url ) ); ?>">
												&raquo;
											</a>
										</li>
									<?php endif; ?>
								</ul>
							</nav>
						</div>
					<?php endif; ?>

				</div><!-- /.card -->

			</div><!-- /.container-fluid -->
		</div><!-- /.content -->

		<!-- Footer -->
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
<!-- END PAGE-CONTAINER -->


<!-- ════════════════════════════════════════════════════════════════════════════
     TOAST CONTAINER
     ════════════════════════════════════════════════════════════════════════ -->
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

<!-- ════════════════════════════════════════════════════════════════════════════
     MODAL: ADD / EDIT USER
     ════════════════════════════════════════════════════════════════════════ -->
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
								<input type="text" class="form-control" name="user_login"
								       id="uf-user-login" autocomplete="off" required>
							</div>
						</div>

						<!-- Email -->
						<div class="col-md-6">
							<div class="form-group form-group-default required">
								<label>Email <span class="text-danger">*</span></label>
								<input type="email" class="form-control" name="user_email"
								       id="uf-user-email" required>
							</div>
						</div>

						<!-- Password -->
						<div class="col-md-6">
							<div class="form-group form-group-default" id="uf-pass-group">
								<label>Пароль <span class="text-danger" id="uf-pass-req">*</span>
									<small class="hint-text" id="uf-pass-hint" style="display:none">
										(пустое поле сохраняет текущий пароль)
									</small>
								</label>
								<input type="password" class="form-control" name="user_pass"
								       id="uf-user-pass" autocomplete="new-password">
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

						<!-- w-100 принудительно начинает новую Bootstrap-строку,
						     гарантируя что Роль и Статус всегда идут парой -->
						<div class="w-100"></div>

						<!-- Сепаратор: .b-b.b-grey — стандартный разделитель Pages -->
						<div class="col-12 m-t-5 m-b-10">
							<div class="b-b b-grey"></div>
						</div>

						<!-- Роль + Статус — гарантировано одна строка -->
						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Роль</label>
								<select class="full-width" name="role" id="uf-role">
									<?php foreach ( $all_roles as $slug => $role_data ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>"
										        <?php echo $slug === 'subscriber' ? 'selected' : ''; ?>>
											<?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Статус</label>
								<select class="full-width" name="account_status" id="uf-status">
									<option value="active">Active</option>
									<option value="blocked">Blocked</option>
								</select>
							</div>
						</div>

						<!-- Notes — необязательное поле, всегда в самом низу -->
						<div class="w-100"></div>
						<div class="col-12 m-t-5 m-b-10">
							<div class="b-b b-grey"></div>
						</div>
						<div class="col-md-12">
							<div class="form-group form-group-default">
								<label>Служебные заметки</label>
								<textarea class="form-control" name="me_notes" id="uf-notes"
								          rows="2" style="resize:vertical"></textarea>
							</div>
						</div>
					</div>

					<!-- Error message -->
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

<!-- ════════════════════════════════════════════════════════════════════════════
     MODAL: CONFIRM DELETE (жёсткое удаление)
     ════════════════════════════════════════════════════════════════════════ -->
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

<!-- ════════════════════════════════════════════════════════════════════════════
     INLINE STYLES
     ════════════════════════════════════════════════════════════════════════ -->
<style>
.me-avatar {
	width: 32px; height: 32px;
	border-radius: 50%;
	display: grid; place-items: center;
	background: linear-gradient(135deg, #1dd3b0, #4cc9f0);
	color: #032b3a;
	font-weight: 700;
	font-size: 13px;
	flex-shrink: 0;
}
.badge {
	font-size: 11px;
	font-weight: 600;
	padding: 3px 8px;
	border-radius: 20px;
}
.badge-success  { background: rgba(29,211,176,.18); color: #0d9e82; border: 1px solid rgba(29,211,176,.3); }
.badge-danger   { background: rgba(255,107,107,.18); color: #e0302e; border: 1px solid rgba(255,107,107,.3); }
.badge-warning  { background: rgba(255,183,3,.18); color: #b8830a; border: 1px solid rgba(255,183,3,.3); }
.badge-info     { background: rgba(76,201,240,.18); color: #1579a8; border: 1px solid rgba(76,201,240,.3); }
.badge-primary  { background: rgba(90,128,255,.18); color: #3f5fcc; border: 1px solid rgba(90,128,255,.3); }
.badge-secondary{ background: rgba(120,120,140,.15); color: #888; border: 1px solid rgba(120,120,140,.2); }
.table th { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; }
.table td { font-size: 13px; }
.btn-xs { padding: 3px 8px; font-size: 12px; }
.toast { background: #1c2b3a; color: #f7fbff; }
.toast.toast-success { border-left: 3px solid #1dd3b0; }
.toast.toast-error   { border-left: 3px solid #ff6b6b; }
.pg-icon.spin { animation: spin 1s linear infinite; display: inline-block; }
@keyframes spin { to { transform: rotate(360deg); } }
.dropdown-menu .dropdown-item { display: flex; align-items: center; }
.dropdown-menu .dropdown-item .pg-icon { flex-shrink: 0; line-height: 1; }
</style>

<?php
// Скрипт добавляем через wp_footer (priority 99) — после того как jQuery уже загружен.
add_action( 'wp_footer', function () use ( $nonce_save, $nonce_status, $nonce_delete, $nonce_admin, $f_status ) {
?>
<script>
(function ($) {
	'use strict';

	// ─── Config ───────────────────────────────────────────────────────────────
	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCES = {
		save:   '<?php echo esc_js( $nonce_save ); ?>',
		status: '<?php echo esc_js( $nonce_status ); ?>',
		delete: '<?php echo esc_js( $nonce_delete ); ?>',
		admin:  '<?php echo esc_js( $nonce_admin ); ?>'
	};

	// ─── Дропдауны Actions в таблице: strategy fixed (fix overflow clipping) ───
	// .table-responsive обрезает абсолютные элементы, поэтому используем fixed.
	$('#users-table .dropdown-toggle').each(function () {
		new bootstrap.Dropdown(this, {
			popperConfig: { strategy: 'fixed' }
		});
	});

	// ─── Select2 в модалке (dropdownParent чтобы дропдаун был поверх overlay) ──
	var $modal = $('#modal-user-form');
	$modal.on('shown.bs.modal', function () {
		$('#uf-role, #uf-status').each(function () {
			if ( !$(this).hasClass('select2-hidden-accessible') ) {
				$(this).select2({ dropdownParent: $modal });
			}
		});
	});
	$modal.on('hidden.bs.modal', function () {
		$('#uf-role, #uf-status').each(function () {
			if ( $(this).hasClass('select2-hidden-accessible') ) {
				$(this).select2('destroy');
			}
		});
	});

	// ─── Toast ────────────────────────────────────────────────────────────────
	function showToast(message, type) {
		var $el = $('#me-toast');
		$el.removeClass('toast-success toast-error');
		$el.addClass(type === 'error' ? 'toast-error' : 'toast-success');
		$('#me-toast-body').text(message);
		var toast = new bootstrap.Toast($el[0], { delay: 4000 });
		toast.show();
	}

	// ─── Универсальный модал-подтверждение ────────────────────────────────────
	var _confirmCb  = null;
	var _confirmModal = new bootstrap.Modal($('#modal-confirm')[0]);

	function showConfirm(message, callback, opts) {
		opts = opts || {};
		$('#confirm-modal-message').text(message);
		$('#btn-confirm-ok')
			.removeClass('btn-primary btn-danger btn-warning')
			.addClass(opts.btnClass || 'btn-primary')
			.text(opts.btnText   || 'Подтвердить');
		_confirmCb = callback;
		_confirmModal.show();
	}

	$('#btn-confirm-ok').on('click', function () {
		_confirmModal.hide();
		if (_confirmCb) { _confirmCb(); _confirmCb = null; }
	});

	// ─── Spinner helper ───────────────────────────────────────────────────────
	function setLoading($btn, loading) {
		$btn.prop('disabled', loading);
		$btn.find('.btn-label').toggle(!loading);
		$btn.find('#btn-save-spinner').toggleClass('d-none', !loading);
	}

	// ─── Modal: Add User (reset form) ─────────────────────────────────────────
	$('#modal-user-form').on('show.bs.modal', function (e) {
		// If triggered via "Add" button (not Edit), reset form
		if (!e.relatedTarget || !$(e.relatedTarget).hasClass('js-edit-user')) {
			resetUserForm();
			$('#modal-user-form-title').text('Добавить пользователя');
			$('#btn-save-user .btn-label').text('Создать');
			$('#uf-login-row').show();
			$('#uf-pass-req').show();
			$('#uf-pass-hint').hide();
			$('#uf-user-pass').attr('required', true);
		}
	});

	// ─── Modal: Edit User ─────────────────────────────────────────────────────
	$(document).on('click', '.js-edit-user', function () {
		// jQuery автоматически парсит JSON из data-атрибутов — не вызываем JSON.parse повторно
		var data = $(this).data('user');
		$('#modal-user-form-title').text('Редактировать: ' + data.user_login);
		$('#btn-save-user .btn-label').text('Сохранить');

		// Populate
		$('#uf-user-id').val(data.id);
		$('#uf-user-login').val(data.user_login);
		$('#uf-user-email').val(data.user_email);
		$('#uf-first-name').val(data.first_name);
		$('#uf-last-name').val(data.last_name);
		$('#uf-display-name').val(data.display_name);
		$('#uf-role').val(data.role).trigger('change');
		$('#uf-status').val(data.account_status).trigger('change');
		$('#uf-notes').val(data.me_notes);
		$('#uf-user-pass').val('').removeAttr('required');

		// Hide username (can't change), show password hint
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

	// ─── Save User (Add / Edit) ───────────────────────────────────────────────
	$('#btn-save-user').on('click', function () {
		var $btn = $(this);
		var $form = $('#form-user');
		var $err  = $('#uf-error');

		$err.addClass('d-none').text('');

		// Basic HTML5 validation
		if ($form[0].checkValidity && !$form[0].checkValidity()) {
			$form[0].reportValidity();
			return;
		}

		var data = $form.serialize();
		data += '&action=me_save_user&_nonce=' + NONCES.save;

		setLoading($btn, true);

		$.post(AJAX_URL, data)
			.done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					bootstrap.Modal.getInstance($('#modal-user-form')[0]).hide();
					// Reload to reflect changes
					setTimeout(function () { window.location.reload(); }, 800);
				} else {
					$err.removeClass('d-none').text(res.data.message || 'Ошибка.');
					setLoading($btn, false);
				}
			})
			.fail(function () {
				$err.removeClass('d-none').text('Ошибка сервера. Повторите попытку.');
				setLoading($btn, false);
			});
	});

	// ─── Set Status ───────────────────────────────────────────────────────────
	$(document).on('click', '.js-set-status', function (e) {
		e.preventDefault();
		var $el         = $(this);
		var uid         = $el.data('user-id');
		var status      = $el.data('status');
		var confirm_msg = $el.data('confirm');

		function doSetStatus() {
			$.post(AJAX_URL, {
				action:  'me_set_user_status',
				user_id: uid,
				status:  status,
				_nonce:  NONCES.status
			})
			.done(function (res) {
				if (res.success) {
					var d = res.data;
					var $badge = $('#status-badge-' + uid);
					$badge
						.removeClass('badge-success badge-danger badge-secondary badge-warning badge-info badge-primary')
						.addClass('badge-' + d.badge_class)
						.text(d.label);
					showToast(d.message, 'success');
					var currentStatus = '<?php echo esc_js( $f_status ); ?>';
					if (!currentStatus || currentStatus === 'active' || currentStatus === 'blocked') {
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

	// ─── Delete ───────────────────────────────────────────────────────────────
	$(document).on('click', '.js-delete-user', function (e) {
		e.preventDefault();
		var $el      = $(this);
		var uid      = $el.data('user-id');
		var username = $el.data('username');
		var hard     = parseInt($el.data('hard'), 10) === 1;

		if (hard) {
			showConfirm(
				'Физически удалить пользователя «' + username + '»? Это действие необратимо.',
				function () { doDelete(uid, true); },
				{ btnClass: 'btn-danger', btnText: 'Удалить навсегда' }
			);
		} else {
			showConfirm(
				'Переместить «' + username + '» в архив?',
				function () { doDelete(uid, false); }
			);
		}
	});

	function doDelete(uid, hard) {
		$.post(AJAX_URL, {
			action:  'me_delete_user',
			user_id: uid,
			hard:    hard ? 1 : 0,
			_nonce:  NONCES.delete
		})
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

	// ─── Toggle Admin Role ────────────────────────────────────────────────────
	$(document).on('click', '.js-toggle-admin', function (e) {
		e.preventDefault();
		var $el = $(this);
		var uid = $el.data('user-id');
		var act = $el.data('action-type');
		var msg = act === 'make' ? 'Назначить администратором?' : 'Снять роль администратора?';

		showConfirm(msg, function () {
			$.post(AJAX_URL, {
				action:      'me_toggle_admin_role',
				action_type: act,
				user_id:     uid,
				_nonce:      NONCES.admin
			})
			.done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					setTimeout(function () { window.location.reload(); }, 800);
				} else {
					showToast(res.data.message || 'Ошибка.', 'error');
				}
			})
			.fail(function () { showToast('Ошибка сервера.', 'error'); });
		});
	});

	// ─── Toggle Manager Rights ────────────────────────────────────────────────
	$(document).on('click', '.js-toggle-manager', function (e) {
		e.preventDefault();
		var $el = $(this);
		var uid = $el.data('user-id');
		var act = $el.data('action-type');
		var msg = act === 'grant' ? 'Выдать права менеджера пользователей?' : 'Отозвать права менеджера?';

		showConfirm(msg, function () {
			$.post(AJAX_URL, {
				action:      'me_toggle_user_manager',
				action_type: act,
				user_id:     uid,
				_nonce:      NONCES.admin
			})
			.done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					setTimeout(function () { window.location.reload(); }, 800);
				} else {
					showToast(res.data.message || 'Ошибка.', 'error');
				}
			})
			.fail(function () { showToast('Ошибка сервера.', 'error'); });
		});
	});

}(jQuery));
</script>
<?php
}, 99 );
?>

<?php
// Quickview + Overlay
get_template_part( 'template-parts/quickview' );
get_template_part( 'template-parts/overlay' );

get_footer();
