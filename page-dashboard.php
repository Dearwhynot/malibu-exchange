<?php
/*
Template Name: Dashboard Page
Slug: dashboard
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

// ─── Скоп компании / таймзона ─────────────────────────────────────────────────
$_uid        = get_current_user_id();
$_company_id = crm_is_root( $_uid )
	? (int) CRM_DEFAULT_ORG_ID
	: crm_get_current_user_company_id( $_uid );
$_org_id     = ( $_company_id > 0 ) ? $_company_id : (int) CRM_DEFAULT_ORG_ID;
$_tz         = crm_get_timezone( $_org_id );

// ─── Диапазон «сегодня» в UTC ─────────────────────────────────────────────────
$_today_local = new DateTime( 'now', $_tz );

$_day_start = ( clone $_today_local )->setTime( 0, 0, 0 );
$_day_start->setTimezone( new DateTimeZone( 'UTC' ) );
$_day_start_sql = $_day_start->format( 'Y-m-d H:i:s' );

$_day_end = ( clone $_today_local )->setTime( 23, 59, 59 );
$_day_end->setTimezone( new DateTimeZone( 'UTC' ) );
$_day_end_sql = $_day_end->format( 'Y-m-d H:i:s' );

// ─── Диапазон «последние 7 дней» (UTC) ───────────────────────────────────────
$_week_start = ( clone $_today_local )->modify( '-6 days' )->setTime( 0, 0, 0 );
$_week_start->setTimezone( new DateTimeZone( 'UTC' ) );
$_week_start_sql = $_week_start->format( 'Y-m-d H:i:s' );

// ─── Запросы ──────────────────────────────────────────────────────────────────
global $wpdb;

$_co_cond = $_company_id > 0 ? $wpdb->prepare( ' AND company_id = %d', $_company_id ) : '';
$_co_po   = $_company_id > 0 ? $wpdb->prepare( ' AND company_id = %d', $_company_id ) : '';

// 1. Кол-во сделок сегодня (все статусы)
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$_today_total = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM `crm_fintech_payment_orders`
	 WHERE created_at BETWEEN %s AND %s" . $_co_cond,
	$_day_start_sql, $_day_end_sql
) );

// 2. Статусы за сегодня
$_today_by_status_raw = $wpdb->get_results( $wpdb->prepare(
	"SELECT status_code, COUNT(*) AS cnt
	 FROM `crm_fintech_payment_orders`
	 WHERE created_at BETWEEN %s AND %s" . $_co_cond . "
	 GROUP BY status_code",
	$_day_start_sql, $_day_end_sql
) );

$_today_status = [];
foreach ( (array) $_today_by_status_raw as $r ) {
	$_today_status[ $r->status_code ] = (int) $r->cnt;
}

// 3. USDT накоплено по paid-ордерам за сегодня (amount_asset_value = USDT)
$_today_paid_sum = (float) $wpdb->get_var( $wpdb->prepare(
	"SELECT COALESCE(SUM(amount_asset_value), 0)
	 FROM `crm_fintech_payment_orders`
	 WHERE status_code = 'paid' AND paid_at BETWEEN %s AND %s" . $_co_cond,
	$_day_start_sql, $_day_end_sql
) );

// 4. Всего USDT по paid-ордерам (все время) — база для долга ЭП
$_total_paid_all = (float) $wpdb->get_var(
	"SELECT COALESCE(SUM(amount_asset_value), 0)
	 FROM `crm_fintech_payment_orders`
	 WHERE status_code = 'paid'" . $_co_po
);

// 5. Всего выплачено ЭП в USDT (все время)
$_total_out_all = (float) $wpdb->get_var(
	"SELECT COALESCE(SUM(amount), 0)
	 FROM `crm_acquirer_payouts`
	 WHERE 1=1" . $_co_po
);

$_ep_debt = max( 0.0, $_total_paid_all - $_total_out_all );

// 6. Суммы USDT по статусам (за все время)
$_open_statuses     = [ 'created', 'pending' ];
$_closed_statuses   = [ 'paid' ];
$_canceled_statuses = [ 'declined', 'cancelled', 'expired', 'error' ];

$_ph_open   = implode( ',', array_fill( 0, count( $_open_statuses ),     '%s' ) );
$_ph_closed = implode( ',', array_fill( 0, count( $_closed_statuses ),   '%s' ) );
$_ph_cancel = implode( ',', array_fill( 0, count( $_canceled_statuses ), '%s' ) );

$_sum_open = (float) $wpdb->get_var( $wpdb->prepare(
	"SELECT COALESCE(SUM(amount_asset_value), 0)
	 FROM `crm_fintech_payment_orders`
	 WHERE status_code IN ($_ph_open)" . $_co_cond,
	$_open_statuses
) );
$_cnt_open = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM `crm_fintech_payment_orders`
	 WHERE status_code IN ($_ph_open)" . $_co_cond,
	$_open_statuses
) );

$_sum_closed = (float) $wpdb->get_var( $wpdb->prepare(
	"SELECT COALESCE(SUM(amount_asset_value), 0)
	 FROM `crm_fintech_payment_orders`
	 WHERE status_code IN ($_ph_closed)" . $_co_cond,
	$_closed_statuses
) );
$_cnt_closed = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM `crm_fintech_payment_orders`
	 WHERE status_code IN ($_ph_closed)" . $_co_cond,
	$_closed_statuses
) );

$_sum_cancel = (float) $wpdb->get_var( $wpdb->prepare(
	"SELECT COALESCE(SUM(amount_asset_value), 0)
	 FROM `crm_fintech_payment_orders`
	 WHERE status_code IN ($_ph_cancel)" . $_co_cond,
	$_canceled_statuses
) );
$_cnt_cancel = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM `crm_fintech_payment_orders`
	 WHERE status_code IN ($_ph_cancel)" . $_co_cond,
	$_canceled_statuses
) );

// 7. Последние 7 дней — динамика сделок
$_week_rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT
		DATE(CONVERT_TZ(created_at, 'UTC', %s)) AS day_local,
		COUNT(*) AS total,
		SUM(CASE WHEN status_code = 'paid' THEN 1 ELSE 0 END) AS paid_cnt
	 FROM `crm_fintech_payment_orders`
	 WHERE created_at >= %s" . $_co_cond . "
	 GROUP BY day_local
	 ORDER BY day_local",
	$_tz->getName(),
	$_week_start_sql
) );

// Заполняем массив на 7 дней (могут быть пропуски)
$_week_data_total = [];
$_week_data_paid  = [];
$_week_labels     = [];

$_today_str = $_today_local->format( 'Y-m-d' );
$_week_map  = [];
foreach ( (array) $_week_rows as $r ) {
	$_week_map[ $r->day_local ] = [ 'total' => (int) $r->total, 'paid' => (int) $r->paid_cnt ];
}
for ( $i = 6; $i >= 0; $i-- ) {
	$d = ( clone $_today_local )->modify( "-{$i} days" )->format( 'Y-m-d' );
	$_week_data_total[] = $_week_map[ $d ]['total'] ?? 0;
	$_week_data_paid[]  = $_week_map[ $d ]['paid']  ?? 0;
	$_week_labels[]     = date( 'd.m', strtotime( $d ) );
}
// phpcs:enable

// ─── Вспомогательные ─────────────────────────────────────────────────────────
function _dash_fmt_usdt( float $v ): string {
	// До 8 знаков, но обрезаем незначащие нули
	$formatted = rtrim( rtrim( number_format( $v, 8, '.', "\xc2\xa0" ), '0' ), '.' );
	return $formatted . "\xc2\xa0USDT";
}

$_vendor_img = get_template_directory_uri() . '/vendor/pages/assets/img';

get_header();
?>

<!-- BEGIN SIDEBAR -->
<?php get_template_part( 'template-parts/sidebar' ); ?>
<!-- END SIDEBAR -->

<div class="page-container">

	<!-- HEADER -->
	<div class="header">
		<a href="#" class="btn-link toggle-sidebar d-lg-none pg-icon btn-icon-link" data-toggle="sidebar">menu</a>
		<div class="">
			<div class="brand inline">
				<img src="<?php echo esc_url( $_vendor_img . '/logo.png' ); ?>" alt="logo"
				     data-src="<?php echo esc_url( $_vendor_img . '/logo.png' ); ?>"
				     data-src-retina="<?php echo esc_url( $_vendor_img . '/logo_2x.png' ); ?>"
				     width="78" height="22">
			</div>
		</div>
		<div class="d-flex align-items-center">
			<div class="dropdown pull-right d-lg-block d-none">
				<button class="profile-dropdown-toggle" type="button" data-bs-toggle="dropdown"
				        aria-haspopup="true" aria-expanded="false" aria-label="profile dropdown">
					<span class="thumbnail-wrapper d32 circular inline">
						<img src="<?php echo esc_url( $_vendor_img . '/profiles/avatar.jpg' ); ?>"
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
							<li class="breadcrumb-item active">Дашборд</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">

				<!-- ══ ROW 1: Ключевые метрики сегодня ══════════════════════════ -->
				<div class="row m-b-5">
					<div class="col-12">
						<p class="hint-text fs-12 text-uppercase m-b-10" style="letter-spacing:.07em;">
							Сегодня — <?php echo esc_html( $_today_local->format( 'd.m.Y' ) ); ?>
							<span class="m-l-10 text-muted" style="letter-spacing:0;"><?php echo esc_html( crm_get_timezone_label( $_org_id ) ); ?></span>
						</p>
					</div>
				</div>

				<div class="row m-b-20">

					<!-- Сделок сегодня -->
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="widget-8 card bg-complete no-margin">
							<div class="container-xs-height full-height">
								<div class="row-xs-height">
									<div class="col-xs-height col-top">
										<div class="card-header top-left top-right p-l-20 p-t-15 p-b-0">
											<span class="font-montserrat fs-11 all-caps text-white">Сделок сегодня</span>
										</div>
									</div>
								</div>
								<div class="row-xs-height">
									<div class="col-xs-height col-middle p-l-20 p-b-20">
										<h1 class="text-white no-margin bold"><?php echo esc_html( $_today_total ); ?></h1>
										<p class="text-white hint-text no-margin">
											<?php
											$_paid_today_cnt     = $_today_status['paid']      ?? 0;
											$_pending_today_cnt  = ( $_today_status['created'] ?? 0 ) + ( $_today_status['pending'] ?? 0 );
											echo esc_html( "paid: {$_paid_today_cnt} · ожидают: {$_pending_today_cnt}" );
											?>
										</p>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Paid сегодня (RUB) -->
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="widget-8 card bg-success no-margin">
							<div class="container-xs-height full-height">
								<div class="row-xs-height">
									<div class="col-xs-height col-top">
										<div class="card-header top-left top-right p-l-20 p-t-15 p-b-0">
											<span class="font-montserrat fs-11 all-caps text-white">Оплачено сегодня</span>
										</div>
									</div>
								</div>
								<div class="row-xs-height">
									<div class="col-xs-height col-middle p-l-20 p-b-20">
										<h3 class="text-white no-margin bold"><?php echo esc_html( _dash_fmt_usdt( $_today_paid_sum ) ); ?></h3>
										<p class="text-white hint-text no-margin">
											<?php echo esc_html( "сделок: {$_paid_today_cnt}" ); ?>
										</p>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Долг ЭП -->
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="widget-8 card <?php echo $_ep_debt > 0 ? 'bg-warning' : 'bg-master-light'; ?> no-margin">
							<div class="container-xs-height full-height">
								<div class="row-xs-height">
									<div class="col-xs-height col-top">
										<div class="card-header top-left top-right p-l-20 p-t-15 p-b-0">
											<span class="font-montserrat fs-11 all-caps <?php echo $_ep_debt > 0 ? 'text-white' : 'text-master'; ?>">Долг ЭП</span>
										</div>
									</div>
								</div>
								<div class="row-xs-height">
									<div class="col-xs-height col-middle p-l-20 p-b-20">
										<h3 class="no-margin bold <?php echo $_ep_debt > 0 ? 'text-white' : 'text-master'; ?>"><?php echo esc_html( _dash_fmt_usdt( $_ep_debt ) ); ?></h3>
										<p class="hint-text no-margin <?php echo $_ep_debt > 0 ? 'text-white' : 'text-master'; ?>">не выплачено ЭП</p>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Накоплено paid (всего) -->
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="widget-8 card bg-primary no-margin">
							<div class="container-xs-height full-height">
								<div class="row-xs-height">
									<div class="col-xs-height col-top">
										<div class="card-header top-left top-right p-l-20 p-t-15 p-b-0">
											<span class="font-montserrat fs-11 all-caps text-white">Paid-ордеров (всего)</span>
										</div>
									</div>
								</div>
								<div class="row-xs-height">
									<div class="col-xs-height col-middle p-l-20 p-b-20">
										<h3 class="text-white no-margin bold"><?php echo esc_html( _dash_fmt_usdt( $_total_paid_all ) ); ?></h3>
										<p class="text-white hint-text no-margin">
											<?php echo esc_html( "выплачено ЭП: " . _dash_fmt_usdt( $_total_out_all ) ); ?>
										</p>
									</div>
								</div>
							</div>
						</div>
					</div>

				</div>
				<!-- /ROW 1 -->

				<!-- ══ ROW 2: График + Статусы сегодня ══════════════════════════ -->
				<div class="row m-b-20">

					<!-- График: динамика за 7 дней -->
					<div class="col-lg-8 col-md-12 m-b-15">
						<div class="card card-default no-margin full-height">
							<div class="card-header">
								<div class="card-title">Сделки за последние 7 дней</div>
							</div>
							<div class="card-body">
								<div class="row m-b-10">
									<div class="col-6">
										<span class="m-r-10" style="display:inline-block;width:12px;height:12px;background:#6d5cae;border-radius:2px;"></span>
										<span class="hint-text fs-12">Всего</span>
									</div>
									<div class="col-6">
										<span class="m-r-10" style="display:inline-block;width:12px;height:12px;background:#10cfbd;border-radius:2px;"></span>
										<span class="hint-text fs-12">Paid</span>
									</div>
								</div>
								<div id="dash-sparkline-chart" style="min-height:90px; overflow-x:auto; white-space:nowrap;"></div>
								<div class="row m-t-10">
									<?php foreach ( $_week_labels as $i => $label ) : ?>
									<div class="col text-center">
										<span class="hint-text fs-10"><?php echo esc_html( $label ); ?></span>
									</div>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					</div>

					<!-- Статусы сегодня — визуализация -->
					<div class="col-lg-4 col-md-12 m-b-15">
						<div class="card card-default no-margin full-height">
							<div class="card-header">
								<div class="card-title">Статусы сегодня</div>
							</div>
							<div class="card-body">
								<?php
								$_status_defs = [
									'paid'      => [ 'label' => 'Оплачены',  'color' => 'bg-success' ],
									'pending'   => [ 'label' => 'Ожидают',   'color' => 'bg-warning' ],
									'created'   => [ 'label' => 'Созданы',   'color' => 'bg-complete' ],
									'declined'  => [ 'label' => 'Отклонены', 'color' => 'bg-danger' ],
									'cancelled' => [ 'label' => 'Отменены',  'color' => 'bg-danger' ],
									'expired'   => [ 'label' => 'Истекли',   'color' => 'bg-master-light' ],
								];
								$_total_today_for_pct = max( 1, $_today_total );
								if ( empty( $_today_status ) ) {
									echo '<p class="hint-text text-center m-t-20">Сделок сегодня нет.</p>';
								} else {
									foreach ( $_status_defs as $code => $def ) {
										$cnt = $_today_status[ $code ] ?? 0;
										if ( $cnt === 0 ) continue;
										$pct = round( $cnt / $_total_today_for_pct * 100 );
										echo '<div class="m-b-15">';
										echo '<div class="d-flex justify-content-between m-b-3">';
										echo '<span class="fs-12">' . esc_html( $def['label'] ) . '</span>';
										echo '<span class="bold fs-12">' . esc_html( $cnt ) . ' (' . esc_html( $pct ) . '%)</span>';
										echo '</div>';
										echo '<div class="progress progress-small no-margin">';
										echo '<div class="progress-bar ' . esc_attr( $def['color'] ) . '" role="progressbar" style="width:' . esc_attr( $pct ) . '%"></div>';
										echo '</div></div>';
									}
								}
								?>
							</div>
						</div>
					</div>

				</div>
				<!-- /ROW 2 -->

				<!-- ══ ROW 3: Суммы по статусам (всё время) ═════════════════════ -->
				<div class="row m-b-5">
					<div class="col-12">
						<p class="hint-text fs-12 text-uppercase m-b-10" style="letter-spacing:.07em;">Всего за всё время</p>
					</div>
				</div>

				<div class="row m-b-20">

					<div class="col-xl-4 col-lg-4 col-md-4 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<div class="d-flex align-items-center m-b-5">
									<i class="pg-icon text-warning m-r-10" style="font-size:22px;">list</i>
									<span class="hint-text fs-12 text-uppercase">Открытые</span>
									<span class="badge badge-warning m-l-auto"><?php echo esc_html( $_cnt_open ); ?></span>
								</div>
								<h4 class="no-margin bold text-warning"><?php echo esc_html( _dash_fmt_usdt( $_sum_open ) ); ?></h4>
								<p class="hint-text fs-11 no-margin">created + pending</p>
							</div>
						</div>
					</div>

					<div class="col-xl-4 col-lg-4 col-md-4 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<div class="d-flex align-items-center m-b-5">
									<i class="pg-icon text-success m-r-10" style="font-size:22px;">checkmark</i>
									<span class="hint-text fs-12 text-uppercase">Оплаченные</span>
									<span class="badge badge-success m-l-auto"><?php echo esc_html( $_cnt_closed ); ?></span>
								</div>
								<h4 class="no-margin bold text-success"><?php echo esc_html( _dash_fmt_usdt( $_sum_closed ) ); ?></h4>
								<p class="hint-text fs-11 no-margin">статус: paid</p>
							</div>
						</div>
					</div>

					<div class="col-xl-4 col-lg-4 col-md-4 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<div class="d-flex align-items-center m-b-5">
									<i class="pg-icon text-danger m-r-10" style="font-size:22px;">close</i>
									<span class="hint-text fs-12 text-uppercase">Отменённые</span>
									<span class="badge badge-danger m-l-auto"><?php echo esc_html( $_cnt_cancel ); ?></span>
								</div>
								<h4 class="no-margin bold text-danger"><?php echo esc_html( _dash_fmt_usdt( $_sum_cancel ) ); ?></h4>
								<p class="hint-text fs-11 no-margin">declined + cancelled + expired</p>
							</div>
						</div>
					</div>

				</div>
				<!-- /ROW 3 -->

				<!-- ══ ROW 4: Быстрая навигация ══════════════════════════════════ -->
				<div class="row m-b-5">
					<div class="col-12">
						<p class="hint-text fs-12 text-uppercase m-b-10" style="letter-spacing:.07em;">Быстрые переходы</p>
					</div>
				</div>

				<div class="row m-b-30">

					<?php if ( crm_can_access( 'orders.view' ) ) : ?>
					<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 m-b-10">
						<a href="<?php echo esc_url( home_url( '/orders/' ) ); ?>" class="btn btn-complete btn-block">
							<i class="pg-icon m-r-5">list</i>Ордера
						</a>
					</div>
					<?php endif; ?>

					<?php if ( crm_can_access( 'payouts.view' ) ) : ?>
					<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 m-b-10">
						<a href="<?php echo esc_url( home_url( '/payouts/' ) ); ?>" class="btn btn-success btn-block">
							<i class="pg-icon m-r-5">card</i>Выплаты ЭП
						</a>
					</div>
					<?php endif; ?>

					<?php if ( crm_can_access( 'rates.view' ) ) : ?>
					<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 m-b-10">
						<a href="<?php echo esc_url( home_url( '/rates/' ) ); ?>" class="btn btn-info btn-block">
							<i class="pg-icon m-r-5">chart</i>Курсы
						</a>
					</div>
					<?php endif; ?>

					<?php if ( crm_can_access( 'users.view' ) ) : ?>
					<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 m-b-10">
						<a href="<?php echo esc_url( home_url( '/users/' ) ); ?>" class="btn btn-default btn-block">
							<i class="pg-icon m-r-5">users</i>Пользователи
						</a>
					</div>
					<?php endif; ?>

					<?php if ( crm_can_access( 'settings.view' ) ) : ?>
					<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 m-b-10">
						<a href="<?php echo esc_url( home_url( '/settings/' ) ); ?>" class="btn btn-default btn-block">
							<i class="pg-icon m-r-5">settings</i>Настройки
						</a>
					</div>
					<?php endif; ?>

				</div>

			</div>
			<!-- /container -->

		</div>
	</div>

</div>
<!-- END PAGE CONTAINER -->

<?php get_template_part( 'template-parts/quickview' ); ?>
<?php get_template_part( 'template-parts/overlay' ); ?>

<?php
add_action( 'wp_footer', function () use ( $_week_data_total, $_week_data_paid, $_week_labels ) {
?>
<script>
(function($) {
	var totalData = <?php echo json_encode( array_values( $_week_data_total ) ); ?>;
	var paidData  = <?php echo json_encode( array_values( $_week_data_paid ) ); ?>;
	var labels    = <?php echo json_encode( array_values( $_week_labels ) ); ?>;

	function initChart() {
		if (typeof $.fn.sparkline === 'undefined') return;

		var $el = $('#dash-sparkline-chart');
		$el.empty();

		// Рисуем два слоя sparkline (composite)
		$el.sparkline(totalData, {
			type:        'bar',
			barWidth:    20,
			barSpacing:  6,
			barColor:    '#6d5cae',
			zeroColor:   '#6d5cae',
			tooltipFormatter: function(sp, opts, fields) {
				return labels[fields[0].offset] + ': ' + fields[0].value + ' всего';
			}
		});
		$el.sparkline(paidData, {
			type:        'bar',
			barWidth:    20,
			barSpacing:  6,
			barColor:    '#10cfbd',
			zeroColor:   '#10cfbd',
			composite:   true,
			tooltipFormatter: function(sp, opts, fields) {
				return labels[fields[0].offset] + ': ' + fields[0].value + ' paid';
			}
		});
	}

	$(document).ready(function() {
		initChart();
	});
})(jQuery);
</script>
<?php
}, 99 );
?>

<?php get_footer(); ?>
