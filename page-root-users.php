<?php
/*
Template Name: Root Users Page
Slug: root-users
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

global $wpdb;

$per_page = 20;
$paged    = max( 1, (int) sanitize_text_field( $_GET['paged'] ?? '1' ) );
$s        = sanitize_text_field( $_GET['me_s'] ?? '' );
$f_role   = sanitize_key( $_GET['me_role'] ?? '' );
$f_status = sanitize_key( $_GET['me_status'] ?? '' );

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
	$role_user_ids = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT DISTINCT ur.user_id
			 FROM crm_user_roles ur
			 JOIN crm_roles r ON r.id = ur.role_id
			 WHERE r.code = %s',
			$f_role
		)
	);
	$query_args['include'] = ! empty( $role_user_ids ) ? array_map( 'intval', $role_user_ids ) : [ -1 ];
}

if ( $f_status === '' ) {
	$exclude = $wpdb->get_col( "SELECT user_id FROM crm_user_accounts WHERE status = 'archived'" );
	if ( ! empty( $exclude ) ) {
		$query_args['exclude'] = array_map( 'intval', $exclude );
	}
} elseif ( $f_status === 'active' ) {
	$exclude = $wpdb->get_col( "SELECT user_id FROM crm_user_accounts WHERE status != 'active'" );
	if ( ! empty( $exclude ) ) {
		$query_args['exclude'] = array_map( 'intval', $exclude );
	}
} elseif ( $f_status !== 'all' ) {
	$include = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT user_id FROM crm_user_accounts WHERE status = %s',
			$f_status
		)
	);
	$query_args['include'] = ! empty( $include ) ? array_map( 'intval', $include ) : [ -1 ];
}

if ( isset( $query_args['include'] ) ) {
	$query_args['include'] = array_values(
		array_filter(
			$query_args['include'],
			static fn( $id ) => ! crm_is_root( (int) $id )
		)
	);
	if ( empty( $query_args['include'] ) ) {
		$query_args['include'] = [ -1 ];
	}
} else {
	$query_args['exclude'] = array_unique( array_merge( $query_args['exclude'] ?? [], [ 1 ] ) );
}

$uq          = new WP_User_Query( $query_args );
$users       = array_values(
	array_filter(
		$uq->get_results(),
		static fn( $user ) => $user instanceof WP_User && ! crm_is_root( (int) $user->ID )
	)
);
$total       = (int) $uq->get_total();
$total_pages = (int) ceil( $total / $per_page );

$user_ids          = array_map( static fn( $user ) => (int) $user->ID, $users );
$crm_accounts      = crm_get_accounts_for_users( $user_ids );
$crm_roles_map     = crm_get_roles_for_users( $user_ids );
$all_crm_roles     = crm_get_all_roles();
$crm_companies_map = crm_get_companies_for_users( $user_ids );
$all_companies     = crm_get_all_companies_list();

$can_assign_roles = crm_user_has_permission( $current_uid, 'users.assign_roles' );
$can_hard_delete  = me_users_can_hard_delete();
$page_url         = get_permalink();

$nonce_save    = wp_create_nonce( 'me_users_save' );
$nonce_status  = wp_create_nonce( 'me_users_status' );
$nonce_delete  = wp_create_nonce( 'me_users_delete' );
$nonce_company = wp_create_nonce( 'me_assign_user_company' );

get_template_part(
	'template-parts/root-page-start',
	null,
	[
		'title'       => 'Пользователи',
		'description' => 'Root-only экран всех CRM-пользователей по компаниям, без смешивания с компаниями, офисами и ролями в одном tab bar.',
		'breadcrumbs' => [
			[
				'label'  => 'Пользователи',
				'url'    => '',
				'active' => true,
			],
		],
	]
);
?>

<div class="card card-default users-filter-card m-b-20">
	<div class="card-body p-t-15 p-b-15">
		<form method="get" action="<?php echo esc_url( $page_url ); ?>" id="users-filter-form">
			<div class="row g-2 align-items-center">
				<div class="col-12 col-lg-6">
					<div class="input-group">
						<span class="input-group-text"><i class="pg-icon">search</i></span>
						<input type="text" class="form-control"
						       name="me_s" value="<?php echo esc_attr( $s ); ?>"
						       placeholder="Поиск по логину, email, имени…">
					</div>
				</div>
				<div class="col-12 col-lg-6">
					<div class="d-flex flex-column flex-sm-row gap-2">
						<select class="full-width" name="me_role" data-init-plugin="select2">
							<option value="">Все роли</option>
							<?php foreach ( $all_crm_roles as $crm_role ) : ?>
								<option value="<?php echo esc_attr( $crm_role->code ); ?>" <?php selected( $f_role, $crm_role->code ); ?>>
									<?php echo esc_html( $crm_role->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<select class="full-width" name="me_status" data-init-plugin="select2">
							<option value="" <?php selected( $f_status, '' ); ?>>Активные (без архивных)</option>
							<option value="active" <?php selected( $f_status, 'active' ); ?>>Только активные</option>
							<option value="blocked" <?php selected( $f_status, 'blocked' ); ?>>Заблокированные</option>
							<option value="pending" <?php selected( $f_status, 'pending' ); ?>>Ожидающие</option>
							<option value="archived" <?php selected( $f_status, 'archived' ); ?>>Архивные</option>
							<option value="all" <?php selected( $f_status, 'all' ); ?>>Все</option>
						</select>
						<div class="d-flex gap-2 users-filter-buttons flex-shrink-0">
							<button type="submit" class="btn btn-primary btn-sm flex-fill flex-sm-grow-0">
								<i class="pg-icon">search</i>
							</button>
							<a href="<?php echo esc_url( $page_url ); ?>" class="btn btn-default btn-sm flex-fill flex-sm-grow-0">
								<i class="pg-icon">close</i>
							</a>
						</div>
					</div>
				</div>
			</div>
		</form>
	</div>
</div>

<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 m-b-10">
	<p class="hint-text m-b-0">Всего в выборке: <strong><?php echo (int) $total; ?></strong></p>
	<button type="button" class="btn btn-primary btn-cons"
	        data-bs-toggle="modal" data-bs-target="#modal-user-form">
		<i class="pg-icon">user_add</i>&nbsp; Добавить пользователя
	</button>
</div>

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
							<th style="width:120px">Компания</th>
							<th style="width:95px">Статус</th>
							<th style="width:120px">Телефон</th>
							<th style="width:130px">Telegram</th>
							<th style="width:100px">Зарегистрирован</th>
							<th style="width:110px">Последний вход</th>
							<th style="width:60px"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $users as $user ) : ?>
							<?php
							$acc           = $crm_accounts[ $user->ID ] ?? null;
							$user_roles    = $crm_roles_map[ $user->ID ] ?? [];
							$crm_status    = $acc ? $acc->status : CRM_STATUS_ACTIVE;
							$status_class  = crm_status_badge_class( $crm_status );
							$last_login    = me_users_get_last_login( $user->ID );
							$avatar_letter = me_users_avatar_letter( $user );
							$user_company  = $crm_companies_map[ $user->ID ] ?? null;

							$user_json = esc_attr(
								wp_json_encode(
									[
										'id'                => $user->ID,
										'user_login'        => $user->user_login,
										'user_email'        => $user->user_email,
										'first_name'        => $user->first_name,
										'last_name'         => $user->last_name,
										'display_name'      => $user->display_name,
										'crm_status'        => $crm_status,
										'phone'             => $acc ? ( $acc->phone ?? '' ) : '',
										'telegram_username' => $acc ? ( $acc->telegram_username ?? '' ) : '',
										'telegram_id'       => $acc ? ( $acc->telegram_id ?? '' ) : '',
										'department'        => $acc ? ( $acc->department ?? '' ) : '',
										'position_title'    => $acc ? ( $acc->position_title ?? '' ) : '',
										'note'              => $acc ? ( $acc->note ?? '' ) : '',
										'role_ids'          => array_map( static fn( $role ) => (int) $role->id, $user_roles ),
										'company_id'        => $user_company ? (int) $user_company->id : 0,
										'company_name'      => $user_company ? $user_company->name : '',
									]
								)
							);
							?>
							<tr id="urow-<?php echo (int) $user->ID; ?>">
								<td class="v-align-middle">
									<span class="hint-text fs-12">#<?php echo (int) $user->ID; ?></span>
								</td>
								<td class="v-align-middle">
									<div class="d-flex align-items-center">
										<div class="me-avatar m-r-10" aria-hidden="true"><?php echo esc_html( $avatar_letter ); ?></div>
										<div>
											<span class="semi-bold"><?php echo esc_html( $user->user_login ); ?></span>
										</div>
									</div>
								</td>
								<td class="v-align-middle">
									<a href="mailto:<?php echo esc_attr( $user->user_email ); ?>">
										<?php echo esc_html( $user->user_email ); ?>
									</a>
								</td>
								<td class="v-align-middle"><?php echo esc_html( $user->display_name ?: '—' ); ?></td>
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
								<td class="v-align-middle" id="company-cell-<?php echo (int) $user->ID; ?>">
									<?php if ( $user_company ) : ?>
										<span class="badge badge-info"><?php echo esc_html( $user_company->name ); ?></span>
									<?php else : ?>
										<span class="hint-text">—</span>
									<?php endif; ?>
								</td>
								<td class="v-align-middle">
									<span class="badge badge-<?php echo esc_attr( $status_class ); ?>" id="status-badge-<?php echo (int) $user->ID; ?>">
										<?php echo esc_html( crm_status_label( $crm_status ) ); ?>
									</span>
								</td>
								<td class="v-align-middle"><span class="hint-text fs-12"><?php echo $acc && $acc->phone ? esc_html( $acc->phone ) : '—'; ?></span></td>
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
								<td class="v-align-middle"><span class="hint-text fs-12"><?php echo esc_html( wp_date( 'd.m.Y', strtotime( $user->user_registered ) ) ); ?></span></td>
								<td class="v-align-middle">
									<span class="hint-text fs-12">
										<?php echo $last_login ? esc_html( wp_date( 'd.m.Y H:i', strtotime( $last_login ) ) ) : '—'; ?>
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
											<li>
												<a class="dropdown-item" href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>" target="_blank" rel="noopener">
													<i class="pg-icon m-r-5">see</i> Просмотр (WP)
												</a>
											</li>
											<li>
												<a class="dropdown-item js-edit-user" href="#"
												   data-user="<?php echo $user_json; ?>"
												   data-bs-toggle="modal"
												   data-bs-target="#modal-user-form">
													<i class="pg-icon m-r-5">edit</i> Редактировать
												</a>
											</li>
											<li>
												<a class="dropdown-item js-assign-company" href="#"
												   data-user-id="<?php echo (int) $user->ID; ?>"
												   data-user-login="<?php echo esc_attr( $user->user_login ); ?>"
												   data-company-id="<?php echo (int) ( $user_company ? $user_company->id : 0 ); ?>"
												   data-bs-toggle="modal"
												   data-bs-target="#modal-assign-company">
													<i class="pg-icon m-r-5">grid</i> Компания
												</a>
											</li>
											<?php if ( $user->ID !== $current_uid ) : ?>
												<li><hr class="dropdown-divider"></li>
												<?php if ( $crm_status === CRM_STATUS_BLOCKED ) : ?>
													<li>
														<a class="dropdown-item js-set-status text-success" href="#" data-user-id="<?php echo (int) $user->ID; ?>" data-status="active">
															<i class="pg-icon m-r-5">tick_circle</i> Разблокировать
														</a>
													</li>
												<?php elseif ( $crm_status === CRM_STATUS_ARCHIVED ) : ?>
													<li>
														<a class="dropdown-item js-set-status text-success" href="#" data-user-id="<?php echo (int) $user->ID; ?>" data-status="active">
															<i class="pg-icon m-r-5">undo</i> Восстановить
														</a>
													</li>
												<?php elseif ( $crm_status === CRM_STATUS_PENDING ) : ?>
													<li>
														<a class="dropdown-item js-set-status text-success" href="#" data-user-id="<?php echo (int) $user->ID; ?>" data-status="active">
															<i class="pg-icon m-r-5">tick_circle</i> Активировать
														</a>
													</li>
												<?php else : ?>
													<li>
														<a class="dropdown-item js-set-status text-warning" href="#" data-user-id="<?php echo (int) $user->ID; ?>" data-status="blocked">
															<i class="pg-icon m-r-5">disable</i> Заблокировать
														</a>
													</li>
												<?php endif; ?>
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
			</div>
		<?php endif; ?>
	</div>

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
</div>

<?php get_template_part( 'template-parts/toast-host' ); ?>

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
						<div class="col-md-6" id="uf-login-row">
							<div class="form-group form-group-default required">
								<label>Username <span class="text-danger">*</span></label>
								<input type="text" class="form-control" name="user_login" id="uf-user-login" autocomplete="off">
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group form-group-default required">
								<label>Email <span class="text-danger">*</span></label>
								<input type="email" class="form-control" name="user_email" id="uf-user-email">
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Пароль
									<span class="text-danger" id="uf-pass-req">*</span>
									<small class="hint-text" id="uf-pass-hint" style="display:none">(пустое поле сохраняет текущий пароль)</small>
								</label>
								<input type="password" class="form-control" name="user_pass" id="uf-user-pass" autocomplete="new-password">
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Имя</label>
								<input type="text" class="form-control" name="first_name" id="uf-first-name">
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Фамилия</label>
								<input type="text" class="form-control" name="last_name" id="uf-last-name">
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Display Name</label>
								<input type="text" class="form-control" name="display_name" id="uf-display-name">
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Телефон</label>
								<input type="text" class="form-control" name="phone" id="uf-phone" placeholder="+7 ...">
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Telegram Username</label>
								<input type="text" class="form-control" name="telegram_username" id="uf-telegram-username" placeholder="@username">
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Telegram ID</label>
								<input type="text" class="form-control" name="telegram_id" id="uf-telegram-id" placeholder="123456789">
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Отдел</label>
								<input type="text" class="form-control" name="department" id="uf-department">
							</div>
						</div>

						<div class="col-md-6">
							<div class="form-group form-group-default">
								<label>Должность</label>
								<input type="text" class="form-control" name="position_title" id="uf-position-title">
							</div>
						</div>

						<div class="w-100"></div>
						<div class="col-12 m-t-5 m-b-10"><div class="b-b b-grey"></div></div>

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

						<div class="col-md-6" id="uf-company-row">
							<div class="form-group form-group-default">
								<label>Компания</label>
								<select class="full-width" name="company_id" id="uf-company-id">
									<?php foreach ( $all_companies as $company ) : ?>
									<option value="<?php echo (int) $company->id; ?>">
										<?php echo esc_html( $company->name ); ?>
									</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>

						<div class="w-100"></div>
						<div class="col-12 m-t-5 m-b-10"><div class="b-b b-grey"></div></div>

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

<div class="modal fade" id="modal-confirm" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
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

<div class="modal fade" id="modal-assign-company" tabindex="-1"
     aria-labelledby="modal-assign-company-title" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header clearfix text-left">
				<button aria-label="Закрыть" type="button" class="close" data-bs-dismiss="modal" aria-hidden="true">
					<i class="pg-icon">close</i>
				</button>
				<h5 class="modal-title" id="modal-assign-company-title">Назначить компанию</h5>
			</div>
			<div class="modal-body">
				<p class="m-b-15">Пользователь: <strong id="ac-user-login">—</strong></p>
				<div class="form-group form-group-default">
					<label>Компания</label>
					<select class="full-width" id="ac-company-select">
						<?php foreach ( $all_companies as $company ) : ?>
						<option value="<?php echo (int) $company->id; ?>">
							<?php echo esc_html( $company->name ); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</div>
				<input type="hidden" id="ac-user-id" value="0">
				<div class="alert alert-danger d-none m-t-10" id="ac-error"></div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-bs-dismiss="modal">Отмена</button>
				<button type="button" class="btn btn-primary" id="btn-assign-company">
					<span class="btn-label">Назначить</span>
					<i class="pg-icon spin d-none" id="btn-assign-company-spinner">refresh</i>
				</button>
			</div>
		</div>
	</div>
</div>

<div class="modal fade" id="modal-credentials" tabindex="-1"
     aria-labelledby="modal-credentials-title" aria-hidden="true"
     data-bs-backdrop="static" data-bs-keyboard="false">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header clearfix text-left">
				<h5 class="modal-title" id="modal-credentials-title">Данные нового пользователя</h5>
			</div>
			<div class="modal-body">
				<p class="hint-text m-b-10">Передайте пользователю данные для входа:</p>
				<div class="alert alert-success">
					<p class="m-b-5"><strong>Логин:</strong> <span id="cred-login" class="semi-bold">—</span></p>
					<p class="m-b-0"><strong>Пароль:</strong> <span id="cred-password" class="semi-bold">—</span></p>
				</div>
				<p id="cred-company-info" class="hint-text fs-12 d-none">
					Компания: <strong id="cred-company-name">—</strong>
				</p>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-primary" id="btn-credentials-close">
					Готово — обновить страницу
				</button>
			</div>
		</div>
	</div>
</div>

<style>
.me-avatar {
	width:32px;
	height:32px;
	border-radius:50%;
	display:grid;
	place-items:center;
	background:linear-gradient(135deg,#1dd3b0,#4cc9f0);
	color:#032b3a;
	font-weight:700;
	font-size:13px;
	flex-shrink:0;
}
.badge { font-size:11px; font-weight:600; padding:3px 8px; border-radius:20px; }
.badge-success  { background:rgba(29,211,176,.18); color:#0d9e82; border:1px solid rgba(29,211,176,.3); }
.badge-danger   { background:rgba(255,107,107,.18); color:#e0302e; border:1px solid rgba(255,107,107,.3); }
.badge-warning  { background:rgba(255,183,3,.18); color:#b8830a; border:1px solid rgba(255,183,3,.3); }
.badge-info     { background:rgba(76,201,240,.18); color:#1579a8; border:1px solid rgba(76,201,240,.3); }
.badge-primary  { background:rgba(90,128,255,.18); color:#3f5fcc; border:1px solid rgba(90,128,255,.3); }
.badge-secondary{ background:rgba(120,120,140,.15); color:#888; border:1px solid rgba(120,120,140,.2); }
.m-r-2 { margin-right:2px; }
.table th { font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
.table td { font-size:13px; }
.btn-xs { padding:3px 8px; font-size:12px; }
.pg-icon.spin { animation:spin 1s linear infinite; display:inline-block; }
.dropdown-menu .dropdown-item { display:flex; align-items:center; }
.dropdown-menu .dropdown-item .pg-icon { flex-shrink:0; line-height:1; }
@media (max-width: 991px) {
	.users-filter-card > .card-body { padding-left:2px; padding-right:2px; }
	.users-filter-card .select2-container { width:100% !important; }
	.users-filter-card .users-filter-buttons { width:100%; }
}
@keyframes spin { to { transform:rotate(360deg); } }
</style>

<?php
add_action(
	'wp_footer',
	function () use ( $nonce_save, $nonce_status, $nonce_delete, $nonce_company, $can_assign_roles, $f_status ) {
		?>
<script>
(function ($) {
	'use strict';

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCES = {
		save: '<?php echo esc_js( $nonce_save ); ?>',
		status: '<?php echo esc_js( $nonce_status ); ?>',
		delete: '<?php echo esc_js( $nonce_delete ); ?>',
		company: '<?php echo esc_js( $nonce_company ); ?>'
	};
	var CAN_ASSIGN_ROLES = <?php echo $can_assign_roles ? 'true' : 'false'; ?>;

	function showToast(message, type) {
		if (window.MalibuToast && typeof window.MalibuToast.show === 'function') {
			window.MalibuToast.show(message, type || 'info');
			return;
		}
		if (window.console && console.warn) {
			console.warn(message);
		}
	}

	function initActionDropdowns($scope) {
		($scope || $(document)).find('.dropdown-toggle[data-bs-toggle="dropdown"]').each(function () {
			if (this.dataset.dropdownFixedReady === '1') {
				return;
			}
			new bootstrap.Dropdown(this, { popperConfig: { strategy: 'fixed' } });
			this.dataset.dropdownFixedReady = '1';
		});
	}

	function setLoading($btn, loading) {
		$btn.prop('disabled', loading);
		$btn.find('.btn-label').toggle(!loading);
		$btn.find('#btn-save-spinner').toggleClass('d-none', !loading);
	}

	initActionDropdowns($(document));

	var $userModal = $('#modal-user-form');
	$userModal.on('shown.bs.modal', function () {
		$('#uf-crm-roles, #uf-status, #uf-company-id').each(function () {
			if (!$(this).hasClass('select2-hidden-accessible')) {
				$(this).select2({ dropdownParent: $userModal });
			}
		});
	});
	$userModal.on('hidden.bs.modal', function () {
		$('#uf-crm-roles, #uf-status, #uf-company-id').each(function () {
			if ($(this).hasClass('select2-hidden-accessible')) {
				$(this).select2('destroy');
			}
		});
	});

	var confirmCb = null;
	var confirmModal = new bootstrap.Modal($('#modal-confirm')[0]);

	function showConfirm(message, callback, opts) {
		opts = opts || {};
		$('#confirm-modal-message').text(message);
		$('#btn-confirm-ok')
			.removeClass('btn-primary btn-danger btn-warning')
			.addClass(opts.btnClass || 'btn-primary')
			.text(opts.btnText || 'Подтвердить');
		confirmCb = callback;
		confirmModal.show();
	}

	function resetUserForm() {
		$('#form-user')[0].reset();
		$('#uf-user-id').val(0);
		$('#uf-error').addClass('d-none').text('');
		setLoading($('#btn-save-user'), false);
	}

	$('#btn-confirm-ok').on('click', function () {
		confirmModal.hide();
		if (confirmCb) {
			confirmCb();
			confirmCb = null;
		}
	});

	$('#modal-user-form').on('show.bs.modal', function (e) {
		if (!e.relatedTarget || !$(e.relatedTarget).hasClass('js-edit-user')) {
			resetUserForm();
			$('#modal-user-form-title').text('Добавить пользователя');
			$('#btn-save-user .btn-label').text('Создать');
			$('#uf-login-row').show();
			$('#uf-pass-req').show();
			$('#uf-pass-hint').hide();
			$('#uf-company-row').show();
			$('#uf-company-id').val($('#uf-company-id option:first').val() || '');
		}
	});

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
		$('#uf-status').val(data.crm_status || 'active');

		if (CAN_ASSIGN_ROLES) {
			$('#uf-crm-roles').val((data.role_ids || []).map(String));
		}

		$('#uf-login-row').hide();
		$('#uf-pass-req').hide();
		$('#uf-pass-hint').show();
		$('#uf-company-row').hide();
		$('#uf-error').addClass('d-none').text('');
	});

	$('#btn-save-user').on('click', function () {
		var $btn = $(this);
		var $form = $('#form-user');
		var $error = $('#uf-error');

		$error.addClass('d-none').text('');

		if ($form[0].checkValidity && !$form[0].checkValidity()) {
			$form[0].reportValidity();
			return;
		}

		if ($('#uf-user-id').val() === '0') {
			var companyId = parseInt($('#uf-company-id').val(), 10) || 0;
			if (companyId <= 0) {
				$error.removeClass('d-none').text('Для пользователя обязательно выберите компанию.');
				return;
			}
		}

		var data = $form.serialize() + '&action=me_save_user&_nonce=' + NONCES.save;
		setLoading($btn, true);

		$.post(AJAX_URL, data)
			.done(function (res) {
				if (!res || !res.success) {
					$error.removeClass('d-none').text((res && res.data && res.data.message) || 'Ошибка.');
					setLoading($btn, false);
					return;
				}

				bootstrap.Modal.getInstance($('#modal-user-form')[0]).hide();
				if (res.data.credentials) {
					var cred = res.data.credentials;
					$('#cred-login').text(cred.login);
					$('#cred-password').text(cred.password);
					if (cred.company_name) {
						$('#cred-company-name').text(cred.company_name);
						$('#cred-company-info').removeClass('d-none');
					} else {
						$('#cred-company-info').addClass('d-none');
					}
					new bootstrap.Modal($('#modal-credentials')[0]).show();
					return;
				}

				showToast(res.data.message || 'Пользователь сохранён.', 'success');
				setTimeout(function () { window.location.reload(); }, 800);
			})
			.fail(function (xhr) {
				var message = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
					? xhr.responseJSON.data.message
					: 'Ошибка сервера.';
				$error.removeClass('d-none').text(message);
				setLoading($btn, false);
			});
	});

	$(document).on('click', '.js-set-status', function (e) {
		e.preventDefault();
		var $el = $(this);
		var userId = $el.data('user-id');
		var status = $el.data('status');
		var confirmMessage = $el.data('confirm');

		function doSetStatus() {
			$.post(AJAX_URL, { action: 'me_set_user_status', user_id: userId, status: status, _nonce: NONCES.status })
				.done(function (res) {
					if (!res || !res.success) {
						showToast((res && res.data && res.data.message) || 'Ошибка.', 'error');
						return;
					}

					var data = res.data;
					$('#status-badge-' + userId)
						.removeClass('badge-success badge-danger badge-secondary badge-warning badge-info badge-primary')
						.addClass('badge-' + data.badge_class)
						.text(data.label);
					showToast(data.message || 'Статус обновлён.', 'success');

					if (data.status === 'blocked') {
						$el.data('status', 'active').attr('data-status', 'active')
							.removeClass('text-warning').addClass('text-success')
							.html('<i class="pg-icon m-r-5">tick_circle</i> Разблокировать');
					} else if (data.status === 'active') {
						$el.data('status', 'blocked').attr('data-status', 'blocked')
							.removeClass('text-success').addClass('text-warning')
							.html('<i class="pg-icon m-r-5">disable</i> Заблокировать');
					}

					var currentFilter = '<?php echo esc_js( $f_status ); ?>';
					if (!currentFilter || currentFilter === 'active' || currentFilter === 'blocked' || currentFilter === 'pending') {
						if (data.status === 'archived') {
							setTimeout(function () {
								$('#urow-' + userId).fadeOut(300, function () { $(this).remove(); });
							}, 600);
						}
					}
				})
				.fail(function () {
					showToast('Ошибка сервера.', 'error');
				});
		}

		if (confirmMessage) {
			showConfirm(confirmMessage, doSetStatus);
		} else {
			doSetStatus();
		}
	});

	function doDelete(userId, hard) {
		$.post(AJAX_URL, { action: 'me_delete_user', user_id: userId, hard: hard ? 1 : 0, _nonce: NONCES.delete })
			.done(function (res) {
				if (!res || !res.success) {
					showToast((res && res.data && res.data.message) || 'Ошибка.', 'error');
					return;
				}
				showToast(res.data.message || 'Пользователь удалён.', 'success');
				$('#urow-' + userId).fadeOut(300, function () { $(this).remove(); });
			})
			.fail(function () {
				showToast('Ошибка сервера.', 'error');
			});
	}

	$(document).on('click', '.js-delete-user', function (e) {
		e.preventDefault();

		var userId = $(this).data('user-id');
		var username = $(this).data('username');
		var hard = parseInt($(this).data('hard'), 10) === 1;

		if (hard) {
			showConfirm(
				'Физически удалить «' + username + '»? Это действие необратимо.',
				function () { doDelete(userId, true); },
				{ btnClass: 'btn-danger', btnText: 'Удалить навсегда' }
			);
			return;
		}

		showConfirm('Переместить «' + username + '» в архив?', function () { doDelete(userId, false); });
	});

	var $assignCompanyModal = $('#modal-assign-company');
	$assignCompanyModal.on('shown.bs.modal', function () {
		if (!$('#ac-company-select').hasClass('select2-hidden-accessible')) {
			$('#ac-company-select').select2({ dropdownParent: $assignCompanyModal });
		}
	});
	$assignCompanyModal.on('hidden.bs.modal', function () {
		if ($('#ac-company-select').hasClass('select2-hidden-accessible')) {
			$('#ac-company-select').select2('destroy');
		}
		$('#ac-error').addClass('d-none').text('');
	});

	$(document).on('click', '.js-assign-company', function () {
		var userId = $(this).data('user-id');
		var login = $(this).data('user-login');
		var companyId = parseInt($(this).data('company-id'), 10) || 0;

		$('#ac-user-id').val(userId);
		$('#ac-user-login').text(login);
		$('#ac-company-select').val(companyId > 0 ? companyId : ($('#ac-company-select option:first').val() || ''));
	});

	$('#btn-assign-company').on('click', function () {
		var $btn = $(this);
		var userId = parseInt($('#ac-user-id').val(), 10) || 0;
		var companyId = parseInt($('#ac-company-select').val(), 10) || 0;

		if (companyId <= 0) {
			$('#ac-error').removeClass('d-none').text('Пользователю обязательно должна быть назначена компания.');
			return;
		}

		$('#ac-error').addClass('d-none').text('');
		$btn.prop('disabled', true)
			.find('.btn-label').hide()
			.end().find('#btn-assign-company-spinner').removeClass('d-none');

		$.post(AJAX_URL, {
			action: 'me_assign_user_company',
			user_id: userId,
			company_id: companyId,
			_nonce: NONCES.company
		})
		.done(function (res) {
			if (!res || !res.success) {
				$('#ac-error').removeClass('d-none').text((res && res.data && res.data.message) || 'Ошибка.');
				$btn.prop('disabled', false)
					.find('.btn-label').show()
					.end().find('#btn-assign-company-spinner').addClass('d-none');
				return;
			}

			showToast(res.data.message || 'Компания назначена.', 'success');
			$('#company-cell-' + userId).html('<span class="badge badge-info">' + $('<div>').text(res.data.company_name).html() + '</span>');
			bootstrap.Modal.getInstance($assignCompanyModal[0]).hide();
		})
		.fail(function () {
			$('#ac-error').removeClass('d-none').text('Ошибка сервера.');
			$btn.prop('disabled', false)
				.find('.btn-label').show()
				.end().find('#btn-assign-company-spinner').addClass('d-none');
		});
	});

	$('#btn-credentials-close').on('click', function () {
		window.location.reload();
	});

}(jQuery));
</script>
		<?php
	},
	99
);

get_template_part( 'template-parts/root-page-end' );
