<?php
/*
Template Name: Root Dashboard Page
Slug: root-dashboard
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

$_current_uid = get_current_user_id();

if ( ! crm_can_access( 'dashboard.view' ) ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

if ( ! crm_is_root( $_current_uid ) ) {
	wp_safe_redirect( malibu_exchange_get_company_dashboard_url() );
	exit;
}

global $wpdb;

$_tz                   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
$_now_local            = new DateTime( 'now', $_tz );
$_recent_users_from    = ( clone $_now_local )->modify( '-6 days' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
$_recent_companies_from = ( clone $_now_local )->modify( '-29 days' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
$_root_companies = $wpdb->get_results(
	"SELECT
		c.id,
		c.code,
		c.name,
		c.status,
		c.created_at,
		(
			SELECT COUNT(*)
			FROM crm_user_companies uc
			WHERE uc.company_id = c.id AND uc.is_primary = 1 AND uc.status = 'active'
		) AS users_cnt,
		(
			SELECT COUNT(*)
			FROM crm_fintech_payment_orders o
			WHERE o.company_id = c.id
		) AS orders_cnt,
		(
			SELECT COALESCE(SUM(o.amount_asset_value), 0)
			FROM crm_fintech_payment_orders o
			WHERE o.company_id = c.id AND o.status_code = 'paid'
		) AS paid_sum,
		(
			SELECT COALESCE(SUM(p.amount), 0)
			FROM crm_acquirer_payouts p
			WHERE p.company_id = c.id
		) AS payouts_sum,
		(
			SELECT MAX(o.created_at)
			FROM crm_fintech_payment_orders o
			WHERE o.company_id = c.id
		) AS last_order_at
	FROM crm_companies c
	WHERE c.id > 0
	ORDER BY FIELD(c.status, 'active', 'blocked', 'archived'), c.id ASC"
) ?: [];

$_root_status_stats = $wpdb->get_row(
	"SELECT
		COALESCE(SUM(CASE WHEN status_code IN ('created', 'pending') THEN 1 ELSE 0 END), 0) AS open_cnt,
		COALESCE(SUM(CASE WHEN status_code IN ('created', 'pending') THEN amount_asset_value ELSE 0 END), 0) AS open_sum,
		COALESCE(SUM(CASE WHEN status_code = 'paid' THEN 1 ELSE 0 END), 0) AS closed_cnt,
		COALESCE(SUM(CASE WHEN status_code = 'paid' THEN amount_asset_value ELSE 0 END), 0) AS closed_sum,
		COALESCE(SUM(CASE WHEN status_code IN ('declined', 'cancelled', 'expired', 'error') THEN 1 ELSE 0 END), 0) AS cancel_cnt,
		COALESCE(SUM(CASE WHEN status_code IN ('declined', 'cancelled', 'expired', 'error') THEN amount_asset_value ELSE 0 END), 0) AS cancel_sum
	FROM crm_fintech_payment_orders
	WHERE company_id > 0"
);

$_root_new_users_7d = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(DISTINCT uc.user_id)
	FROM {$wpdb->users} u
	INNER JOIN crm_user_companies uc
		ON uc.user_id = u.ID
		AND uc.is_primary = 1
		AND uc.status = 'active'
	WHERE u.ID != 1
		AND uc.company_id > 0
		AND u.user_registered >= %s",
	$_recent_users_from
) );

$_root_new_companies_30d = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*)
	FROM crm_companies
	WHERE id > 0
		AND created_at >= %s",
	$_recent_companies_from
) );

$_root_recent_users = $wpdb->get_results(
	"SELECT
		u.ID,
		u.user_login,
		u.display_name,
		u.user_registered,
		c.id AS company_id,
		c.name AS company_name
	FROM {$wpdb->users} u
	INNER JOIN crm_user_companies uc
		ON uc.user_id = u.ID
		AND uc.is_primary = 1
		AND uc.status = 'active'
	INNER JOIN crm_companies c
		ON c.id = uc.company_id
	WHERE u.ID != 1
		AND c.id > 0
	ORDER BY u.user_registered DESC
	LIMIT 8"
) ?: [];

$_root_recent_payouts = $wpdb->get_results(
	"SELECT
		p.id,
		p.amount,
		p.created_at,
		c.id AS company_id,
		c.name AS company_name
	FROM crm_acquirer_payouts p
	LEFT JOIN crm_companies c
		ON c.id = p.company_id
	WHERE p.company_id > 0
	ORDER BY p.created_at DESC
	LIMIT 8"
) ?: [];
// phpcs:enable

$_root_company_total        = count( $_root_companies );
$_root_company_active_count = 0;
$_root_users_total          = 0;
$_root_orders_total         = 0;
$_root_paid_total           = 0.0;
$_root_payout_total         = 0.0;

foreach ( $_root_companies as $_root_company ) {
	if ( $_root_company->status === 'active' ) {
		$_root_company_active_count++;
	}

	$_root_users_total  += (int) $_root_company->users_cnt;
	$_root_orders_total += (int) $_root_company->orders_cnt;
	$_root_paid_total   += (float) $_root_company->paid_sum;
	$_root_payout_total += (float) $_root_company->payouts_sum;
}

$_root_debt_total = $_root_paid_total - $_root_payout_total;
$_root_open_cnt   = (int) ( $_root_status_stats->open_cnt ?? 0 );
$_root_open_sum   = (float) ( $_root_status_stats->open_sum ?? 0 );
$_root_closed_cnt = (int) ( $_root_status_stats->closed_cnt ?? 0 );
$_root_closed_sum = (float) ( $_root_status_stats->closed_sum ?? 0 );
$_root_cancel_cnt = (int) ( $_root_status_stats->cancel_cnt ?? 0 );
$_root_cancel_sum = (float) ( $_root_status_stats->cancel_sum ?? 0 );

function _root_dash_fmt_usdt( float $value ): string {
	$formatted = number_format( $value, 8, '.', "\xc2\xa0" );

	if ( strpos( $formatted, '.' ) === false ) {
		return $formatted . '.00' . "\xc2\xa0USDT";
	}

	list( $integer, $fraction ) = explode( '.', $formatted, 2 );
	$fraction = rtrim( $fraction, '0' );

	if ( $fraction === '' ) {
		$fraction = '00';
	} elseif ( strlen( $fraction ) < 2 ) {
		$fraction = str_pad( $fraction, 2, '0' );
	}

	return $integer . '.' . $fraction . "\xc2\xa0USDT";
}

$_root_company_status_map = [
	'active'   => [ 'label' => 'Активна', 'class' => 'text-success' ],
	'blocked'  => [ 'label' => 'Заблокирована', 'class' => 'text-warning' ],
	'archived' => [ 'label' => 'Архив', 'class' => 'text-master' ],
];

get_header();
?>

<?php get_template_part( 'template-parts/sidebar' ); ?>

<div class="page-container">
	<?php get_template_part( 'template-parts/header-backoffice' ); ?>

	<div class="page-content-wrapper">
		<div class="content">
			<div class="jumbotron" data-pages="parallax">
				<div class="container-fluid container-fixed-lg sm-p-l-0 sm-p-r-0">
					<div class="inner">
						<ol class="breadcrumb">
							<li class="breadcrumb-item active">Сводка</li>
						</ol>
					</div>
				</div>
			</div>

			<div class="container-fluid container-fixed-lg mt-4">
				<div class="row m-b-5">
					<div class="col-12">
						<p class="hint-text fs-12 text-uppercase m-b-10" style="letter-spacing:.07em;">
							Сводка root по всем бизнес-компаниям — <?php echo esc_html( $_now_local->format( 'd.m.Y H:i' ) ); ?>
							<span class="m-l-10 text-muted" style="letter-spacing:0;"><?php echo esc_html( $_tz->getName() ); ?></span>
						</p>
						<p class="hint-text m-b-20">
							Отдельный контур только для root. Обычный <strong>dashboard</strong> остаётся company-scoped: для обычного пользователя он показывает его компанию, для root — его системный контур <strong>0</strong>. Этот обзор в агрегаты системную компанию <strong>0</strong> не включает.
						</p>
					</div>
				</div>

				<div class="row m-b-20">
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Активных компаний</p>
								<h3 class="no-margin bold text-master"><?php echo esc_html( $_root_company_active_count ); ?></h3>
								<p class="hint-text fs-11 no-margin">всего компаний: <?php echo esc_html( $_root_company_total ); ?></p>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Пользователей</p>
								<h3 class="no-margin bold text-complete"><?php echo esc_html( $_root_users_total ); ?></h3>
								<p class="hint-text fs-11 no-margin">активные primary-привязки</p>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Сделок всего</p>
								<h3 class="no-margin bold text-primary"><?php echo esc_html( $_root_orders_total ); ?></h3>
								<p class="hint-text fs-11 no-margin">по всем компаниям</p>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Общий долг ЭП</p>
								<h3 class="no-margin bold font-montserrat <?php echo $_root_debt_total >= 0 ? 'text-warning' : 'text-success'; ?>">
									<?php echo esc_html( _root_dash_fmt_usdt( $_root_debt_total ) ); ?>
								</h3>
								<p class="hint-text fs-11 no-margin">paid минус выплаты</p>
							</div>
						</div>
					</div>
				</div>

				<div class="row m-b-20">
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Paid по всем компаниям</p>
								<h3 class="no-margin bold text-success font-montserrat"><?php echo esc_html( _root_dash_fmt_usdt( $_root_paid_total ) ); ?></h3>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Выплаты ЭП</p>
								<h3 class="no-margin bold text-complete font-montserrat"><?php echo esc_html( _root_dash_fmt_usdt( $_root_payout_total ) ); ?></h3>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Новых пользователей 7д</p>
								<h3 class="no-margin bold text-primary"><?php echo esc_html( $_root_new_users_7d ); ?></h3>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Новых компаний 30д</p>
								<h3 class="no-margin bold text-info"><?php echo esc_html( $_root_new_companies_30d ); ?></h3>
							</div>
						</div>
					</div>
				</div>

				<div class="row m-b-20">
					<div class="col-xl-4 col-lg-4 col-md-4 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<div class="d-flex align-items-center m-b-5">
									<i class="pg-icon text-warning m-r-10" style="font-size:22px;">time</i>
									<span class="hint-text fs-12 text-uppercase">Открытые сделки</span>
								</div>
								<h4 class="no-margin bold text-warning font-montserrat"><?php echo esc_html( _root_dash_fmt_usdt( $_root_open_sum ) ); ?></h4>
								<p class="hint-text fs-11 no-margin">created + pending · <?php echo esc_html( $_root_open_cnt ); ?> шт.</p>
							</div>
						</div>
					</div>
					<div class="col-xl-4 col-lg-4 col-md-4 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<div class="d-flex align-items-center m-b-5">
									<i class="pg-icon text-success m-r-10" style="font-size:22px;">tick_circle</i>
									<span class="hint-text fs-12 text-uppercase">Закрытые сделки</span>
								</div>
								<h4 class="no-margin bold text-success font-montserrat"><?php echo esc_html( _root_dash_fmt_usdt( $_root_closed_sum ) ); ?></h4>
								<p class="hint-text fs-11 no-margin">paid · <?php echo esc_html( $_root_closed_cnt ); ?> шт.</p>
							</div>
						</div>
					</div>
					<div class="col-xl-4 col-lg-4 col-md-4 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<div class="d-flex align-items-center m-b-5">
									<i class="pg-icon text-danger m-r-10" style="font-size:22px;">close</i>
									<span class="hint-text fs-12 text-uppercase">Отменённые / ошибочные</span>
								</div>
								<h4 class="no-margin bold text-danger font-montserrat"><?php echo esc_html( _root_dash_fmt_usdt( $_root_cancel_sum ) ); ?></h4>
								<p class="hint-text fs-11 no-margin">declined + cancelled + expired + error · <?php echo esc_html( $_root_cancel_cnt ); ?> шт.</p>
							</div>
						</div>
					</div>
				</div>

				<div class="card card-default m-b-20">
					<div class="card-header">
						<div class="card-title">Компании</div>
					</div>
					<div class="card-body">
						<div class="table-responsive">
							<table class="table table-hover m-b-0">
								<thead>
									<tr>
										<th>#</th>
										<th>Статус</th>
										<th>Компания</th>
										<th>Пользователи</th>
										<th>Ордера</th>
										<th>Paid</th>
										<th>Выплаты</th>
										<th>Долг ЭП</th>
										<th>Последний ордер</th>
										<th>Создана</th>
									</tr>
								</thead>
								<tbody>
									<?php if ( empty( $_root_companies ) ) : ?>
									<tr>
										<td colspan="10" class="text-center hint-text p-t-20 p-b-20">Бизнес-компаний пока нет.</td>
									</tr>
									<?php else : ?>
										<?php foreach ( $_root_companies as $_root_company ) : ?>
											<?php
											$_company_debt   = (float) $_root_company->paid_sum - (float) $_root_company->payouts_sum;
											$_last_order     = ! empty( $_root_company->last_order_at ) ? wp_date( 'd.m.Y H:i', strtotime( (string) $_root_company->last_order_at ), $_tz ) : '—';
											$_company_created = ! empty( $_root_company->created_at ) ? wp_date( 'd.m.Y H:i', strtotime( (string) $_root_company->created_at ), $_tz ) : '—';
											$_status_meta    = $_root_company_status_map[ $_root_company->status ] ?? [ 'label' => (string) $_root_company->status, 'class' => 'text-master' ];
											?>
											<tr>
												<td><?php echo esc_html( (int) $_root_company->id ); ?></td>
												<td><span class="<?php echo esc_attr( $_status_meta['class'] ); ?>"><?php echo esc_html( $_status_meta['label'] ); ?></span></td>
												<td>
													<div class="bold"><?php echo esc_html( $_root_company->name ); ?></div>
													<div class="hint-text fs-11"><?php echo esc_html( $_root_company->code ); ?></div>
												</td>
												<td><?php echo esc_html( (int) $_root_company->users_cnt ); ?></td>
												<td><?php echo esc_html( (int) $_root_company->orders_cnt ); ?></td>
												<td class="text-success"><?php echo esc_html( _root_dash_fmt_usdt( (float) $_root_company->paid_sum ) ); ?></td>
												<td class="text-complete"><?php echo esc_html( _root_dash_fmt_usdt( (float) $_root_company->payouts_sum ) ); ?></td>
												<td class="<?php echo esc_attr( $_company_debt >= 0 ? 'text-warning' : 'text-success' ); ?>"><?php echo esc_html( _root_dash_fmt_usdt( $_company_debt ) ); ?></td>
												<td><?php echo esc_html( $_last_order ); ?></td>
												<td><?php echo esc_html( $_company_created ); ?></td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<div class="row m-b-20">
					<div class="col-lg-6 col-md-12 m-b-15">
						<div class="card card-default no-margin full-height">
							<div class="card-header">
								<div class="card-title">Последние созданные пользователи</div>
							</div>
							<div class="card-body">
								<div class="table-responsive">
									<table class="table table-hover m-b-0">
										<thead>
											<tr>
												<th>Пользователь</th>
												<th>Компания</th>
												<th>Создан</th>
											</tr>
										</thead>
										<tbody>
											<?php if ( empty( $_root_recent_users ) ) : ?>
											<tr>
												<td colspan="3" class="text-center hint-text p-t-20 p-b-20">Созданных пользователей пока нет.</td>
											</tr>
											<?php else : ?>
												<?php foreach ( $_root_recent_users as $_root_user ) : ?>
												<tr>
													<td>
														<div class="bold"><?php echo esc_html( $_root_user->display_name !== '' ? $_root_user->display_name : $_root_user->user_login ); ?></div>
														<div class="hint-text fs-11"><?php echo esc_html( $_root_user->user_login ); ?></div>
													</td>
													<td><?php echo esc_html( $_root_user->company_name ); ?></td>
													<td><?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( (string) $_root_user->user_registered ), $_tz ) ); ?></td>
												</tr>
												<?php endforeach; ?>
											<?php endif; ?>
										</tbody>
									</table>
								</div>
							</div>
						</div>
					</div>
					<div class="col-lg-6 col-md-12 m-b-15">
						<div class="card card-default no-margin full-height">
							<div class="card-header">
								<div class="card-title">Последние выплаты ЭП</div>
							</div>
							<div class="card-body">
								<div class="table-responsive">
									<table class="table table-hover m-b-0">
										<thead>
											<tr>
												<th>ID</th>
												<th>Компания</th>
												<th>Сумма</th>
												<th>Создана</th>
											</tr>
										</thead>
										<tbody>
											<?php if ( empty( $_root_recent_payouts ) ) : ?>
											<tr>
												<td colspan="4" class="text-center hint-text p-t-20 p-b-20">Выплат пока нет.</td>
											</tr>
											<?php else : ?>
												<?php foreach ( $_root_recent_payouts as $_root_payout ) : ?>
												<tr>
													<td><?php echo esc_html( (int) $_root_payout->id ); ?></td>
													<td><?php echo esc_html( $_root_payout->company_name ?: '—' ); ?></td>
													<td class="text-complete"><?php echo esc_html( _root_dash_fmt_usdt( (float) $_root_payout->amount ) ); ?></td>
													<td><?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( (string) $_root_payout->created_at ), $_tz ) ); ?></td>
												</tr>
												<?php endforeach; ?>
											<?php endif; ?>
										</tbody>
									</table>
								</div>
							</div>
						</div>
					</div>
				</div>

			</div>
		</div>

		<?php get_template_part( 'template-parts/footer-backoffice' ); ?>
	</div>
</div>

<?php get_template_part( 'template-parts/quickview' ); ?>
<?php get_template_part( 'template-parts/overlay' ); ?>
<?php get_footer(); ?>
