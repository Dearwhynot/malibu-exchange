<?php
/*
Template Name: Dashboard Page
Slug: dashboard
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

malibu_exchange_require_login();

// Root использует тот же company-scoped экран, что и остальные, но в company_id = 0.
// Отдельный all-company обзор будет вынесен на специальную страницу позже.
$_dashboard_is_root = false;
global $wpdb;

if ( $_dashboard_is_root ) {
	$_tz         = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	$_today_local = new DateTime( 'now', $_tz );

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	$_root_companies = $wpdb->get_results(
		"SELECT
			c.id,
			c.code,
			c.name,
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
		WHERE c.status = 'active'
		ORDER BY c.id ASC"
	) ?: [];
	// phpcs:enable

	$_root_company_count = count( $_root_companies );
	$_root_users_total   = 0;
	$_root_orders_total  = 0;
	$_root_paid_total    = 0.0;
	$_root_payout_total  = 0.0;

	foreach ( $_root_companies as $_root_company ) {
		$_root_users_total  += (int) $_root_company->users_cnt;
		$_root_orders_total += (int) $_root_company->orders_cnt;
		$_root_paid_total   += (float) $_root_company->paid_sum;
		$_root_payout_total += (float) $_root_company->payouts_sum;
	}

	$_root_debt_total = $_root_paid_total - $_root_payout_total;
} else {
	// ─── Скоп компании / таймзона ─────────────────────────────────────────────
	$_company_id = crm_require_company_page_context();
	$_tz         = crm_get_timezone( $_company_id );

	// ─── Диапазон «сегодня» в UTC ─────────────────────────────────────────────
	$_today_local = new DateTime( 'now', $_tz );

	$_day_start = ( clone $_today_local )->setTime( 0, 0, 0 );
	$_day_start->setTimezone( new DateTimeZone( 'UTC' ) );
	$_day_start_sql = $_day_start->format( 'Y-m-d H:i:s' );

	$_day_end = ( clone $_today_local )->setTime( 23, 59, 59 );
	$_day_end->setTimezone( new DateTimeZone( 'UTC' ) );
	$_day_end_sql = $_day_end->format( 'Y-m-d H:i:s' );

	// ─── Диапазон «последние 7 дней» (UTC) ───────────────────────────────────
	$_week_start = ( clone $_today_local )->modify( '-6 days' )->setTime( 0, 0, 0 );
	$_week_start->setTimezone( new DateTimeZone( 'UTC' ) );
	$_week_start_sql = $_week_start->format( 'Y-m-d H:i:s' );

	// ─── Запросы ──────────────────────────────────────────────────────────────
	$_co_cond = $wpdb->prepare( ' AND company_id = %d', $_company_id );
	$_co_po   = $wpdb->prepare( ' AND company_id = %d', $_company_id );

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

	// 6. Финансовая картина компании: внешний контур, мерчанты, прибыль.
	$_merchant_balance_totals = $wpdb->get_row(
		"SELECT
			COALESCE(SUM(CASE
				WHEN entry_type IN ('merchant_accrual', 'manual_credit')
					THEN amount
				WHEN entry_type IN ('merchant_payout', 'manual_debit')
					THEN -ABS(amount)
				ELSE 0
			END), 0) AS main_balance,
			COALESCE(SUM(CASE
				WHEN entry_type IN ('bonus_accrual', 'bonus_adjustment')
					THEN amount
				WHEN entry_type = 'bonus_withdrawal'
					THEN -ABS(amount)
				ELSE 0
			END), 0) AS bonus_balance,
			COALESCE(SUM(CASE
				WHEN entry_type IN ('referral_accrual', 'referral_adjustment')
					THEN amount
				ELSE 0
			END), 0) AS referral_balance,
			COALESCE(SUM(CASE
				WHEN entry_type IN ('merchant_accrual', 'manual_credit', 'bonus_accrual', 'bonus_adjustment', 'referral_accrual', 'referral_adjustment')
					THEN amount
				WHEN entry_type IN ('merchant_payout', 'manual_debit', 'bonus_withdrawal')
					THEN -ABS(amount)
				ELSE 0
			END), 0) AS total_balance
		 FROM `crm_merchant_wallet_ledger`
		 WHERE 1=1" . $_co_po
	);
	$_merchant_main_payable_all     = max( 0.0, (float) ( $_merchant_balance_totals->main_balance ?? 0 ) );
	$_merchant_bonus_payable_all    = max( 0.0, (float) ( $_merchant_balance_totals->bonus_balance ?? 0 ) );
	$_merchant_referral_payable_all = max( 0.0, (float) ( $_merchant_balance_totals->referral_balance ?? 0 ) );
	$_merchant_payable_all          = max( 0.0, (float) ( $_merchant_balance_totals->total_balance ?? 0 ) );

	$_has_merchant_payouts_table = function_exists( 'malibu_migrations_table_exists' )
		&& malibu_migrations_table_exists( 'crm_merchant_payouts' );
	$_merchant_payouts_total = $_has_merchant_payouts_table
		? (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(amount), 0)
			 FROM `crm_merchant_payouts`
			 WHERE 1=1" . $_co_po
		)
		: 0.0;

	$_merchant_paid_gross_total = (float) $wpdb->get_var(
		"SELECT COALESCE(SUM(amount_asset_value), 0)
		 FROM `crm_fintech_payment_orders`
		 WHERE status_code = 'paid'
		   AND created_for_type = 'merchant'" . $_co_po
	);

	$_company_paid_gross_total = (float) $wpdb->get_var(
		"SELECT COALESCE(SUM(amount_asset_value), 0)
		 FROM `crm_fintech_payment_orders`
		 WHERE status_code = 'paid'
		   AND created_for_type = 'company'" . $_co_po
	);

	$_platform_fee_total = (float) $wpdb->get_var(
		"SELECT COALESCE(SUM(COALESCE(platform_fee_value, merchant_markup_value, 0)), 0)
		 FROM `crm_fintech_payment_orders`
		 WHERE status_code = 'paid'
		   AND created_for_type = 'merchant'" . $_co_po
	);
	$_company_withdrawals_total = 0.0;
	if ( function_exists( 'crm_company_withdrawals_get_stats' ) ) {
		$_company_wallet_stats      = crm_company_withdrawals_get_stats( $_company_id );
		$_company_withdrawals_total = (float) ( $_company_wallet_stats['withdrawals_total'] ?? 0 );
		$_company_profit_balance    = (float) ( $_company_wallet_stats['available_balance'] ?? ( $_platform_fee_total - $_company_withdrawals_total ) );
	} else {
		$_company_profit_balance = $_platform_fee_total;
	}

	// 7. Суммы USDT по статусам (за все время)
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

	$_all_time_total_cnt = $_cnt_open + $_cnt_closed + $_cnt_cancel;
	$_all_time_total_sum = $_sum_open + $_sum_closed + $_sum_cancel;
	$_dashboard_has_all_time_orders = $_all_time_total_cnt > 0;

	// 8. Последние 7 дней — динамика сделок.
	// Даты группируем в PHP, чтобы не зависеть от MySQL timezone tables.
	$_week_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT created_at, status_code
		 FROM `crm_fintech_payment_orders`
		 WHERE created_at >= %s" . $_co_cond . "
		 ORDER BY created_at ASC",
		$_week_start_sql
	) );

	$_week_data_total = [];
	$_week_data_paid  = [];
	$_week_labels     = [];
	$_week_map        = [];

	foreach ( (array) $_week_rows as $r ) {
		try {
			$_created_local = new DateTimeImmutable( (string) $r->created_at, new DateTimeZone( 'UTC' ) );
			$_created_local = $_created_local->setTimezone( $_tz );
		} catch ( Exception $e ) {
			continue;
		}

		$_day_local = $_created_local->format( 'Y-m-d' );
		if ( ! isset( $_week_map[ $_day_local ] ) ) {
			$_week_map[ $_day_local ] = [ 'total' => 0, 'paid' => 0 ];
		}

		$_week_map[ $_day_local ]['total']++;
		if ( (string) $r->status_code === 'paid' ) {
			$_week_map[ $_day_local ]['paid']++;
		}
	}

	for ( $i = 6; $i >= 0; $i-- ) {
		$d = ( clone $_today_local )->modify( "-{$i} days" )->format( 'Y-m-d' );
		$_week_data_total[] = $_week_map[ $d ]['total'] ?? 0;
		$_week_data_paid[]  = $_week_map[ $d ]['paid']  ?? 0;
		$_week_labels[]     = date( 'd.m', strtotime( $d ) );
	}
	$_week_has_activity = array_sum( $_week_data_total ) > 0 || array_sum( $_week_data_paid ) > 0;

	$_week_chart_title       = 'Сделки за последние 7 дней';
	$_week_chart_hint        = 'Календарные дни по таймзоне компании.';
	$_week_chart_is_fallback = false;

	if ( ! $_week_has_activity && $_dashboard_has_all_time_orders ) {
		$_active_rows = $wpdb->get_results(
			"SELECT created_at, status_code
			 FROM `crm_fintech_payment_orders`
			 WHERE 1=1" . $_co_cond . "
			 ORDER BY created_at DESC
			 LIMIT 500"
		);

		$_active_map        = [];
		$_active_day_order  = [];
		foreach ( (array) $_active_rows as $r ) {
			try {
				$_created_local = new DateTimeImmutable( (string) $r->created_at, new DateTimeZone( 'UTC' ) );
				$_created_local = $_created_local->setTimezone( $_tz );
			} catch ( Exception $e ) {
				continue;
			}

			$_day_local = $_created_local->format( 'Y-m-d' );
			if ( ! isset( $_active_map[ $_day_local ] ) ) {
				if ( count( $_active_day_order ) >= 7 ) {
					break;
				}
				$_active_map[ $_day_local ] = [ 'total' => 0, 'paid' => 0 ];
				$_active_day_order[]        = $_day_local;
			}

			$_active_map[ $_day_local ]['total']++;
			if ( (string) $r->status_code === 'paid' ) {
				$_active_map[ $_day_local ]['paid']++;
			}
		}

		if ( ! empty( $_active_day_order ) ) {
			$_week_data_total      = [];
			$_week_data_paid       = [];
			$_week_labels          = [];
			$_active_days_ascending = array_reverse( $_active_day_order );

			foreach ( $_active_days_ascending as $_day_local ) {
				$_week_data_total[] = $_active_map[ $_day_local ]['total'];
				$_week_data_paid[]  = $_active_map[ $_day_local ]['paid'];
				$_week_labels[]     = date( 'd.m', strtotime( $_day_local ) );
			}

			$_week_has_activity     = true;
			$_week_chart_title      = 'Сделки за последние активные дни';
			$_week_chart_hint       = 'За календарные 7 дней пусто, поэтому показаны последние дни с ордерами.';
			$_week_chart_is_fallback = true;
		}
	}

	$_week_total_count = array_sum( $_week_data_total );
	$_week_paid_count  = array_sum( $_week_data_paid );
	$_week_max_daily_total = ! empty( $_week_data_total ) ? max( array_map( 'intval', $_week_data_total ) ) : 0;
	$_week_active_days_count = 0;
	$_week_paid_share = $_week_total_count > 0
		? (int) round( ( $_week_paid_count / $_week_total_count ) * 100 )
		: 0;

	foreach ( $_week_data_total as $_week_daily_total ) {
		if ( (int) $_week_daily_total > 0 ) {
			$_week_active_days_count++;
		}
	}
	// phpcs:enable
}

// ─── Вспомогательные ─────────────────────────────────────────────────────────
function _dash_fmt_usdt( float $v ): string {
	$formatted = number_format( $v, 8, '.', "\xc2\xa0" );

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

get_header();
?>

<!-- BEGIN SIDEBAR -->
<?php get_template_part( 'template-parts/sidebar' ); ?>
<!-- END SIDEBAR -->

<div class="page-container">

	<?php get_template_part( 'template-parts/header-backoffice' ); ?>

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
				<?php if ( $_dashboard_is_root ) : ?>

				<div class="row m-b-5">
					<div class="col-12">
						<p class="hint-text fs-12 text-uppercase m-b-10" style="letter-spacing:.07em;">
							Root company 0 overview — <?php echo esc_html( $_today_local->format( 'd.m.Y' ) ); ?>
							<span class="m-l-10 text-muted" style="letter-spacing:0;"><?php echo esc_html( $_tz->getName() ); ?></span>
						</p>
					</div>
				</div>

				<div class="row m-b-20">
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Активных компаний</p>
								<h3 class="no-margin bold text-master"><?php echo esc_html( $_root_company_count ); ?></h3>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Пользователей</p>
								<h3 class="no-margin bold text-complete"><?php echo esc_html( $_root_users_total ); ?></h3>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Ордера по всем компаниям</p>
								<h3 class="no-margin bold text-primary"><?php echo esc_html( $_root_orders_total ); ?></h3>
							</div>
						</div>
					</div>
					<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Общий долг ЭП</p>
								<h3 class="no-margin bold <?php echo $_root_debt_total >= 0 ? 'text-warning' : 'text-success'; ?>">
									<?php echo esc_html( _dash_fmt_usdt( $_root_debt_total ) ); ?>
								</h3>
							</div>
						</div>
					</div>
				</div>

				<div class="row m-b-20">
					<div class="col-xl-6 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Paid по всем компаниям</p>
								<h3 class="no-margin bold text-success"><?php echo esc_html( _dash_fmt_usdt( $_root_paid_total ) ); ?></h3>
							</div>
						</div>
					</div>
					<div class="col-xl-6 col-lg-6 col-md-6 col-sm-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Выплаты ЭП по всем компаниям</p>
								<h3 class="no-margin bold text-complete"><?php echo esc_html( _dash_fmt_usdt( $_root_payout_total ) ); ?></h3>
							</div>
						</div>
					</div>
				</div>

				<div class="card card-default m-b-20">
					<div class="card-header">
						<div class="card-title">Компании</div>
					</div>
					<div class="card-body">
						<p class="hint-text m-b-20">
							Это обзор для root с системной компанией 0. Детальные all-company экраны по ордерам, выплатам и логам можно сделать отдельно, не ослабляя company-scoped страницы.
						</p>
						<div class="table-responsive">
							<table class="table table-hover m-b-0">
								<thead>
									<tr>
										<th>#</th>
										<th>Компания</th>
										<th>Пользователи</th>
										<th>Ордера</th>
										<th>Paid</th>
										<th>Выплаты</th>
										<th>Долг ЭП</th>
										<th>Последний ордер</th>
									</tr>
								</thead>
								<tbody>
									<?php if ( empty( $_root_companies ) ) : ?>
									<tr>
										<td colspan="8" class="text-center hint-text p-t-20 p-b-20">Активных компаний пока нет.</td>
									</tr>
									<?php else : ?>
										<?php foreach ( $_root_companies as $_root_company ) : ?>
											<?php
											$_company_debt = (float) $_root_company->paid_sum - (float) $_root_company->payouts_sum;
											$_last_order   = '';
											if ( ! empty( $_root_company->last_order_at ) ) {
												$_last_order = wp_date( 'd.m.Y H:i', strtotime( (string) $_root_company->last_order_at ), $_tz );
											}
											?>
											<tr>
												<td><?php echo esc_html( (int) $_root_company->id ); ?></td>
												<td>
													<div class="bold"><?php echo esc_html( $_root_company->name ); ?></div>
													<div class="hint-text fs-11"><?php echo esc_html( $_root_company->code ); ?></div>
												</td>
												<td><?php echo esc_html( (int) $_root_company->users_cnt ); ?></td>
												<td><?php echo esc_html( (int) $_root_company->orders_cnt ); ?></td>
												<td class="text-success"><?php echo esc_html( _dash_fmt_usdt( (float) $_root_company->paid_sum ) ); ?></td>
												<td class="text-complete"><?php echo esc_html( _dash_fmt_usdt( (float) $_root_company->payouts_sum ) ); ?></td>
												<td class="<?php echo $_company_debt >= 0 ? 'text-warning' : 'text-success'; ?>">
													<?php echo esc_html( _dash_fmt_usdt( $_company_debt ) ); ?>
												</td>
												<td><?php echo esc_html( $_last_order !== '' ? $_last_order : '—' ); ?></td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>

				<div class="row m-b-30">
					<div class="col-xl-3 col-lg-4 col-md-6 col-sm-12 m-b-10">
						<a href="<?php echo esc_url( home_url( '/users/' ) ); ?>" class="btn btn-default btn-block">
							<i class="pg-icon m-r-5">users</i>Пользователи и компании
						</a>
					</div>
				</div>

				<?php else : ?>

				<!-- ══ ROW 1: Ключевые метрики сегодня ══════════════════════════ -->
				<div class="row m-b-5">
					<div class="col-12">
						<p class="hint-text fs-12 text-uppercase m-b-10" style="letter-spacing:.07em;">
							Сегодня — <?php echo esc_html( $_today_local->format( 'd.m.Y' ) ); ?>
							<span class="m-l-10 text-muted" style="letter-spacing:0;"><?php echo esc_html( crm_get_timezone_label( $_company_id ) ); ?></span>
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
										<h3 class="text-white no-margin bold font-montserrat"><?php echo esc_html( _dash_fmt_usdt( $_today_paid_sum ) ); ?></h3>
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
										<h3 class="no-margin bold font-montserrat <?php echo $_ep_debt > 0 ? 'text-white' : 'text-master'; ?>"><?php echo esc_html( _dash_fmt_usdt( $_ep_debt ) ); ?></h3>
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
										<h3 class="text-white no-margin bold font-montserrat"><?php echo esc_html( _dash_fmt_usdt( $_total_paid_all ) ); ?></h3>
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

				<!-- ══ ROW 2: Финансовая картина компании ══════════════════════ -->
				<div class="row m-b-5">
					<div class="col-12">
						<p class="hint-text fs-12 text-uppercase m-b-10" style="letter-spacing:.07em;">Финансовая картина компании</p>
					</div>
				</div>

				<div class="row m-b-20">
					<?php
					$_dashboard_finance_cards = [
						[
							'label'   => 'Долг ЭП → компании',
							'value'   => $_ep_debt,
							'color'   => $_ep_debt > 0 ? 'text-warning' : 'text-success',
							'caption' => 'paid-ордера минус выплаты ЭП',
						],
						[
							'label'   => 'К выплате мерчантам',
							'value'   => $_merchant_payable_all,
							'color'   => $_merchant_payable_all > 0 ? 'text-warning' : 'text-success',
							'caption' => 'основной + бонус + рефка',
						],
						[
							'label'   => 'Выплачено мерчантам',
							'value'   => $_merchant_payouts_total,
							'color'   => 'text-complete',
							'caption' => 'зафиксированные выплаты',
						],
						[
							'label'   => 'Прибыль компании',
							'value'   => $_company_profit_balance,
							'color'   => $_company_profit_balance < 0 ? 'text-danger' : 'text-success',
							'caption' => 'merchant fee минус выводы',
						],
					];
					?>
					<?php foreach ( $_dashboard_finance_cards as $_dashboard_finance_card ) : ?>
						<div class="col-xl-3 col-lg-6 col-md-6 col-sm-12 m-b-15">
							<div class="card card-default no-margin">
								<div class="card-body p-3">
									<p class="hint-text no-margin fs-12 text-uppercase"><?php echo esc_html( $_dashboard_finance_card['label'] ); ?></p>
									<h3 class="no-margin bold font-montserrat <?php echo esc_attr( $_dashboard_finance_card['color'] ); ?>">
										<?php echo esc_html( _dash_fmt_usdt( (float) $_dashboard_finance_card['value'] ) ); ?>
									</h3>
									<p class="hint-text no-margin fs-12"><?php echo esc_html( $_dashboard_finance_card['caption'] ); ?></p>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="row m-b-20">
					<div class="col-lg-4 col-md-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Операторский / company контур paid</p>
								<h3 class="no-margin bold font-montserrat text-primary"><?php echo esc_html( _dash_fmt_usdt( $_company_paid_gross_total ) ); ?></h3>
								<p class="hint-text no-margin fs-12">ордера без мерчантского контура</p>
							</div>
						</div>
					</div>
					<div class="col-lg-4 col-md-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Мерчантский контур paid</p>
								<h3 class="no-margin bold font-montserrat text-complete"><?php echo esc_html( _dash_fmt_usdt( $_merchant_paid_gross_total ) ); ?></h3>
								<p class="hint-text no-margin fs-12">gross paid-объём по ордерам мерчантов</p>
							</div>
						</div>
					</div>
					<div class="col-lg-4 col-md-12 m-b-15">
						<div class="card card-default no-margin">
							<div class="card-body p-3">
								<p class="hint-text no-margin fs-12 text-uppercase">Выведено компанией</p>
								<h3 class="no-margin bold font-montserrat text-complete"><?php echo esc_html( _dash_fmt_usdt( $_company_withdrawals_total ) ); ?></h3>
								<p class="hint-text no-margin fs-12">списания из profit/wallet</p>
							</div>
						</div>
					</div>
				</div>

				<!-- ══ ROW 3: График + Статусы сегодня ══════════════════════════ -->
				<div class="row m-b-20">

					<!-- График: динамика за 7 дней -->
					<div class="col-lg-8 col-md-12 m-b-15">
						<div class="card card-default no-margin full-height">
							<div class="card-header">
								<div class="card-title"><?php echo esc_html( $_week_chart_title ); ?></div>
							</div>
							<div class="card-body">
								<?php if ( $_week_has_activity ) : ?>
									<p class="hint-text fs-12 m-b-15"><?php echo esc_html( $_week_chart_hint ); ?></p>
									<div class="row m-b-15">
										<div class="col-sm-4 col-6 m-b-10">
											<p class="hint-text no-margin fs-11 text-uppercase">Всего</p>
											<h4 class="no-margin bold text-primary"><?php echo esc_html( number_format_i18n( (int) $_week_total_count ) ); ?></h4>
										</div>
										<div class="col-sm-4 col-6 m-b-10">
											<p class="hint-text no-margin fs-11 text-uppercase">Paid</p>
											<h4 class="no-margin bold text-success"><?php echo esc_html( number_format_i18n( (int) $_week_paid_count ) ); ?></h4>
										</div>
										<div class="col-sm-4 col-12 m-b-10">
											<p class="hint-text no-margin fs-11 text-uppercase">Период</p>
											<h5 class="no-margin bold text-master">
												<?php echo esc_html( reset( $_week_labels ) . ' — ' . end( $_week_labels ) ); ?>
											</h5>
										</div>
									</div>
									<div class="dashboard-week-legend m-b-15">
										<span class="dashboard-week-legend__item">
											<span class="dashboard-week-legend__swatch dashboard-week-legend__swatch--total" aria-hidden="true"></span>
											<span>Всего ордеров</span>
										</span>
										<span class="dashboard-week-legend__item">
											<span class="dashboard-week-legend__swatch dashboard-week-legend__swatch--paid" aria-hidden="true"></span>
											<span>Paid</span>
										</span>
										<?php if ( $_week_chart_is_fallback ) : ?>
											<span class="dashboard-week-badge">Последние активные дни</span>
										<?php endif; ?>
									</div>
									<div class="dashboard-week-grid">
										<?php foreach ( $_week_labels as $i => $label ) : ?>
											<?php
											$_week_day_total = isset( $_week_data_total[ $i ] ) ? (int) $_week_data_total[ $i ] : 0;
											$_week_day_paid  = isset( $_week_data_paid[ $i ] ) ? (int) $_week_data_paid[ $i ] : 0;
											$_week_day_total_height = $_week_max_daily_total > 0 && $_week_day_total > 0
												? max( 18, (int) round( ( $_week_day_total / $_week_max_daily_total ) * 100 ) )
												: 8;
											$_week_day_paid_height = $_week_max_daily_total > 0 && $_week_day_paid > 0
												? max( 18, (int) round( ( $_week_day_paid / $_week_max_daily_total ) * 100 ) )
												: 8;
											$_week_day_paid_ratio = $_week_day_total > 0
												? (int) round( ( $_week_day_paid / $_week_day_total ) * 100 )
												: 0;
											?>
											<div class="dashboard-week-day<?php echo $_week_day_total > 0 ? ' is-active' : ' is-empty'; ?>">
												<div class="dashboard-week-day__top">
													<span class="dashboard-week-day__label"><?php echo esc_html( $label ); ?></span>
													<span class="dashboard-week-day__share"><?php echo esc_html( $_week_day_paid_ratio ); ?>%</span>
												</div>
												<div class="dashboard-week-day__bars" aria-hidden="true">
													<span class="dashboard-week-day__bar dashboard-week-day__bar--total" style="height:<?php echo esc_attr( $_week_day_total_height ); ?>%"></span>
													<span class="dashboard-week-day__bar dashboard-week-day__bar--paid" style="height:<?php echo esc_attr( $_week_day_paid_height ); ?>%"></span>
												</div>
												<div class="dashboard-week-day__stats">
													<span><strong><?php echo esc_html( number_format_i18n( $_week_day_total ) ); ?></strong> всего</span>
													<span><strong><?php echo esc_html( number_format_i18n( $_week_day_paid ) ); ?></strong> paid</span>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
									<div class="dashboard-week-foot">
										<span>Активных дней: <strong><?php echo esc_html( number_format_i18n( $_week_active_days_count ) ); ?></strong> из 7</span>
										<span>Paid-rate: <strong><?php echo esc_html( number_format_i18n( $_week_paid_share ) ); ?>%</strong></span>
									</div>
								<?php else : ?>
									<div class="dashboard-empty-state dashboard-empty-state--chart">
										<div class="dashboard-empty-state__icon" aria-hidden="true">
											<i class="pg-icon">chart</i>
										</div>
										<div class="dashboard-empty-state__title">За последние 7 дней активности не было</div>
										<p class="dashboard-empty-state__text">Когда появятся ордера, здесь покажем динамику по всем заявкам и paid.</p>
										<div class="dashboard-empty-state__dates">
											<?php foreach ( $_week_labels as $label ) : ?>
												<span><?php echo esc_html( $label ); ?></span>
											<?php endforeach; ?>
										</div>
									</div>
								<?php endif; ?>
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
									echo '<div class="dashboard-empty-state dashboard-empty-state--compact">';
									echo '<div class="dashboard-empty-state__icon" aria-hidden="true"><i class="pg-icon">alert</i></div>';
									echo '<div class="dashboard-empty-state__title">Сегодня ордеров пока нет</div>';
									echo '<p class="dashboard-empty-state__text">Когда появятся сделки, здесь будет раскладка по текущим статусам.</p>';
									echo '</div>';
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
				<!-- /ROW 3 -->

				<!-- ══ ROW 4: Суммы по статусам (всё время) ═════════════════════ -->
				<div class="row m-b-5">
					<div class="col-12">
						<p class="hint-text fs-12 text-uppercase m-b-10" style="letter-spacing:.07em;">Всего за всё время</p>
					</div>
				</div>

				<div class="row m-b-20">
					<?php
					$_dashboard_kpi_cards = [
						[
							'label'        => 'Открытые',
							'icon'         => 'time',
							'variant'      => 'warning',
							'count'        => $_cnt_open,
							'sum'          => $_sum_open,
							'caption'      => 'созданные и ожидающие',
						],
						[
							'label'        => 'Оплаченные',
							'icon'         => 'tick_circle',
							'variant'      => 'success',
							'count'        => $_cnt_closed,
							'sum'          => $_sum_closed,
							'caption'      => 'успешно оплаченные',
						],
						[
							'label'        => 'Отменённые',
							'icon'         => 'close',
							'variant'      => 'danger',
							'count'        => $_cnt_cancel,
							'sum'          => $_sum_cancel,
							'caption'      => 'ошибка, отмена и истечение',
						],
					];
					?>
					<?php foreach ( $_dashboard_kpi_cards as $_dashboard_kpi_card ) : ?>
						<?php
						$_dashboard_kpi_count_share  = $_all_time_total_cnt > 0
							? (int) round( ( $_dashboard_kpi_card['count'] / $_all_time_total_cnt ) * 100 )
							: 0;
						$_dashboard_kpi_volume_share = $_all_time_total_sum > 0
							? (int) round( ( $_dashboard_kpi_card['sum'] / $_all_time_total_sum ) * 100 )
							: 0;
						$_dashboard_kpi_progress_css = 'width:' . $_dashboard_kpi_volume_share . '%;';
						if ( $_dashboard_kpi_card['count'] > 0 && $_dashboard_kpi_volume_share > 0 ) {
							$_dashboard_kpi_progress_css .= 'min-width:16px;';
						}
						?>
						<div class="col-xl-4 col-lg-4 col-md-6 col-sm-12 m-b-15">
							<div class="card card-default no-margin dashboard-kpi-card dashboard-kpi-card--<?php echo esc_attr( $_dashboard_kpi_card['variant'] ); ?>">
								<div class="card-body">
									<div class="dashboard-kpi-card__top">
										<div class="dashboard-kpi-card__eyebrow">
											<span class="dashboard-kpi-card__icon" aria-hidden="true">
												<i class="pg-icon"><?php echo esc_html( $_dashboard_kpi_card['icon'] ); ?></i>
											</span>
											<div class="dashboard-kpi-card__title-group">
												<div class="dashboard-kpi-card__label font-montserrat all-caps">
													<?php echo esc_html( $_dashboard_kpi_card['label'] ); ?>
												</div>
												<div class="dashboard-kpi-card__caption">
													<?php echo esc_html( $_dashboard_kpi_card['caption'] ); ?>
												</div>
											</div>
										</div>
										<span class="dashboard-kpi-card__count">
											<?php echo esc_html( number_format_i18n( (int) $_dashboard_kpi_card['count'] ) ); ?>
											<span>шт.</span>
										</span>
									</div>

									<div class="dashboard-kpi-card__value font-montserrat">
										<?php echo esc_html( _dash_fmt_usdt( (float) $_dashboard_kpi_card['sum'] ) ); ?>
									</div>

									<div class="dashboard-kpi-card__footer">
										<?php if ( $_dashboard_has_all_time_orders ) : ?>
											<div class="dashboard-kpi-card__progress" aria-hidden="true">
												<span style="<?php echo esc_attr( $_dashboard_kpi_progress_css ); ?>"></span>
											</div>
											<div class="dashboard-kpi-card__meta">
												<span><?php echo esc_html( $_dashboard_kpi_count_share ); ?>% ордеров</span>
												<span><?php echo esc_html( $_dashboard_kpi_volume_share ); ?>% объёма</span>
											</div>
										<?php else : ?>
											<div class="dashboard-kpi-card__empty">
												История появится после первых ордеров
											</div>
										<?php endif; ?>
									</div>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<!-- /ROW 4 -->

					<?php endif; ?>

			</div>
			<!-- /container -->

		</div>

		<?php get_template_part( 'template-parts/footer-backoffice' ); ?>
	</div>

</div>
<!-- END PAGE CONTAINER -->

<?php get_template_part( 'template-parts/quickview' ); ?>
<?php get_template_part( 'template-parts/overlay' ); ?>

<?php get_footer(); ?>
