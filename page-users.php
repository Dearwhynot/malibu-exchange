<?php
/*
Template Name: Users Page
Slug: users
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

$current_uid = get_current_user_id();
if ( crm_is_root( $current_uid ) ) {
	malibu_exchange_render_root_company_scope_denied();
}

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

// Company scope: non-root admin sees only users from their own company.
if ( ! crm_is_root( get_current_user_id() ) ) {
	$_query_org_id  = crm_get_current_user_company_id( get_current_user_id() );
	$_co_user_ids   = $_query_org_id > 0 ? crm_get_company_user_ids( $_query_org_id ) : [];

	if ( isset( $query_args['include'] ) ) {
		// Already filtered to a subset — keep only users also in this company.
		$query_args['include'] = array_values( array_intersect( $query_args['include'], $_co_user_ids ) );
		if ( empty( $query_args['include'] ) ) {
			$query_args['include'] = [ -1 ];
		}
	} else {
		// Convert: restrict to company, honouring any existing exclude list.
		$_excl = $query_args['exclude'] ?? [];
		unset( $query_args['exclude'] );
		$_filtered             = array_values( array_diff( $_co_user_ids, $_excl ) );
		$query_args['include'] = ! empty( $_filtered ) ? $_filtered : [ -1 ];
	}
}

// Root (uid=1) невидим на уровне CRM — исключаем из любой выборки.
if ( isset( $query_args['include'] ) ) {
	$query_args['include'] = array_values( array_filter( $query_args['include'], fn( $id ) => $id !== 1 ) );
	if ( empty( $query_args['include'] ) ) {
		$query_args['include'] = [ -1 ];
	}
} else {
	$query_args['exclude'] = array_unique( array_merge( $query_args['exclude'] ?? [], [ 1 ] ) );
}

$uq          = new WP_User_Query( $query_args );
$users       = array_values( array_filter(
	$uq->get_results(),
	static fn( $user ) => $user instanceof WP_User && ! crm_is_root( (int) $user->ID )
) );
$total       = (int) $uq->get_total();
$total_pages = (int) ceil( $total / $per_page );

// ─── Batch-загрузка CRM-данных для всех пользователей в выборке ─────────────
$user_ids          = array_map( function ( $u ) { return (int) $u->ID; }, $users );
$crm_accounts      = crm_get_accounts_for_users( $user_ids );
$crm_roles_map     = crm_get_roles_for_users( $user_ids );
$all_crm_roles     = crm_get_all_roles();
$crm_companies_map = crm_get_companies_for_users( $user_ids );
$all_companies     = crm_get_all_companies_list();

// ─── Вспомогательные данные ──────────────────────────────────────────────────
$can_assign_roles = crm_user_has_permission( get_current_user_id(), 'users.assign_roles' );
$can_hard_delete  = me_users_can_hard_delete();
$page_url         = get_permalink();
$vendor_img_uri   = get_template_directory_uri() . '/vendor/pages/assets/img';

// Nonces
$nonce_save      = wp_create_nonce( 'me_users_save' );
$nonce_status    = wp_create_nonce( 'me_users_status' );
$nonce_delete    = wp_create_nonce( 'me_users_delete' );
$nonce_roles     = wp_create_nonce( 'me_roles_save' );
$nonce_company   = wp_create_nonce( 'me_assign_user_company' );
$nonce_merchants_list = wp_create_nonce( 'me_merchants_list' );
$nonce_merchant_delete = wp_create_nonce( 'me_merchants_delete' );
$nonce_create_co     = wp_create_nonce( 'me_create_company' );
$nonce_create_office = wp_create_nonce( 'me_create_company_office' );
$nonce_company_settings = wp_create_nonce( 'me_company_fintech_access_save' );

if ( ! function_exists( 'me_users_render_company_provider_badges_html' ) ) {
	function me_users_render_company_provider_badges_html( array $providers ): string {
		$providers = crm_fintech_normalize_allowed_providers( $providers );

		ob_start();
		if ( empty( $providers ) ) :
			?>
			<span class="badge badge-secondary m-r-2">Контуры отключены</span>
			<?php
		else :
			foreach ( $providers as $provider ) :
				$badge_class = $provider === 'doverka' ? 'badge-info' : 'badge-primary';
				?>
				<span class="badge <?php echo esc_attr( $badge_class ); ?> m-r-2">
					<?php echo esc_html( crm_fintech_provider_label( $provider ) ); ?>
				</span>
				<?php
			endforeach;
		endif;

		return (string) ob_get_clean();
	}
}

// Данные для вкладки "Компании" — только root
$all_companies_full        = [];
$company_fintech_access_map = [];
if ( crm_is_root( $current_uid ) ) {
	$all_companies_full = crm_get_all_companies_full();
	foreach ( $all_companies_full as $co ) {
		$co_id = (int) $co->id;
		$company_fintech_access_map[ $co_id ] = crm_fintech_get_allowed_providers( $co_id );
	}
}

// Данные для вкладки "Офисы" — root или пользователь с permission offices.create
$can_manage_offices        = crm_can_create_company_offices( $current_uid );
$office_scope_company      = null;
$office_scope_company_name = '';
$office_scope_company_id   = 0;
$office_scope_company_ids  = [];

if ( $can_manage_offices ) {
	if ( crm_is_root( $current_uid ) ) {
		$office_scope_company_ids = array_map( static fn( $company ) => (int) $company->id, $all_companies_full );
	} else {
		$office_scope_company = crm_get_user_primary_company( $current_uid );
		if ( $office_scope_company ) {
			$office_scope_company_id   = (int) $office_scope_company->id;
			$office_scope_company_name = (string) $office_scope_company->name;
			$office_scope_company_ids  = [ $office_scope_company_id ];
		} else {
			$can_manage_offices = false;
		}
	}
}

$company_offices = $can_manage_offices
	? crm_get_company_offices_full_by_company_ids( $office_scope_company_ids )
	: [];

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
				<ul class="nav nav-tabs nav-tabs-simple m-b-0" id="users-page-tabs" role="tablist" data-init-reponsive-tabs="dropdownfx">
					<li class="nav-item">
						<a class="active" data-bs-toggle="tab" role="tab" data-bs-target="#tab-users" data-target="#tab-users" href="#tab-users">
							Пользователи
						</a>
					</li>
					<li class="nav-item">
						<a data-bs-toggle="tab" role="tab" data-bs-target="#tab-roles" data-target="#tab-roles" href="#tab-roles">
							Роли и права
						</a>
					</li>
					<?php if ( $can_manage_offices ) : ?>
					<li class="nav-item">
						<a data-bs-toggle="tab" role="tab" data-bs-target="#tab-offices" data-target="#tab-offices" href="#tab-offices">
							Офисы
						</a>
					</li>
					<?php endif; ?>
					<?php if ( crm_is_root( $current_uid ) ) : ?>
					<li class="nav-item">
						<a data-bs-toggle="tab" role="tab" data-bs-target="#tab-merchants" data-target="#tab-merchants" href="#tab-merchants">
							Тех. мерчанты
						</a>
					</li>
					<?php endif; ?>
					<?php if ( crm_is_root( $current_uid ) ) : ?>
					<li class="nav-item">
						<a data-bs-toggle="tab" role="tab" data-bs-target="#tab-companies" data-target="#tab-companies" href="#tab-companies">
							Компании
						</a>
					</li>
					<?php endif; ?>
				</ul>

				<div class="tab-content" style="padding:0;overflow:visible">

				<!-- ─── TAB: USERS ────────────────────────────────────────────── -->
				<div class="tab-pane active" id="tab-users">

				<!-- ─── FILTERS ────────────────────────────────────────────────── -->
				<style>
				@media (max-width: 991px) {
				    .users-filter-card > .card-body { padding-left: 2px; padding-right: 2px; }
				    .users-filter-card .select2-container { width: 100% !important; }
				    .users-filter-card .users-filter-buttons { width: 100%; }
				}
				</style>
				<div class="card card-default users-filter-card m-b-20">
					<div class="card-body p-t-15 p-b-15">
						<form method="get" action="<?php echo esc_url( $page_url ); ?>" id="users-filter-form">
							<div class="row g-2 align-items-center">
								<!-- Поиск — 6 колонок -->
								<div class="col-12 col-lg-6">
									<div class="input-group">
										<span class="input-group-text"><i class="pg-icon">search</i></span>
										<input type="text" class="form-control"
										       name="me_s" value="<?php echo esc_attr( $s ); ?>"
										       placeholder="Поиск по логину, email, имени…">
									</div>
								</div>
								<!-- Правая половина: 2 селекта + 2 кнопки — 6 колонок -->
								<div class="col-12 col-lg-6">
									<div class="d-flex flex-column flex-sm-row gap-2">
										<select class="full-width" name="me_role" data-init-plugin="select2">
											<option value="">Все роли</option>
											<?php foreach ( $all_crm_roles as $crm_role ) : ?>
												<option value="<?php echo esc_attr( $crm_role->code ); ?>"
												        <?php selected( $f_role, $crm_role->code ); ?>>
													<?php echo esc_html( $crm_role->name ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<select class="full-width" name="me_status" data-init-plugin="select2">
											<option value=""        <?php selected( $f_status, '' ); ?>>Активные (без архивных)</option>
											<option value="active"   <?php selected( $f_status, 'active' ); ?>>Только активные</option>
											<option value="blocked"  <?php selected( $f_status, 'blocked' ); ?>>Заблокированные</option>
											<option value="pending"  <?php selected( $f_status, 'pending' ); ?>>Ожидающие</option>
											<option value="archived" <?php selected( $f_status, 'archived' ); ?>>Архивные</option>
											<option value="all"      <?php selected( $f_status, 'all' ); ?>>Все</option>
										</select>
										<div class="d-flex gap-2 users-filter-buttons flex-shrink-0">
											<button type="submit" class="btn btn-primary btn-sm flex-fill flex-sm-grow-0">
												<i class="pg-icon">search</i>
											</button>
											<a href="<?php echo esc_url( $page_url ); ?>"
											   class="btn btn-default btn-sm flex-fill flex-sm-grow-0">
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
											<?php foreach ( $users as $user ) :
												$acc           = $crm_accounts[ $user->ID ] ?? null;
												$user_roles    = $crm_roles_map[ $user->ID ] ?? [];
												$crm_status    = $acc ? $acc->status : CRM_STATUS_ACTIVE;
												$status_class  = crm_status_badge_class( $crm_status );
												$last_login    = me_users_get_last_login( $user->ID );
												$is_super      = crm_is_root( (int) $user->ID );
												$avatar_letter = me_users_avatar_letter( $user );

											// JSON для модалки редактирования
											$user_company = $crm_companies_map[ $user->ID ] ?? null;

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
												'company_id'       => $user_company ? (int) $user_company->id : 0,
												'company_name'     => $user_company ? $user_company->name    : '',
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

											<td class="v-align-middle" id="company-cell-<?php echo (int) $user->ID; ?>">
												<?php if ( $user_company ) : ?>
													<span class="badge badge-info"><?php echo esc_html( $user_company->name ); ?></span>
												<?php else : ?>
													<span class="hint-text">—</span>
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
														<?php if ( crm_is_root( $current_uid ) ) : ?>
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

														<!-- Assign Company — uid=1 only -->
														<?php if ( crm_is_root( $current_uid ) && ! $is_super ) : ?>
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
														<?php endif; ?>

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

				<!-- ─── TAB: OFFICES ─────────────────────────────────────────── -->
				<?php if ( $can_manage_offices ) : ?>
				<div class="tab-pane" id="tab-offices">

					<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 m-b-10">
						<div>
							<p class="hint-text m-b-0">Всего офисов в контуре: <strong id="offices-total-count"><?php echo (int) count( $company_offices ); ?></strong></p>
							<?php if ( ! crm_is_root( $current_uid ) ) : ?>
							<p class="hint-text fs-12 m-b-0">Контур компании: <?php echo esc_html( $office_scope_company_name ); ?></p>
							<?php endif; ?>
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
											<?php if ( crm_is_root( $current_uid ) ) : ?>
											<th style="width:180px">Компания</th>
											<?php endif; ?>
											<th>Офис</th>
											<th style="width:140px">Город</th>
											<th>Адрес</th>
											<th style="width:90px">Статус</th>
										</tr>
									</thead>
									<tbody id="company-offices-tbody">
										<?php if ( empty( $company_offices ) ) : ?>
										<tr id="company-offices-empty-row">
											<td colspan="<?php echo crm_is_root( $current_uid ) ? 6 : 5; ?>" class="text-center hint-text p-t-20 p-b-20">
												Офисы пока не созданы.
											</td>
										</tr>
										<?php else : ?>
											<?php foreach ( $company_offices as $office ) : ?>
											<tr id="office-row-<?php echo (int) $office['id']; ?>">
												<td class="v-align-middle">
													<span class="hint-text fs-12">#<?php echo (int) $office['id']; ?></span>
												</td>
												<?php if ( crm_is_root( $current_uid ) ) : ?>
												<td class="v-align-middle">
													<div>
														<span class="semi-bold"><?php echo esc_html( $office['company_name'] ); ?></span>
														<small class="hint-text m-l-5 fs-11"><?php echo esc_html( $office['company_code'] ); ?></small>
													</div>
												</td>
												<?php endif; ?>
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

				</div><!-- /#tab-offices -->
				<?php endif; ?>

				<!-- ─── TAB: MERCHANTS (root only) ─────────────────────────── -->
				<?php if ( crm_is_root( $current_uid ) ) : ?>
				<div class="tab-pane" id="tab-merchants">

					<div class="card card-default m-b-20">
						<div class="card-body p-t-20 p-b-15">
							<div class="row g-2 align-items-center m-b-10">
								<div class="col-12 col-md-4 col-lg-3">
									<div class="input-group">
										<span class="input-group-text"><i class="pg-icon">search</i></span>
										<input type="search" id="sys-merchant-search" class="form-control"
										       placeholder="chat_id, username, имя">
									</div>
								</div>
								<div class="col-6 col-md-2">
									<select id="sys-merchant-company" class="full-width" data-init-plugin="select2">
										<option value="">Все компании</option>
										<?php foreach ( $all_companies_full as $company ) : ?>
										<option value="<?php echo (int) $company->id; ?>">
											<?php echo esc_html( $company->name ); ?>
										</option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-6 col-md-2">
									<select id="sys-merchant-status" class="full-width" data-init-plugin="select2">
										<option value="">Все статусы</option>
										<?php foreach ( crm_merchant_statuses() as $status_code => $label ) : ?>
										<option value="<?php echo esc_attr( $status_code ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<div class="col-6 col-md-2">
									<input type="date" id="sys-merchant-date-from" class="form-control" placeholder="Дата с">
								</div>
								<div class="col-6 col-md-2">
									<input type="date" id="sys-merchant-date-to" class="form-control" placeholder="Дата по">
								</div>
							</div>

							<div class="row g-2 align-items-center">
								<div class="col-4 col-md-1">
									<select id="sys-merchant-per-page" class="full-width" data-init-plugin="select2">
										<option value="25">25</option>
										<option value="50">50</option>
										<option value="100">100</option>
									</select>
								</div>
								<div class="col-8 col-md-3 d-flex gap-2">
									<button type="button" id="btn-sys-merchants-search" class="btn btn-primary">
										<i class="pg-icon">search</i> Найти
									</button>
									<button type="button" id="btn-sys-merchants-reset" class="btn btn-default">
										Сброс
									</button>
								</div>
							</div>
						</div>
					</div>

					<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2 m-b-10">
						<div>
							<p class="hint-text m-b-0">Root-only hard delete для dev-сценариев.</p>
							<p class="hint-text fs-12 m-b-0">Удаление сработает только если у мерчанта нет ордеров, ledger и живых реферальных хвостов. Его собственные invite-записи будут очищены вместе с ним.</p>
						</div>
						<div class="d-flex align-items-center gap-2">
							<div id="sys-merchants-stats" class="text-muted small"></div>
							<div id="sys-merchants-loading" class="text-muted small d-none">
								<span class="pg-icon" style="animation:spin 1s linear infinite;display:inline-block;">refresh</span>
								Загрузка…
							</div>
						</div>
					</div>

					<div class="card card-default">
						<div class="card-header">
							<div class="card-title">Технический контур мерчантов</div>
						</div>
						<div class="card-body no-padding">
							<div class="table-responsive">
								<table class="table table-hover m-b-0" id="sys-merchants-table">
									<thead>
										<tr>
											<th style="width:60px">ID</th>
											<th>Мерчант</th>
											<th style="width:180px">Компания</th>
											<th style="width:150px">chat_id</th>
											<th style="width:170px">Telegram</th>
											<th style="width:110px">Статус</th>
											<th style="width:150px">Создан</th>
											<th style="width:60px"></th>
										</tr>
									</thead>
									<tbody id="sys-merchants-tbody">
										<tr id="sys-merchants-empty-row">
											<td colspan="8" class="text-center hint-text p-t-20 p-b-20">
												Откройте вкладку или нажмите «Найти», чтобы загрузить мерчантов.
											</td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>
					</div>

					<div id="sys-merchants-pagination" class="d-flex justify-content-between align-items-center m-t-15"></div>

				</div><!-- /#tab-merchants -->
				<?php endif; ?>

				<!-- ─── TAB: COMPANIES (uid=1 only) ─────────────────────── -->
				<?php if ( crm_is_root( $current_uid ) ) : ?>
				<div class="tab-pane" id="tab-companies">

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
								<div class="p-30 text-center hint-text">
									<p>Компании не найдены</p>
								</div>
							<?php else : ?>
								<div class="table-responsive">
									<table class="table table-hover m-b-0" id="companies-table">
										<thead>
											<tr>
												<th style="width:50px">ID</th>
												<th>Название</th>
												<th style="width:80px">Статус</th>
												<th style="width:120px">Пользователей</th>
												<th>Телефон</th>
												<th>Адрес / заметка</th>
												<th style="width:60px"></th>
											</tr>
										</thead>
										<tbody id="companies-tbody">
											<?php foreach ( $all_companies_full as $co ) :
												$co_id             = (int) $co->id;
												$allowed_providers = $company_fintech_access_map[ $co_id ] ?? crm_fintech_default_allowed_providers();
											?>
											<tr id="corow-<?php echo $co_id; ?>">
												<td class="v-align-middle">
													<span class="hint-text fs-12">#<?php echo $co_id; ?></span>
												</td>
												<td class="v-align-middle">
													<div>
														<span class="semi-bold"><?php echo esc_html( $co->name ); ?></span>
														<small class="hint-text m-l-5 fs-11"><?php echo esc_html( $co->code ); ?></small>
													</div>
													<div class="m-t-5" id="company-providers-<?php echo $co_id; ?>">
														<?php echo me_users_render_company_provider_badges_html( $allowed_providers ); ?>
													</div>
												</td>
												<td class="v-align-middle">
													<span class="badge badge-<?php echo $co->status === 'active' ? 'success' : 'secondary'; ?>">
														<?php echo esc_html( $co->status ); ?>
													</span>
												</td>
												<td class="v-align-middle hint-text fs-12">
													<?php echo (int) $co->user_count; ?>
												</td>
												<td class="v-align-middle hint-text fs-12">
													<?php echo esc_html( $co->phone ?? '—' ); ?>
												</td>
												<td class="v-align-middle hint-text fs-12">
													<?php echo esc_html( $co->address ?? ( $co->note ?: '—' ) ); ?>
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
																   data-company-id="<?php echo $co_id; ?>"
																   data-company-name="<?php echo esc_attr( $co->name ); ?>"
																   data-company-code="<?php echo esc_attr( $co->code ); ?>"
																   data-allowed-providers="<?php echo esc_attr( implode( ',', $allowed_providers ) ); ?>"
																   data-bs-toggle="modal"
																   data-bs-target="#modal-company-settings">
																	<i class="pg-icon m-r-5">settings</i> Настройки
																</a>
															</li>
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
					</div>

				</div><!-- /#tab-companies -->
				<?php endif; ?>

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


<?php get_template_part( 'template-parts/toast-host' ); ?>

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

						<!-- Company (только для uid=1, только при создании) -->
						<?php if ( crm_is_root( $current_uid ) ) : ?>
						<div class="col-md-6" id="uf-company-row">
							<div class="form-group form-group-default">
								<label>Компания</label>
								<select class="full-width" name="company_id" id="uf-company-id">
									<?php foreach ( $all_companies as $co ) : ?>
									<option value="<?php echo (int) $co->id; ?>">
										<?php echo esc_html( $co->name ); ?>
									</option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<?php endif; ?>

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
     MODAL: CREATE OFFICE
     ════════════════════════════════════════════════════════════════════ -->
<?php if ( $can_manage_offices ) : ?>
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
						<?php if ( crm_is_root( $current_uid ) ) : ?>
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
						<?php else : ?>
						<input type="hidden" id="cof-company-id" value="<?php echo (int) $office_scope_company_id; ?>">
						<div class="col-12">
							<div class="form-group form-group-default">
								<label>Компания</label>
								<input type="text" class="form-control" value="<?php echo esc_attr( $office_scope_company_name ); ?>" readonly>
							</div>
						</div>
						<?php endif; ?>

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
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════════
     MODAL: ASSIGN COMPANY (uid=1 only)
     ════════════════════════════════════════════════════════════════════ -->
<?php if ( crm_is_root( $current_uid ) ) : ?>
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
							<?php foreach ( $all_companies as $co ) : ?>
							<option value="<?php echo (int) $co->id; ?>">
								<?php echo esc_html( $co->name ); ?>
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

<!-- ════════════════════════════════════════════════════════════════════════
     MODAL: CREATE COMPANY (uid=1 only)
     ════════════════════════════════════════════════════════════════════ -->
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

<!-- ════════════════════════════════════════════════════════════════════════
     MODAL: COMPANY SETTINGS (root only)
     ════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modal-company-settings" tabindex="-1"
     aria-labelledby="modal-company-settings-title" aria-hidden="true">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header clearfix text-left">
				<button aria-label="Закрыть" type="button" class="close" data-bs-dismiss="modal" aria-hidden="true">
					<i class="pg-icon">close</i>
				</button>
				<h5 class="modal-title" id="modal-company-settings-title">Настройки компании</h5>
				<p class="p-b-10 m-b-0">
					Root определяет, какие платёжные контуры компания видит в настройках и через какие ей разрешено создавать новые ордера.
				</p>
			</div>
			<div class="modal-body">
				<form id="form-company-settings" novalidate>
					<input type="hidden" id="cfs-company-id" value="0">

					<div class="form-group-attached">
						<div class="row">
							<div class="col-md-8">
								<div class="form-group form-group-default">
									<label>Компания</label>
									<input type="text" class="form-control" id="cfs-company-name" value="" readonly>
								</div>
							</div>
							<div class="col-md-4">
								<div class="form-group form-group-default">
									<label>ID / код</label>
									<input type="text" class="form-control" id="cfs-company-code" value="" readonly>
								</div>
							</div>
						</div>

						<div class="row">
							<div class="col-12">
								<div class="form-group form-group-default">
									<label>Доступные платёжные контуры</label>
									<p class="hint-text small m-b-0">
										Секция сделана расширяемой: позже сюда можно будет добавить и другие настройки компании.
									</p>
								</div>
							</div>
						</div>

						<div class="row">
							<div class="col-md-6">
								<div class="form-group form-group-default">
									<label>Kanyon (Pay2Day)</label>
									<div class="form-check complete m-t-5">
										<input type="checkbox" id="cfs-provider-kanyon" class="js-company-provider" value="kanyon">
										<label for="cfs-provider-kanyon">Разрешить компании</label>
									</div>
									<p class="hint-text small m-b-0">
										Логин, пароль и создание новых ордеров через Kanyon / Pay2Day.
									</p>
								</div>
							</div>
							<div class="col-md-6">
								<div class="form-group form-group-default">
									<label>Doverka</label>
									<div class="form-check complete m-t-5">
										<input type="checkbox" id="cfs-provider-doverka" class="js-company-provider" value="doverka">
										<label for="cfs-provider-doverka">Разрешить компании</label>
									</div>
									<p class="hint-text small m-b-0">
										API-ключ Doverka, выбор Doverka как активного провайдера и создание новых ордеров.
									</p>
								</div>
							</div>
						</div>
					</div>

					<div class="alert alert-danger d-none m-t-10" id="cfs-error"></div>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-bs-dismiss="modal">Отмена</button>
				<button type="button" class="btn btn-primary" id="btn-save-company-settings">
					<span class="btn-label">Сохранить</span>
					<i class="pg-icon spin d-none" id="btn-company-settings-spinner">refresh</i>
				</button>
			</div>
		</div>
	</div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════
     MODAL: CREDENTIALS (показывается после создания нового пользователя)
     ════════════════════════════════════════════════════════════════════ -->
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
<?php endif; ?>

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
.pg-icon.spin { animation:spin 1s linear infinite; display:inline-block; }
@keyframes spin { to { transform:rotate(360deg); } }
.dropdown-menu .dropdown-item { display:flex; align-items:center; }
.dropdown-menu .dropdown-item .pg-icon { flex-shrink:0; line-height:1; }
/* ── Tabs ── */
#tab-users, #tab-roles, #tab-offices, #tab-merchants, #tab-companies { padding: 0; }
.nav-tabs ~ .tab-content { overflow: visible; }
.perm-module-title { font-size:10px; text-transform:uppercase; letter-spacing:.08em; color:#aaa; margin:14px 0 6px; padding-bottom:4px; border-bottom:1px solid rgba(0,0,0,.06); }
.perm-check-label { font-size:13px; cursor:pointer; display:flex; align-items:center; gap:6px; margin-bottom:4px; }
.perm-check-label input { flex-shrink:0; }
</style>

<?php
add_action( 'wp_footer', function () use ( $nonce_save, $nonce_status, $nonce_delete, $nonce_roles, $nonce_company, $nonce_merchants_list, $nonce_merchant_delete, $nonce_create_co, $nonce_create_office, $nonce_company_settings, $f_status, $can_assign_roles, $can_edit_roles, $all_permissions_grouped, $role_permissions_map, $current_uid, $can_manage_offices ) {
?>
<script>
(function ($) {
	'use strict';

	// ── Tab switching (fix Pages + Bootstrap 5 incompatibility) ─────────────────
	var $usersTabNav   = $('#users-page-tabs');
	var $usersTabLinks = $usersTabNav.find('a[data-bs-toggle="tab"]');
	var $usersTabPanes = $('#tab-users, #tab-roles, #tab-offices, #tab-merchants, #tab-companies');

	function activateTab(targetId) {
		if ( ! targetId || targetId === 'undefined' ) {
			return;
		}

		var $activeLink = $usersTabLinks.filter('[data-bs-target="' + targetId + '"]');
		if ( ! $activeLink.length ) {
			return;
		}

		$usersTabLinks.removeClass('active').attr('aria-selected', 'false');
		$usersTabLinks.parent('.nav-item').removeClass('active');
		$activeLink.addClass('active').attr('aria-selected', 'true');
		$activeLink.parent('.nav-item').addClass('active');

		$usersTabPanes.removeClass('show active');
		$(targetId).addClass('show active');

		if ( targetId === '#tab-merchants' && typeof ensureSystemMerchantsLoaded === 'function' ) {
			ensureSystemMerchantsLoaded();
		}
	}
	// Desktop: перехватываем клик; .data('bs-target') не работает в jQuery 3 — используем .attr()
	$(document).on('click.me-tabs', '#users-page-tabs a[data-bs-toggle="tab"]', function (e) {
		e.preventDefault();
		e.stopImmediatePropagation();
		activateTab($(this).attr('data-bs-target'));
	});
	// Mobile: Pages создаёт select с value="undefined" (не находит data-target),
	// поэтому сначала пробуем value, а затем корректно fallback'аемся к selectedIndex
	function bindMobileTabDropdown(attempt) {
		var $select = $usersTabNav.nextAll('.nav-tab-dropdown').find('select').first();
		if ( ! $select.length ) {
			if ( attempt < 10 ) {
				setTimeout(function () {
					bindMobileTabDropdown(attempt + 1);
				}, 150);
			}
			return;
		}

		$select.off('change.me-tabs').on('change.me-tabs', function () {
			var targetId = this.value;

			if ( ! targetId || targetId === 'undefined' ) {
				var $link = $usersTabLinks.eq(this.selectedIndex);
				targetId = $link.attr('data-bs-target');
			}

			activateTab(targetId);
		});
	}

	bindMobileTabDropdown(0);

	var AJAX_URL = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
	var NONCES   = {
		save:          '<?php echo esc_js( $nonce_save ); ?>',
		status:        '<?php echo esc_js( $nonce_status ); ?>',
		delete:        '<?php echo esc_js( $nonce_delete ); ?>',
		company:       '<?php echo esc_js( $nonce_company ); ?>',
		merchantList:  '<?php echo esc_js( $nonce_merchants_list ); ?>',
		merchantDelete:'<?php echo esc_js( $nonce_merchant_delete ); ?>',
		createCo:      '<?php echo esc_js( $nonce_create_co ); ?>',
		createOffice:  '<?php echo esc_js( $nonce_create_office ); ?>',
		companySettings: '<?php echo esc_js( $nonce_company_settings ); ?>'
	};
	var CAN_ASSIGN_ROLES = <?php echo $can_assign_roles ? 'true' : 'false'; ?>;
	var CAN_MANAGE_OFFICES = <?php echo $can_manage_offices ? 'true' : 'false'; ?>;
	var IS_ROOT          = <?php echo crm_is_root( $current_uid ) ? 'true' : 'false'; ?>;
	var FINTECH_PROVIDER_LABELS = <?php echo crm_json_for_inline_js( crm_fintech_provider_labels() ); ?>;

	// ── Дропдауны Actions: strategy fixed (fix overflow in .table-responsive) ──
	function initActionDropdowns($scope) {
		($scope || $(document)).find('.dropdown-toggle[data-bs-toggle="dropdown"]').each(function () {
			if (this.dataset.dropdownFixedReady === '1') {
				return;
			}

			new bootstrap.Dropdown(this, { popperConfig: { strategy: 'fixed' } });
			this.dataset.dropdownFixedReady = '1';
		});
	}

	initActionDropdowns($(document));

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
		if (window.MalibuToast && typeof window.MalibuToast.show === 'function') {
			window.MalibuToast.show(message, type || 'info');
			return;
		}
		alert(message);
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

	function renderCompanyProviderBadges(providers) {
		var normalized = normalizeProviderCodes(providers);
		var html = '';

		if (!normalized.length) {
			return '<span class="badge badge-secondary m-r-2">Контуры отключены</span>';
		}

		$.each(normalized, function (_, provider) {
			var badgeClass = provider === 'doverka' ? 'badge-info' : 'badge-primary';
			html += '<span class="badge ' + badgeClass + ' m-r-2">' + escapeHtml(FINTECH_PROVIDER_LABELS[provider] || provider) + '</span>';
		});

		return html;
	}

	function buildCompanyActionsDropdown(company) {
		return ''
			+ '<div class="dropdown">'
			+   '<button type="button" class="btn btn-default btn-xs dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Действия компании">'
			+     '<i class="pg-icon">more_vertical</i>'
			+   '</button>'
			+   '<ul class="dropdown-menu dropdown-menu-end">'
			+     '<li><a class="dropdown-item js-company-settings" href="#"'
			+       ' data-company-id="' + escapeHtml(company.id) + '"'
			+       ' data-company-name="' + escapeHtml(company.name) + '"'
			+       ' data-company-code="' + escapeHtml(company.code) + '"'
			+       ' data-allowed-providers="' + escapeHtml((company.allowed_providers || []).join(',')) + '"'
			+       ' data-bs-toggle="modal"'
			+       ' data-bs-target="#modal-company-settings">'
			+       '<i class="pg-icon m-r-5">settings</i> Настройки'
			+     '</a></li>'
			+   '</ul>'
			+ '</div>';
	}

	function renderOfficeStatusBadge(status) {
		var normalized = $.trim(String(status || '')).toLowerCase();
		var badgeClass = normalized === 'active' ? 'success' : 'secondary';

		return '<span class="badge badge-' + badgeClass + '">' + escapeHtml(normalized || '—') + '</span>';
	}

	function buildOfficeRow(office) {
		var html = ''
			+ '<tr id="office-row-' + escapeHtml(office.id) + '">'
			+   '<td class="v-align-middle"><span class="hint-text fs-12">#' + escapeHtml(office.id) + '</span></td>';

		if (IS_ROOT) {
			html += ''
				+ '<td class="v-align-middle">'
				+   '<div><span class="semi-bold">' + escapeHtml(office.company_name || '—') + '</span>'
				+   '<small class="hint-text m-l-5 fs-11">' + escapeHtml(office.company_code || '') + '</small></div>'
				+ '</td>';
		}

		html += ''
			+ '<td class="v-align-middle">'
			+   '<div><span class="semi-bold">' + escapeHtml(office.name || '—') + '</span>';

		if (office.is_default) {
			html += '<span class="badge badge-info m-l-5">Default</span>';
		}

		html += ''
			+   '</div>'
			+   '<small class="hint-text fs-11">' + escapeHtml(office.code || '') + '</small>'
			+ '</td>'
			+ '<td class="v-align-middle hint-text fs-12">' + escapeHtml(office.city || '—') + '</td>'
			+ '<td class="v-align-middle hint-text fs-12">' + escapeHtml(office.address_line || '—') + '</td>'
			+ '<td class="v-align-middle">' + renderOfficeStatusBadge(office.status || '') + '</td>'
			+ '</tr>';

		return html;
	}

	// ── Root merchants tab ────────────────────────────────────────────────────
	var SYSTEM_MERCHANTS_STATE = {
		page: 1,
		loaded: false,
		loading: false
	};

	function systemMerchantInitials(label) {
		return $.trim(String(label || 'TG')).replace(/\s+/g, ' ').substring(0, 2).toUpperCase() || 'TG';
	}

	function systemMerchantAvatarHtml(url, label) {
		if (url) {
			return '<span class="thumbnail-wrapper d32 circular inline m-r-10" style="overflow:hidden;">'
				+ '<img src="' + escapeHtml(url) + '" data-src="' + escapeHtml(url) + '" data-src-retina="' + escapeHtml(url) + '" alt="Profile Image" width="32" height="32" style="width:32px;height:32px;object-fit:cover;">'
				+ '</span>';
		}

		return '<span class="thumbnail-wrapper d32 circular inline m-r-10 bg-complete text-white" style="display:inline-flex;align-items:center;justify-content:center;font-weight:700;">'
			+ escapeHtml(systemMerchantInitials(label))
			+ '</span>';
	}

	function buildSystemMerchantActions(row) {
		return ''
			+ '<div class="dropdown">'
			+   '<button type="button" class="btn btn-default btn-xs dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Действия мерчанта">'
			+     '<i class="pg-icon">more_vertical</i>'
			+   '</button>'
			+   '<ul class="dropdown-menu dropdown-menu-end">'
			+     '<li><a class="dropdown-item text-danger js-system-merchant-delete" href="#"'
			+       ' data-merchant-id="' + escapeHtml(row.id) + '"'
			+       ' data-merchant-name="' + escapeHtml(row.name || ('Merchant #' + row.id)) + '">'
			+       '<i class="pg-icon m-r-5">trash</i> Удалить физически'
			+     '</a></li>'
			+   '</ul>'
			+ '</div>';
	}

	function buildSystemMerchantRow(row) {
		var profileLine = $.trim([row.telegram_first_name || '', row.telegram_last_name || ''].join(' '));
		var tgHtml = row.telegram_username ? '@' + escapeHtml(row.telegram_username) : '<span class="hint-text">—</span>';
		var nameHtml = '<div class="d-flex align-items-center">'
			+ systemMerchantAvatarHtml(row.telegram_avatar_url || '', row.name || row.telegram_first_name || row.telegram_username || 'TG')
			+ '<div><div class="semi-bold">' + escapeHtml(row.name || profileLine || ('Merchant #' + row.id)) + '</div>'
			+ '<div class="hint-text fs-12">ID #' + escapeHtml(row.id) + (profileLine ? ' · ' + escapeHtml(profileLine) : '') + '</div></div></div>';

		return ''
			+ '<tr id="sys-merchant-row-' + escapeHtml(row.id) + '" data-merchant-id="' + escapeHtml(row.id) + '">'
			+   '<td class="v-align-middle"><span class="hint-text fs-12">#' + escapeHtml(row.id) + '</span></td>'
			+   '<td class="v-align-middle">' + nameHtml + '</td>'
			+   '<td class="v-align-middle"><div>' + escapeHtml(row.company_name || '—') + '</div><div class="hint-text fs-12">' + escapeHtml(row.company_code || '') + '</div></td>'
			+   '<td class="v-align-middle"><code>' + escapeHtml(row.chat_id || '—') + '</code></td>'
			+   '<td class="v-align-middle">' + tgHtml + '</td>'
			+   '<td class="v-align-middle"><span class="badge badge-' + escapeHtml(row.status_badge || 'secondary') + '">' + escapeHtml(row.status_label || row.status || '—') + '</span></td>'
			+   '<td class="v-align-middle hint-text fs-12">' + escapeHtml(row.created_at || '—') + '</td>'
			+   '<td class="v-align-middle text-right">' + buildSystemMerchantActions(row) + '</td>'
			+ '</tr>';
	}

	function renderSystemMerchantsTable(rows) {
		var $tbody = $('#sys-merchants-tbody').empty();

		if (!rows || !rows.length) {
			$tbody.html('<tr id="sys-merchants-empty-row"><td colspan="8" class="text-center hint-text p-t-20 p-b-20">Мерчанты не найдены.</td></tr>');
			return;
		}

		$.each(rows, function (_, row) {
			$tbody.append(buildSystemMerchantRow(row));
		});

		initActionDropdowns($tbody);
	}

	function renderSystemMerchantsPagination(totalPages, current) {
		var $wrap = $('#sys-merchants-pagination').empty();
		if (totalPages <= 1) {
			return;
		}

		var html = '<ul class="pagination pagination-sm no-margin">';
		html += '<li class="page-item' + (current <= 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (current - 1) + '">&laquo;</a></li>';
		for (var i = 1; i <= totalPages; i++) {
			html += '<li class="page-item' + (i === current ? ' active' : '') + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
		}
		html += '<li class="page-item' + (current >= totalPages ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (current + 1) + '">&raquo;</a></li>';
		html += '</ul>';
		$wrap.html(html);
	}

	function collectSystemMerchantFilters() {
		return {
			action: 'me_merchants_list',
			_nonce: NONCES.merchantList,
			page: SYSTEM_MERCHANTS_STATE.page,
			per_page: $('#sys-merchant-per-page').val() || '25',
			search: $('#sys-merchant-search').val() || '',
			company_id: $('#sys-merchant-company').val() || '',
			status: $('#sys-merchant-status').val() || '',
			date_from: $('#sys-merchant-date-from').val() || '',
			date_to: $('#sys-merchant-date-to').val() || ''
		};
	}

	function loadSystemMerchants(page) {
		if (!IS_ROOT) {
			return;
		}

		if (page) {
			SYSTEM_MERCHANTS_STATE.page = page;
		}

		SYSTEM_MERCHANTS_STATE.loading = true;
		$('#sys-merchants-loading').removeClass('d-none');

		$.post(AJAX_URL, collectSystemMerchantFilters())
			.done(function (res) {
				if (!res || !res.success) {
					showToast((res && res.data && res.data.message) || 'Не удалось загрузить мерчантов.', 'error');
					return;
				}

				SYSTEM_MERCHANTS_STATE.loaded = true;
				renderSystemMerchantsTable(res.data.rows || []);
				renderSystemMerchantsPagination(parseInt(res.data.total_pages || 1, 10), parseInt(res.data.page || 1, 10));
				$('#sys-merchants-stats').text('Найдено: ' + (res.data.total || 0));
			})
			.fail(function () {
				showToast('Ошибка сервера при загрузке мерчантов.', 'error');
			})
			.always(function () {
				SYSTEM_MERCHANTS_STATE.loading = false;
				$('#sys-merchants-loading').addClass('d-none');
			});
	}

	function ensureSystemMerchantsLoaded() {
		if (!IS_ROOT || SYSTEM_MERCHANTS_STATE.loaded || SYSTEM_MERCHANTS_STATE.loading) {
			return;
		}

		loadSystemMerchants(1);
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
			// Показываем company row только при создании (uid=1)
			if (IS_ROOT) {
				$('#uf-company-row').show();
				$('#uf-company-id').val($('#uf-company-id option:first').val() || '');
			}
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
		// Скрываем company row при редактировании (назначение через отдельное действие)
		if (IS_ROOT) {
			$('#uf-company-row').hide();
		}
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
		if (IS_ROOT && $('#uf-user-id').val() === '0') {
			var companyId = parseInt($('#uf-company-id').val(), 10) || 0;
			if (companyId <= 0) {
				$err.removeClass('d-none').text('Для пользователя обязательно выберите компанию.');
				return;
			}
		}

		var data = $form.serialize() + '&action=me_save_user&_nonce=' + NONCES.save;
		setLoading($btn, true);

		$.post(AJAX_URL, data)
			.done(function (res) {
				if (res.success) {
					bootstrap.Modal.getInstance($('#modal-user-form')[0]).hide();
					// uid=1 и новый пользователь — показываем credentials modal
					if (IS_ROOT && res.data.credentials) {
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
					} else {
						showToast(res.data.message, 'success');
						setTimeout(function () { window.location.reload(); }, 800);
					}
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

					// Обновить пункт меню блокировки без перезагрузки
					if (d.status === 'blocked') {
						$el.data('status', 'active').attr('data-status', 'active')
						   .removeClass('text-warning').addClass('text-success')
						   .html('<i class="pg-icon m-r-5">tick_circle</i> Разблокировать');
					} else if (d.status === 'active') {
						$el.data('status', 'blocked').attr('data-status', 'blocked')
						   .removeClass('text-success').addClass('text-warning')
						   .html('<i class="pg-icon m-r-5">disable</i> Заблокировать');
					}

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

	// ── Root merchants tab ────────────────────────────────────────────────────
	$('#btn-sys-merchants-search').on('click', function () {
		SYSTEM_MERCHANTS_STATE.page = 1;
		loadSystemMerchants(1);
	});

	$('#btn-sys-merchants-reset').on('click', function () {
		$('#sys-merchant-search').val('');
		$('#sys-merchant-company').val('');
		$('#sys-merchant-status').val('');
		$('#sys-merchant-date-from').val('');
		$('#sys-merchant-date-to').val('');
		$('#sys-merchant-per-page').val('25');

		$('#sys-merchant-company, #sys-merchant-status, #sys-merchant-per-page').each(function () {
			if ($(this).hasClass('select2-hidden-accessible')) {
				$(this).trigger('change.select2');
			}
		});

		SYSTEM_MERCHANTS_STATE.page = 1;
		loadSystemMerchants(1);
	});

	$('#sys-merchant-search').on('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			SYSTEM_MERCHANTS_STATE.page = 1;
			loadSystemMerchants(1);
		}
	});

	$('#sys-merchants-pagination').on('click', '.page-link', function (e) {
		e.preventDefault();
		var page = parseInt($(this).data('page'), 10) || 1;
		if ($(this).closest('.page-item').hasClass('disabled') || $(this).closest('.page-item').hasClass('active')) {
			return;
		}
		loadSystemMerchants(page);
	});

	$(document).on('click', '.js-system-merchant-delete', function (e) {
		e.preventDefault();

		var merchantId = parseInt($(this).data('merchant-id'), 10) || 0;
		var merchantName = $(this).data('merchant-name') || ('Merchant #' + merchantId);
		if (!merchantId) {
			return;
		}

		showConfirm(
			'Физически удалить мерчанта «' + merchantName + '»? Удаление сработает только если у него нет связанных ордеров, ledger и живых реферальных хвостов.',
			function () {
				var currentRows = $('#sys-merchants-tbody tr[data-merchant-id]').length;
				$.post(AJAX_URL, {
					action: 'me_merchants_delete',
					merchant_id: merchantId,
					_nonce: NONCES.merchantDelete
				})
				.done(function (res) {
					if (!res || !res.success) {
						showToast((res && res.data && res.data.message) || 'Не удалось удалить мерчанта.', 'error');
						return;
					}

					showToast(res.data.message || 'Мерчант удалён.', 'success');
					if (currentRows <= 1 && SYSTEM_MERCHANTS_STATE.page > 1) {
						SYSTEM_MERCHANTS_STATE.page -= 1;
					}
					SYSTEM_MERCHANTS_STATE.loaded = false;
					loadSystemMerchants(SYSTEM_MERCHANTS_STATE.page);
				})
				.fail(function (xhr) {
					var message = 'Ошибка сервера при удалении мерчанта.';
					if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						message = xhr.responseJSON.data.message;
					}
					showToast(message, 'error');
				});
			},
			{ btnClass: 'btn-danger', btnText: 'Удалить мерчанта' }
		);
	});


	// ── Roles: permissions modal ──────────────────────────────────────────────
	var PERMS_GROUPED  = <?php echo crm_json_for_inline_js( $all_permissions_grouped ); ?>;
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

	if (CAN_MANAGE_OFFICES) {
		var $createOfficeModal = $('#modal-create-office');

		function resetOfficeForm() {
			$('#form-company-office')[0].reset();
			$('#cof-error').addClass('d-none').text('');
			$('#btn-create-office').prop('disabled', false)
				.find('.btn-label').show()
				.end().find('#btn-create-office-spinner').addClass('d-none');
			if (IS_ROOT && $('#cof-company-id').hasClass('select2-hidden-accessible')) {
				$('#cof-company-id').trigger('change.select2');
			}
		}

		$createOfficeModal.on('shown.bs.modal', function () {
			if (IS_ROOT && !$('#cof-company-id').hasClass('select2-hidden-accessible')) {
				$('#cof-company-id').select2({ dropdownParent: $createOfficeModal });
			}
		});

		$createOfficeModal.on('hidden.bs.modal', function () {
			if (IS_ROOT && $('#cof-company-id').hasClass('select2-hidden-accessible')) {
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
				$('#cof-error').removeClass('d-none').text(IS_ROOT ? 'Выберите компанию.' : 'Компания не определена.');
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
				_nonce: NONCES.createOffice
			})
			.done(function (res) {
				if (!res.success) {
					$('#cof-error').removeClass('d-none').text(res.data && res.data.message ? res.data.message : 'Ошибка создания офиса.');
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
				var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
					? xhr.responseJSON.data.message
					: 'Ошибка сервера.';
				$('#cof-error').removeClass('d-none').text(msg);
				$btn.prop('disabled', false)
					.find('.btn-label').show()
					.end().find('#btn-create-office-spinner').addClass('d-none');
			});
		});
	}


	<?php if ( crm_is_root( $current_uid ) ) : ?>
		// ── Assign Company ────────────────────────────────────────────────────────
		var $assignCoModal = $('#modal-assign-company');
		var $companySettingsModal = $('#modal-company-settings');

	// Инициализация Select2 при открытии
	$assignCoModal.on('shown.bs.modal', function () {
		if (!$('#ac-company-select').hasClass('select2-hidden-accessible')) {
			$('#ac-company-select').select2({ dropdownParent: $assignCoModal });
		}
	});
	$assignCoModal.on('hidden.bs.modal', function () {
		if ($('#ac-company-select').hasClass('select2-hidden-accessible')) {
			$('#ac-company-select').select2('destroy');
		}
		$('#ac-error').addClass('d-none').text('');
	});

	// Заполняем данные при клике на "Компания" в дропдауне
	$(document).on('click', '.js-assign-company', function () {
		var uid       = $(this).data('user-id');
		var login     = $(this).data('user-login');
		var companyId = parseInt($(this).data('company-id'), 10) || 0;

		$('#ac-user-id').val(uid);
		$('#ac-user-login').text(login);
		$('#ac-company-select').val(companyId > 0 ? companyId : ($('#ac-company-select option:first').val() || ''));
	});

		$('#btn-assign-company').on('click', function () {
			var $btn      = $(this);
			var userId    = parseInt($('#ac-user-id').val(), 10);
			var companyId = parseInt($('#ac-company-select').val(), 10) || 0;

		if (companyId <= 0) {
			$('#ac-error').removeClass('d-none').text('Пользователю обязательно должна быть назначена компания.');
			return;
		}

		$btn.prop('disabled', true)
			.find('.btn-label').hide()
			.end().find('#btn-assign-company-spinner').removeClass('d-none');
		$('#ac-error').addClass('d-none').text('');

		$.post(AJAX_URL, {
			action:     'me_assign_user_company',
			user_id:    userId,
			company_id: companyId,
			_nonce:     NONCES.company
		})
		.done(function (res) {
			if (res.success) {
				showToast(res.data.message, 'success');
				bootstrap.Modal.getInstance($assignCoModal[0]).hide();

				// Обновляем ячейку компании в строке таблицы
				var $cell = $('#company-cell-' + userId);
				$cell.html('<span class="badge badge-info">' + $('<div>').text(res.data.company_name).html() + '</span>');
			} else {
				$('#ac-error').removeClass('d-none').text(res.data.message || 'Ошибка.');
				$btn.prop('disabled', false)
					.find('.btn-label').show()
					.end().find('#btn-assign-company-spinner').addClass('d-none');
			}
		})
		.fail(function () {
			$('#ac-error').removeClass('d-none').text('Ошибка сервера.');
			$btn.prop('disabled', false)
				.find('.btn-label').show()
				.end().find('#btn-assign-company-spinner').addClass('d-none');
			});
		});

		function parseCompanyProvidersAttr(rawValue) {
			if (!rawValue) {
				return [];
			}
			return normalizeProviderCodes(String(rawValue).split(','));
		}

		$companySettingsModal.on('hidden.bs.modal', function () {
			$('#cfs-company-id').val(0);
			$('#modal-company-settings-title').text('Настройки компании');
			$('#cfs-company-name').val('');
			$('#cfs-company-code').val('');
			$('#cfs-error').addClass('d-none').text('');
			$('.js-company-provider').prop('checked', false);
			$('#btn-save-company-settings').prop('disabled', false)
				.find('.btn-label').show()
				.end().find('#btn-company-settings-spinner').addClass('d-none');
		});

		$(document).on('click', '.js-company-settings', function () {
			var $trigger = $(this);
			var companyId = parseInt($trigger.attr('data-company-id'), 10) || 0;
			var companyName = $trigger.attr('data-company-name') || '—';
			var companyCode = $trigger.attr('data-company-code') || '—';
			var providers = parseCompanyProvidersAttr($trigger.attr('data-allowed-providers'));

			$('#cfs-company-id').val(companyId);
			$('#modal-company-settings-title').text('Настройки: ' + companyName);
			$('#cfs-company-name').val(companyName);
			$('#cfs-company-code').val('#' + companyId + ' · ' + companyCode);
			$('.js-company-provider').each(function () {
				$(this).prop('checked', providers.indexOf($(this).val()) !== -1);
			});
			$('#cfs-error').addClass('d-none').text('');
		});

		$('#btn-save-company-settings').on('click', function () {
			var $btn = $(this);
			var companyId = parseInt($('#cfs-company-id').val(), 10) || 0;
			var providers = [];

			$('.js-company-provider:checked').each(function () {
				providers.push($(this).val());
			});

			$('#cfs-error').addClass('d-none').text('');
			$btn.prop('disabled', true)
				.find('.btn-label').hide()
				.end().find('#btn-company-settings-spinner').removeClass('d-none');

			$.post(AJAX_URL, {
				action: 'me_company_fintech_access_save',
				nonce: NONCES.companySettings,
				company_id: companyId,
				providers: providers
			})
			.done(function (res) {
				if (!res.success) {
					$('#cfs-error').removeClass('d-none').text(res.data && res.data.message ? res.data.message : 'Ошибка сохранения.');
					$btn.prop('disabled', false)
						.find('.btn-label').show()
						.end().find('#btn-company-settings-spinner').addClass('d-none');
					return;
				}

				var providerList = normalizeProviderCodes(res.data.allowed_providers || []);
				var providerAttr = providerList.join(',');
				var $row = $('#corow-' + companyId);
				$row.find('.js-company-settings').attr('data-allowed-providers', providerAttr);
				$('#company-providers-' + companyId).html(renderCompanyProviderBadges(providerList));
				showToast(res.data.message || 'Настройки компании сохранены.', 'success');
				bootstrap.Modal.getInstance($companySettingsModal[0]).hide();
			})
			.fail(function (xhr) {
				var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
					? xhr.responseJSON.data.message
					: 'Ошибка сервера.';
				$('#cfs-error').removeClass('d-none').text(msg);
				$btn.prop('disabled', false)
					.find('.btn-label').show()
					.end().find('#btn-company-settings-spinner').addClass('d-none');
			});
		});

		// ── Create Company ────────────────────────────────────────────────────────
		var $createCoModal = $('#modal-create-company');

	$createCoModal.on('hidden.bs.modal', function () {
		$('#form-company')[0].reset();
		$('#cc-error').addClass('d-none').text('');
	});

	$('#btn-create-company').on('click', function () {
		var $btn  = $(this);
		var name  = $.trim($('#cc-name').val());
		var phone = $.trim($('#cc-phone').val());
		var addr  = $.trim($('#cc-address').val());

		$('#cc-error').addClass('d-none').text('');

		if (!name) {
			$('#cc-error').removeClass('d-none').text('Введите название компании.');
			return;
		}

		$btn.prop('disabled', true)
			.find('.btn-label').hide()
			.end().find('#btn-create-company-spinner').removeClass('d-none');

		$.post(AJAX_URL, {
			action:   'me_create_company',
			name:     name,
			phone:    phone,
			address:  addr,
			_nonce:   NONCES.createCo
		})
		.done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					bootstrap.Modal.getInstance($createCoModal[0]).hide();

					// Добавляем строку в таблицу компаний
					var co = res.data.company;
					var $tbody = $('#companies-tbody');
					if ($tbody.length) {
						var providerBadges = renderCompanyProviderBadges(co.allowed_providers || []);
						var row = '<tr id="corow-' + co.id + '">' +
							'<td class="v-align-middle"><span class="hint-text fs-12">#' + co.id + '</span></td>' +
							'<td class="v-align-middle"><div><span class="semi-bold">' + escapeHtml(co.name) + '</span> <small class="hint-text m-l-5 fs-11">' + escapeHtml(co.code) + '</small></div><div class="m-t-5" id="company-providers-' + co.id + '">' + providerBadges + '</div></td>' +
							'<td class="v-align-middle"><span class="badge badge-success">active</span></td>' +
							'<td class="v-align-middle hint-text fs-12">0</td>' +
							'<td class="v-align-middle hint-text fs-12">' + escapeHtml(co.phone || '—') + '</td>' +
							'<td class="v-align-middle hint-text fs-12">' + escapeHtml(co.address || '—') + '</td>' +
							'<td class="v-align-middle text-right">' + buildCompanyActionsDropdown(co) + '</td>' +
							'</tr>';
						var $row = $(row);
						$tbody.append($row);
						initActionDropdowns($row);
					}

					// Добавляем в select'ы компаний (форма пользователя и форма назначения)
					var newOpt = $('<option>').val(co.id).text(co.name);
				$('#uf-company-id').append(newOpt.clone());
				$('#ac-company-select').append(newOpt.clone());
				if (CAN_MANAGE_OFFICES && IS_ROOT && $('#cof-company-id').length) {
					$('#cof-company-id').append(newOpt.clone());
				}
			} else {
				$('#cc-error').removeClass('d-none').text(res.data.message || 'Ошибка.');
				$btn.prop('disabled', false)
					.find('.btn-label').show()
					.end().find('#btn-create-company-spinner').addClass('d-none');
			}
		})
		.fail(function () {
			$('#cc-error').removeClass('d-none').text('Ошибка сервера.');
			$btn.prop('disabled', false)
				.find('.btn-label').show()
				.end().find('#btn-create-company-spinner').addClass('d-none');
		});
	});

	// ── Credentials modal (после создания пользователя) ──────────────────────
	$('#btn-credentials-close').on('click', function () {
		window.location.reload();
	});

	// Select2 для company в форме нового пользователя
	var $userModal = $('#modal-user-form');
	$userModal.on('shown.bs.modal', function () {
		if (!$('#uf-company-id').hasClass('select2-hidden-accessible')) {
			$('#uf-company-id').select2({ dropdownParent: $userModal });
		}
	});
	$userModal.on('hidden.bs.modal', function () {
		if ($('#uf-company-id').hasClass('select2-hidden-accessible')) {
			$('#uf-company-id').select2('destroy');
		}
	});
	<?php endif; ?>

	}(jQuery));
</script>
<?php
}, 99 );
?>

<?php
get_template_part( 'template-parts/quickview' );
get_template_part( 'template-parts/overlay' );
get_footer();
